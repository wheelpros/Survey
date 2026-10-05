// The only file that knows how to reach the PHP application. Every tool
// goes through here rather than building its own URL, so the base URL
// stays configurable in exactly one place (see env.example /
// WZONE_BASE_URL) - the whole point of this file existing is that a
// future domain migration is a one-line env change, not a code change.
//
// This client has no database credentials and no ability to write
// anything: it only ever issues GET/HEAD requests against a single,
// already-public, read-only endpoint.

import { logger } from "./logger.js";

const BASE_URL = (process.env.WZONE_BASE_URL || "").replace(/\/+$/, "");
const TIMEOUT_MS = Number(process.env.WZONE_REQUEST_TIMEOUT_MS || 8000);
const CACHE_TTL_MS = Number(process.env.WZONE_CACHE_TTL_MS ?? 60000);
const CACHE_MAX_ENTRIES = 500;

// Optional shared secret. When set (here and as MCP_UPSTREAM_KEY on the
// PHP side), api/inquiry-lookup.php counts this server's traffic in its
// own bucket instead of the single per-IP one every AI user would
// otherwise share - this server already limits each of its own callers
// per IP before anything reaches PHP.
const UPSTREAM_KEY = process.env.MCP_UPSTREAM_KEY || "";

if (!BASE_URL) {
  throw new Error(
    "WZONE_BASE_URL is not set. Copy env.example to .env and configure it."
  );
}

const LOOKUP_URL = `${BASE_URL}/api/inquiry-lookup.php`;
const SUBMIT_URL = `${BASE_URL}/api/inquiry-submit.php`;

// api/inquiry-submit.php refuses anyone without the shared key, so the
// submit tools are only offered when it is configured.
export const canSubmitUpstream = UPSTREAM_KEY !== "";

/** An absolute URL on the PHP portal, e.g. portalUrl("oauth-consent.html"). */
export function portalUrl(path) {
  return `${BASE_URL}/${path.replace(/^\/+/, "")}`;
}

/** The public link a person can open to answer the form themselves. */
export function publicFormUrl(name) {
  return `${BASE_URL}/inquiry.html?name=${encodeURIComponent(name)}`;
}

/*
| Successful lookups only, for CACHE_TTL_MS. A form's questions rarely
| change, and an admin who edits one sees it reflected within the TTL.
| Failures are never cached: a 429 or a not-yet-created slug must be
| retried for real. Since only real, existing slugs can ever land here,
| the map is bounded by how many inquiries exist; the cap is a backstop.
*/
const cache = new Map();

function cacheGet(name) {
  const hit = cache.get(name);
  if (!hit) return null;
  if (hit.expires <= Date.now()) {
    cache.delete(name);
    return null;
  }
  return hit.value;
}

function cacheSet(name, value) {
  if (CACHE_TTL_MS <= 0) return;
  if (cache.size >= CACHE_MAX_ENTRIES) {
    // Map iterates in insertion order - drop the oldest entry.
    cache.delete(cache.keys().next().value);
  }
  cache.set(name, { value, expires: Date.now() + CACHE_TTL_MS });
}

export function clearCache() {
  cache.clear();
}

function upstreamHeaders() {
  const headers = { Accept: "application/json" };
  if (UPSTREAM_KEY) headers["X-MCP-Key"] = UPSTREAM_KEY;
  return headers;
}

async function fetchWithTimeout(url, init) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } catch (err) {
    if (err.name === "AbortError") {
      logger.error("wzone_api_timeout", { url });
      throw new Error("The WZONE API did not respond in time");
    }
    logger.error("wzone_api_request_failed", { error: err.message });
    throw err;
  } finally {
    clearTimeout(timeout);
  }
}

/**
 * Looks up one inquiry's public template (title, intro text, status,
 * fields) by its exact name/slug - the same information, and the same
 * "you must already know the exact name" boundary, as the public
 * inquiry.html form itself.
 *
 * Never throws for an expected outcome (not found, invalid name,
 * rate-limited) - those come back as a normal { success: false, message }
 * shape from the PHP side and are passed through as-is. This only throws
 * for something unexpected: a network failure, a timeout, or a non-JSON
 * response.
 *
 * Resolves to { body, cached }.
 */
