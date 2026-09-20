// Deliberately tiny - stdout only, one JSON line per event. No file
// rotation, no external service. Swap for pino/winston later if this
// server's traffic ever justifies it; it doesn't yet.

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };
const currentLevel = LEVELS[process.env.LOG_LEVEL] ?? LEVELS.info;

function log(level, message, meta = {}) {
  if (LEVELS[level] > currentLevel) return;

  // Never let a caller's `meta` accidentally include secrets - this
  // server holds none, but a defensive strip costs nothing and keeps
  // that true even if something's added to `meta` later without
  // thinking about it.
  const safeMeta = { ...meta };
  delete safeMeta.password;
  delete safeMeta.token;
  delete safeMeta.secret;
  delete safeMeta.apiKey;

  console.log(
    JSON.stringify({
      ts: new Date().toISOString(),
      level,
      message,
      ...safeMeta,
    })
  );
}

export const logger = {
  error: (message, meta) => log("error", message, meta),
  warn: (message, meta) => log("warn", message, meta),
  info: (message, meta) => log("info", message, meta),
  debug: (message, meta) => log("debug", message, meta),
};
