import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { InMemoryTransport } from "@modelcontextprotocol/sdk/inMemory.js";

import { buildServer } from "../src/server.js";
import { clearCache } from "../src/wzoneClient.js";
import { canonicalAnswers, idempotencyKey, issueToken, verifyToken } from "../src/confirmation.js";
import { resetSubmitLimiter } from "../src/submitLimiter.js";
import { jsonResponse, sampleInquiry, stubUpstream } from "./helpers.js";

const SIGNING_KEY = process.env.MCP_SIGNING_KEY;
const answers = [
  { field_id: 12, value: "Help with SEO" },
  { field_id: 11, value: "Sara" },
];

let client;

async function connect(clientIp = "203.0.113.10") {
  const [clientSide, serverSide] = InMemoryTransport.createLinkedPair();
  await buildServer({ requestId: "test", clientIp }).connect(serverSide);
  const c = new Client({ name: "test-client", version: "0.0.0" });
  await c.connect(clientSide);
  return c;
}

beforeEach(async () => {
  clearCache();
  resetSubmitLimiter();
  client = await connect();
});

afterEach(async () => {
  await client.close();
  vi.unstubAllGlobals();
});

/**
 * A fake api/inquiry-submit.php: validates "required" on field 11, echoes a
 * dry run, stores real submits by submission_key (so duplicates show up).
 */
function fakeSubmitEndpoint() {
  const stored = new Map();
  const upstream = stubUpstream((url, init) => {
    if (!url.endsWith("/api/inquiry-submit.php")) return jsonResponse(200, sampleInquiry);
    const body = JSON.parse(init.body);
    const byId = Object.fromEntries(body.answers.map((a) => [a.fieldId, a.value]));
    if (!byId[11]) return jsonResponse(422, { success: false, message: 'Please fill in "Your name"' });

    if (body.dry_run) {
      return jsonResponse(200, {
        success: true,
        inquiry: { title: sampleInquiry.inquiry.title },
        answers: sampleInquiry.fields.map((f) => ({
          field_id: f.id, label: f.label, type: f.type, value: byId[f.id] ?? "",
        })),
      });
    }
    const duplicate = stored.has(body.submission_key);
    stored.set(body.submission_key, body);
    return jsonResponse(200, { success: true, duplicate, message: "ok" });
  });
  return { upstream, stored };
}

const prepare = (a = answers, name = "growth") =>
  client.callTool({ name: "prepare_inquiry_submission", arguments: { name, answers: a } });
const submit = (token, a = answers, name = "growth") =>
  client.callTool({
    name: "submit_inquiry_response",
    arguments: { name, answers: a, confirmation_token: token },
  });

describe("confirmation tokens", () => {
  const args = canonicalAnswers(answers);

  it("verifies for the same answers in any order", () => {
    const { token } = issueToken(SIGNING_KEY, "inquiry:x", args);
    const reordered = canonicalAnswers([...answers].reverse());
    expect(verifyToken(SIGNING_KEY, token, "inquiry:x", reordered)).toEqual({ ok: true });
  });

  it("rejects a changed answer, another inquiry, another key, or junk", () => {
    const { token } = issueToken(SIGNING_KEY, "inquiry:x", args);
    const changed = canonicalAnswers([{ field_id: 12, value: "Help with SEO!" }, answers[1]]);
    expect(verifyToken(SIGNING_KEY, token, "inquiry:x", changed).reason).toBe("mismatch");
    expect(verifyToken(SIGNING_KEY, token, "inquiry:y", args).reason).toBe("mismatch");
    expect(verifyToken("another-key-0123456789abcdef0123456789", token, "inquiry:x", args).reason).toBe("mismatch");
    expect(verifyToken(SIGNING_KEY, "nope", "inquiry:x", args).reason).toBe("malformed");
  });

  it("expires after 15 minutes, but only for a genuine signature", () => {
    const issued = Date.now();
    const { token } = issueToken(SIGNING_KEY, "inquiry:x", args, issued);
    expect(verifyToken(SIGNING_KEY, token, "inquiry:x", args, issued + 14 * 60e3).ok).toBe(true);
    expect(verifyToken(SIGNING_KEY, token, "inquiry:x", args, issued + 16 * 60e3).reason).toBe("expired");

    const forged = token.replace(/^\d+/, String(Math.floor(issued / 1000) + 99999));
    expect(verifyToken(SIGNING_KEY, forged, "inquiry:x", args, issued).reason).toBe("mismatch");
  });

  it("derives a stable 64-hex idempotency key", () => {
    expect(idempotencyKey("a.b")).toMatch(/^[0-9a-f]{64}$/);
    expect(idempotencyKey("a.b")).toBe(idempotencyKey("a.b"));
  });
});

