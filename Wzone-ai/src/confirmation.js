// Stateless "the person confirmed exactly this" tokens.
//
// MCP elicitation would let the server ask the person directly, but it
// needs the server to hold a conversation open across requests, and this
// server is deliberately stateless (a fresh instance per request - see
// server.js). So confirmation is two tool calls instead:
//
//   prepare_*  validates, shows the person a summary, returns a token
//   submit_*   takes the same arguments back plus that token
//
// The token is an HMAC over the arguments and an expiry. submit recomputes
// it from what it was actually given, so if the model changed anything
// between the person saying yes and the submit call, it doesn't match and
// nothing is sent. sha256(token) doubles as the idempotency key upstream,
// so a retried submit can never store twice.
//
// Built for inquiry submissions first; written generically so the
// authenticated tools in later phases can reuse it.

import { createHash, createHmac, timingSafeEqual } from "node:crypto";

export const CONFIRMATION_TTL_MS = 15 * 60 * 1000;

// Answers in a fixed order and shape, so the same answers always sign the
// same way however the model ordered them.
export function canonicalAnswers(answers) {
  return [...answers]
    .map((a) => [a.field_id, a.value])
    .sort((x, y) => x[0] - y[0]);
}

function sign(key, scope, args, exp) {
  return createHmac("sha256", key)
    .update(JSON.stringify(["v1", scope, args, exp]))
    .digest("base64url");
}

/**
 * Issues a token for `args` under `scope` (e.g. "inquiry:<name>").
 * Returns { token, expiresAt } - token is "<unix expiry>.<signature>".
 */
export function issueToken(key, scope, args, now = Date.now()) {
  const exp = Math.floor((now + CONFIRMATION_TTL_MS) / 1000);
  return {
    token: `${exp}.${sign(key, scope, args, exp)}`,
    expiresAt: new Date(exp * 1000).toISOString(),
  };
}

/**
 * Checks a token against the args actually being submitted.
 * Returns { ok: true } or { ok: false, reason: "malformed" | "expired" | "mismatch" }.
 */
export function verifyToken(key, token, scope, args, now = Date.now()) {
  const match = /^(\d{1,12})\.([A-Za-z0-9_-]{43})$/.exec(String(token || ""));
  if (!match) return { ok: false, reason: "malformed" };

  const exp = Number(match[1]);
  const given = Buffer.from(match[2]);
  const expected = Buffer.from(sign(key, scope, args, exp));

  // Signature before expiry, so a forged token never learns whether its
  // expiry would have been accepted.
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) {
    return { ok: false, reason: "mismatch" };
  }
  if (exp * 1000 <= now) return { ok: false, reason: "expired" };
  return { ok: true };
}

export function idempotencyKey(token) {
  return createHash("sha256").update(String(token)).digest("hex");
}
