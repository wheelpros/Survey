import { readFileSync } from "node:fs";
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

import { createApp } from "../src/app.js";
import { READ_TOOLS } from "../src/portal/tools.js";
import { jsonResponse, stubUpstream } from "./helpers.js";

const fixtures = JSON.parse(readFileSync(new URL("./fixtures/api-v1.json", import.meta.url), "utf8"));

const ALL_STAFF = [
  "clients:read", "clients:write", "forms:read", "forms:write", "forms:review",
  "inquiries:read", "inquiries:write", "content:read", "content:write",
  "calendar:read", "calendar:write", "projects:read", "projects:write",
  "notifications:read", "notifications:write",
];

// What an seo_admin's role allows: no calendar, no inquiries.
const SEO = ALL_STAFF.filter((s) => !s.startsWith("calendar") && !s.startsWith("inquiries") && s !== "forms:review" && s !== "clients:write");

/**
 * A fake portal: introspection grants `scopes`; api/v1 answers from the
 * real replies in fixtures/api-v1.json, or from `v1(path, url)` if given.
 */
function fakePortal({ scopes, v1 } = {}) {
  return stubUpstream((url, init) => {
    if (url.endsWith("/api/oauth/server.php")) {
      return jsonResponse(200, {
        active: true, client_id: "c1", client_name: "Claude", scopes,
        expires_at: Math.floor(Date.now() / 1000) + 3600, resource: "http://localhost:18999/mcp",
        grant_id: 7, principal: { kind: "admin", id: 1, name: "Olivia", role: "owner" },
      });
    }
    const u = new URL(url);
    const path = u.pathname.replace("/api/v1/", "");
    if (v1) {
      const custom = v1(path, u, init);
      if (custom) return custom;
    }
    const q = u.searchParams;
    const pick = {
      "me.php": "whoami",
      "clients.php": q.get("part") === "team" ? "get_client_team" : q.get("part") === "note" ? "get_my_client_note" : q.has("id") ? "get_client_overview" : "search_clients",
      "forms.php": q.has("id") ? "get_form" : q.has("queue") ? "list_forms_awaiting_review" : "list_forms",
      "projects.php": q.has("id") ? "get_project" : "list_projects",
      "notifications.php": "list_my_notifications",
      "calendar.php": q.has("id") ? "get_meeting" : q.has("pending") ? "list_pending_approvals" : "get_calendar",
    }[path];
    return pick ? jsonResponse(200, { ok: true, data: fixtures[pick] }) : jsonResponse(404, { ok: false, error: { code: "not_found", message: "No such thing" } });
  });
}

const v1Calls = (upstream) =>
  upstream.mock.calls.filter(([url]) => url.includes("/api/v1/")).map(([url, init]) => ({ url: new URL(url), init }));

