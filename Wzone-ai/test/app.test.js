import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from "vitest";

import { createApp } from "../src/app.js";
import { jsonResponse, stubUpstream } from "./helpers.js";

let httpServer;
let base;

beforeAll(async () => {
  httpServer = createApp().listen(0);
  await new Promise((resolve) => httpServer.once("listening", resolve));
  base = `http://127.0.0.1:${httpServer.address().port}`;
});

afterAll(() => new Promise((resolve) => httpServer.close(resolve)));

afterEach(() => vi.unstubAllGlobals());

const listTools = (forwardedFor) =>
  fetch(`${base}/mcp`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json, text/event-stream",
      "X-Forwarded-For": forwardedFor,
    },
    body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/list" }),
  });

describe("health endpoints", () => {
  it("/healthz is up without touching upstream", async () => {
    const upstream = stubUpstream(() => jsonResponse(200, {}));

    const res = await fetch(`${base}/healthz`);

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ ok: true });
    expect(upstream).not.toHaveBeenCalled();
  });

  it("/readyz is 200 when PHP answers, via HEAD", async () => {
    const upstream = stubUpstream(() => new Response(null, { status: 405 }));

    const res = await fetch(`${base}/readyz`);

    expect(res.status).toBe(200);
    expect(upstream.mock.calls[0][1].method).toBe("HEAD");
  });

  it("/readyz is 503 when PHP is unreachable", async () => {
    stubUpstream(() => Promise.reject(new TypeError("fetch failed")));

    const res = await fetch(`${base}/readyz`);

    expect(res.status).toBe(503);
    expect(await res.json()).toEqual({ ok: false, upstream: false });
  });
});

describe("/mcp", () => {
  it("rate-limits each caller separately behind the proxy", async () => {
    // MCP_RATE_LIMIT_MAX_REQUESTS is 3 in vitest.config.js.
    for (let i = 0; i < 3; i++) {
      expect((await listTools("203.0.113.1")).status).toBe(200);
    }
    const limited = await listTools("203.0.113.1");
    expect(limited.status).toBe(429);
    expect((await limited.json()).error.message).toBe("Too many requests");

    // A different caller is unaffected.
    expect((await listTools("203.0.113.2")).status).toBe(200);
  });

  it("tags every response with a request id", async () => {
    const res = await listTools("203.0.113.3");
    expect(res.headers.get("x-request-id")).toMatch(/^[0-9a-f-]{36}$/);
  });

  it("answers malformed JSON with a JSON-RPC parse error", async () => {
    const res = await fetch(`${base}/mcp`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Forwarded-For": "203.0.113.4" },
      body: "{not json",
    });

    expect(res.status).toBe(400);
    expect((await res.json()).error.code).toBe(-32700);
  });

  it("refuses GET and DELETE - the server is stateless", async () => {
    for (const method of ["GET", "DELETE"]) {
      const res = await fetch(`${base}/mcp`, { method });
      expect(res.status).toBe(405);
    }
  });
});
