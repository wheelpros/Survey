# Roles and permissions: what was built, and how to deploy it

Date: 2026-11-20 · Branch: `daily-reports` · Final report of the RBAC overhaul.

Read this first if you are new to the code. It describes the system **as it is in the code**, not as it was proposed. Where the build differs from `docs/plans/2026-11-20-authorization-design.md`, section 11 says so. The findings it answers are in `docs/rbac-audit-2026-11-20.md` (S1–S23).

**State of the work:** everything below is in the working tree, **uncommitted**, apart from this file. Nothing has run against production.

---

## 1. How authorization works now

Four modules. Every browser endpoint and every `api/v1` handler goes through them. Nothing else decides access.

```
request ─► auth.php         requireAdmin($pdo, $perm?)       who is calling: 401 / 403
              │             requirePage($pdo, $pages)         "the Director turned this page off"
              ▼
        permissions.php     can($who, $perm)                  may they do this kind of thing?
                            permissionDecision($who, $perm)   ...and why
                            adminTargetRefusal / permissionChangeRefusal   who an admin action may touch
              ▼
        scope.php           scopeSql / canAccessRecord / requireRecord     which records
              ▼
        team-scope.php      teamPoolFor(), MANAGER_CAN_MANAGE            the management tree
```

### 1.1 Roles

Stored in `admins.role`. The keys were kept, because renaming them needs a data migration and gains nothing.

| Stored | Shown as |
|---|---|
| `owner` | Director |
| `super_admin` | Super Admin |
| `account_manager` | Account Manager |
| `seo_admin` | Admin |

A client is not a role here: a client session is refused by `can()` and by `requireAdmin()` (403).

### 1.2 Permission resolution (`api/permissions.php`)

`permissionDecision($who, $perm)` returns `{allowed, source}`. `can()` is its boolean. The order:

1. Unknown key: throws `LogicException` (a bug, not a denial).
2. Not an admin, an empty or unknown role, or the permission tables can't be read: **deny**, source `system`.
3. `owner`: **allow**, source `director`.
4. The admin's own override row: its value, source `override`.
5. For a Director-only key (`owner_only`): **deny**. Role rows and defaults never hand one out.
6. The role's row in `role_permissions`: source `role`.
7. The shipped default for the role (`PERMISSIONS[...]["roles"]`): source `default`. The UI shows `role` and `default` the same way, as "from role".
8. Otherwise deny.

A database error other than "table doesn't exist yet" denies. Before, the code fell back to the defaults and could ignore a Director's stored Deny.

Only changes are stored (Allow or Deny rows), so a fresh install behaves like the old hard-coded rules. "Inherit" means no row.

### 1.3 System rules that no permission switches

`adminTargetRefusal($actor, $target, $action)`, with `$action` one of `edit`, `role`, `permissions`, `active`, `manager`, `remove`:

- Nobody changes the Director's role, permissions, active state or manager, edits the Director through the admin endpoints, or removes the Director.
- Nobody changes their own role, permissions, active state or manager, or removes themself.
- Only the Director acts on an admin who holds a Director-only key.

`permissionChangeRefusal($actor, $changes, $role)`: only the Director hands out or takes a Director-only key, and nobody but the Director changes the permission row of their own role.

Both run in the browser endpoints and in the `api/v1` write tools, since both call `api/admin-settings-writes.php`.

### 1.4 Data scope (`api/scope.php`)

A permission says what someone may do. Scope says to which records. The level comes from a key, never from a role name.

| Resource | Key that lifts the limit | Narrower rule |
|---|---|---|
| `client` | `clients.view_all` | clients assigned to them (`admin_user_assignments`) |
| `response` | `forms.responses` | answers from assigned clients, or to a form they built |
| `form` | `forms.review` | forms they built |
| `content` | `content.view_all` | posts they created or are assigned to |
| `project` | `projects.view_all` | assigned clients' projects, ones they manage, ones they belong to |

