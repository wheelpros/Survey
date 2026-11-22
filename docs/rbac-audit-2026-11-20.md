# Roles & Permissions Audit — WZONE Admin Portal

Date: 2026-11-20 · Branch: `daily-reports` (at `30525e3`) · Scope: read-only audit, no code changed.

This is Phase 1 of the RBAC refactor. It maps how authorization works today, lists the defects with file references, and names the files the later phases have to touch. Severity: **Critical / High / Medium / Low**.

- **Verified**: the author re-read the code for this finding.
- **Reported**: found by a parallel read-only reviewer, with the code path quoted, and not re-read.

---

## 1. Current architecture

| Layer | What it is |
|---|---|
| Backend | Plain PHP 8 + MySQL (PDO). About 100 endpoint files in `api/`. No router or framework; each file is its own endpoint. Tables are created lazily by `ensure*()` in `api/db.php`. `sql/*.sql` are the hand-run copies. |
| AI-facing API | `api/v1/*` uses `_bootstrap.php`, `_helpers.php` and `_writes.php`. `_writes.php` is a two-step write registry: prepare, then `confirm_change`. |
| OAuth / MCP | `api/oauth/*` is the PHP side of OAuth 2.1 (grants, tokens, consent). `Wzone-ai/` is a Node MCP server that forwards every tool call to `api/v1` with the user's token. |
| Frontend | Multi-page static HTML with vanilla JS (`*.html`, `assets/*.js`). The token and user object live in `localStorage`. |
| Tests | `tests/api-v1/run.php` is a PHP integration runner against a throwaway MySQL with `tests/api-v1/schema.sql`. Unit-style permission tests are in `tests/api-v1/permissions.php`. `Wzone-ai/test` uses Vitest. `tests/sidebar-check.php` checks sidebar wiring. |

### Roles (`admins.role`, a VARCHAR(30) with no constraint)

| Stored value | Shown as | Notes |
|---|---|---|
| `owner` | Director | `can()` returns true for every key (`api/permissions.php:197`). It cannot be assigned, demoted or edited through the admin endpoints. |
| `super_admin` | Super Admin | |
| `account_manager` | Account Manager | |
| `seo_admin` | Admin | A legacy name for the plain Admin role. |
| (users table) | Client | `principal.role = "client"`. `can()` is always false for a client. |

### Core modules

| File | Responsibility |
|---|---|
| `api/permissions.php` | The `PERMISSIONS` catalog (39 keys, 9 areas): defaults per role, `owner_only` flag, `can()`, `effectivePermissions()`, `requirePermission()`, catalog, matrix and store functions. |
| `api/auth.php` | `currentPrincipal()` (session token or MCP token; checks `active`/`approved`), `SCOPE_PERMISSIONS` / `SCOPE_PAGES` / `scopesAllowedFor()`, `assignedClientIds()` / `canSeeClient()`, `requirePage()`. |
| `api/admin-settings-writes.php` | Shared write rules for the browser and v1: role change, activate, manager assignment, admin CRUD, permission change validation, `permissionsOverview()`. |
| `api/team-scope.php` | `teamPoolFor()`: who manages whom, hard-coded by role. |
| `api/db.php` | Record-scope helpers: `projectScopeSql()` (2331), `contentSeesAll()` / `contentVisibleSql()` (1054-1077), `ensurePermissionTables()` (1489). |
| `api/activity-report-lib.php` | Report visibility and rights (`drSeesAll`, `drPool`, `drCanSee`, `drCanPlan`, ...). |
| `assets/permissions.js` | `window.adminCan(perm)`, read from `localStorage.admin_user.permissions`. |
| `assets/admin-nav.js` | Builds the sidebar from `LINKS` filtered by `adminCan`, and redirects pages the user cannot open. |
| `assets/role-nav.js` | Polls `api/admin-session.php` on load, on focus and every 3 minutes. It signs the user out on a role change and reloads on a permission change. |

### Storage

