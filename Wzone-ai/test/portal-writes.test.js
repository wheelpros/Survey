import { readFileSync } from "node:fs";
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

import { createApp } from "../src/app.js";
import { jsonResponse, stubUpstream } from "./helpers.js";

const fixtures = JSON.parse(readFileSync(new URL("./fixtures/api-v1.json", import.meta.url), "utf8"));

const READS = ["clients:read", "forms:read", "content:read", "projects:read", "notifications:read"];
// An seo_admin: no calendar, inquiries, review or notes.
const SEO = [...READS, "forms:write", "content:write", "projects:write", "notifications:write"];

/**
 * Introspection grants `scopes`; changes.php and notifications.php answer
 * from the real replies in fixtures/api-v1.json unless `v1` handles it.
 */
function fakePortal({ scopes, v1 } = {}) {
  return stubUpstream((url, init) => {
    if (url.endsWith("/api/oauth/server.php")) {
      return jsonResponse(200, {
        active: true, client_id: "c1", client_name: "Claude", scopes,
        expires_at: Math.floor(Date.now() / 1000) + 3600, resource: "http://localhost:18999/mcp",
        grant_id: 7, principal: { kind: "admin", id: 5, name: "Sean", role: "seo_admin" },
      });
    }
    const path = new URL(url).pathname.replace("/api/v1/", "");
    const body = init?.body ? JSON.parse(init.body) : undefined;
    const custom = v1?.(path, body, init);
    if (custom) return custom;
    if (path === "changes.php" && body.action === "prepare") return jsonResponse(200, { ok: true, data: { ...fixtures.prepare_change, tool: body.tool } });
    if (path === "changes.php" && body.action === "confirm") return jsonResponse(200, { ok: true, data: fixtures.confirm_change });
    if (path === "notifications.php") return jsonResponse(200, { ok: true, data: fixtures.mark_notifications_read });
    return jsonResponse(404, { ok: false, error: { code: "not_found", message: "No such thing" } });
  });
}

const posts = (upstream) =>
  upstream.mock.calls
    .filter(([url, init]) => url.includes("/api/v1/") && init.method === "POST")
    .map(([url, init]) => ({ path: new URL(url).pathname.replace("/api/v1/", ""), body: JSON.parse(init.body), headers: init.headers }));