describe("private server: per-person tools", () => {
  let httpServer;
  let base;
  let nextIp = 10;

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

  // Introspection is cached per token for 60 s, so each test uses its own.
  let tokenSeq = 0;
  async function connect() {
    const token = "wzat_" + String(++tokenSeq).padStart(43, "t");
    const c = new Client({ name: "t", version: "0" });
    await c.connect(new StreamableHTTPClientTransport(new URL(`${base}/mcp`), {
      requestInit: { headers: { Authorization: `Bearer ${token}`, "X-Forwarded-For": `198.51.100.${nextIp++}` } },
    }));
    return c;
  }

  const toolNames = async (c) => (await c.listTools()).tools.map((t) => t.name).sort();

  it("gives a client connection nothing but whoami", async () => {
    fakePortal({ scopes: ["self:read", "self:write"] });
    const c = await connect();
    expect(await toolNames(c)).toEqual(["whoami"]);
    // Not even advertised: with nothing to offer, the server has no prompts
    // or resources capability at all.
    expect(c.getServerCapabilities().prompts).toBeUndefined();
    expect(c.getServerCapabilities().resources).toBeUndefined();
    await c.close();
  });

  it("gives the owner every read tool, resource and prompt", async () => {
    fakePortal({ scopes: ALL_STAFF });
    const c = await connect();
    expect(await toolNames(c)).toEqual(["whoami", ...READ_TOOLS.map((t) => t.name)].sort());
    expect((await c.listResourceTemplates()).resourceTemplates.map((r) => r.uriTemplate).sort())
      .toEqual(["client://{id}", "form://{id}", "project://{id}"]);
    expect((await c.listPrompts()).prompts.map((p) => p.name).sort())
      .toEqual(["client_weekly_report", "meeting_prep", "plan_month_content", "review_queue", "triage_new_leads"]);
    const { tools } = await c.listTools();
    expect(tools.every((t) => t.annotations?.readOnlyHint === true && t.outputSchema)).toBe(true);
    await c.close();
  });

  it("leaves out an area the role can't reach, and the prompts that need it", async () => {
    fakePortal({ scopes: SEO });
    const c = await connect();
    const names = await toolNames(c);
    expect(names).toContain("get_client_overview");
    expect(names).not.toContain("get_calendar");
    expect(names).not.toContain("list_inquiry_leads");
    const prompts = (await c.listPrompts()).prompts.map((p) => p.name);
    expect(prompts).not.toContain("meeting_prep");
    expect(prompts).not.toContain("triage_new_leads");
    expect(prompts).toContain("plan_month_content");
    await c.close();
  });

  it("maps tool arguments onto api/v1 queries", async () => {
    const upstream = fakePortal({ scopes: ALL_STAFF });
    const c = await connect();

    await c.callTool({ name: "get_client_team", arguments: { client: 10 } });
    await c.callTool({ name: "list_forms_awaiting_review", arguments: { limit: 5 } });
    await c.callTool({ name: "list_my_notifications", arguments: { unread: true } });
    await c.callTool({ name: "list_my_notifications", arguments: { unread: false } });
    await c.callTool({ name: "get_calendar", arguments: { from: "2026-10-01", client: 10 } });

    const calls = v1Calls(upstream).map(({ url }) => `${url.pathname.replace("/api/v1/", "")}?${url.searchParams}`);
    expect(calls).toEqual([
      "clients.php?id=10&part=team",
      "forms.php?limit=5&queue=1",
      "notifications.php?unread=1",
      "notifications.php?",
      "calendar.php?from=2026-10-01&client=10",
    ]);
    const { init } = v1Calls(upstream)[0];
    expect(init.headers["X-MCP-Tool"]).toBe("get_client_team");
    await c.close();
  });

  it("returns typed results, with the untrusted-content reminder where it applies", async () => {
    fakePortal({ scopes: ALL_STAFF });
    const c = await connect();

    const overview = await c.callTool({ name: "get_client_overview", arguments: { client: 10 } });
    expect(overview.isError).toBeFalsy();
    expect(overview.structuredContent.client.id).toBe(10);
    expect(overview.content[0].text).toMatch(/^Fields named untrusted_content/);

    const types = await c.callTool({ name: "list_my_notifications", arguments: {} });
    expect(types.structuredContent.unread_count).toBe(1);
    await c.close();
  });

  it("rejects bad arguments before calling the portal", async () => {
    const upstream = fakePortal({ scopes: ALL_STAFF });
    const c = await connect();
    const r = await c.callTool({ name: "get_calendar", arguments: { from: "next tuesday" } });
    expect(r.isError).toBe(true);
    expect(v1Calls(upstream)).toEqual([]);
    await c.close();
  });

  it("turns a portal refusal or a drifted reply into a clean tool error", async () => {
    fakePortal({
      scopes: ALL_STAFF,
      v1: (path, url) => {
        if (path === "projects.php" && url.searchParams.get("id") === "999") {
          return jsonResponse(404, { ok: false, error: { code: "not_found", message: "No project with that id among the projects you can see." } });
        }
        if (path === "projects.php") return jsonResponse(200, { ok: true, data: { items: [{ id: "not a number" }], next_cursor: null } });
        return null;
      },
    });
    const c = await connect();

    const missing = await c.callTool({ name: "get_project", arguments: { id: 999 } });
    expect(missing.isError).toBe(true);
    expect(missing.content[0].text).toMatch(/among the projects you can see/);

    const drifted = await c.callTool({ name: "list_projects", arguments: {} });
    expect(drifted.isError).toBe(true);
    expect(drifted.content[0].text).toMatch(/unexpected shape/);
    await c.close();
  });

  it("lists and reads resources as the person", async () => {
    const upstream = fakePortal({ scopes: ALL_STAFF });
    const c = await connect();

    const { resources } = await c.listResources();
    expect(resources.map((r) => r.uri)).toContain("client://10");
    expect(resources.find((r) => r.uri === "client://10").name).toBe("Acme Ltd (Alice)");
    expect(resources.map((r) => r.uri)).toContain("project://501");

    const read = await c.readResource({ uri: "project://501" });
    const body = JSON.parse(read.contents[0].text);
    expect(body._note).toMatch(/untrusted_content/);
    expect(body.title).toBe("Beta SEO");

    await expect(c.readResource({ uri: "client://abc" })).rejects.toThrow();
    const tools = v1Calls(upstream).map(({ init }) => init.headers["X-MCP-Tool"]);
    expect(tools).toContain("resource_project");
    await c.close();
  });

  it("fills prompts with the arguments given", async () => {
    fakePortal({ scopes: ALL_STAFF });
    const c = await connect();

    const p = await c.getPrompt({ name: "plan_month_content", arguments: { client: "10", month: "2026-02" } });
    const text = p.messages[0].content.text;
    expect(text).toMatch(/from 2026-02-01, to 2026-02-28/);
    expect(text).toMatch(/untrusted_content/);

    await expect(c.getPrompt({ name: "plan_month_content", arguments: { client: "10", month: "Feb" } })).rejects.toThrow();
    await c.close();
  });
});