- `dataScope()` returns `all`, the narrow level, or `none` (not an admin).
- `scopeSql()` returns `[sql, params]`, or `["", []]` for everything.
- `canAccessRecord($pdo, $p, $resource, $id)` and `requireRecord()`. Out of scope answers **404**, the same as a missing record, so ids can't be probed.

Not in `scope.php`; these keep their own rules, which the tests exercise: activity reports (`activity-report-lib.php`, management tree plus chosen reviewers), team forms (`team-form-writes.php`), messages (participants only), meetings and bookings (assigned clients), inquiries and tracking (all or nothing, by key).

**There is no department scope.** A department is a label and a filter. No query grants access by department, because nothing enforces it reliably.

### 1.5 Management hierarchy (`api/team-scope.php`)

- Links are `admins.managed_by_admin_id`. Only runs down `MANAGER_CAN_MANAGE`: a Super Admin manages Admins; an Account Manager manages Admins and Super Admins. An Account Manager cannot manage another Account Manager. Links go only down the ranks, so a loop can't form.
- Only the Director sets links (`admins.manage`). A manager cannot change a report's role or their own permissions.
- `changeAdminRole()` clears the person's overrides, ends their session, and **drops any manager link the new role breaks**: their own manager if that manager can't take the new role, and the people under them that the new role can't manage. The Director re-assigns. One Tracking entry is logged.
- `setAdminActive(…, 0)` clears the session token. Assignments are kept, so reactivating restores access as it was.
- Moving an admin to another manager or department does not touch client assignments (`admin_user_assignments`). Team access (report review, client-team editing) follows the manager at once. This is open question Q2.

### 1.6 One way in (`api/auth.php`)

`requireAdmin($pdo, $perm = null)`:

| Case | Reply |
|---|---|
| No token, unknown or cleared token, deactivated admin, or an AI (MCP) token | 401 |
| A client session | 403 |
| Admin without `$perm` | 403 |

The body is `{"success":false,"status":"error","message":…}`. The role is read from the database on every request. Download links that carry `?token=` use `adminFromSessionToken()`, held to the same rules. `admin-session.php` keeps its own reply shape.

### 1.7 AI connections

`scopesAllowedFor()` recomputes each AI scope from `can()` on every request, so a connection never holds more than its person, and loses a scope as soon as the permission goes. `api/v1` scope helpers call `scope.php`. Director-only keys follow the rules above: `set_role_permissions` and `set_admin_permissions` need `permissions.manage` and the same refusals as Settings.

### 1.8 Frontend

- `adminCan(perm)` and `adminCanAny()` in `assets/permissions.js` are the only page-side checks. They read the permission list the server sent at sign-in and on every `role-nav.js` session check. They are presentation only: the server checks again.
- `assets/admin-nav.js` builds the sidebar from the effective page keys and redirects a page the person can't open. The page's endpoints refuse as well.
- No page decides by role name any more, apart from hierarchy wording and the Director card (intentional system rules).

### 1.9 Settings > Permissions

`api/permissions-admin.php`: `GET` returns the catalog, each role's column, each admin's exceptions with the `source` of every answer, what the caller may not change, and which keys are risky. `POST` takes `{batch:[{role|admin_id, changes}], dry_run}` and stores all of it or none of it. The old one-item body still works. It needs `permissions.manage` and refuses AI tokens.

What the panel does:
- Shows each answer's source: from role, own setting, or Director.
- One area per sidebar page, headed by that page's switch (`PERMISSION_AREA_PAGES`). While a page is off, the keys under it grey out and read "No effect", and Save lists them. Areas collapse.
- Director-only keys are marked.
- Every control is a switch. On "One person", flipping a switch makes an "Own setting" chip; its reset button goes back to Inherit.
- Undo, Discard, Reset to role. A review dialog before risky saves (`RISKY_PERMISSIONS` plus Director-only keys). A `beforeunload` guard for unsaved changes.
- After Save it redraws from the server's reply, so it shows what is stored.

---

## 2. Permission matrix

