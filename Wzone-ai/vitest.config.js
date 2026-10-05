import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    // wzoneClient.js reads these at import time.
    env: {
      WZONE_BASE_URL: "https://wzone.test",
      WZONE_REQUEST_TIMEOUT_MS: "200",
      MCP_UPSTREAM_KEY: "test-key",
      MCP_RATE_LIMIT_MAX_REQUESTS: "3",
      TRUST_PROXY_HOPS: "1",
      LOG_LEVEL: "error",
    },
  },
});