export async function lookupInquiry(name) {
  const hit = cacheGet(name);
  if (hit) return { body: hit, cached: true };

  const res = await fetchWithTimeout(
    `${LOOKUP_URL}?name=${encodeURIComponent(name)}`,
    { method: "GET", headers: upstreamHeaders() }
  );

  let body;
  try {
    body = await res.json();
  } catch {
    logger.error("wzone_api_non_json_response", { status: res.status });
    throw new Error("The WZONE API returned an unexpected response");
  }

  // A 4xx/5xx with a valid { success:false, message } body is an
  // expected outcome (not found, rate-limited) - hand it back rather
  // than throwing, so the tool can surface the real message instead of
  // a generic error.
  if (body && body.success === true) cacheSet(name, body);
  return { body, cached: false };
}

/**
 * Sends answers to api/inquiry-submit.php - a dry run (validate and echo
 * back what would be stored) or the real thing with an idempotency key.
 *
 * `answers` is [{ field_id, value }]; PHP's shape is { fieldId, value }.
 * Resolves to { status, body } for any JSON reply, including refusals
 * (404 not found, 409 closed, 422 invalid, 429) - the caller decides what
 * each means. Throws only for a network failure, a timeout or a non-JSON
 * reply. Never retried here: on a real submit a timeout leaves the outcome
 * unknown, and the caller's idempotency key is what makes a retry safe.
 */
export async function submitToWzone({ name, answers, dryRun, submissionKey }) {
  const res = await fetchWithTimeout(SUBMIT_URL, {
    method: "POST",
    headers: { ...upstreamHeaders(), "Content-Type": "application/json" },
    body: JSON.stringify({
      name,
      answers: answers.map((a) => ({ fieldId: a.field_id, value: a.value })),
      dry_run: dryRun,
      ...(submissionKey ? { submission_key: submissionKey } : {}),
    }),
  });

  let body;
  try {
    body = await res.json();
  } catch {
    logger.error("wzone_submit_non_json_response", { status: res.status });
    throw new Error("The WZONE API returned an unexpected response");
  }
  return { status: res.status, body };
}

async function postJson(url, payload, extraHeaders = {}) {
  const res = await fetchWithTimeout(url, {
    method: "POST",
    headers: { ...upstreamHeaders(), "Content-Type": "application/json", ...extraHeaders },
    body: JSON.stringify(payload),
  });
  let body;
  try {
    body = await res.json();
  } catch {
    logger.error("wzone_api_non_json_response", { url, status: res.status });
    throw new Error("The WZONE API returned an unexpected response");
  }
  return { status: res.status, body };
}

/**
 * One step of the OAuth flow's storage (api/oauth/server.php) - MCP
 * server only, authenticated by the shared X-MCP-Key. Resolves to
 * { status, body }; throws only if the portal can't be reached.
 */
export function callOAuthStore(action, payload) {
  return postJson(`${BASE_URL}/api/oauth/server.php`, { action, ...payload });
}

/**
 * A call into the AI-facing api/v1 as the signed-in person: their access
 * token, plus the X-MCP-Key that api/v1 requires before it will accept an
 * MCP token at all, plus the tool and request id for the audit log.
 * Resolves to { status, body } where body is api/v1's { ok, data | error }.
 */
export async function callPortalApi(path, { token, tool, requestId, query } = {}) {
  const url = new URL(`${BASE_URL}/api/v1/${path}`);
  for (const [k, v] of Object.entries(query || {})) {
    if (v !== undefined && v !== null && v !== "") url.searchParams.set(k, String(v));
  }
  const res = await fetchWithTimeout(url, {
    method: "GET",
    headers: {
      ...upstreamHeaders(),
      Authorization: `Bearer ${token}`,
      ...(tool ? { "X-MCP-Tool": tool } : {}),
      ...(requestId ? { "X-Request-Id": requestId } : {}),
    },
  });
  let body;
  try {
    body = await res.json();
  } catch {
    logger.error("wzone_api_non_json_response", { path, status: res.status });
    throw new Error("The WZONE API returned an unexpected response");
  }
  return { status: res.status, body };
}

/**
 * Readiness probe: true if the PHP application answers at all.
 *
 * HEAD, because inquiry-lookup.php rejects anything but GET before it
 * touches its rate limiter - so probing on a timer never spends the
 * budget real lookups need. This proves the PHP side is reachable, not
 * that every query behind it will succeed.
 */
export async function pingUpstream() {
  try {
    const res = await fetchWithTimeout(LOOKUP_URL, {
      method: "HEAD",
      headers: upstreamHeaders(),
    });
    return res.status < 500;
  } catch {
    return false;
  }
}