- **`role_permissions`** `(role, permission, allowed, updated_by, updated_at)`: one row per role for each key the Director changed from its default.
- **`admin_permission_overrides`** `(admin_id, permission, allowed, ...)`: one row per individual Allow or Deny. Inherit means no row.
- **Defaults** live in code (`PERMISSIONS[...]["roles"]`). Only deltas are stored, so a fresh install behaves exactly like the old hard-coded rules.
- **Relationships:**
  - `admins.managed_by_admin_id` is the hierarchy.
  - `admins.department_id` points to `departments`.
  - `admin_user_assignments(admin_id, user_id)` is the client scope.
  - `project_members` and `projects.account_manager_admin_id` give project scope.
  - `content_assignees` and `content.created_by` give content scope.
  - Report reviewers are in `daily_report_reviewers`.
- **Legacy:** `admins.inquiries_access` is mirrored to the `inquiries.read` override (`permissions.php:357`). It is re-imported on every request by `ensurePermissionTables()` with `INSERT IGNORE`.
- **No foreign keys** anywhere. Session tokens are stored in plaintext: one `session_token` column per admin or user, with no expiry and no logout endpoint.

---

## 2. Current permission flow

```
Browser login (api/admin-login.php)
  -> admins.session_token = random, returns {role, permissions: effectivePermissions()}
  -> localStorage.admin_token / admin_user
Page load
  -> assets/admin-nav.js: sidebar = LINKS filtered by adminCan(page key); redirect if the section is closed
  -> assets/role-nav.js: api/admin-session.php re-reads role + permissions (on load, focus, every 3 minutes)
API request (Authorization: Bearer <token>)
  -> PATH A (newer endpoints): currentPrincipal($pdo) -> checks active -> can()/requirePage()
  -> PATH B (about 40 legacy endpoints): own "SELECT ... FROM admins WHERE session_token=?"
       -> does NOT check active -> can($row, perm)
can($who, $perm):
  1. unknown key -> LogicException
  2. client / non-admin -> false;  owner -> true;  role not in PERMISSION_ROLES -> false
  3. owner_only key -> individual override only (default false)    <- the Director can delegate
  4. individual override -> role_permissions row -> code default
Record scope: decided in each endpoint (assignedClientIds, projectScopeSql, contentVisibleSql,
  drCanSee, teamPoolFor ...). *.view_all keys lift it.
AI (MCP): Wzone-ai -> api/v1 with a wzat_ token + X-MCP-Key
  -> principalFromMcpToken -> scopes = scopesAllowedFor(live permissions), not the consented list
  -> v1RequireScope + per-handler can() + the same scope helpers; writes are prepared, then confirm_change
```

### Current default matrix (code defaults; the Director may have changed them in the DB)

Y = granted by default, blank = not. The Director holds everything.

| Key | Admin | Super Admin | Account Mgr |
|---|:-:|:-:|:-:|
| `pages.dashboard`, `pages.forms`, `pages.content`, `pages.projects`, `pages.files`, `pages.messages`, `pages.notifications`, `pages.daily_reports` | Y | Y | Y |
| `clients.details` (open Clients), `calendar.use` | | Y | Y |
| `inquiries.read`, `inquiries.write`, `events.view` | | | |
| `clients.view_all`, `clients.approve`, `clients.delete`, `clients.manage_accounts` | | | |
| `clients.edit` | | | Y |
| `forms.review` | | | Y |
| `forms.responses` | | | |
| `content.view_all`, `content.publish` | | | Y |
| `content.create` | | Y | Y |
| `projects.create`, `tasks.write` | | Y | Y |
| `projects.view_all`, `projects.manage_any`, `projects.delete_any` | | | |
| `tasks.edit_any`, `tasks.see_drafts` | | | Y |
| `team.overview`, `announcements.send`, `team_forms.send`, `team_forms.responses` | | | |
| `reports.self`, `reports.review` | | Y | Y |
| `reports.create`, `reports.view_all` | | | |
| `settings.manage`, `admins.manage`, `permissions.manage` (owner_only) | – | – | – |

The data model behind this is already sound: catalog keys, a three-state override, deny-by-default, and live re-resolution for AI tokens. **The problems are enforcement gaps around it, not the model.**

---

## 3. Bugs and inconsistencies

