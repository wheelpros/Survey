import { vi } from "vitest";

const realFetch = globalThis.fetch;

export function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

/**
 * Replaces fetch for the PHP upstream only (anything under
 * WZONE_BASE_URL); every other URL - the test's own calls to a local
 * app - still goes out for real. Returns the mock so tests can inspect
 * the upstream calls.
 */
export function stubUpstream(handler) {
  const upstream = vi.fn(handler);
  vi.stubGlobal("fetch", (url, init) =>
    String(url).startsWith(process.env.WZONE_BASE_URL)
      ? upstream(String(url), init)
      : realFetch(url, init)
  );
  return upstream;
}

export const sampleInquiry = {
  success: true,
  inquiry: {
    title: "Free 30-minute business growth consultation",
    intro_text: "Tell us about your business.",
    status: "active",
  },
  fields: [
    { id: 11, label: "Your name", type: "input", required: true, options: [] },
    { id: 12, label: "What do you need help with?", type: "textarea", required: false, options: [] },
  ],
};
