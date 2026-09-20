import "dotenv/config";
import express from "express";
import rateLimit from "express-rate-limit";
import { z } from "zod";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";

import { logger } from "./logger.js";
import { lookupInquiry } from "./wzoneClient.js";

const PORT = Number(process.env.MCP_PORT || 8787);

/*
|--------------------------------------------------------------------------
| Building a fresh McpServer + transport per request, on purpose
|--------------------------------------------------------------------------
|
| @modelcontextprotocol/sdk < 1.26.0 has a disclosed cross-client data
| leak (CVE-2026-25536 / GHSA-345p-7cg4-v4c7) when a single
| StreamableHTTPServerTransport or McpServer instance is reused across
| more than one client connection in a stateless deployment - which is
| exactly the shape below (no session tracking, one tool, no per-client
| state to preserve).
|
| package.json already pins the SDK to >=1.26.0, which contains the
| fix. This per-request construction is a second, independent
| mitigation: even on a correctly-patched SDK, there is structurally no
| shared instance for a bug like that to leak across, because nothing
| here is shared - each request gets its own McpServer and its own
| transport, used once, then discarded.
|
| This costs a small amount of per-request setup. For a single
| lightweight read-only tool with no session state to preserve, that's
| a fine trade against a documented cross-client leak vector - and it's
| the officially documented pattern for stateless deployments anyway
| (sessionIdGenerator: undefined below).
*/
function buildServer() {
  const server = new McpServer({
    name: "wzone-inquiries",
    version: "1.0.0",
  });

  server.tool(
    "get_inquiry",
    "Look up the public consultation inquiry form registered under an " +
      "exact name (its URL slug, e.g. " +
      "'free-30-minute-business-growth-consultation'). Returns the " +
      "form's title, intro text, active/inactive status, and its field " +
      "definitions (label, type, required, options). This is the same " +
      "information anyone already sees by opening the public link " +
      "directly - the exact name must already be known; there is no " +
      "way to list or browse inquiries through this tool.",
    {
      name: z
        .string()
        .min(1)
        .max(200)
        .regex(
          /^[a-z0-9-]+$/,
          "must be lowercase letters, digits and dashes only, e.g. 'free-consultation'"
        )
        .describe("The inquiry's exact name/slug from its public link"),
    },
    async ({ name }) => {
      try {
        const result = await lookupInquiry(name);

        if (!result.success) {
          // Expected, non-error outcomes: not found, inactive, invalid
          // name, rate-limited by the PHP side. Reported as tool
          // content, not thrown - this isn't a server malfunction.
          return {
            content: [{ type: "text", text: result.message || "Inquiry not available" }],
          };
        }

        return {
          content: [
            {
              type: "text",
              text: JSON.stringify(
                {
                  title: result.inquiry.title,
                  intro_text: result.inquiry.intro_text,
                  status: result.inquiry.status,
                  fields: result.fields,
                },
                null,
                2
              ),
            },
          ],
        };

      } catch (err) {
        logger.error("get_inquiry_tool_failed", { error: err.message });
        return {
          isError: true,
          content: [{ type: "text", text: "Unable to reach the WZONE inquiry API right now." }],
        };
      }
    }
  );

  return server;
}

const app = express();
app.use(express.json());

// Defense-in-depth only. The endpoint this server calls (PHP,
// api/inquiry-lookup.php) enforces its own, primary rate limit
// independently - it's reachable with or without this MCP server
// existing, so it has to protect itself regardless. This limit exists
// so a runaway or malicious MCP client can't hammer this process
// itself, e.g. with oversized/rapid requests before they ever reach
// the PHP side.
const limiter = rateLimit({
  windowMs: Number(process.env.MCP_RATE_LIMIT_WINDOW_MS || 60000),
  max: Number(process.env.MCP_RATE_LIMIT_MAX_REQUESTS || 60),
  standardHeaders: true,
  legacyHeaders: false,
  handler: (req, res) => {
    logger.warn("mcp_rate_limited", { ip: req.ip });
    res.status(429).json({
      jsonrpc: "2.0",
      error: { code: -32000, message: "Too many requests" },
      id: null,
    });
  },
});

app.get("/healthz", (req, res) => {
  // No secrets, no config values - just confirms the process is up.
  res.json({ ok: true });
});

app.post("/mcp", limiter, async (req, res) => {
  const server = buildServer();

  // sessionIdGenerator: undefined -> stateless mode, the SDK's own
  // documented pattern for a server with no session state to keep
  // between requests. Combined with the fresh-instance-per-request
  // approach above, no state or transport is ever shared across
  // callers.
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: undefined,
  });

  res.on("close", () => {
    transport.close();
    server.close();
  });

  try {
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch (err) {
    logger.error("mcp_request_failed", { error: err.message });
    if (!res.headersSent) {
      res.status(500).json({
        jsonrpc: "2.0",
        error: { code: -32603, message: "Internal server error" },
        id: null,
      });
    }
  }
});

// Streamable HTTP is POST-only for a stateless server like this one -
// no GET/DELETE session semantics to support.
app.get("/mcp", (req, res) => {
  res.status(405).json({
    jsonrpc: "2.0",
    error: { code: -32000, message: "Method not allowed - this server is stateless (POST only)" },
    id: null,
  });
});

app.listen(PORT, () => {
  logger.info("mcp_server_started", {
    port: PORT,
    wzoneBaseUrl: process.env.WZONE_BASE_URL,
  });
});