### 3.1 Two authentication paths (root cause of several security findings)

Around 40 legacy files do their own token lookup instead of calling `currentPrincipal()`. They:

- skip the `admins.active` check;
- answer 200 with `success:false` instead of 401/403;
- differ in how they parse `Authorization`. Some use `str_replace("Bearer ", ...)`, others `trim(str_replace("Bearer", ...))`, and some fall back to `?token=`.

**Own lookup:** add-user, admin-content, admin-dashboard, admin-inquiries, admin-inquiry-responses, admin-login-slides, admin-manager-assignments, admin-position, admin-responses, admin-role, admin-survey-review, admin-surveys, admin-update, admin-user-details, admin-users, announcements, calendar, client-team, content-types, delete-client, download-response-excel, download-source-file, download-survey-file, get-admins, inquiries-access, notifications, project-tasks, projects, remove-admin, response-details, settings, source-files, source-folders, sources-lock, update-my-profile, verify-source-password.

`requirePage()` makes this worse: for a principal it cannot resolve (for example a deactivated admin) it returns silently and leaves the check "to the endpoint" (`api/auth.php:384-387`). The endpoint then lets the deactivated admin in.

### 3.2 Role-name checks that bypass the catalog (Medium/Low)

| Location | Check | Should be |
|---|---|---|
| `api/settings.php:13` | `in_array(role, ['owner','super_admin','account_manager'])` | `currentPrincipal` plus per-action key. An Admin given `settings.manage` is still refused. |
| `api/admin-dashboard.php:98` | team tab for `account_manager` / `super_admin` | `team.overview` or a scope rule |
| `api/client-team.php:96-103` | `editableColumnsFor` role matrix | hierarchy rule (see section 5) |
| `api/team-scope.php:26-70` | `teamPoolFor` role ladder | a hierarchy service |
| `api/activity-report-lib.php:86, 202, 219, 643` | `role === "owner"`, `drPlannable` role list | `can()` / hierarchy |
| `api/team-form-writes.php:356, 616` | close/reopen `role === "owner"` | a permission key |
| `api/projects.php:279, 853` | manager must be `owner` / `account_manager` | an explicit business rule constant |
| `api/admin-inquiries.php:50` | picker `role = 'account_manager'` | the same rule constant |
| `api/admin-users.php:37` | role whitelist | `currentPrincipal` plus key |
| `api/update-my-profile.php:36`, `settings.html:4190` | Director card | intentional (system-level), keep |
| `admin-dashboard.html:647` | default Team tab for `owner` | `adminCan('team.overview')` |
| `clients-management.html:366-381` | header copy by role | `clients.approve` / `clients.view_all` |

### 3.3 Hierarchy and lifecycle

- **Stale hierarchy on role change.** `changeAdminRole()` (`admin-settings-writes.php:689`) clears overrides but leaves `managed_by_admin_id`, both theirs and their reports'. An Admin promoted to Account Manager stays "managed by" a Super Admin. A Super Admin demoted to Admin still "manages" Admins, and `teamPoolFor` silently drops them.
- **Hard delete with orphans.** `api/remove-admin.php` runs `DELETE FROM admins` and leaves rows in `admin_permission_overrides`, `admin_user_assignments`, `project_members`, `content_assignees`, `managed_by_admin_id` and the OAuth grants. It also refuses `account_manager` targets for no stated reason, and no UI calls it.
- **Manager assignment wipes silently.** `assignAdminsToManager()` is a full replace with no diff or confirmation (`admin-settings-writes.php:760`, UI `settings.html` Distribute panel). Moving an admin to a new manager silently removes them from the old one; nothing is reassigned or notified.
- **Cycles.** None are possible today, because the role ladder is strict (AM > SA > Admin). They become possible as soon as a role changes, because of the first point above.
- **`ensurePermissionTables()` runs an `INSERT IGNORE` migration on every request** (`db.php:1527`). This is harmless, but it is a migration in a hot path.

### 3.4 Functional bugs found on the way

