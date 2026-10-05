import { randomUUID } from "node:crypto";
import express from "express";
import rateLimit from "express-rate-limit";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { mcpAuthRouter, getOAuthProtectedResourceMetadataUrl } from "@modelcontextprotocol/sdk/server/auth/router.js";
import { requireBearerAuth } from "@modelcontextprotocol/sdk/server/auth/middleware/bearerAuth.js";

import { logger } from "./logger.js";
import { buildServer } from "./server.js";
import { buildPortalServer } from "./portal/server.js";
import { ALL_SCOPES } from "./portal/scopes.js";
import { createWzoneOAuthProvider, sha256 } from "./oauth/provider.js";
import { pingUpstream, portalUrl } from "./wzoneClient.js";

function rpcError(res, status, code, message) {
  res.status(status).json({ jsonrpc: "2.0", error: { code, message }, id: null });
}

// What a JSON-RPC body asked for, for the request log only.
function describeRpc(body) {
  const first = Array.isArray(body) ? body[0] : body;
  return {
    rpc_method: first?.method,
    tool: first?.method === "tools/call" ? first?.params?.name : undefined,
    batch: Array.isArray(body) ? body.length : undefined,
  };
}

/**
 * The POST /mcp handler, given how to build this request's McpServer.
 */
function mcpHandler(build) {
  return async (req, res) => {
    const started = Date.now();
    const server = build(req);

    // sessionIdGenerator: undefined -> stateless mode, the SDK's own
    // documented pattern for a server with no session state to keep
    // between requests. Combined with the fresh-instance-per-request
    // approach in server.js, no state or transport is ever shared across
    // callers.
    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: undefined,
    });

    res.on("close", () => {
      transport.close();
      server.close();
      logger.info("mcp_request", {
        requestId: req.id,
        ip: req.ip,
        principal: req.auth ? `${req.auth.extra?.principal?.kind}:${req.auth.extra?.principal?.id}` : undefined,
        status: res.statusCode,
        duration_ms: Date.now() - started,
        ...describeRpc(req.body),
      });
    });

    try {
      await server.connect(transport);
      await transport.handleRequest(req, res, req.body);
    } catch (err) {
      logger.error("mcp_request_failed", { requestId: req.id, error: err.message });
      if (!res.headersSent) {
        rpcError(res, 500, -32603, "Internal server error");
      }
    }
  };
}

/**
 * MCP_MODE=public (default): anonymous, public inquiry data only.
 * MCP_MODE=private: OAuth required; the portal as the signed-in person.
 * Two deployments of one codebase, so an anonymous caller can never reach
 * a private tool - the private tools simply aren't in the public process.
 */
export function createApp({ mode = process.env.MCP_MODE || "public" } = {}) {
  const app = express();

  // Coolify puts a reverse proxy in front of this container, so the socket
  // peer is always the proxy. Without this, req.ip is the proxy's address
  // and every caller shares one rate-limit bucket - one noisy client locks
  // out everyone. The value is how many proxy hops to trust from the right
  // of X-Forwarded-For: 1 for the proxy alone, 2 with a CDN in front of it.
  // Set it to 0 if this ever runs with no proxy at all, or callers could
  // pick their own bucket via a forged header.
  app.set("trust proxy", Number(process.env.TRUST_PROXY_HOPS ?? 1));

  app.use((req, res, next) => {
    req.id = randomUUID();
    res.setHeader("X-Request-Id", req.id);
    next();
  });

  // Defense-in-depth only. The PHP endpoints this server calls enforce
  // their own limits independently. This one exists so a runaway or
  // malicious MCP client can't hammer this process itself, and it's per
  // caller, which the PHP side can't be: every request reaching PHP from
  // here comes from this one container.
  const limiter = rateLimit({
    windowMs: Number(process.env.MCP_RATE_LIMIT_WINDOW_MS || 60000),
    limit: Number(process.env.MCP_RATE_LIMIT_MAX_REQUESTS || 60),
    standardHeaders: true,
    legacyHeaders: false,
    handler: (req, res) => {
      logger.warn("mcp_rate_limited", { requestId: req.id, ip: req.ip });
      rpcError(res, 429, -32000, "Too many requests");
    },
  });

  app.get("/healthz", (req, res) => {
    // Liveness: no secrets, no config values, no upstream call - just
    // confirms the process is up. The container HEALTHCHECK uses this, so
    // a PHP outage never gets this container restarted for nothing.
    res.json({ ok: true });
  });

  app.get("/readyz", async (req, res) => {
    // Readiness: can this server actually do its job right now?
    const upstream = await pingUpstream();
    res.status(upstream ? 200 : 503).json({ ok: upstream, upstream });
  });

  if (mode === "private") {
    mountPrivate(app, limiter);
  } else {
    app.post(
      "/mcp",
      limiter,
      express.json({ limit: "100kb" }),
      mcpHandler((req) => buildServer({ requestId: req.id, clientIp: req.ip }))
    );
  }

  // Streamable HTTP is POST-only for a stateless server like this one -
  // no GET (SSE stream) / DELETE (end session) semantics to support.
  app.all("/mcp", (req, res) => {
    rpcError(res, 405, -32000, "Method not allowed - this server is stateless (POST only)");
  });

  // Malformed JSON from express.json(): answer in JSON-RPC like
  // everything else on /mcp, instead of Express's default HTML page.
  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    if (err.type === "entity.parse.failed") {
      return rpcError(res, 400, -32700, "Parse error");
    }
    if (err.type === "entity.too.large") {
      return rpcError(res, 413, -32600, "Request too large");
    }
    logger.error("http_unhandled_error", { requestId: req.id, error: err.message });
    rpcError(res, 500, -32603, "Internal server error");
  });

  return app;
}

