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

## One app, two endpoints

W|ZONE runs a single Coolify app from this folder, on `mcp.websitezone.co.uk`, with `MCP_MODE=private`:

| Endpoint | Login | Who | What |
|---|---|---|---|
| `https://mcp.websitezone.co.uk/mcp` | yes (OAuth, the person's portal account) | staff, and clients if the owner allows it | the portal as that person: read tools for every area, and changes the person confirms (below). This is the address to add as a connector in Claude |
| `https://mcp.websitezone.co.uk/public/mcp` | none | anyone with a consultation link | the inquiry tools above |

The two can't share one path. Claude decides whether to sign the person in from how `/mcp` answers, so a path is either behind a login or it isn't.

An anonymous caller can never reach a portal tool:
- `/mcp` refuses any request without a valid token before a server is even built.
- `/public/mcp` only ever builds the anonymous inquiry server.
- Each endpoint has its own rate-limit bucket.

`MCP_MODE=public` (the default) still exists. It serves only the inquiry tools, at `/mcp`, for a deployment that should have no portal access at all.

### How signing in works (private)

```
Claude ──/register, /authorize──▶ mcp.websitezone.co.uk (Node, SDK mcpAuthRouter)
                                   │ stores the request (api/oauth/server.php)
         ◀── 302 ──────────────────┘
Browser ──▶ survey.websitezone.co.uk/oauth-consent.html
            (signed in? else admin-login / login with ?next=, then back)
            Allow → api/oauth/consent.php → grant + one-time code
         ◀── 302 to Claude's redirect_uri?code=…&state=…
Claude ──/token (code + PKCE)──▶ mcp.websitezone.co.uk → api/oauth/server.php
         ◀── access token (1 h) + refresh token (30 days, rotated)
Claude ──/mcp, Bearer wzat_…──▶ mcp.websitezone.co.uk ─introspect (cached 60 s)─▶ PHP
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

## What the private server offers

Each person gets only the tools their connection's scopes allow, so a client never sees a staff tool. Every tool calls `api/v1` with the person's own token. PHP applies the same visibility rule as that area's browser page, and anything outside it is a 404.

| Scope | Tools | Who sees which records (same as the browser) |
|---|---|---|
| always | `whoami` | your own account and scopes |
| `clients:read` | `search_clients`, `get_client_overview`, `get_client_team`, `get_my_client_note` | the owner: every client; everyone else: the clients assigned to them. Notes are private to whoever wrote them |
| `forms:read` | `list_forms`, `get_form`, `list_forms_awaiting_review`, `list_form_responses`, `get_form_response` | forms: the owner and account managers see all, others the forms they wrote. Responses: from your assigned clients |
| `inquiries:read` | `list_inquiries`, `get_inquiry_form`, `list_inquiry_leads`, `get_inquiry_lead` | the owner, and account managers with inquiries access |
| `content:read` | `list_content`, `get_content`, `list_content_types` | the owner and account managers see all posts, others their own |
| `calendar:read` | `get_calendar`, `list_pending_approvals`, `get_meeting` | meetings of your assigned clients; closed to SEO admins |
| `projects:read` | `list_projects`, `get_project` | your clients' projects, the ones you manage, and the ones you're a member of; drafts as on the project page |
| `notifications:read` | `list_my_notifications` | your own inbox |

- **`get_client_overview`** answers "tell me about client X" in one call: profile, team, open forms, recent responses, upcoming and recent content, upcoming meetings, active projects. A section the connection has no scope for is left out and named in `not_included`.
- **Lists are paged by cursor.** Pass `next_cursor` back as `cursor`. Rows never repeat or go missing when new ones arrive between pages.
- **Resources:** `client://{id}`, `project://{id}` and `form://{id}` hold the same data as the overview and `get_*` tools, for attaching as context. Listing them returns only what the caller can see (up to 100 of each).
- **Prompts:** `client_weekly_report(client)`, `meeting_prep(meeting)`, `plan_month_content(client, month)`, `triage_new_leads(since)` and `review_queue()`. Each one is offered only when every tool it uses is available. They end in a recommendation; approving, scheduling and sending still happen in the portal.
- **Text people typed comes back under `untrusted_content`.** That covers form answers, lead answers, meeting topics and notes, captions, client descriptions and notifications. The server's instructions, and a line in front of every result that contains it, tell the model to treat it as data and never follow instructions inside it. Nothing exposed can write, so a hostile answer has nothing to act on.
- **Every reply is checked against a zod schema** before it reaches the model ([`src/portal/schemas.js`](src/portal/schemas.js)), and so is each tool's `outputSchema`. If PHP drifts, the tool returns an "unexpected shape" error and the log names the field.

### Changes, and how the person stays in charge

| Scope | Tool | What it does once confirmed |
|---|---|---|
| `clients:write` | `save_my_client_note` | replaces your own private note about a client |
| `forms:write` | `create_form_draft` | creates a form for your client. It **always** goes into the review queue, even when a reviewer asks for it |
| `forms:review` | `review_form` | approves a waiting form (it reaches the client) or returns it to its author with a comment |
| `inquiries:write` | `create_inquiry_draft` | creates a consultation inquiry, **closed** until someone opens it in the portal |
| `content:write` | `create_content_draft` | saves a **draft** post. Nothing here publishes |
| `calendar:write` | `propose_meeting` | asks a client for a meeting: every account at their company that you can reach, in the portal and by email, as the calendar page does |
| `calendar:write` | `respond_to_meeting` | accepts or declines a client's pending request |
| `projects:write` | `post_project_update` | adds an update to a project, published (the client is told once it's live) or as a draft |
| `projects:write` | `update_task` | changes the given fields of an update |
| `notifications:write` | `mark_notifications_read` | marks your own notifications read. **The only change without a confirmation step** |

Every other change takes two steps, through [`api/v1/changes.php`](../api/v1/changes.php):

1. **Prepare.** The tool above checks the change as the person, against the portal as it is now, and writes nothing. It returns a plain-words `summary`, a `preview` and a single-use `confirmation_token`, valid for 15 minutes. These tools are read-only, and the model is told to show the summary word for word and wait for a yes.
2. **`confirm_change(confirmation_token, summary)`** makes exactly the prepared change:
   - **The arguments never come back from the model.** PHP stored them in `mcp_confirmations` at prepare time, so nothing can be altered in between.
   - **The summary must match.** It is what the person's client shows when it asks them to approve a non-read-only tool, so they approve the change itself rather than an opaque token.
   - **Everything is checked again first:** scope, visibility, and state. If a reviewer decided the form in the meantime, or the client was moved to someone else, the change stops.
   - **It applies at most once.** A repeat returns the first result with `duplicate: true`. Only the person who prepared it, through the same connection, can confirm it.

Elicitation would be the protocol's way to ask, but this server is stateless, so a token carries the confirmation instead.

**Same behaviour as the page.** The changes call the browser endpoints' own shared functions, so a change made through the assistant lands exactly as the same click would: the same review gate, the same notifications and emails.

| Shared code | Used by the page | And by |
|---|---|---|
| [`api/survey-writes.php`](../api/survey-writes.php) | `admin-surveys.php`, `admin-survey-review.php` | `create_form_draft`, `review_form` |
| [`api/meeting-writes.php`](../api/meeting-writes.php) | `calendar.php` | `propose_meeting`, `respond_to_meeting` |
| [`api/task-writes.php`](../api/task-writes.php) | `project-tasks.php` | `post_project_update`, `update_task` |
| [`api/inquiry-templates.php`](../api/inquiry-templates.php) | `admin-inquiries.php` | `create_inquiry_draft` |

**Attribution.** `mcp_confirmations` keeps every prepared change: who, through which connection (`grant_id`), what, and its result with the ids it created. Each call is also in `mcp_audit_log`.

**Never exposed:** deleting anything, publishing content, sending announcements, and account, role or permission changes. Those stay in the portal.

### Clients

A client can connect their own AI assistant only when the owner has turned on **Allow clients to connect AI assistants** on the Connected apps page. It is off by default. Turning it off ends every client connection at once, because PHP re-checks the switch on every request.

A client's connection sees only that client's own records, from [`api/v1/me/`](../api/v1/me). The client's id comes from the token, never from an argument, and each file uses the same rule as the client's own page. Staff tools are never registered for a client, and the client tools are never registered for staff.

| Scope | Tool | What it does |
|---|---|---|
| `self:read` | `my_overview` | what's waiting on them (meeting requests, forms to fill in) and what's coming up |
| `self:read` | `my_calendar`, `get_my_meeting` | their meetings, and the posts that went live in the range |
| `self:read` | `my_forms`, `get_my_form` | released forms only, with their own answers once sent |
| `self:read` | `my_content` | live posts for their company, plus posts for every client |
| `self:read` | `my_projects`, `get_my_project` | their projects and only the **live** updates. No drafts, no staff names |
| `self:read` | `my_notifications` | their own inbox |
| `self:write` | `request_meeting` | asks the W\|ZONE team for a meeting, as the dashboard does |
| `self:write` | `respond_to_my_meeting` | accepts or declines a meeting W\|ZONE asked for |
| `self:write` | `submit_my_form` | sends their answers to a form (see below) |
| `self:write` | `mark_my_notifications_read` | own inbox, no confirmation |

The three `self:write` changes go through the same prepare → `confirm_change` steps as staff changes. `changes.php` never lets a client reach a staff change, or the other way round.

**`submit_my_form`** is checked more strictly than the page checks it:
- Every question needs an answer, and ticked options must come from the form's own list.
- A form with a file question has to be finished on the portal page, and the refusal says where.
- The answers are stored the way the page stores them (`✅ Instagram, ✅ TikTok`).

The submission itself is the page's own code, `submitSurveyResponse()` in [`api/survey-submit.php`](../api/survey-submit.php). So the client's admins are notified, the CSV email goes out, and the Google Apps Script gets its copy, exactly as from the page. That shared function also now refuses a form that hasn't been released to the client, which `submit-survey.php` used to accept if someone guessed its id.

**Prompts for clients:** `fill_in_my_form(form)` goes through a form one question at a time, then sends it once the client confirms. `my_week` summarises what's waiting on them and what's coming up.

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
src/portal/server.js the private server: whoami, then the tools, resources and prompts this person's scopes allow
src/portal/tools.js  the read tools as data: scope, input, output schema, api/v1 path
src/portal/writes.js the change tools (prepare), confirm_change, marking notifications read - staff's and clients'
src/portal/schemas.js what each api/v1 endpoint answers with (zod)
src/portal/resources.js client:// project:// form://
src/portal/prompts.js workflows: for staff (weekly report, meeting prep, content plan, lead triage, review queue, onboarding) and for clients (fill in a form, my week)
src/portal/call.js   the one way into api/v1: token, audit headers, envelope and shape checks
src/portal/scopes.js scopes advertised in metadata (PHP's api/oauth/lib.php decides who gets which)
src/oauth/provider.js OAuth provider for the SDK router; storage and consent live in PHP
src/confirmation.js signed, expiring confirmation tokens + idempotency keys
src/submitLimiter.js per-caller submission cap
src/wzoneClient.js  the only code that talks to PHP: timeout, 60s cache, X-MCP-Key
src/logger.js       one JSON line per event, written to stdout
test/               vitest suite, with PHP mocked at the fetch layer
test/fixtures/      real api/v1 replies, captured by tests/api-v1/run.php (see Testing)
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

## Testing

`npm test` runs the vitest suite with PHP mocked at the fetch layer. `test/contract.test.js` checks every tool's output schema against real `api/v1` replies in `test/fixtures/api-v1.json`. A reply must parse to itself, so a field PHP adds without a matching schema change fails too.

The PHP side has its own test, a permission matrix in [`tests/api-v1/run.php`](../tests/api-v1/run.php) at the repo root:

1. It seeds one person per role (owner, super admin, both kinds of account manager, SEO admin, a deactivated admin, clients).
2. It serves the real endpoints with `php -S`.
3. It checks that each role sees exactly the records its browser page shows, and is refused (403 or 404, never an empty success) everything else. It also covers cursors, parameter checks, MCP tokens and the audit log.
4. [`tests/api-v1/writes.php`](../tests/api-v1/writes.php) checks the changes. The browser endpoints that now use the shared write functions must behave as before (messages, review gate, notifications). Every change goes through prepare and confirm, and is stopped by a missing scope, an invisible record, a stale state, or a reused, expired, altered or borrowed confirmation.
5. [`tests/api-v1/clients.php`](../tests/api-v1/clients.php) checks a client's connection: nothing until the owner's switch is on, and cut off when it goes off. A client sees only their own records, never what their pages hide (unreleased forms, draft posts, draft or future project updates, a meeting they declined). Each client change works and is refused in each wrong case. The pages that now share the client's write code still behave as before.

The runner's PHP server sends no email, either way the portal sends it: SMTP goes to a closed local port and `mail()` to `/bin/true`. `SURVEY_WEBHOOK_URL` is set empty, so no test answer ever reaches the real Google sheet.

```bash
# from the repo root, against a throwaway MySQL/MariaDB database whose name ends in _test
DB_HOST=127.0.0.1 DB_NAME=wzone_test DB_USER=... DB_PASS=... php tests/api-v1/run.php
# ...and --fixtures to rewrite Wzone-ai/test/fixtures/api-v1.json after changing a reply
```

The runner drops every table in the database it is given, so it refuses a name that doesn't end in `_test`. `tests/` is in `.dockerignore`, so it never reaches the web root. CI runs both, and regenerates the fixtures from the real PHP before the Node tests run, so a reply and its schema can't drift apart unnoticed.

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