- **`admin-responses.php:70, 91`**: alias `s` is used twice (users and surveys). Every admin without `forms.responses` gets an uncaught SQL error 1066. It fails closed, but the feature is broken. (Reported)
- **`project-tasks.php:337-385`**: `GET ?id=` returns a teammate's draft phase without the `seesEveryDraft` rule that the list applies. (Reported)
- **`clients.details` vs AI.** `clients:read` maps to `null`, and `SCOPE_PAGES` has no `clients:` entry (`auth.php:72, 112`). An admin whose Clients page is switched off still reads client details and teams through the AI. (Reported, mapping verified)
- **Inconsistent login-slides policy.** The browser lets any admin edit them (`admin-login-slides.php:106-119`, "on purpose"). v1 requires `settings.manage`.
- **`content-types.php`**: any admin can create or delete global content types. There is no key.
- **Consent.** `consent.php:57-58` grants every allowed scope whatever the app requested. The comment at `auth.php:23-25` says the opposite.

### 3.5 Settings → Permissions UI (`settings.html:1955-2360`)

- **No effective value or source.** The API sends `admins[].permissions`, but the UI never uses it, so the user has to combine the role column and the override in their head. Inherit, Allow and Deny are only distinguished by a select style.
- **The "changed" dot is misleading.** It means "differs from the default", not "unsaved". No row shows which changes are still unsaved.
- **No confirmation on dangerous changes.** None before granting Director-only keys, removing page access from a whole role, or "Reset to default".
- **Unsaved edits can be lost.** There is no `beforeunload` guard, and pending edits for other people are invisible apart from the total count.
- **Save is not atomic.** It sends one POST per role or admin in sequence. A mid-way failure leaves some changes committed and the rest pending. HTTP status is ignored.
- **Director-only keys** are hidden on the By-role tab. On One-person they read "No - Director only" with only Inherit or Allow.
- **Stale or false copy:** `settings.html:1953` and `permissions-admin.php:14` say "The Director's alone", which is no longer true once the keys are delegated (see 4.2).

---

## 4. Security vulnerabilities

