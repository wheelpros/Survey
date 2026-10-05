// Per-caller cap on submissions, on top of app.js's general per-caller
// request limit. Reading a form a few dozen times a minute is fine;
// sending a lead more than a handful of times in ten minutes is not
// something a person does.
//
// In memory, per process: right for one container, and a restart only
// ever resets it in the caller's favour. PHP's own limit on
// api/inquiry-submit.php is the backstop that survives restarts.

const WINDOW_MS = Number(process.env.MCP_SUBMIT_WINDOW_MS || 10 * 60 * 1000);
const MAX = Number(process.env.MCP_SUBMIT_MAX || 5);
const MAX_TRACKED = 10000;

const hits = new Map(); // ip -> timestamps (ms) inside the window

/**
 * Records one attempt for `ip` and returns true if it is allowed.
 */
export function allowSubmit(ip, now = Date.now()) {
  const key = ip || "unknown";
  const recent = (hits.get(key) || []).filter((t) => t > now - WINDOW_MS);

  if (recent.length >= MAX) {
    hits.set(key, recent);
    return false;
  }

  recent.push(now);
  hits.delete(key); // re-insert so the Map stays oldest-first
  hits.set(key, recent);

  if (hits.size > MAX_TRACKED) {
    hits.delete(hits.keys().next().value);
  }
  return true;
}

export function resetSubmitLimiter() {
  hits.clear();
}
