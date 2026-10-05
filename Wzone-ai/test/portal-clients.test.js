import { readFileSync } from "node:fs";
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

import { createApp } from "../src/app.js";
import { jsonResponse, stubUpstream } from "./helpers.js";

const fixtures = JSON.parse(readFileSync(new URL("./fixtures/api-v1.json", import.meta.url), "utf8"));

const CLIENT = ["self:read", "self:write"];

/** Introspection as a client; api/v1/me answers from the real replies. */
function fakePortal({ scopes = CLIENT } = {}) {
  return stubUpstream((url, init) => {
    if (url.endsWith("/api/oauth/server.php")) {
      return jsonResponse(200, {
        active: true, client_id: "c1", client_name: "Claude", scopes,
        expires_at: Math.floor(Date.now() / 1000) + 3600, resource: "http://localhost:18999/mcp",
        grant_id: 8, principal: { kind: "user", id: 10, name: "Alice", role: "client" },
      });
    }
    const u = new URL(url);
    const path = u.pathname.replace("/api/v1/", "");
    const body = init?.body ? JSON.parse(init.body) : undefined;
    const q = u.searchParams;
    const pick = {
      "me/overview.php": "my_overview",
      "me/calendar.php": q.has("id") ? "get_my_meeting" : "my_calendar",
      "me/forms.php": q.has("id") ? "get_my_form" : "my_forms",
      "me/content.php": "my_content",
      "me/projects.php": q.has("id") ? "get_my_project" : "my_projects",
      "me/notifications.php": body ? "mark_my_notifications_read" : "my_notifications",
      "changes.php": body?.action === "confirm" ? "confirm_change" : "prepare_change",
    }[path];
    return pick
      ? jsonResponse(200, { ok: true, data: fixtures[pick] })
      : jsonResponse(404, { ok: false, error: { code: "not_found", message: "No such thing" } });
  });
}

const calls = (upstream) =>
  upstream.mock.calls
    .filter(([url]) => url.includes("/api/v1/"))
    .map(([url, init]) => ({ path: new URL(url).pathname.replace("/api/v1/", ""), body: init.body ? JSON.parse(init.body) : undefined }));

describe("private server: a client's own assistant", () => {
  let httpServer;
  let base;
  let nextIp = 120;
  let tokenSeq = 0;

  beforeAll(async () => {
    vi.stubEnv("MCP_PUBLIC_URL", "http://localhost:18999");
    vi.stubEnv("MCP_RATE_LIMIT_MAX_REQUESTS", "1000");
    httpServer = createApp({ mode: "private" }).listen(0);
    await new Promise((r) => httpServer.once("listening", r));
    base = `http://127.0.0.1:${httpServer.address().port}`;
  });

  afterAll(async () => {
    vi.unstubAllEnvs();
    await new Promise((r) => httpServer.close(r));
  });

  afterEach(() => vi.unstubAllGlobals());

  async function connect() {
    const token = "wzat_" + String(++tokenSeq).padStart(43, "k");
    const c = new Client({ name: "t", version: "0" });
    await c.connect(new StreamableHTTPClientTransport(new URL(`${base}/mcp`), {
      requestInit: { headers: { Authorization: `Bearer ${token}`, "X-Forwarded-For": `198.51.100.${nextIp++}` } },
    }));
    return c;
  }

  it("offers the client's tools and prompts, and nothing of staff's", async () => {
    fakePortal();
    const c = await connect();
    const names = (await c.listTools()).tools.map((t) => t.name);
    for (const n of ["my_overview", "submit_my_form", "request_meeting", "respond_to_my_meeting", "confirm_change", "mark_my_notifications_read"]) {
      expect(names).toContain(n);
    }
    for (const n of ["search_clients", "get_client_overview", "create_form_draft", "mark_notifications_read", "list_my_notifications"]) {
      expect(names).not.toContain(n);
    }
    expect((await c.listPrompts()).prompts.map((p) => p.name).sort()).toEqual(["fill_in_my_form", "my_week"]);
    await c.close();
  });

  it("reads from api/v1/me with no client id ever sent", async () => {
    const upstream = fakePortal();
    const c = await connect();
    const r = await c.callTool({ name: "my_overview", arguments: {} });
    expect(r.isError).toBeFalsy();
    expect(r.structuredContent.me.name).toBe("Alice");
    await c.callTool({ name: "get_my_project", arguments: { id: 500 } });
    expect(calls(upstream).map((x) => x.path)).toEqual(["me/overview.php", "me/projects.php"]);
    await c.close();
  });

  it("prepares a form submission and leaves the sending to confirm_change", async () => {
    const upstream = fakePortal();
    const c = await connect();
    const args = { id: 120, answers: [{ question_id: 1202, value: ["Instagram"] }, { question_id: 1203, value: true }] };
    const r = await c.callTool({ name: "submit_my_form", arguments: args });
    expect(r.content[0].text).toMatch(/^NOTHING HAS CHANGED YET/);
    expect(calls(upstream)).toEqual([{ path: "changes.php", body: { action: "prepare", tool: "submit_my_form", args } }]);
    await c.close();
  });

  it("marks the client's own notifications read through api/v1/me", async () => {
    const upstream = fakePortal();
    const c = await connect();
    const r = await c.callTool({ name: "mark_my_notifications_read", arguments: { all: true } });
    expect(r.isError).toBeFalsy();
    expect(calls(upstream)).toEqual([{ path: "me/notifications.php", body: { all: true } }]);
    await c.close();
  });

  it("fills the form prompt around the confirmation step", async () => {
    fakePortal();
    const c = await connect();
    const p = await c.getPrompt({ name: "fill_in_my_form", arguments: { form: "120" } });
    const text = p.messages[0].content.text;
    expect(text).toMatch(/ONE AT A TIME/);
    expect(text).toMatch(/submit_my_form/);
    expect(text).toMatch(/confirm_change only after they say yes/);
    await c.close();
  });

  it("gives a read-only client connection nothing that changes anything", async () => {
    fakePortal({ scopes: ["self:read"] });
    const c = await connect();
    const names = (await c.listTools()).tools.map((t) => t.name);
    expect(names).not.toContain("submit_my_form");
    expect(names).not.toContain("confirm_change");
    expect(names).not.toContain("mark_my_notifications_read");
    expect((await c.listPrompts()).prompts.map((p) => p.name)).toEqual(["my_week"]);
    await c.close();
  });
});