| # | Sev | Finding | Where | Status |
|---|---|---|---|---|
| S1 | **Critical** | **Remote code execution by a client.** The profile-image upload trusts the browser's MIME type and keeps the file's own extension. It saves to the web root `uploads/` with no PHP-execution block: Docker's Apache has `AllowOverride None` and no `<Directory>` rule. An approved client uploads `x.php` labelled `image/png`, then opens `/uploads/profile_<id>_<t>.php`. | `api/update-profile.php:91-109`; `Dockerfile`, `docker/*.conf` | Verified |
| S2 | **High** | **A delegate can make himself a full Director.** A non-owner given `permissions.manage` can POST changes for `admin_id` = himself: `checkAdminPermissionChange()` has no self or "may not exceed own grants" check. He then grants himself `admins.manage`, `settings.manage`, `clients.view_all` and the rest. | `admin-settings-writes.php:1075-1087`; `permissions-admin.php`; `v1/_writes.php:3037` | Verified |
| S3 | **High** | **The Director can be locked out.** `checkAdminActiveChange()` only forbids deactivating yourself. A delegated `admins.manage` holder can deactivate the Director (`owner`). Every Director session and MCP token then dies. | `admin-settings-writes.php:710`; `settings.php:138`; `v1/_writes.php:3165` | Verified |
| S4 | **High** | **The Director-only permission tools work over MCP.** `permissions-admin.php` refuses non-session callers ("from the browser only"), but `set_role_permissions` / `set_admin_permissions` in v1 accept an MCP token. Together with S2 and prompt injection in `untrusted_content`, a manipulated assistant can escalate. | `v1/_writes.php:3012-3070, 3473-3482` | Reported, consistent with verified code |
| S5 | **High** | **Deactivated admins keep access.** Deactivation does not clear `session_token`, sessions never expire, and the PATH B endpoints (3.1) never check `active`. A fired account manager keeps reading and editing projects, content, client details, files and forms. | `admin-settings-writes.php:719`; 3.1 list; `auth.php:384` | Verified |
| S6 | **High** | **IDOR in client details.** `admin-user-details.php` checks `clients.details` / `clients.edit` but never the client assignment, except for `remove_teammate` at L159. Any Super Admin or AM can read any client by id (contact details, appointments, files, invites), and an AM can rewrite any client's details. Rewriting `company_name` then exposes another company's posts and files, which are matched by name (`user-content.php:129`, `attachment.php:85`). | `api/admin-user-details.php:186-316, 325+` | Verified |
| S7 | **High** | **Stored XSS and IDOR in survey uploads.** Any admin token, of any role and even deactivated, downloads any client upload by sequential id. `mode=view` serves it inline with the client-supplied Content-Type and no `nosniff`. A client uploads an HTML file; when an admin previews it, the script runs on the portal origin, where the token sits in `?token=` and localStorage. | `api/download-survey-file.php:21-63`; `survey-submit.php:151` | Verified (whether the frontend opens `mode=view` as a page is unverified) |
| S8 | Medium | **Form answer export has no access check.** Any admin token exports any `survey_responses` id. | `api/download-response-excel.php:11-26` | Verified |
| S9 | Medium | **Legacy calendar actions skip client scope.** `get_admin_calendar_data&user_id=N` returns any client's appointments with their notes. `create_appointment` works for any client. | `api/calendar.php:435-469` | Reported |
| S10 | Medium | **"Reject" deletes approved clients.** It needs only `clients.approve`, not `clients.delete`, and skips the safeguards and cleanup in `delete-client.php`. | `api/admin-users.php:93-110` | Reported |
| S11 | Medium | **Delegated admin management can escalate.** An `admins.manage` holder can create or promote an `account_manager`, which may be more than they hold themselves. There is no "cannot grant above yourself" rule. | `add_admin`, `change_admin_role`, `add-user.php`, `admin-update.php` | Verified (by design today) |
| S12 | Medium | **Files can be assigned to clients outside your scope.** `source-files.php` takes `user_id` without `canSeeClient()`, so the file lands in that client's downloads. | `api/source-files.php:143-157` | Reported |
| S13 | Medium | **Executable file types are kept in the web root.** Source, survey and team-form uploads keep any extension, including `.php`, under `uploads/` in 0777 dirs. The names are random, so this is latent RCE once a path leaks. The `uploads/attachments/.htaccess` it relies on is ignored under `AllowOverride None`. | `source-files.php:119`; `survey-submit.php:172`; `team-form-writes.php:566` | Reported |
| S14 | Medium | **Login slides and content types are writable by any admin**, including deactivated ones. | `admin-login-slides.php`; `content-types.php:131, 249` | Verified (slides) / Reported |
| S15 | Medium | **A sign-up can claim another company's name.** Registration accepts any `company_name`; once approved, the client sees that company's posts. | `register.php:46`; `user-content.php:126` | Reported |
| S16 | Medium | **Consent always grants every scope.** Consent ignores the requested scopes and dynamic client registration is open, so phishing a Director yields an `admins:write` / `settings:write` token. It is bounded by live permissions. | `oauth/consent.php:57, 124`; `oauth/server.php:92-120` | Reported |
| S17 | Low | **Session tokens are weak.** They are stored in plaintext, never expire, have no logout, and travel in `?token=` for downloads, which leaks them into logs, history and Referer. | `download-*.php`; `admins.session_token` | Verified |
| S18 | Low | **The page switch does not cover every endpoint.** `team-forms.php` and `response-details.php` ignore `requirePage`, so switching Forms off does not block them. | | Reported |
| S19 | Low | **Unauthenticated maintenance script.** `convert_admin_passwords.php` is reachable without auth and rehashes non-`$2y$` hashes. | `api/convert_admin_passwords.php` | Reported |
| S20 | Low | **Missing hygiene.** A client password reset does not clear the client's session. There is no rate limit on forgot-password, register or the Files-password checks. | `reset-password.php:70`; `forgot-password.php`; `verify-source-password.php` | Reported |
| S21 | Low | **The Director's position can be changed by others.** `set_admin_position` / `admin-position.php` have no guard for the Director, unlike photo and update. | `v1/_writes.php:3102`; `admin-position.php:61` | Reported |
| S22 | Low | **v1 audit gap.** v1 accepts browser session tokens without `X-MCP-Key`, and those calls skip `mcp_audit_log`. | `v1/_bootstrap.php:147` | Reported |
| S23 | Info | **Frontend tampering.** It trusts `localStorage.admin_user` until `role-nav.js` refreshes it. Editing it shows owner UI for a moment but no data, because the server re-checks. The preview login (`admin-login.html:282`) is localhost-only. | | Reported |