describe("private server: confirmed changes", () => {
  let httpServer;
  let base;
  let nextIp = 50;
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
    const token = "wzat_" + String(++tokenSeq).padStart(43, "w");
    const c = new Client({ name: "t", version: "0" });
    await c.connect(new StreamableHTTPClientTransport(new URL(`${base}/mcp`), {
      requestInit: { headers: { Authorization: `Bearer ${token}`, "X-Forwarded-For": `198.51.100.${nextIp++}` } },
    }));
    return c;
  }

  const toolNames = async (c) => (await c.listTools()).tools.map((t) => t.name).sort();

  it("offers no change tools to a read-only connection", async () => {
    fakePortal({ scopes: READS });
    const c = await connect();
    const names = await toolNames(c);
    expect(names).not.toContain("confirm_change");
    expect(names).not.toContain("create_form_draft");
    expect(names).not.toContain("mark_notifications_read");
    await c.close();
  });

  it("offers exactly the changes a role's scopes allow", async () => {
    fakePortal({ scopes: SEO });
    const c = await connect();
    const names = await toolNames(c);
    for (const n of ["create_form_draft", "create_content_draft", "post_project_update", "update_task", "confirm_change", "mark_notifications_read"]) {
      expect(names).toContain(n);
    }
    for (const n of ["review_form", "propose_meeting", "respond_to_meeting", "save_my_client_note", "create_inquiry_draft"]) {
      expect(names).not.toContain(n);
    }
    await c.close();
  });

  it("prepares without changing anything, and tells the model to ask first", async () => {
    const upstream = fakePortal({ scopes: SEO });
    const c = await connect();

    const args = { client: 10, title: "Onboarding", questions: [{ text: "Goals?", type: "textarea" }] };
    const r = await c.callTool({ name: "create_form_draft", arguments: args });

    expect(r.isError).toBeFalsy();
    expect(r.content[0].text).toMatch(/^NOTHING HAS CHANGED YET/);
    expect(r.content[0].text).toContain(fixtures.prepare_change.summary);
    expect(r.structuredContent.confirmation_token).toBe(fixtures.prepare_change.confirmation_token);

    const [call] = posts(upstream);
    expect(call.path).toBe("changes.php");
    expect(call.body).toEqual({ action: "prepare", tool: "create_form_draft", args });
    expect(call.headers["X-MCP-Tool"]).toBe("create_form_draft");
    await c.close();
  });

  it("confirms with the token and the summary the person saw", async () => {
    const upstream = fakePortal({ scopes: SEO });
    const c = await connect();

    const r = await c.callTool({
      name: "confirm_change",
      arguments: { confirmation_token: "wzct_" + "t".repeat(43), summary: "Create the form ..." },
    });
    expect(r.isError).toBeFalsy();
    expect(r.content[0].text).toMatch(/^Done\./);
    expect(r.structuredContent.result.form_id).toBe(fixtures.confirm_change.result.form_id);
    expect(posts(upstream)[0].body).toEqual({ action: "confirm", confirmation_token: "wzct_" + "t".repeat(43), summary: "Create the form ..." });
    await c.close();
  });

  it("says so when a confirmation had already been used", async () => {
    fakePortal({
      scopes: SEO,
      v1: (path, body) =>
        path === "changes.php" && body.action === "confirm"
          ? jsonResponse(200, { ok: true, data: { ...fixtures.confirm_change, duplicate: true } })
          : null,
    });
    const c = await connect();
    const r = await c.callTool({ name: "confirm_change", arguments: { confirmation_token: "x", summary: "y" } });
    expect(r.content[0].text).toMatch(/already been done - nothing happened twice/);
    await c.close();
  });

  it("passes the portal's refusal through", async () => {
    fakePortal({
      scopes: SEO,
      v1: (path) =>
        path === "changes.php"
          ? jsonResponse(409, { ok: false, error: { code: "change_refused", message: "That form isn't waiting for review (it is pending)." } })
          : null,
    });
    const c = await connect();
    const r = await c.callTool({ name: "update_task", arguments: { id: 600, status: "published" } });
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toMatch(/isn't waiting for review/);
    await c.close();
  });

  it("checks arguments before asking the portal", async () => {
    const upstream = fakePortal({ scopes: SEO });
    const c = await connect();
    const bad = [
      ["create_content_draft", { client: 10, type: "tiktok", title: "x" }],
      ["create_form_draft", { client: 10, title: "x", questions: [] }],
      ["update_task", { id: 1, date: "tomorrow" }],
    ];
    for (const [name, args] of bad) {
      const r = await c.callTool({ name, arguments: args });
      expect(r.isError, name).toBe(true);
    }
    expect(posts(upstream)).toEqual([]);
    await c.close();
  });

  it("marks notifications read without a confirmation step", async () => {
    const upstream = fakePortal({ scopes: SEO });
    const c = await connect();
    const r = await c.callTool({ name: "mark_notifications_read", arguments: { all: true } });
    expect(r.structuredContent).toEqual(fixtures.mark_notifications_read);
    const r2 = await c.callTool({ name: "mark_notifications_read", arguments: { ids: [3, 4] } });
    expect(r2.isError).toBeFalsy();
    expect(posts(upstream).map((p) => p.body)).toEqual([{ all: true }, { ids: [3, 4] }]);
    await c.close();
  });

  it("doesn't claim a timed-out change failed", async () => {
    fakePortal({
      scopes: SEO,
      // Never answers - until the client's own timeout aborts it, as a real
      // fetch would.
      v1: (_path, _body, init) =>
        new Promise((_, reject) =>
          init.signal.addEventListener("abort", () => reject(Object.assign(new Error("aborted"), { name: "AbortError" })))
        ),
    });
    const c = await connect();
    const r = await c.callTool({ name: "confirm_change", arguments: { confirmation_token: "x", summary: "y" } });
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toMatch(/isn't known whether this went through/);
    await c.close();
  });

  it("builds the onboarding prompt around the tools this connection has", async () => {
    fakePortal({ scopes: SEO });
    const c = await connect();
    const p = await c.getPrompt({ name: "onboard_new_client", arguments: { client: "10" } });
    const text = p.messages[0].content.text;
    expect(text).toMatch(/create_form_draft/);
    expect(text).toMatch(/post_project_update/);
    expect(text).toMatch(/confirm_change only after they say yes/);
    await c.close();
  });
});
