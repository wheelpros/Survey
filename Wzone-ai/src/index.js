import "dotenv/config";

import { logger } from "./logger.js";
import { createApp } from "./app.js";

const PORT = Number(process.env.MCP_PORT || 8787);

const httpServer = createApp().listen(PORT, () => {
  logger.info("mcp_server_started", {
    port: PORT,
    mode: process.env.MCP_MODE || "public",
    wzoneBaseUrl: process.env.WZONE_BASE_URL,
    publicUrl: process.env.MCP_PUBLIC_URL,
  });
});

/*
| Coolify sends SIGTERM on every redeploy. Stop accepting new connections
| and let in-flight lookups (bounded by WZONE_REQUEST_TIMEOUT_MS) finish,
| instead of cutting them off mid-response. The timer is the backstop for
| a client holding a connection open.
*/
function shutdown(signal) {
  logger.info("mcp_server_stopping", { signal });
  httpServer.close(() => {
    logger.info("mcp_server_stopped");
    process.exit(0);
  });
  httpServer.closeIdleConnections?.();
  setTimeout(() => process.exit(1), 10000).unref();
}

process.on("SIGTERM", () => shutdown("SIGTERM"));
process.on("SIGINT", () => shutdown("SIGINT"));