**Checked and found sound:**

- `attachment.php` (active check, per-owner scope, nosniff).
- The `admin-content` view/edit/delete rules.
- The activity-report lib checks.
- Meeting and booking writes.
- `oauth/grants.php` and `oauth/admin.php`.
- The v1 two-step confirm: it is bound to the person and the connection, and re-runs prepare and the scope check.
- Client-only v1 endpoints.
- The Node MCP server, which delegates every decision to PHP.

---

## 5. Recommended authorization architecture

Keep the existing model and language. The catalog plus three-state overrides is the right core. The work is to make it the **only** path.

1. **One authentication gate.** Add `requireAdmin($pdo, array $opts = [])` and `requireClient()` to `api/auth.php`, built on `currentPrincipal()`:
   - always checks `active`;
   - returns 401 for no or invalid token and 403 for a refused permission;
   - optional `session_only`, `page`, `perm`.

   Migrate every PATH B file to it, which deletes about 40 copies of the token lookup. `requirePage()` must 401 on an unresolved token instead of returning.
2. **One resolution service: `api/permissions.php`.** Keep `can()` / `effectivePermissions()`. Then:
   - Split `owner_only` into **`director_only`** (never delegable: `permissions.manage`, plus whatever section 7 decides) and **delegable** keys.
   - An override can never grant a `director_only` key. This matches the goal's rule that "an individual override must never bypass Director-only restrictions".
   - Add an explainer, `permissionSource($who, $perm)` → `system | override | role | default`, for the UI.
3. **Grant guards: one function used by the browser and v1.** `assertCanManageAdmin($actor, $target, $change)`:
   - never the Director as target, for role, active, position, permissions or delete;
   - never yourself for role, active or permissions;
   - never a target outside your hierarchy;
   - never grant a key, role or scope you do not hold yourself;
   - permission writes from a browser session only. Mirror the browser rule in v1, or decide otherwise in section 7.
4. **One data-scope module: `api/scope.php`.** Collect `clientScope` (assigned / all), `projectScopeSql`, `contentVisibleSql`, `teamPoolFor` / hierarchy and the report scope behind one interface, `scopeSql($p, $resource)` / `canAccessRecord($p, $resource, $id)`. Every by-id endpoint calls `canAccessRecord`, which covers S6, S7, S8, S9 and S12. Only these scopes are enforceable today: **own**, **assigned (clients via `admin_user_assignments`)**, **project membership**, **managed team (`managed_by_admin_id`)**, and **all (`*.view_all`)**. Department scope is enforceable for activity reports only. Do not introduce department scope elsewhere until the business rule exists.
5. **Hierarchy service.** Replace `teamPoolFor` with `managedAdmins($actor)`, data-driven from a single `MANAGES` rule map. On role change, re-validate or clear `managed_by_admin_id` in both directions, and reject cycles when assigning.
6. **Sessions.** Clear `session_token` on deactivate and on role change (role change already does). Add an expiry. Hash tokens at rest. Move downloads off `?token=` to short-lived signed URLs, or to a header plus blob fetch.
7. **AI parity.** Map `clients:read` to `clients.details`, add `clients:` / `inquiries:` / `calendar:` to `SCOPE_PAGES`, and enforce the consented scopes as a second ceiling (least privilege). Keep re-resolving them live.
8. **Uploads.** Use one `storeUpload()` with an extension and MIME allow-list (sniffed with `finfo`) and server-generated names, stored outside the web root or behind a `<Directory uploads> php_admin_flag engine off` / `Require all denied` Apache rule. Serve files with `nosniff` and `Content-Disposition: attachment` unless the type is an allow-listed image.
9. **Frontend.** Keep `adminCan` as the single helper. Replace the role checks in 3.2. Make the Permissions UI render `effective` plus `source` from the API (section 3.5), add an unsaved marker, a `beforeunload` guard, a confirmation dialog listing dangerous changes, and one atomic batch save endpoint.

