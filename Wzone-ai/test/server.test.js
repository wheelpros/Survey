import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { InMemoryTransport } from "@modelcontextprotocol/sdk/inMemory.js";

import { buildServer } from "../src/server.js";
import { clearCache } from "../src/wzoneClient.js";
import { jsonResponse, sampleInquiry, stubUpstream } from "./helpers.js";

let client;

beforeEach(async () => {
  clearCache();
  const [clientSide, serverSide] = InMemoryTransport.createLinkedPair();
  await buildServer({ requestId: "test" }).connect(serverSide);
  client = new Client({ name: "test-client", version: "0.0.0" });
  await client.connect(clientSide);
});

afterEach(async () => {
  await client.close();
  vi.unstubAllGlobals();
});

const call = (name) => client.callTool({ name: "get_inquiry", arguments: { name } });

describe("tools/list", () => {
  it("advertises get_inquiry as read-only with an output schema", async () => {
    const { tools } = await client.listTools();
    expect(tools.map((t) => t.name).sort()).toEqual([
      "get_inquiry",
      "prepare_inquiry_submission",
      "submit_inquiry_response",
    ]);

    const tool = tools.find((t) => t.name === "get_inquiry");
    expect(tool.title).toBeTruthy();
    expect(tool.annotations).toMatchObject({
      readOnlyHint: true,
      destructiveHint: false,
      idempotentHint: true,
    });
    expect(Object.keys(tool.outputSchema.properties)).toEqual(
      expect.arrayContaining(["title", "intro_text", "status", "fields"])
    );
  });
});

describe("get_inquiry", () => {
  it("returns the form as structuredContent and as text", async () => {
    const upstream = stubUpstream(() => jsonResponse(200, sampleInquiry));

    const result = await call("free-30-minute-business-growth-consultation");

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toEqual({
      ...sampleInquiry.inquiry,
      fields: sampleInquiry.fields,
    });
    expect(JSON.parse(result.content[0].text)).toEqual(result.structuredContent);

    const [url, init] = upstream.mock.calls[0];
    expect(url).toBe(
      "https://wzone.test/api/inquiry-lookup.php?name=free-30-minute-business-growth-consultation"
    );
    expect(init.method).toBe("GET");
    expect(init.headers["X-MCP-Key"]).toBe("test-key");
  });

  it("returns an inactive inquiry as a normal result, not an error", async () => {
    stubUpstream(() =>
      jsonResponse(200, { ...sampleInquiry, inquiry: { ...sampleInquiry.inquiry, status: "inactive" } })
    );

    const result = await call("closed-one");

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent.status).toBe("inactive");
  });

  it("accepts a null intro_text", async () => {
    stubUpstream(() =>
      jsonResponse(200, { ...sampleInquiry, inquiry: { ...sampleInquiry.inquiry, intro_text: null } })
    );

    const result = await call("no-intro");

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent.intro_text).toBeNull();
  });

  it("serves a repeat lookup from cache", async () => {
    const upstream = stubUpstream(() => jsonResponse(200, sampleInquiry));

    await call("cached-one");
    const second = await call("cached-one");

    expect(upstream).toHaveBeenCalledTimes(1);
    expect(second.structuredContent.title).toBe(sampleInquiry.inquiry.title);
  });

  it("passes PHP's not-found message through as a tool error, uncached", async () => {
    const upstream = stubUpstream(() =>
      jsonResponse(404, { success: false, message: "No inquiry found with that name" })
    );

    const first = await call("missing");
    await call("missing");

    expect(first.isError).toBe(true);
    expect(first.content[0].text).toBe("No inquiry found with that name");
    expect(upstream).toHaveBeenCalledTimes(2);
  });

  it("passes PHP's rate-limit message through as a tool error", async () => {
    stubUpstream(() =>
      jsonResponse(429, { success: false, message: "Too many requests - please try again shortly" })
    );

    const result = await call("busy");

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toMatch(/Too many requests/);
  });

  it("reports a timeout as a generic upstream error", async () => {
    stubUpstream(
      (url, init) =>
        new Promise((resolve, reject) => {
          init.signal.addEventListener("abort", () =>
            reject(Object.assign(new Error("aborted"), { name: "AbortError" }))
          );
        })
    );

    const result = await call("slow");

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toBe("Unable to reach the WZONE inquiry API right now.");
  });

  it("reports a non-JSON response as a generic upstream error", async () => {
    stubUpstream(() => new Response("<html>502 Bad Gateway</html>", { status: 502 }));

    const result = await call("broken");

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toBe("Unable to reach the WZONE inquiry API right now.");
  });

  it("rejects an upstream payload that doesn't match the output schema", async () => {
    stubUpstream(() =>
      jsonResponse(200, { success: true, inquiry: { title: 42 }, fields: "nope" })
    );

    const result = await call("drifted");

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toMatch(/unexpected shape/);
  });

  it("refuses a malformed name before calling upstream", async () => {
    const upstream = stubUpstream(() => jsonResponse(200, sampleInquiry));

    const result = await call("../etc/passwd");

    expect(result.isError).toBe(true);
    expect(upstream).not.toHaveBeenCalled();
  });
});