Generated from `PERMISSIONS` in `api/permissions.php`: **41 keys in 14 areas** (Admin 8 by default, Super Admin 15, Account Manager 21). The Director holds all. "yes" is the shipped default; a live portal may differ, because the Director can store changes. "Asks before saving" marks `RISKY_PERMISSIONS`.

| Key | Area | What it does | Admin | Super Admin | Account Manager | Note |
|---|---|---|:-:|:-:|:-:|---|
| `pages.dashboard` | dashboard | Open Dashboard | yes | yes | yes | page switch |
| `team.overview` | dashboard | See the whole team's tasks on the dashboard | - | - | - |  |
| `pages.forms` | forms | Open Forms | yes | yes | yes | page switch |
| `forms.review` | forms | See every form and approve or return them | - | - | yes | asks before saving |
| `forms.responses` | forms | See every client's answers | - | - | - | asks before saving |
| `team_forms.send` | forms | Send forms to the team | - | - | - |  |
| `team_forms.responses` | forms | See the answers to every team form | - | - | - | asks before saving |
| `pages.content` | content | Open Content | yes | yes | yes | page switch |
| `content.view_all` | content | See every post | - | - | yes | asks before saving |
| `content.create` | content | Create posts | - | yes | yes |  |
| `content.publish` | content | Schedule and publish, change or delete anyone's post | - | - | yes |  |
| `pages.projects` | projects | Open Project Management | yes | yes | yes | page switch |
| `projects.view_all` | projects | See every project | - | - | - | asks before saving |
| `projects.create` | projects | Create projects | - | yes | yes |  |
| `projects.manage_any` | projects | Edit any project | - | - | - |  |
| `projects.delete_any` | projects | Delete any project | - | - | - | asks before saving |
| `tasks.write` | projects | Post updates on any project they can see | - | yes | yes |  |
| `tasks.edit_any` | projects | Edit or delete a teammate's update | - | - | yes |  |
| `tasks.see_drafts` | projects | See teammates' draft updates | - | - | yes |  |
| `inquiries.read` | inquiries | Open Inquiries and see their leads | - | - | - | page switch |
| `inquiries.write` | inquiries | Create, edit and close inquiries | - | - | - |  |
| `clients.details` | clients | Open Clients Management | - | yes | yes | page switch |
| `clients.edit` | clients | Edit client details and internal notes | - | - | yes |  |
| `clients.approve` | clients | Approve or reject new registrations | - | - | - |  |
| `clients.delete` | clients | Delete a client | - | - | - | asks before saving |
| `clients.manage_accounts` | clients | Change a client's owner or merge accounts | - | - | - | asks before saving |
| `pages.files` | files | Open Files | yes | yes | yes | page switch |
| `calendar.use` | calendar | Open Appointments and book meetings | - | yes | yes | page switch |
| `pages.messages` | messages | Open Messages | yes | yes | yes | page switch |
| `pages.notifications` | notifications | Open Notifications | yes | yes | yes | page switch |
| `announcements.send` | notifications | Send announcements | - | - | - |  |
| `events.view` | tracking | Open Tracking (all admins' changes and AI activity) | - | - | - | page switch |
| `pages.daily_reports` | reports | Open Activity Reports | yes | yes | yes | page switch |
| `reports.create` | reports | Plan and review reports and templates for their team | - | - | - |  |
| `reports.self` | reports | Plan their own report | - | yes | yes |  |
| `reports.review` | reports | Review their team's reports | - | yes | yes |  |
| `reports.view_all` | reports | See every report in every department | - | - | - | asks before saving |
| `clients.view_all` | everywhere | See every client, not only their own | - | - | - | asks before saving |
| `settings.manage` | director | Change portal settings | - | - | - | Director-only |
| `admins.manage` | director | Add, edit and remove admins | - | - | - | Director-only |
| `permissions.manage` | director | Change these permissions | - | - | - | Director-only |

**Brief's identifiers → existing keys.** No keys were invented without a feature behind them.

| Brief | Key |
|---|---|
| clients.view | `clients.details` plus assigned scope |
| clients.view_all | `clients.view_all` |
| clients.update | `clients.edit` |
| clients.change_owner | `clients.manage_accounts` |
| content.publish | `content.publish` |
| inquiries.manage | `inquiries.write` |
| team.manage | `admins.manage` |
| reports.review | `reports.review` |
| settings.update | `settings.manage` |
| tracking.view | `events.view` |

`clients.create` has no counterpart: registration is self-service and approval is `clients.approve`.

---

## 3. Database and migration

**No schema change.** The tables are the existing `role_permissions` and `admin_permission_overrides`. The migration only cleans rows and adds one log table.

`sql/rbac-2026-11.php` is a command-line script (it answers 404 over the web). The runbook is its header comment.

| Command | Effect |
|---|---|
| `php sql/rbac-2026-11.php` | Dry run. SELECTs only; lists every row it would change. |
| `php sql/rbac-2026-11.php --apply --database=NAME` | Makes the changes in one transaction. |
| `php sql/rbac-2026-11.php --rollback=RUN --database=NAME` | Undoes one run. |
| `--runs` | Lists the runs. |
| `--steps=a,b`, `--json`, `--skip-conflicts`, `--map-role=OLD=NEW` | See the header. |

Steps: `orphans`, `dead_rows`, `role_case`, `legacy_roles` (only with `--map-role`), `inquiries_flag`, `manager_links`, `inactive_sessions`.

Safety:
- `--apply` reads every admin's effective permissions, team and assigned clients through the live resolver before and after. If anything moved it rolls back. Only the admins `--map-role` names may change, and it lists them.
- Each changed row goes to `rbac_migration_log` with its old values. A rollback restores a row only if nobody changed it since; a conflict stops it unless `--skip-conflicts`.
- `--apply` and `--rollback` refuse unless `--database` repeats `DB_NAME`.
- It never grants anything, never renames keys, never copies defaults into the tables, and never assigns `owner`.
- Cleared session tokens are not restored by a rollback.

**Reported, never changed** (they need the Director): Director-only keys held by an individual Allow (Q1); admins whose role isn't one of the four (they hold no permissions today); manager links involving them.

Verified on a database copy: dry run, apply, rollback. A dump after the rollback matched the original except for the one cleared session.

---

## 4. Manual deployment steps

1. **Back up** the production database.
2. Deploy the code. No schema step; `ensurePermissionTables()` still creates anything missing.
3. **Dry run on production:** `php sql/rbac-2026-11.php`. Do not run `--apply` yet.
4. The Director reads the report. Decide what to do with:
   - Director-only keys held by individual admins (Q1);
   - legacy roles (`--map-role=OLD=NEW` is the only way anything is granted; the Director chooses);
   - orphan rows from deleted admins.
5. `php sql/rbac-2026-11.php --apply --database=<prod DB name>`. Note the run number.
6. If it must be undone: `--rollback=<run> --database=<name>`.
7. **Expect sign-outs.** Deactivated admins and anyone whose role was mapped lose their session.
8. **Check `uploads/`.** The code no longer stores runnable names (R1, fixed), and the Docker image installs `docker/uploads.conf`, which stops PHP under `uploads/`. On any other host, add the same `<Directory>` block to the virtual host. Then confirm: put `x.php` in `uploads/` and request it. It must answer 403. Also list `uploads/` for any `.php`, `.phtml` or `.phar` file saved before the fix, and delete them.
9. Spot-check as a Super Admin and an Admin: sidebar, Settings > Permissions (Director only), one direct URL to a page switched off.

---

## 5. Breaking changes

| Change | Who notices | Why |
|---|---|---|
| Refusals are real **401 / 403 / 404** instead of `200` with `success:false`. | Any client of the browser endpoints that tested the HTTP status. The portal's own pages were updated. | Direct calls must fail visibly, and out-of-scope ids must look missing. |
| Deactivating an admin **clears their session**. Their open tabs stop on the next request. | The deactivated person. | Closes S5: they used to keep API access. |
| A role change **drops manager links the new role breaks**. | Admins under someone whose role changed. | Stops an Account Manager under an Account Manager, and stale reports. The Director re-assigns. |
| Team forms and sending announcements **need their page switch on**, in the browser and for AI. | Anyone who sent them with that page off. No stored setting changed and nobody gained access. | A page switched off now means off everywhere (S18). |
| Nobody changes **their own permissions**, and nobody but the Director gives a Director-only key or edits their own role's row. | A delegate holding `permissions.manage`. | Closes S2. |
| The bulk manager save drops people the caller can't manage. | Callers who relied on it keeping them. | Found by the tests. |
| Download endpoints, `v1/content` and `v1/projects` fixes. | Download links to out-of-scope records now 404. | Found by the tests. |
| Reject only removes a sign-up that is still waiting. For an approved client it answers 409. | Nobody in the portal: the button only shows for waiting sign-ups. | S10: Reject used to delete approved clients and skip `delete-client.php`. |
| Older calendar actions (`get_admin_calendar_data`, `create_appointment`) and a Files upload with `user_id` answer 404 for a client outside the caller's scope. | Direct callers only. No page uses the calendar actions. | S9, S12. |
| A form upload opens inline only when its bytes are a JPG, PNG, WebP or GIF. Anything else downloads as `application/octet-stream`. | Nobody: the page only previews images. | S7. |
| Profile images are checked by their bytes, saved as `.jpg`, `.png` or `.webp`, and capped at 5 MB. Source, survey and team-form files are stored without dots in the name, and their extension is kept only when it is inert (`.bin` otherwise). Downloads still use the original name. | Nobody. | S1, S13. |
| `GET api/permissions-admin.php` has a new shape (adds `sources`, `locked`, `me`, `risky`). `POST` still accepts the old body. | Anything outside the portal that reads it. | Needed for the panel. |

---

## 6. Tests

Run on 2026-11-20 against a throwaway MySQL 8.4 container, after all changes, including the upload and audit-gap fixes: **2,078 checks, 0 failed** (about 2.5 minutes). The earlier run, before those fixes, had 2,060. Baseline before the RBAC work: 1,380 checks at task 3, 1,501 at the start of task 6.

| Suite file | Covers |
|---|---|
| `authorization.php` | `requireAdmin`, 401/403/404 behaviour, scope helpers, system rules |
| `migration.php` | the migration script run for real, including rollback |
| `permissions-panel.php` | the Settings API: sources, locks, batch, dry run |
| `rbac-matrix.php` | every role against the matrix; inherit, Allow, Deny, Allow over role Deny, Deny over role Allow; Director tier; data scope; AI ceiling |
| `rbac-security.php` | HTTP sweep of the endpoints, direct ids, files, AI, escalation, bulk, hierarchy |
| `upload-safety.php` | S1 and S13: a disguised PHP profile image is refused, a real image is saved under its true extension, stored names for other uploads |
| `audit-gaps.php` | S7, S9, S10, S12 over HTTP, and a `.php` Files upload stored as `.bin` |
| the rest of `run.php` | regression for existing features |

**How to run (Windows).** Setup is in the memory note `cpsdesk-dev-gotchas`: a throwaway MySQL with a name ending `_test` (the runner drops every table in it), PHP extensions through `PHP_INI_SCAN_DIR`, and a runner copy where `/dev/null` and `__DIR__` are replaced (`D:/OrbsAI/.rbac-scratch/make-run-copy.php`). Linux: `DB_HOST=… DB_NAME=…_test DB_USER=… DB_PASS=… php tests/api-v1/run.php`. Afterwards delete what the suite leaves in `uploads/attachments/`.

Not covered by an automated test: the Settings page in a browser (checked by hand in task 5), the Node MCP server (`Wzone-ai/test` not re-run), and production data.

---

## 7. Remaining risks and decisions

**Not fixed**

- **R1. Upload code execution (audit S1, Critical): fixed, with one deploy step.** `api/update-profile.php` now takes the type from the bytes (`finfo` and `getimagesize`) and picks the extension itself. Source, survey and team-form uploads (S13) go through `storedUploadName()` in `api/upload-helpers.php`. `docker/uploads.conf` stops PHP and adds `nosniff` and a sandbox CSP under `uploads/`. It was checked in the `php:8.2-apache` image: `.php` and `.phtml` answer 403, and images still load. **Still to do on a non-Docker host:** add the same rule to the virtual host, and remove any runnable file already in `uploads/` (section 4, step 8).
- **R2.** `permissions.manage` can still be handed to one person by an individual Allow (Q1). The self and Director-key rules limit the damage but it is not Director-locked.
- **R3.** `api/remove-admin.php` still hard-deletes, so orphan rows come back. The migration cleans them but cannot stop them (Q9 of the audit).
- **R4.** Session tokens are plaintext, never expire, and download links carry `?token=` (S17).
- **R5.** `calendar.php` and `notifications.php` answer "no session" with 200 and `success:false`, and `admin-role.php` refuses with 200. Left alone on purpose.
- **R6.** The Files download link still skips the Files password.
- **R7.** AI consent still grants every allowed scope (S16, Q5). A connection follows its person's live permissions, so it never exceeds them.
- **R8.** No department scope. No rate limits on sign-up or the Files password (S20).

**Decisions waiting on the Director.** Don't build these until answered.

- **Q1.** Can Director-only keys be delegated? Recommendation: `permissions.manage` never; the other two only if wanted. Locking is a small change in `permissionDecision()`. The test that "only an individual Allow hands out a Director-only key" locks in today's behaviour: change it with Q1.
- **Q5.** Should an AI connection be capped by what it consented to?
- **Q9.** Is an Account Manager meant to rank above a Super Admin in the hierarchy?
- **Clients page and AI.** Should turning off Clients Management also stop that person's AI from reading client details? Today it doesn't. If yes: every Admin's AI loses client lookups until given the page. The change is `PERMISSION_AREAS_PAGE_OPEN` in `api/permissions.php` plus one line in `api/auth.php`.
- Q2 (assignments on manager change), Q3 (department access), Q4 (Account Manager over Account Manager), Q6 (`content.view_all` also lets you edit), Q10 (client-team columns) keep today's behaviour.

---

## 8. Files changed

Run `git status` for the full list. All uncommitted except this file.

**New:** `api/scope.php`, `sql/rbac-2026-11.php`, `docs/rbac-audit-2026-11-20.md`, `docs/plans/2026-11-20-authorization-design.md`, this file, and the five new test files named in section 6.

**Core:** `api/permissions.php`, `api/auth.php`, `api/team-scope.php`, `api/admin-settings-writes.php`, `api/permissions-admin.php`.

**Endpoints moved onto `requireAdmin()` and the scope helpers** (about 45 files in `api/`): admin and client management (`admin-*.php`, `add-user.php`, `delete-client.php`, `remove-admin.php`, `get-admins.php`, `client-team.php`, `inquiries-access.php`), content and projects (`projects.php`, `project-tasks.php`, `content-types.php`), files (`source-*.php`, `sources-lock.php`, `attachment.php`, `download-*.php`, `verify-source-password.php`), forms (`survey-writes.php`, `team-forms.php`, `team-form-writes.php`, `response-details.php`), activity reports (`activity-report*.php`, `activity-reports.php`), plus `settings.php`, `calendar.php`, `notifications.php`, `announcements.php`, `departments.php`, `update-my-profile.php`, `convert_admin_passwords.php`. AI layer: `api/v1/_bootstrap.php`, `_helpers.php`, `_writes.php`, `content.php`, `projects.php`, `responses.php`.

**Frontend:** `assets/permissions.js`, `assets/admin-nav.js`, `assets/activity-reports.js`, `settings.html`, `admin-dashboard.html`, `admin-content.html`, `clients-management.html`.

**Tests:** `tests/api-v1/{authorization,migration,permissions-panel,rbac-matrix,rbac-security,upload-safety,audit-gaps}.php` (new), `run.php`, `client-account-tools.php`.

**Audit-gap pass (same day):** `api/update-profile.php`, `api/upload-helpers.php`, `api/source-files.php`, `api/survey-submit.php`, `api/team-form-writes.php`, `api/download-survey-file.php`, `api/calendar.php`, `api/admin-users.php`, `docker/uploads.conf` (new), `Dockerfile`.

---

## 9. Where the build differs from the design doc

- The design planned three **tiers** per key (`grantable`, `director_delegable`, `director_locked`). The build kept the single `owner_only` flag, because the tier split depends on Q1.
- `checkAdminTarget()` in the design is `adminTargetRefusal()`, which returns a reason or null. `afterRoleChange()` is inside `changeAdminRole()`.
- `canAccessRecord()` takes a record id, not a row, and `scope.php` covers five resources, not the full table in the design. Activity reports, team forms and messages keep their own rules.
- Page switches per area (`PERMISSION_AREA_PAGES`) and the batch save were added for the Settings panel and aren't in the design.
- The design's section 10 (reconciliation with the audit) was never filled in. Section 10 below takes its place.

---

## 10. Audit findings, checked line by line (2026-11-20)

The task notes said S7, S9, S10 and S12 were closed. They weren't: the code still had the hole. They are fixed now, and `audit-gaps.php` tests each one.

| Finding | State | Where it is enforced |
|---|---|---|
| S1 profile upload runs code | **Fixed** this pass | `update-profile.php`, `docker/uploads.conf`; `upload-safety.php` |
| S2 delegate makes themself Director | Closed | `adminTargetRefusal(…,"permissions")` (no self), `permissionChangeRefusal()` (no Director-only keys, no own role row). Q1 still open (R2) |
| S3 Director can be deactivated | Closed | `adminTargetRefusal()` refuses every action on `owner` |
| S4 permission tools over AI | Partly open, by design | `v1PermissionChanges()` needs `permissions.manage` and the same refusals as Settings. Q5 decides more |
| S5 deactivated admins keep access | Closed | `setAdminActive()` clears the token; `requireAdmin()` / `adminFromSessionToken()` refuse inactive admins |
| S6 client-details IDOR | Closed | `admin-user-details.php` calls `requireRecord(…,"client",…)` on every id |
| S7 survey upload XSS / IDOR | **IDOR closed; XSS fixed** this pass | `scopeSql("response")`; inline only for real images, `nosniff`, sandbox CSP |
| S8 response export | Closed | `download-response-excel.php`: `pages.forms` + `canAccessRecord("response")` |
| S9 legacy calendar actions | **Fixed** this pass | `calendar.php`: `requireRecord(…,"client",…)` |
| S10 Reject deletes approved clients | **Fixed** this pass | `admin-users.php`: only `approved = 0`, else 409 |
| S11 admins.manage escalates | Bounded | `admins.manage` is Director-only. A delegate who holds it by individual Allow can still create an Account Manager (Q1) |
| S12 Files to out-of-scope client | **Fixed** this pass | `source-files.php`: `requireRecord(…,"client",…)` before anything is saved |
| S13 executable upload names | **Fixed** this pass | `storedUploadName()`; `docker/uploads.conf` |
| S14 slides / content types | Closed | slides need `settings.manage`; content-type writes need `content.create` or `content.publish` |
| S15 company-name claim at sign-up | Open, not in scope | `register.php` |
| S16 consent grants all scopes | Open (R7, Q5) | |
| S17 weak session tokens | Open (R4) | |
| S18 page switch gaps | Closed | `team-forms.php`, `response-details.php` call `requirePage("forms")` |
| S19 password conversion script | Closed | `convert_admin_passwords.php` runs from the command line only |
| S20 hygiene and rate limits | Open (R8) | |
| S21 Director's position | Closed | `admin-position.php` and `set_admin_position` call `checkAdminTarget(…,"edit")` |
| S22, S23 | Open, low; not in scope | |