---

## 6. Database changes required

All additive and reversible. Nothing resets stored permissions.

| Change | Why | Migration notes |
|---|---|---|
| `admins.session_expires_at DATETIME NULL`; later, a hashed-token column | S5, S17 | Backfill NULL means "expires at next login", so nobody is signed out at deploy. Keep the old column until all endpoints use `requireAdmin`. |
| Clean up orphans: delete `admin_permission_overrides`, `admin_user_assignments` and `project_members` rows whose `admin_id` no longer exists | 3.3 | Report counts first (a dry-run query in the migration). Make `remove-admin` soft-delete (deactivate) or cascade in code. |
| Director-only overrides that are currently delegated | S2, S3 | **Do not delete silently.** List them in the migration output. Remove them only after the decision in section 7. |
| Null `managed_by_admin_id` where the role pair is invalid (an AM managed by anyone; an Admin or SA managed by a non-manager) | 3.3 | Log each change to `admin_events`. |
| Stop the per-request `INSERT IGNORE` from `inquiries_access` | 3.3 | Run it once in `sql/permissions.sql`. Keep the column for older builds. |
| Optional: FKs with `ON DELETE CASCADE` on override and assignment tables | orphan prevention | Only after cleanup. Skip if prod tables are MyISAM or have bad rows. |
| `sql/rbac-*.sql` hand-run copies of every lazy `ensure*()` change | convention | Same pattern as the existing `sql/*.sql` files. |

---

## 7. Business rules that need a decision (ambiguous, do not invent)

1. **Delegating Director-only keys.** Today the Director can delegate `settings.manage`, `admins.manage` and `permissions.manage` to one person. The goal says overrides must never bypass Director-only restrictions. Recommendation:
   - `permissions.manage` is never delegable;
   - `admins.manage` and `settings.manage` stay delegable, with the section 5 guards (no Director target, no self, no granting above yourself).
2. **Can permission or admin tools run through an AI connection?** The browser says no for permissions, and v1 says yes. Recommendation: browser-only for `permissions.*`, and AI is allowed for the rest of admin management.
3. **Can an Account Manager manage another Account Manager?** Today no; only Super Admins and Admins can sit under an AM. Confirm.
4. **Do managers get rights over their reports beyond activity reports?** For example editing their profile, role or permissions. Today they have none. Recommendation: none; the Director keeps control.
5. **Departments.** Should they limit anything besides activity reports, such as client or project visibility? Today they do not.
6. **On a manager or department change**, should the admin's client assignments and project memberships stay or be cleared? Today they stay.
7. **Inquiries.** Are inquiries and leads global for everyone with `inquiries.read`, or scoped per AM? Today they are global.
8. **Login slides and content types.** Should they need `settings.manage` and a new `content.types` key, or stay open to every admin?
9. **Removing an admin.** Hard delete, or deactivate only? Today there is a hard delete with no UI.
10. **OAuth consent.** Should a connection be limited to the scopes it requested, which is least privilege and a behaviour change for existing connections?

---

## 8. Test coverage today and gaps

**Covered** (`tests/api-v1/permissions.php`, `run.php`, `writes.php`):

- the defaults equal the old rules;
- override → role → default precedence;
- Director-only keys are never given to a role;
- a delegate cannot change the Director's permissions or role, or remove them (browser);
- the v1 permission tools are refused for non-Directors;
- 401 for no, unknown or inactive token on v1;
- a client token is refused on staff areas;
- per-role read scoping (mostly with session tokens);
- MCP tokens follow permission changes;
- confirm-flow binding.

**Missing — add in Phase 5:**