describe("prepare → submit", () => {
  it("prepares without sending, then submits the confirmed answers once", async () => {
    const { upstream, stored } = fakeSubmitEndpoint();

    const prepared = await prepare();
    expect(prepared.isError).toBeFalsy();
    expect(stored.size).toBe(0);
    expect(prepared.structuredContent.answers.map((a) => a.label)).toEqual(["Your name", "What do you need help with?"]);
    expect(prepared.content[0].text).toMatch(/Nothing has been sent yet/);

    const dryRunCall = upstream.mock.calls.at(-1);
    expect(JSON.parse(dryRunCall[1].body)).toMatchObject({ name: "growth", dry_run: true });
    expect(dryRunCall[1].headers["X-MCP-Key"]).toBe("test-key");

    const token = prepared.structuredContent.confirmation_token;
    const sent = await submit(token, [...answers].reverse()); // order doesn't matter
    expect(sent.isError).toBeFalsy();
    expect(sent.structuredContent).toEqual({
      submitted: true,
      duplicate: false,
      message: "Sent. W|ZONE has the answers.",
    });

    const [[key, body]] = [...stored.entries()];
    expect(key).toBe(idempotencyKey(token));
    expect(body.answers).toEqual([
      { fieldId: 11, value: "Sara" },
      { fieldId: 12, value: "Help with SEO" },
    ]);
  });

  it("reports a retry with the same token as a duplicate, not a second lead", async () => {
    fakeSubmitEndpoint();
    const token = (await prepare()).structuredContent.confirmation_token;

    await submit(token);
    const again = await submit(token);

    expect(again.isError).toBeFalsy();
    expect(again.structuredContent.duplicate).toBe(true);
  });

  it("refuses to submit answers that differ from what was confirmed", async () => {
    const { stored } = fakeSubmitEndpoint();
    const token = (await prepare()).structuredContent.confirmation_token;

    const tampered = [{ field_id: 12, value: "Something else" }, answers[1]];
    const result = await submit(token, tampered);

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toMatch(/don't match what the person confirmed/);
    expect(stored.size).toBe(0);
  });

  it("refuses a token issued for a different inquiry", async () => {
    const { stored } = fakeSubmitEndpoint();
    const token = (await prepare(answers, "growth")).structuredContent.confirmation_token;

    const result = await submit(token, answers, "another-form");

    expect(result.isError).toBe(true);
    expect(stored.size).toBe(0);
  });

  it("passes PHP's validation message back from prepare and issues no token", async () => {
    fakeSubmitEndpoint();

    const result = await prepare([{ field_id: 12, value: "Help with SEO" }]);

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toBe('Please fill in "Your name"');
  });

  it("rejects duplicate field ids before calling upstream", async () => {
    const { upstream } = fakeSubmitEndpoint();

    const result = await prepare([answers[0], { ...answers[0], value: "again" }]);

    expect(result.isError).toBe(true);
    expect(upstream).not.toHaveBeenCalled();
  });

  it("limits submissions per caller (MCP_SUBMIT_MAX=3 in tests)", async () => {
    fakeSubmitEndpoint();

    for (let i = 0; i < 3; i++) {
      const a = [{ field_id: 11, value: `Person ${i}` }];
      const token = (await prepare(a)).structuredContent.confirmation_token;
      expect((await submit(token, a)).isError).toBeFalsy();
    }
    const a = [{ field_id: 11, value: "Person 4" }];
    const token = (await prepare(a)).structuredContent.confirmation_token;
    const fourth = await submit(token, a);
    expect(fourth.isError).toBe(true);
    expect(fourth.content[0].text).toMatch(/Too many submissions/);

    // A different caller is unaffected.
    const other = await connect("203.0.113.99");
    const otherToken = (await other.callTool({
      name: "prepare_inquiry_submission",
      arguments: { name: "growth", answers: a },
    })).structuredContent.confirmation_token;
    const ok = await other.callTool({
      name: "submit_inquiry_response",
      arguments: { name: "growth", answers: a, confirmation_token: otherToken },
    });
    expect(ok.isError).toBeFalsy();
    await other.close();
  });

  it("says a timed-out submit is safe to retry", async () => {
    fakeSubmitEndpoint();
    const token = (await prepare()).structuredContent.confirmation_token;

    stubUpstream((url, init) =>
      new Promise((resolve, reject) =>
        init.signal.addEventListener("abort", () =>
          reject(Object.assign(new Error("aborted"), { name: "AbortError" }))
        )
      )
    );
    const result = await submit(token);

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toMatch(/safe to call submit_inquiry_response again/);
  });
});

describe("resource and prompt", () => {
  it("reads inquiry://{name} and lists nothing", async () => {
    stubUpstream(() => jsonResponse(200, sampleInquiry));

    const read = await client.readResource({ uri: "inquiry://growth" });
    expect(JSON.parse(read.contents[0].text).title).toBe(sampleInquiry.inquiry.title);

    const { resources } = await client.listResources();
    expect(resources).toEqual([]);

    const { resourceTemplates } = await client.listResourceTemplates();
    expect(resourceTemplates.map((t) => t.uriTemplate)).toEqual(["inquiry://{name}"]);
  });

  it("refuses a malformed resource name without calling upstream", async () => {
    const upstream = stubUpstream(() => jsonResponse(200, sampleInquiry));
    await expect(client.readResource({ uri: "inquiry://Bad_Name" })).rejects.toThrow();
    expect(upstream).not.toHaveBeenCalled();
  });

  it("renders consultation_intake with the submit steps", async () => {
    const { messages } = await client.getPrompt({ name: "consultation_intake", arguments: { name: "growth" } });
    const text = messages[0].content.text;
    expect(text).toMatch(/get_inquiry with name "growth"/);
    expect(text).toMatch(/ONE AT A TIME/);
    expect(text).toMatch(/Never invent/);
    expect(text).toMatch(/prepare_inquiry_submission/);
  });
});

describe("without MCP_SIGNING_KEY", () => {
  it("offers no submit tools and the prompt hands over the link instead", async () => {
    vi.resetModules();
    vi.stubEnv("MCP_SIGNING_KEY", "");
    try {
      const { buildServer: buildReadOnly } = await import("../src/server.js");
      const [clientSide, serverSide] = InMemoryTransport.createLinkedPair();
      await buildReadOnly({ requestId: "t" }).connect(serverSide);
      const c = new Client({ name: "t", version: "0" });
      await c.connect(clientSide);

      const { tools } = await c.listTools();
      expect(tools.map((t) => t.name)).toEqual(["get_inquiry"]);

      const { messages } = await c.getPrompt({ name: "consultation_intake", arguments: { name: "growth" } });
      expect(messages[0].content.text).toMatch(/inquiry\.html\?name=growth/);
      expect(messages[0].content.text).not.toMatch(/prepare_inquiry_submission/);
      await c.close();
    } finally {
      vi.unstubAllEnvs();
      vi.resetModules();
    }
  });
});
