// The only file that knows how to reach the PHP application. Every tool
// goes through here rather than building its own URL, so the base URL
// stays configurable in exactly one place (see .env.example /
// WZONE_BASE_URL) - the whole point of this file existing is that a
// future domain migration is a one-line env change, not a code change.
//
// This client has no database credentials and no ability to write
// anything: it only ever issues GET requests against a single,
// already-public, read-only endpoint.

import { logger } from "./logger.js";

const BASE_URL = (process.env.WZONE_BASE_URL || "").replace(/\/+$/, "");
const TIMEOUT_MS = Number(process.env.WZONE_REQUEST_TIMEOUT_MS || 8000);

if (!BASE_URL) {
  throw new Error(
    "WZONE_BASE_URL is not set. Copy .env.example to .env and configure it."
  );
}

/**
 * Looks up one inquiry's public template (title, intro text, status,
 * fields) by its exact name/slug - the same information, and the same
 * "you must already know the exact name" boundary, as the public
 * inquiry.html form itself.
 *
 * Never throws for an expected outcome (not found, inactive, invalid
 * name) - those come back as a normal { success: false, message }
 * shape from the PHP side and are passed through as-is. This only
 * throws for something unexpected: a network failure, a timeout, or a
 * non-JSON response.
 */
export async function lookupInquiry(name) {
  const url = `${BASE_URL}/api/inquiry-lookup.php?name=${encodeURIComponent(name)}`;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS);

  try {
    const res = await fetch(url, {
      method: "GET",
      headers: { Accept: "application/json" },
      signal: controller.signal,
    });

    let body;
    try {
      body = await res.json();
    } catch {
      logger.error("wzone_api_non_json_response", { status: res.status });
      throw new Error("The WZONE API returned an unexpected response");
    }

    // A 4xx/5xx with a valid { success:false, message } body is an
    // expected outcome (not found, inactive, rate-limited) - hand it
    // back rather than throwing, so the tool can surface the real
    // message instead of a generic error.
    return body;

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
