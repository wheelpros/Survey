# W|ZONE Inquiries MCP server

A small [Model Context Protocol](https://modelcontextprotocol.io) server. When someone gives an AI assistant a W|ZONE consultation link, the assistant can read which questions that form asks, go through them with the person, and send the answers once the person has confirmed them.

| Kind | Name | What it does |
|---|---|---|
| Tool (read-only) | `get_inquiry(name)` | Returns the title, intro text, `active`/`inactive` status and fields (with `id`) of the public form at `inquiry.html?name=<name>`. |
| Resource | `inquiry://{name}` | The same data, for clients that attach resources as context. There is no `list`, so names can't be enumerated. |
| Prompt | `consultation_intake(name)` | Has the assistant ask the questions one at a time, apply the form's rules (required fields, 120/800 characters, listed options), never invent answers, and ask for confirmation at the end. |
| Tool (read-only) | `prepare_inquiry_submission(name, answers)` | **Step 1 of 2.** Checks the answers with PHP's real validation without storing anything. Returns a summary for the person and a `confirmation_token` that is valid for 15 minutes. |
| Tool (write) | `submit_inquiry_response(name, answers, confirmation_token)` | **Step 2 of 2.** Sends the answers. If they differ from the confirmed ones, it refuses. Retrying is safe: the same token never stores twice. |

The two submit tools exist only when **both** `MCP_SIGNING_KEY` and `MCP_UPSTREAM_KEY` are set. Without them the server stays read-only.

### Why two steps instead of asking the person directly

MCP *elicitation* lets a server ask the person a question in the middle of a tool call. But the answer arrives as a new request, and this server is stateless, with a fresh instance per request (see below). The instance that asked would never receive the answer. Confirmation is therefore a signed token instead ([`src/confirmation.js`](src/confirmation.js)):

- **What's signed:** an HMAC over the inquiry name, the answers (in a fixed order) and an expiry.
- **What `submit` checks:** it recomputes the HMAC from the arguments it actually received. If anything changed after the person said yes, nothing is sent.
- **Retries:** `sha256(token)` is the idempotency key PHP stores under a unique index. A retry, or two copies racing each other, stores one response.

Clients such as Claude also ask the person to approve any tool that isn't read-only, so there is a second confirmation on the client side.

## Two servers, one codebase

`MCP_MODE` decides which server a deployment is. Deploy each one as its own Coolify app from this folder:

| `MCP_MODE` | Domain (suggested) | Who | What |
|---|---|---|---|
| `public` (default) | `mcp.websitezone.co.uk` | anyone with a link | the inquiry tools above |
| `private` | `portal-mcp.websitezone.co.uk` | signed-in staff and (if the owner allows) clients | the portal as that person. `whoami` today; the area tools build on it |

An anonymous caller can never reach a private tool, because the private tools aren't loaded in the public process.

### How signing in works (private)

```
Claude ──/register, /authorize──▶ portal-mcp (Node, SDK mcpAuthRouter)
                                   │ stores the request (api/oauth/server.php)
         ◀── 302 ──────────────────┘
Browser ──▶ survey.websitezone.co.uk/oauth-consent.html
            (signed in? else admin-login / login with ?next=, then back)
            Allow → api/oauth/consent.php → grant + one-time code
         ◀── 302 to Claude's redirect_uri?code=…&state=…
Claude ──/token (code + PKCE)──▶ portal-mcp → api/oauth/server.php
         ◀── access token (1 h) + refresh token (30 days, rotated)
Claude ──/mcp, Bearer wzat_…──▶ portal-mcp ─introspect (cached 60 s)─▶ PHP
                                   └─ tool → api/v1/*.php with the same token
                                      + X-MCP-Key + X-MCP-Tool + X-Request-Id
```

- **Discovery.** A request to `/mcp` without a token gets a 401 whose `WWW-Authenticate` points at `/.well-known/oauth-protected-resource/mcp` (RFC 9728). From there the client finds `/.well-known/oauth-authorization-server` (RFC 8414) and registers itself through dynamic client registration.
- **Consent happens on the portal domain**, because a portal session lives in that domain's `localStorage`. The page lists exactly what will be allowed, cut down to what the person's role allows. For example, an account manager without inquiries access is never offered `inquiries:*`.
- **Tokens:**
  - Opaque, prefixed `wzat_` for access and `wzrt_` for refresh.
  - Stored only as SHA-256. Client secrets are stored the same way, and app.js hashes the presented secret before the SDK compares.
  - Separate from browser sessions, so connecting Claude never signs anyone out.
  - Issued only for this server's `/mcp` (RFC 8707). A token for another resource is refused.
- **Replay detection.** If an authorization code or an already-rotated refresh token is presented again, the whole grant is revoked.
- **Scopes are checked on every request.** A token carries what was consented to, intersected with what the person's role allows *now*. Changing someone's role, deactivating them, or unapproving a client takes effect immediately at the portal, and in this server once the 60-second token-check cache expires.
- **`api/v1` only accepts an MCP token when the call also carries `X-MCP-Key`.** A token copied out of a client can't be replayed against the portal directly. Every MCP call is recorded in `mcp_audit_log`.
- **People stay in control:**
  - **Connected apps** (`connected-apps.html`, linked from Profile and Settings) lists each connection with what it may do, and has a Disconnect button.
  - The **owner** decides whether clients may connect at all. This is off by default, and turning it off ends every existing client connection.
  - The owner also sees recent AI activity across the portal.

## Design rules

These rules are deliberate. Keep them when you add tools.

- **No database credentials.** The server only calls the PHP application over HTTPS:
  - [`api/inquiry-lookup.php`](../api/inquiry-lookup.php) to read a form;
  - [`api/inquiry-submit.php`](../api/inquiry-submit.php) to send answers. It only accepts calls carrying `X-MCP-Key`, and stores them with `source='mcp'`.

  All outbound calls go through [`src/wzoneClient.js`](src/wzoneClient.js). Answers are validated in PHP by the same code the web form uses ([`api/inquiry-submission.php`](../api/inquiry-submission.php)), so the form and the AI can never disagree about what's valid.
- **Admins can see what an AI sent.** Those responses carry a "via AI assistant" label in the inquiry pages.
- **Per-caller submit limit:** 5 submissions per 10 minutes for each caller IP ([`src/submitLimiter.js`](src/submitLimiter.js)). This is on top of the general request limit.
- **Only data that is already public.** It returns exactly what an anonymous visitor to the form already sees.
- **No enumeration.** Callers must already know an inquiry's exact name. There is no way to list inquiries.
- **Stateless, with a fresh `McpServer` for every request.** This is a second, independent defence against CVE-2026-25536 (see [`src/server.js`](src/server.js)).
- **Two rate limits.** This server limits each caller by IP, and PHP limits each visitor separately. When `MCP_UPSTREAM_KEY` is set, all traffic from this server shares one larger bucket in PHP.

## Layout

```
src/index.js        starts the process and handles graceful shutdown
src/app.js          HTTP layer: proxy trust, request ids, rate limit, /healthz, /readyz, /mcp
src/server.js       MCP layer: tools, the inquiry:// resource, the intake prompt, output schemas
src/portal/server.js the private server: tools registered per person, portalCall() into api/v1
src/portal/scopes.js scopes advertised in metadata (PHP's api/oauth/lib.php decides who gets which)
src/oauth/provider.js OAuth provider for the SDK router; storage and consent live in PHP
src/confirmation.js signed, expiring confirmation tokens + idempotency keys
src/submitLimiter.js per-caller submission cap
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
- `MCP_UPSTREAM_KEY`: a shared secret that must be identical on this app and the PHP app. Submitting requires it.
- `MCP_SIGNING_KEY`: signs confirmation tokens. Set it on this app only, never on PHP. Submitting requires it.

The PHP side reads two variables of its own:

- `MCP_UPSTREAM_KEY`: the same key as above.
- `TRUSTED_PROXIES`: optional. A comma-separated list of IPs or CIDRs for any proxy outside the private network, such as a CDN.

## Deploying

Run it as its own Coolify Application with **Base Directory** set to `Wzone-ai`. The [`Dockerfile`](Dockerfile) installs dependencies from the lockfile with `npm ci` and runs as the unprivileged `node` user. The repo root's `.dockerignore` keeps this folder out of the PHP image.
