# W|ZONE Inquiries MCP server

A small, read-only [Model Context Protocol](https://modelcontextprotocol.io) server. When someone gives an AI assistant a W|ZONE consultation link, the assistant can use this server to read which questions that form asks.

It exposes one tool:

| Tool | What it does |
|---|---|
| `get_inquiry(name)` | Returns the title, intro text, `active`/`inactive` status and fields of the public inquiry form at `inquiry.html?name=<name>`. |

## Design rules

These rules are deliberate. Keep them when you add tools.

- **No database credentials.** The server only calls the PHP application over HTTPS, at [`api/inquiry-lookup.php`](../api/inquiry-lookup.php). All outbound calls go through [`src/wzoneClient.js`](src/wzoneClient.js).
- **Only data that is already public.** It returns exactly what an anonymous visitor to the form already sees.
- **No enumeration.** Callers must already know an inquiry's exact name. There is no way to list inquiries.
- **Stateless, with a fresh `McpServer` for every request.** This is a second, independent defence against CVE-2026-25536 (see [`src/server.js`](src/server.js)).
- **Two rate limits.** This server limits each caller by IP, and PHP limits each visitor separately. When `MCP_UPSTREAM_KEY` is set, all traffic from this server shares one larger bucket in PHP.

## Layout

```
src/index.js        starts the process and handles graceful shutdown
src/app.js          HTTP layer: proxy trust, request ids, rate limit, /healthz, /readyz, /mcp
src/server.js       MCP layer: tool definitions and the output schema
src/wzoneClient.js  the only code that talks to PHP: timeout, 60s cache, X-MCP-Key
src/logger.js       one JSON line per event, written to stdout
test/               vitest suite, with PHP mocked at the fetch layer
```

## Running locally

```bash
cp env.example .env        # set WZONE_BASE_URL at minimum
npm ci
npm run dev                # http://localhost:8787/mcp
npm test
```

To try the server interactively:

```bash
npx @modelcontextprotocol/inspector
# Transport: Streamable HTTP, URL: http://localhost:8787/mcp
```

## Endpoints

| Path | Purpose |
|---|---|
| `POST /mcp` | MCP over Streamable HTTP, stateless. `GET` and `DELETE` return 405. |
| `GET /healthz` | Liveness check. Never calls PHP. The container `HEALTHCHECK` uses this. |
| `GET /readyz` | Readiness check. Returns 200 if PHP answers and 503 if it doesn't. |

Every response carries an `X-Request-Id` header. The same id appears on that request's log lines (`mcp_request`, `get_inquiry_completed`), together with `duration_ms`.

## Configuration

All settings are documented in [`env.example`](env.example). Set them in Coolify's Environment Variables panel. `.env` is never built into the image. These settings matter most in production:

- `WZONE_BASE_URL`: the PHP site. The server won't start without it.
- `TRUST_PROXY_HOPS`: `1` behind Coolify's Traefik. If it's wrong, every caller shares one rate-limit bucket.
- `MCP_UPSTREAM_KEY`: a shared secret that must be identical on this app and the PHP app.

The PHP side reads two variables of its own:

- `MCP_UPSTREAM_KEY`: the same key as above.
- `TRUSTED_PROXIES`: optional. A comma-separated list of IPs or CIDRs for any proxy outside the private network, such as a CDN.

## Deploying

Run it as its own Coolify Application with **Base Directory** set to `Wzone-ai`. The [`Dockerfile`](Dockerfile) installs dependencies from the lockfile with `npm ci` and runs as the unprivileged `node` user. The repo root's `.dockerignore` keeps this folder out of the PHP image.