1. Every role against the full matrix; Allow over a role Deny; Deny over a role Allow.
2. A delegate deactivating the Director or changing their position (S3, S21).
3. Self-escalation through `set_admin_permissions` and `permissions-admin.php` (S2, S4).
4. A deactivated admin's token against every legacy endpoint (S5).
5. Direct-id access: client details, survey file, response export, calendar `user_id`, source-file `user_id`, draft phase (S6-S9, S12).
6. An upload with a `.php` or `.html` payload is rejected or neutralised (S1, S7, S13).
7. AI with `clients.details` denied; a session vs MCP confirm; a permission revoked between prepare and confirm.
8. Hierarchy invariants after role change; no cycles.
9. Node: tool lists follow scopes, and a revoked token cannot act through a stale cache.

---

## 9. Files that need modification

**Core (new or central)**

- `api/auth.php`: `requireAdmin()` / `requireClient()`, fix `requirePage()`, `SCOPE_PERMISSIONS` / `SCOPE_PAGES` for clients, inquiries and calendar, consented-scope ceiling.
- `api/permissions.php`: `director_only` vs delegable, `permissionSource()`, deny-by-default kept.
- `api/admin-settings-writes.php`: `assertCanManageAdmin()` used by the role, active, position, permissions, update, add and manager-assignment checks; hierarchy repair on role change; cycle check.
- `api/team-scope.php` → a hierarchy service; **new** `api/scope.php` with record-scope helpers (moving `projectScopeSql` and `contentVisibleSql` out of `db.php`, re-exported for compatibility).
- `api/db.php`: `ensurePermissionTables()` one-time migration, session-expiry column, remove the hot-path migration.
- **New** `api/upload-helpers.php` additions (or `api/uploads.php`): one safe `storeUpload()`.
- `Dockerfile` and `docker/site-files.conf` (or a new conf): deny PHP execution under `/uploads`.

**Legacy endpoints: switch to `requireAdmin` and add record checks**

`add-user`, `admin-content`, `admin-dashboard`, `admin-inquiries`, `admin-inquiry-responses`, `admin-login-slides`, `admin-manager-assignments`, `admin-position`, `admin-responses` (and the alias bug), `admin-role`, `admin-survey-review`, `admin-surveys`, `admin-update`, `admin-user-details` (IDOR), `admin-users` (reject vs delete), `announcements`, `calendar` (legacy scope), `client-team`, `content-types`, `delete-client`, `download-response-excel`, `download-source-file`, `download-survey-file`, `get-admins`, `inquiries-access`, `notifications`, `project-tasks` (draft by id), `projects`, `remove-admin`, `response-details`, `settings`, `source-files` (`user_id` scope), `source-folders`, `sources-lock`, `team-forms`, `update-my-profile`, `verify-source-password`, `update-profile` (S1), `survey-submit` and `team-form-writes` (uploads), `register` (company-name claim), `reset-password` (client session), `forgot-password` (rate limit); delete or block `convert_admin_passwords.php`.

**AI**

- `api/v1/_writes.php`: owner guard on `set_admin_position`, shared guards, permission tools session-only if decided.
- `api/v1/_bootstrap.php`: audit session callers.
- `api/v1/clients.php`: `clients.details`.
- `api/oauth/consent.php` and `api/oauth/server.php`: requested-scope ceiling, redirect URI and PKCE check on the PHP side.
- `Wzone-ai/src/portal/*`: only if scope names change.

**Frontend**

- `settings.html` Permissions and admin-management sections (effective value and source, unsaved marker, `beforeunload`, confirm dialog, batch save, Director-only badge).
- `assets/permissions.js` (optional `adminSource`).
- `admin-dashboard.html:647`, `clients-management.html:366-381`, plus the stale comments at `clients-management.html:351`, `settings.html:2372` and `admin-content.html:421`.
- `assets/admin-nav.js`: keep it; add a check in `tests/sidebar-check.php` that page keys exist in `PERMISSIONS`.

**SQL and tests**

- New `sql/rbac-2026-11.sql` (session expiry, orphan cleanup with dry-run counts, hierarchy repair).
- `tests/api-v1/schema.sql`.
- `tests/api-v1/permissions.php` and new `tests/api-v1/authz-*.php` suites.
- `Wzone-ai/test/portal-tools.test.js`.
