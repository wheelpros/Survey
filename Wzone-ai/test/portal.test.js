import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";
import { InvalidTargetError, InvalidTokenError } from "@modelcontextprotocol/sdk/server/auth/errors.js";

import { createApp } from "../src/app.js";
import { createWzoneOAuthProvider, sha256 } from "../src/oauth/provider.js";
import { jsonResponse, sampleInquiry, stubUpstream } from "./helpers.js";

const resourceUrl = new URL("http://localhost:18999/mcp");
const consentUrl = "https://wzone.test/oauth-consent.html";

const owner = { kind: "admin", id: 1, name: "Olivia Owner", role: "owner" };

/**
 * A fake portal: api/oauth/server.php actions and api/v1/me.php.
 * Returns the fetch mock so tests can count calls by action.
 */
function fakePortal({ active = true, scopes = ["clients:read"], resource = resourceUrl.href, me } = {}) {
  return stubUpstream((url, init) => {
    if (url.endsWith("/api/oauth/server.php")) {
      const body = JSON.parse(init.body);
      switch (body.action) {
        case "introspect":
          return jsonResponse(200, active
            ? { active: true, client_id: "c1", client_name: "Claude", scopes, expires_at: Math.floor(Date.now() / 1000) + 3600, resource, grant_id: 7, principal: owner }
            : { active: false });
        case "create_request":
          return jsonResponse(200, { request_id: "R".repeat(43) });
        case "get_client":
          return body.client_id === "known"
            ? jsonResponse(200, { client: { client_id: "known", redirect_uris: ["https://claude.ai/cb"] }, client_secret_hash: "h".repeat(64) })
            : jsonResponse(404, { error: "invalid_client", error_description: "Unknown client" });
        default:
          return jsonResponse(400, { error: "invalid_request" });
      }
    }
    if (url.includes("/api/v1/me.php")) {
      return me ? me(init) : jsonResponse(200, { ok: true, data: { kind: "admin", id: 1, name: "Olivia Owner", email: "o@x.test", role: "owner", scopes, via: "mcp", connected_app: "Claude", visible_clients: "all", visible_client_count: 2 } });
    }
    return jsonResponse(404, {});
  });
}

const actions = (upstream) =>
  upstream.mock.calls
    .filter(([url]) => url.endsWith("/api/oauth/server.php"))
    .map(([, init]) => JSON.parse(init.body).action);

afterEach(() => vi.unstubAllGlobals());

describe("OAuth provider", () => {
  const provider = createWzoneOAuthProvider({ resourceUrl, consentUrl });

  it("introspects once and serves the next check from cache", async () => {
    const upstream = fakePortal();
    const token = "wzat_" + "b".repeat(43);

    const first = await provider.verifyAccessToken(token);
    const second = await provider.verifyAccessToken(token);

    expect(first.extra.principal.role).toBe("owner");
    expect(first.resource.href).toBe(resourceUrl.href);
    expect(second).toBe(first);
    expect(actions(upstream)).toEqual(["introspect"]);
  });

  it("rejects a malformed token without asking the portal", async () => {
    const upstream = fakePortal();
    await expect(provider.verifyAccessToken("session-token-123")).rejects.toBeInstanceOf(InvalidTokenError);
    expect(actions(upstream)).toEqual([]);
  });

  it("rejects an inactive (expired or revoked) token", async () => {
    fakePortal({ active: false });
    await expect(provider.verifyAccessToken("wzat_" + "c".repeat(43))).rejects.toBeInstanceOf(InvalidTokenError);
  });

  it("sends /authorize to the portal's consent page, for this resource only", async () => {
    const upstream = fakePortal();
    const res = { redirect: vi.fn() };

    await provider.authorize(
      { client_id: "known" },
      { redirectUri: "https://claude.ai/cb", codeChallenge: "x".repeat(43), scopes: ["clients:read"], state: "s" },
      res
    );

    expect(res.redirect).toHaveBeenCalledWith(302, `${consentUrl}?request=${"R".repeat(43)}`);
    const created = JSON.parse(upstream.mock.calls[0][1].body);
    expect(created).toMatchObject({ action: "create_request", client_id: "known", resource: resourceUrl.href, state: "s" });
  });

  it("refuses to authorize a token for another resource", async () => {
    const upstream = fakePortal();
    await expect(
      provider.authorize(
        { client_id: "known" },
        { redirectUri: "https://claude.ai/cb", codeChallenge: "x".repeat(43), resource: new URL("https://evil.example/mcp") },
        { redirect: vi.fn() }
      )
    ).rejects.toBeInstanceOf(InvalidTargetError);
    expect(actions(upstream)).toEqual([]);
  });

  it("hands the SDK the stored secret hash, and undefined for an unknown client", async () => {
    fakePortal();
    expect((await provider.clientsStore.getClient("known")).client_secret).toBe("h".repeat(64));
    expect(await provider.clientsStore.getClient("nope")).toBeUndefined();
    expect(sha256("abc")).toMatch(/^[0-9a-f]{64}$/);
  });
});