/*
|--------------------------------------------------------------------------
| MCP_MODE=private - OAuth 2.1 in front of the portal
|--------------------------------------------------------------------------
|
| The SDK's mcpAuthRouter serves the protocol at this server's origin:
|
|   /.well-known/oauth-protected-resource/mcp   RFC 9728 - where clients start
|   /.well-known/oauth-authorization-server     RFC 8414
|   /register  /authorize  /token  /revoke
|
| and src/oauth/provider.js backs it with the portal (storage in PHP,
| consent on the portal's own page). /mcp then needs a bearer token issued
| for exactly this resource; a 401 carries WWW-Authenticate pointing at the
| metadata, which is how Claude and other clients discover the login flow.
*/
function mountPrivate(app, limiter) {
  const publicUrl = process.env.MCP_PUBLIC_URL;
  if (!publicUrl) {
    throw new Error("MCP_MODE=private needs MCP_PUBLIC_URL - this server's own https origin.");
  }
  if (!process.env.MCP_UPSTREAM_KEY) {
    throw new Error("MCP_MODE=private needs MCP_UPSTREAM_KEY - the portal refuses OAuth storage calls without it.");
  }

  const issuerUrl = new URL(publicUrl);
  const resourceUrl = new URL("/mcp", issuerUrl);
  const provider = createWzoneOAuthProvider({
    resourceUrl,
    consentUrl: portalUrl("oauth-consent.html"),
  });

  // Client secrets are stored as sha256 only. Hash the presented one
  // before the SDK's client authentication compares it with what
  // getClient() returned. Parsing here first is harmless: the SDK's own
  // urlencoded parser sees the body is already parsed and skips it.
  app.use(["/token", "/revoke"], express.urlencoded({ extended: false }), (req, res, next) => {
    if (req.body && typeof req.body.client_secret === "string") {
      req.body.client_secret = sha256(req.body.client_secret);
    }
    next();
  });

  app.use(
    mcpAuthRouter({
      provider,
      issuerUrl,
      resourceServerUrl: resourceUrl,
      scopesSupported: ALL_SCOPES,
      resourceName: "W|ZONE portal",
      // A DCR secret that expires strands the client with no way to
      // refresh; revoking the grant is how access ends here instead.
      clientRegistrationOptions: { clientSecretExpirySeconds: 0 },
    })
  );

  const bearer = requireBearerAuth({
    verifier: provider,
    resourceMetadataUrl: getOAuthProtectedResourceMetadataUrl(resourceUrl),
    expectedResource: resourceUrl,
  });

  app.post(
    "/mcp",
    limiter,
    bearer,
    express.json({ limit: "100kb" }),
    mcpHandler((req) => buildPortalServer({ requestId: req.id, auth: req.auth }))
  );
}