describe("private MCP server", () => {
  let httpServer;
  let base;

  beforeAll(async () => {
    vi.stubEnv("MCP_PUBLIC_URL", "http://localhost:18999");
    // One MCP session is several requests; the per-IP limit itself is
    // covered in app.test.js.
    vi.stubEnv("MCP_RATE_LIMIT_MAX_REQUESTS", "100");
    httpServer = createApp({ mode: "private" }).listen(0);
    await new Promise((r) => httpServer.once("listening", r));
    base = `http://127.0.0.1:${httpServer.address().port}`;
  });

  afterAll(async () => {
    vi.unstubAllEnvs();
    await new Promise((r) => httpServer.close(r));
  });

  it("publishes protected-resource and authorization-server metadata", async () => {
    const pr = await (await fetch(`${base}/.well-known/oauth-protected-resource/mcp`)).json();
    expect(pr.resource).toBe("http://localhost:18999/mcp");
    expect(pr.authorization_servers).toEqual(["http://localhost:18999/"]);

    const as = await (await fetch(`${base}/.well-known/oauth-authorization-server`)).json();
    expect(as.code_challenge_methods_supported).toEqual(["S256"]);
    expect(as.scopes_supported).toContain("self:read");
  });

  it("serves the public inquiry tools at /public/mcp, with no login and nothing of the portal", async () => {
    const upstream = stubUpstream((url) =>
      url.includes("/api/inquiry-lookup.php")
        ? jsonResponse(200, sampleInquiry)
        : jsonResponse(404, {})
    );
    const c = new Client({ name: "t", version: "0" });
    await c.connect(new StreamableHTTPClientTransport(new URL(`${base}/public/mcp`), {
      requestInit: { headers: { "X-Forwarded-For": "198.51.100.250" } },
    }));

    const names = (await c.listTools()).tools.map((t) => t.name);
    expect(names).toContain("get_inquiry");
    expect(names).not.toContain("whoami");
    expect(names.some((n) => n.startsWith("list_") || n.startsWith("my_"))).toBe(false);

    const r = await c.callTool({ name: "get_inquiry", arguments: { name: "free-consult" } });
    expect(r.structuredContent.title).toBe(sampleInquiry.inquiry.title);
    // Anonymous: the portal's token check never ran.
    expect(upstream.mock.calls.some(([url]) => url.endsWith("/api/oauth/server.php"))).toBe(false);
    await c.close();
  });

  it("still turns /mcp away without a token when /public/mcp is open", async () => {
    const res = await fetch(`${base}/mcp`, {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json, text/event-stream", "X-Forwarded-For": "198.51.100.251" },
      body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/list" }),
    });
    expect(res.status).toBe(401);
    const get = await fetch(`${base}/public/mcp`);
    expect(get.status).toBe(405);
  });

  it("answers /mcp without a token with 401 and where to log in", async () => {
    const res = await fetch(`${base}/mcp`, { method: "POST", headers: { "content-type": "application/json" }, body: "{}" });
    expect(res.status).toBe(401);
    expect(res.headers.get("www-authenticate")).toMatch(/resource_metadata="http:\/\/localhost:18999\/\.well-known\/oauth-protected-resource\/mcp"/);
  });

  let nextIp = 10;
  async function connect(token) {
    const ip = `198.51.100.${nextIp++}`;
    const c = new Client({ name: "t", version: "0" });
    await c.connect(new StreamableHTTPClientTransport(new URL(`${base}/mcp`), {
      requestInit: { headers: { Authorization: `Bearer ${token}`, "X-Forwarded-For": ip } },
    }));
    return c;
  }

  it("runs whoami as the token's person, with audit headers on the api/v1 call", async () => {
    // No area scopes: whoami is all there is (portal-tools.test.js covers the rest).
    const upstream = fakePortal({ scopes: [] });
    const c = await connect("wzat_" + "d".repeat(43));

    expect((await c.listTools()).tools.map((t) => t.name)).toEqual(["whoami"]);
    const r = await c.callTool({ name: "whoami", arguments: {} });
    expect(r.isError).toBeFalsy();
    expect(r.structuredContent).toMatchObject({ role: "owner", visible_clients: "all" });

    const [, init] = upstream.mock.calls.find(([url]) => url.includes("/api/v1/me.php"));
    expect(init.headers.Authorization).toBe("Bearer wzat_" + "d".repeat(43));
    expect(init.headers["X-MCP-Key"]).toBe("test-key");
    expect(init.headers["X-MCP-Tool"]).toBe("whoami");
    expect(init.headers["X-Request-Id"]).toMatch(/^[0-9a-f-]{36}$/);
    await c.close();
  });

  it("refuses a token issued for another resource", async () => {
    fakePortal({ resource: "https://elsewhere.example/mcp" });
    const res = await fetch(`${base}/mcp`, {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json, text/event-stream", "X-Forwarded-For": "198.51.100.200", Authorization: `Bearer wzat_${"e".repeat(43)}` },
      body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/list" }),
    });
    expect(res.status).toBe(401);
  });

  it("passes api/v1's refusal back as a tool error", async () => {
    fakePortal({
      me: () => jsonResponse(403, { ok: false, error: { code: "insufficient_scope", message: "This connection isn't allowed to do that (clients:read)." } }),
    });
    const c = await connect("wzat_" + "f".repeat(43));
    const r = await c.callTool({ name: "whoami", arguments: {} });
    expect(r.isError).toBe(true);
    expect(r.content[0].text).toMatch(/isn't allowed/);
    await c.close();
  });
});
