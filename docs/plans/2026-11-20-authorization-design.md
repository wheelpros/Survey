# Authorization design: roles, permissions, data scope, hierarchy

Status: proposal, 2026-11-20. Task #2 of the RBAC audit.

The audit report (task #1) had not landed when this was written. This design was checked directly against the code. The files it relies on are cited inline. Section 10 is where the audit's findings get matched against this design once the report is in `docs/`.

---

## 1. What exists today

The portal already has one permission resolver. The work is to finish moving onto it, not to replace it.

| Concern | Where | State |
|---|---|---|
| Permission catalog and defaults | `api/permissions.php` `PERMISSIONS` | 41 keys in 9 areas. Each key lists the roles that hold it by default. |
| Resolution | `api/permissions.php` `can()` | Order: Director → override → role row → default. `owner_only` keys count only an individual override. |
| Storage | `role_permissions`, `admin_permission_overrides` (`sql/permissions.sql`) | Sparse. A row exists only where the Director changed something. Deleting the row means "Inherit". |
| Principal (who is calling) | `api/auth.php` `currentPrincipal()` | Handles a session token or an MCP token, and checks `active`. Only **9** browser endpoints use it. |
| Endpoint gates | `can()` / `requirePermission()` / `requirePage()` | Used by 54 files. **36** endpoints still do their own `SELECT … WHERE session_token = ?`. |
| Record scope | Scattered: `assignedClientIds()` (auth.php), `projectScopeSql()` and `contentVisibleSql()` (db.php), `isUserInScope()` (survey-writes.php), `drCanSee()` (activity-report-lib.php), `meetingInAdminScope()`, `bookingRequestInScope()`, `v1*ScopeSql()` (v1/_helpers.php) | The same rules are written in several places, some of them twice. |
| Management hierarchy | `admins.managed_by_admin_id`; `api/team-scope.php` `teamPoolFor()`; `checkManagerAssignment()` (admin-settings-writes.php) | Fixed by role: AM → SA → Admin. |
| AI (MCP) | `scopesAllowedFor()` (auth.php) maps scopes to keys via `SCOPE_PERMISSIONS` and `SCOPE_PAGES` | Worked out again on every request, and capped by `can()`. |
| Frontend | `assets/permissions.js` `adminCan()` reads `admin_user.permissions`. `assets/role-nav.js` keeps it fresh. `assets/admin-nav.js` hides sidebar links | Presentation only. The server checks every request again. |

Roles (keys are stored in `admins.role`; keep them, because renaming them would need a data migration and gains nothing):

| Key | Shown as |
|---|---|
| `owner` | Director |
| `super_admin` | Super Admin |
| `account_manager` | Account Manager |
| `seo_admin` | Admin |

## 2. Problems this design fixes

Ranked by severity. File references are where each one was seen.

1. **A delegated `permissions.manage` gives full Director power.** `can()` lets an individual override grant any `owner_only` key. `checkAdminPermissionChange()` (admin-settings-writes.php:1075) blocks the Director as a target, but **not the caller themself**. Anyone handed `permissions.manage` can grant themselves `admins.manage`, `settings.manage` and every `*.view_all`. This conflicts with the brief's "an override must never bypass Director-only restrictions".
2. **Deactivated admins keep API access.** `setAdminActive()` sets `active = 0` but leaves `session_token` in place. About 25 of the 36 endpoints that look up their own token never check `active`, for example `admin-users.php:22`, `admin-role.php`, `calendar.php` and `admin-update.php`. Only `currentPrincipal()` checks it.
3. **There is no single way into the endpoints.** The 36 hand-rolled token lookups each decide 401 or 403, `success` or `status`, and whether to check `active`. Many refuse with HTTP 200 and `success:false` (`admin-role.php`, `admin-manager-assignments.php`).
4. **Role changes leave stale management links.** `changeAdminRole()` clears overrides and the session, but never `managed_by_admin_id`:
   - A Super Admin promoted to Account Manager stays "managed by" an AM, giving an AM under an AM.
   - A Super Admin demoted to Admin keeps Admins pointing at them. `teamPoolFor()` ignores those links now, and brings them back silently if the person is promoted again.
5. **When the permission tables can't be read, the resolver falls back to defaults.** `permissionRows()` swallows `PDOException` and uses the catalog defaults. A Director's role-level **Deny** of a default-on key is then ignored.
6. **Seeing all content also means editing all content.** `canEditContent()` (content-writes.php:62) returns true for `content.view_all`, so a read permission grants a write.
7. **Role-name checks that should be keys, or should be named system rules:**
   - `activity-report-lib.php:86,202,256,643`
   - `team-form-writes.php:356,616`
   - `client-team.php:99` `editableColumnsFor()`
   - `update-my-profile.php:36`
   - `v1/activity-reports.php:125`

   Some are deliberate rules about the Director, but nothing marks which.
8. **Page gates use five naming styles:** `pages.*`, `inquiries.read`, `clients.details`, `calendar.use` and `events.view`. The keys are stored in the database and in MCP mappings, so renaming them has a cost (section 7).
9. **Changes made by an MCP connection aren't bounded by what was consented to.** The grant's stored scopes are only a record (`principalFromMcpToken()`). Live scopes follow the person's current permissions, which satisfies "never exceeds the user", but a connection picks up new kinds of access nobody approved (see Q5).

## 3. Target architecture

There are three modules, each with a small interface. Every endpoint, `api/v1` handler, MCP scope and page reads from these three. Nothing else decides access.

```
request ─► auth.php      requireAdmin($pdo, $perm?)        who is calling; 401 / 403
              │
              ▼
        permissions.php  can($who, $perm)                   may they do this kind of thing?
                         permissionDecision($who, $perm)    …and why (for Settings and tests)
              │
              ▼
        scope.php        scopeSql($pdo, $p, $resource, $alias)    which records, as SQL
                         canAccessRecord($pdo, $p, $resource, $row, $action)
              │
              ▼
        team-scope.php   teamPoolFor(), checkManagerLink(), afterRoleChange()  the hierarchy
```

### 3.1 `auth.php`: one way in

Add `requireAdmin(PDO $pdo, ?string $perm = null): array`:
- No token, an unknown token, or an inactive admin returns **401**.
- A client token on an admin endpoint returns **403**.
- When `$perm` is given and `can()` is false, it returns **403**.
- The body is always `{"success":false,"status":"error","message":…}`, the two-key shape `requirePermission()` already uses, so old pages keep working.

The 36 hand-rolled lookups move onto it one file at a time. That move is also what fixes problem 2. Deactivation additionally sets `session_token = NULL`, as a role change already does. The `role-nav.js` session check then signs the browser out, and MCP is already covered by `loadPrincipal()`.

### 3.2 `permissions.php`: the resolver

The catalog's `owner_only` boolean is replaced by a **tier** on each key:

| Tier | Meaning | Keys |
|---|---|---|
| `grantable` | Role rows and individual overrides both apply. | All but the four below |
| `director_delegable` | Never on a role row. Only an individual Allow, and only if Q1 says so. | `settings.manage`, `admins.manage` |
| `director_locked` | The Director and nobody else, whatever is stored. | `permissions.manage` |

Resolution, in this order, for `permissionDecision($who, $perm)`. It returns `{allowed, source}`, and `can()` is its boolean.

1. Unknown key: throws `LogicException`. This is a bug, not a denial, and matches today's behaviour.
2. Not an active admin principal (client, unknown role, inactive): **deny**, `source: system`.
3. `role === owner`: **allow**, `source: director`.
4. `director_locked`: **deny**, `source: system`.
5. Individual override row: its value, `source: override`. For `director_delegable` keys this is the only step that can allow (Q1).
6. Role row: its value, `source: role`.
7. Catalog default for the role: `source: default`. The UI shows `default` and `role` the same way, as "from role".
8. Otherwise **deny**.

On a database error (anything other than "the table doesn't exist yet"), it **denies** instead of falling back to defaults (problem 5; see Q7).

**System restrictions on who an admin action may target.** These are not permissions, so neither a key nor an override can switch them. They live in one function, `checkAdminTarget($actor, $target, $action)`, beside `permissionDecision()`:
- Nobody changes the Director's role, permissions, active state or manager, or removes the Director.
- Nobody changes their own role, permissions or active state. Today this is enforced for role and active state, but not for permissions.
- If director-tier keys are delegable (Q1), a delegate cannot act on another admin who holds a director-tier key, and cannot grant director-tier keys.
- Role values are limited to `ASSIGNABLE_ADMIN_ROLES`. `owner` is never assignable.

### 3.3 `scope.php`: data scope (new; collects the existing helpers)

Being allowed to act is separate from being allowed to see a particular record. The **level** comes from a `*.view_all` key or from the hierarchy, never from the role name.

| Resource | Levels the code can enforce today | What decides it | Rule now lives in |
|---|---|---|---|
| client | assigned / all | `clients.view_all` | `assignedClientIds()`, `v1ClientScopeSql()`, `isUserInScope()`, `scopedClients()` ×2 |
| project | assigned + managed + member / all | `projects.view_all` | `projectScopeSql()` |
| content | own (created or assigned) / all | `content.view_all` | `contentVisibleSql()`, `contentSeesAll()` |
| form (client form) | own (created) / all | `forms.review` | `canSeeSurvey()`, `v1SeesAllForms()` |
| form response | assigned clients / all | `forms.responses` | `admin-responses.php`, `response-details.php` |
| activity report | own + chosen reviewer + team / all | `reports.view_all`, `reports.review` + `teamPoolFor()` | `drCanSee()` and related functions |
| team form | sent or received / all answers | `team_forms.responses` | `team-form-writes.php` |
| meeting / booking | assigned clients / all | `clients.view_all` | `meetingInAdminScope()`, `bookingRequestInScope()` |
| inquiry / lead | all-or-nothing | `inquiries.read` | the endpoint |
| message | participant only | none (system rule) | `admin-messages.php` |
| tracking event | all-or-nothing | `events.view` | the endpoint |

The interface:
- `scopeSql($pdo, $p, $resource, $alias)` returns `[sql, params]`, or `["", []]` when all records are visible.
- `canAccessRecord($pdo, $p, $resource, $row, $action)` returns a bool. `$action` is `view`, `edit` or `delete`. For edit and delete it applies the resource's write rule as well as the read scope, for example `canManageProject()` and `canDeleteProject()`.
- Endpoints answer **404** when the record is out of scope, the same as for a missing record, so ids can't be probed. `v1VisibleClient()` already does this.

The existing helpers become private parts of `scope.php`, and callers move over one at a time. Deletion test: remove `scope.php` and the same rules reappear in about 12 places, which is today's state.

**No department scope.** Departments (`admins.department_id`) are labels and list filters today. No query grants access by department. The brief asks for no scope that can't be enforced reliably, so "managed team" means the `managed_by_admin_id` tree and nothing else (Q3).

### 3.4 `team-scope.php`: the hierarchy

| Rule | Today | Proposed |
|---|---|---|
| Who sets manager links | `admins.manage` (the Director) | Unchanged |
| Allowed links | SA → Admin; AM → SA; AM → Admin | Unchanged (Q4, Q9) |
| AM manages another AM | Not possible | Not possible (Q4) |
| Cycles | Impossible: links only go down the role ranks and are at most two levels deep | Kept true by `afterRoleChange()` |
| Manager changes a report's role | Not possible (`admins.manage` needed) | Unchanged: a system rule |
| Manager changes their own permissions | Only if granted `permissions.manage` (problem 1) | Never (`checkAdminTarget`) |
| After a role change | Overrides cleared, session ended; links **kept** | Also: drop the person's own `managed_by` link if it is now invalid, and release their reports if they can no longer manage those roles. The Director re-assigns. Logged as an event. |
| After a manager change | Team access (report review, client-team editing) moves at once; client assignments (`admin_user_assignments`) stay | Unchanged (Q2) |
| After a department change | Nothing about access changes | Unchanged |
| After deactivation | Login blocked; session token kept (problem 2) | Session token cleared. Assignments kept, so reactivating restores everything, as today. |

The client-team column rule (`editableColumnsFor()`, client-team.php:99) is part of the hierarchy, so it moves here as a named system rule rather than a permission (Q10).

## 4. Permission matrix

Shipped defaults from `PERMISSIONS`. A live portal may have role rows and overrides that differ, which is why the tests exercise resolution against fixtures, not production values. **D** is the Director (always yes).

| Key | Area | Admin | Super Admin | Account Manager | D | Tier |
|---|---|:-:|:-:|:-:|:-:|---|
| pages.dashboard | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| pages.forms | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| pages.content | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| pages.projects | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| inquiries.read | pages | – | – | – | ✓ | grantable |
| clients.details | pages | – | ✓ | ✓ | ✓ | grantable |
| pages.files | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| calendar.use | pages | – | ✓ | ✓ | ✓ | grantable |
| pages.messages | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| pages.notifications | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| events.view | pages | – | – | – | ✓ | grantable |
| pages.daily_reports | pages | ✓ | ✓ | ✓ | ✓ | grantable |
| clients.view_all | clients | – | – | – | ✓ | grantable (scope) |
| clients.edit | clients | – | – | ✓ | ✓ | grantable |
| clients.approve | clients | – | – | – | ✓ | grantable |
| clients.delete | clients | – | – | – | ✓ | grantable, **dangerous** |
| clients.manage_accounts | clients | – | – | – | ✓ | grantable, **dangerous** |
| forms.review | forms | – | – | ✓ | ✓ | grantable (scope) |
| forms.responses | forms | – | – | – | ✓ | grantable (scope) |
| content.view_all | content | – | – | ✓ | ✓ | grantable (scope) |
| content.create | content | – | ✓ | ✓ | ✓ | grantable |
| content.publish | content | – | – | ✓ | ✓ | grantable |
| projects.view_all | projects | – | – | – | ✓ | grantable (scope) |
| projects.create | projects | – | ✓ | ✓ | ✓ | grantable |
| projects.manage_any | projects | – | – | – | ✓ | grantable |
| projects.delete_any | projects | – | – | – | ✓ | grantable, **dangerous** |
| tasks.write | projects | – | ✓ | ✓ | ✓ | grantable |
| tasks.edit_any | projects | – | – | ✓ | ✓ | grantable |
| tasks.see_drafts | projects | – | – | ✓ | ✓ | grantable |
| inquiries.write | inquiries | – | – | – | ✓ | grantable |
| team.overview | team | – | – | – | ✓ | grantable |
| announcements.send | team | – | – | – | ✓ | grantable |
| team_forms.send | team | – | – | – | ✓ | grantable |
| team_forms.responses | team | – | – | – | ✓ | grantable (scope) |
| reports.create | reports | – | – | – | ✓ | grantable |
| reports.self | reports | – | ✓ | ✓ | ✓ | grantable |
| reports.review | reports | – | ✓ | ✓ | ✓ | grantable |
| reports.view_all | reports | – | – | – | ✓ | grantable (scope) |
| settings.manage | director | – | – | – | ✓ | director_delegable (Q1) |
| admins.manage | director | – | – | – | ✓ | director_delegable (Q1) |
| permissions.manage | director | – | – | – | ✓ | **director_locked** |

The **dangerous** set triggers the save-time warning in Settings. It is every director-tier key, every "(scope)" key, and the three marked rows.

**Identifier mapping to the brief's examples.** The existing keys stay; no new keys are invented without a feature behind them.

| Brief | Existing key |
|---|---|
| clients.view | `clients.details` (page) + assigned scope |
| clients.view_all | `clients.view_all` |
| clients.update | `clients.edit` |
| clients.change_owner | `clients.manage_accounts` |
| projects.update / delete | `projects.manage_any` / `projects.delete_any`; own records by the creator and manager rule |
| content.publish | `content.publish` |
| inquiries.manage | `inquiries.write` |
| team.manage | `admins.manage` (director tier) |
| reports.review | `reports.review` |
| settings.update | `settings.manage` |
| tracking.view | `events.view` |

`clients.create` has no counterpart: registration is self-service and approval is `clients.approve`.

## 5. AI / MCP

- The ceiling is unchanged and correct: every request recomputes `scopesAllowedFor()` from `can()`, so a connection never exceeds its person. Each new key must be added to `SCOPE_PERMISSIONS` when the tool that needs it is added. The design adds a test that every `STAFF_SCOPES` entry has a mapping.
- Director-tier keys follow the tier rules, so a delegate's connection gets `settings:*` and `admins:write` only if Q1 allows delegation, and never anything needing `permissions.manage`. `v1/permissions.php` already requires `permissions.manage` (line 24), so `director_locked` limits it to the Director with no further change.
- Record scope inside the tools uses `scope.php`, the same as the browser. The `v1*ScopeSql` wrappers become thin calls into it.
- Q5: whether a grant should also be capped by what was consented to.

## 6. Frontend and the Settings → Permissions panel

- `adminCan()` stays the only check on the page side, and stays presentation only. It's fed from `effectivePermissions()` at sign-in and on each `role-nav.js` session check. No page re-implements resolution.
- Opening a page by its URL: `admin-nav.js` already redirects pages that are switched off. The page's endpoints refuse too, through `requirePage()` and then `requireAdmin()`, which is the real control.
- `GET api/permissions-admin.php` adds `source` per cell from `permissionDecision()`. The panel then shows, per row:
  - the effective value
  - whether it comes **from the role** or from **this person's override**
  - a "Director only" badge for director-tier keys, plus a lock for `director_locked`
- Save checks, on the server and mirrored in the UI:
  - unknown keys are rejected (already done by `checkPermissionChanges()`)
  - director-tier keys on a role row are rejected (already done)
  - `director_locked` is rejected everywhere
  - changing your own row is rejected
- A dangerous change (section 4) asks for confirmation, naming the key and how many people it affects.
- Unsaved changes are guarded on tab switch and on `beforeunload`.
- "Reset to default" sends `"default"`, which deletes the row (already the semantics). "Undo" drops the unsaved changes on the page.
- After saving, the panel redraws from the server's reply (already the behaviour), so what it shows is what was stored.

## 7. Database

The model needs **no schema change**. The proposed migrations are safe and report first:

1. **Orphan report (read-only):** override rows for deleted admins or for keys that aren't in the catalog; role rows for unknown roles. The script lists them and deletes nothing until the Director agrees.
2. **Director-tier override rows:** any `admin_permission_overrides` row for `permissions.manage` stops working under `director_locked`. The migration **lists** these rows and the admins affected before deploy, so nobody loses access silently. It never adds a grant.
3. **Stale manager links:** a one-time report of links that `afterRoleChange()` would now drop (AM under someone, or someone under a non-manager). The fix is a separate script the Director runs after reviewing the report, and it logs an event for each link it changes.
4. **Deactivated admins with a session token:** set `session_token = NULL` where `active = 0`. This only removes access that should already be gone.
5. Keep the legacy `admins.inquiries_access` mirror (`storeAdminPermission()`), since older builds read it.

There are no key renames (problem 8). The panel groups page gates under "Pages" by `area`, which it already does. A rename would touch both tables, `SCOPE_PERMISSIONS` and the pages, for a cosmetic gain.

**Built (task #4): `sql/rbac-2026-11.php`.** It is a command-line script; over the web it answers 404. Its header comment is the runbook.

- **Modes.** With no options it is a dry run: SELECTs only, listing every row it would change and why. `--apply --database=NAME` makes the changes in one transaction. `--rollback=RUN --database=NAME` undoes one run. `--runs` lists the runs.
- **Steps:** `orphans`, `dead_rows`, `role_case`, `legacy_roles`, `inquiries_flag`, `manager_links`, `inactive_sessions`. They cover items 1, 3 and 4 above. Orphans also cover client assignments, project and post memberships, and the AI connections of deleted admins.
- **Safety checks.**
  - Inside the transaction, `--apply` reads every admin's effective permissions, team and assigned clients through the live resolver, before and after. If anything moved, it rolls back.
  - Each changed row goes into `rbac_migration_log` with its old values. A rollback restores a row only if nobody has changed it since. Any conflict stops the rollback, unless `--skip-conflicts` is given.
- **Reported, not changed:**
  - Director-only keys held by override (item 2, Q1).
  - Admins whose role isn't one of the four.
  - Manager links that involve such an admin.
- **Legacy roles.** `seo_admin` stays the stored key for Admin. Any other value is a legacy role. It is mapped only when the Director passes `--map-role=OLD=NEW`. That clears the person's overrides and session, as a role change does, and it never gives the role `owner`.
- **Deliberately not done:**
  - Defaults are not copied into the tables. A stored copy would stop a later change to a default from reaching anyone.
  - Rows that repeat a default are kept.
  - `daily_report_reviewers` is left alone, because the app copies a report's original reviewer back in.
  - Cleared session tokens are not restored by a rollback.
  - The per-request `inquiries_access` import (item 5) stays.
- **Tests.** `tests/api-v1/migration.php` runs the real script against the suite database. It was also run on a copy of that database: the dry run, the apply and the rollback. A full dump after the rollback matched the original, apart from the one cleared session.

## 8. Implementation order (phases 3 to 5)

1. Resolver: add `permissionDecision()`, the tiers, deny on database error, and `checkAdminTarget()` (including the self-permissions block). Unit tests come first.
2. `requireAdmin()`. Move the 36 hand-rolled endpoints onto it, in batches by area. Clear the session token on deactivation.
3. `scope.php`: move the helpers in, then the callers. Split the content view and edit rule (Q6).
4. `afterRoleChange()` and the migration reports.
5. Settings panel: source column, badges, confirmation, unsaved-changes guard.
6. Tests: extend `tests/api-v1` (a real MySQL run) with integration cases for every row of section 9. Add resolver unit tests.

## 9. What the tests must prove

- **Matrix:** for each role, for each key, `can()` equals the default with no rows, and equals the stored row when one exists.
- **Overrides:**
  - inherit
  - allow over a role deny
  - deny over a role allow
  - "default" deletes the row
  - a role change clears overrides
- **Director tier:**
  - `permissions.manage` is denied even with an override row
  - director-delegable keys are never granted by a role row
  - no admin changes their own permissions or role
- **Scope:** for every resource in 3.3, out-of-scope ids give 404 on direct GET, PUT and DELETE, and list endpoints and `api/v1` lists leave them out.
- **Endpoints:** no token and an inactive admin's token give 401; a missing key gives 403; a client token on an admin endpoint gives 403.
- **Bulk:** assignment and permission saves reject or drop ids outside the actor's pool.
- **Files:** `attachment.php` and the `download-*` endpoints refuse records out of scope.
- **MCP:**
  - an MCP principal never holds a scope whose key `can()` denies
  - revoking a key removes the scope on the next request
  - every `STAFF_SCOPES` entry has a mapping
- **Hierarchy:** after each role transition the links stay valid and there are no cycles; a deactivated admin's token stops working.

## 10. Reconciliation with the audit

Fill this in when task #1's report lands. Each audit finding should map onto one of: a problem in section 2, a module in section 3, or a new item. A finding with no home is a gap in this design.

| Audit finding | Covered by | Note |
|---|---|---|
| | | |

## 11. Open questions (business rules only; not invented here)

- **Q1. Can the Director delegate director-tier power to one person?** Today `settings.manage`, `admins.manage` and `permissions.manage` can all be given to one admin. The brief says overrides must never bypass Director-only restrictions.
  - *Recommendation:* make `permissions.manage` Director-only always, since delegating it amounts to delegating the Director (problem 1). For `settings.manage` and `admins.manage`, choose **(a)** never delegable, or **(b)** delegable to one named person, guarded by `checkAdminTarget()`.
- **Q2.** When an admin moves to another manager, should their client assignments (`admin_user_assignments`) be kept (today) or cleared?
- **Q3.** Should a department ever grant access to records? For example, an AM reviewing every report in their department rather than only their managed tree. Today it doesn't.
- **Q4.** Can an Account Manager manage another Account Manager? Today they can't. AMs answer only to the Director.
- **Q5. MCP consent.** Should a connection be capped by the scopes the person approved, so that new tools need re-consent? Or should it keep following their permissions live, as today?
- **Q6.** Should `content.view_all` keep implying "edit any post" (today), or should editing need `content.publish` or a new `content.edit_any`?
- **Q7.** If the permission tables can't be read, should the portal deny (proposed) or fall back to the shipped defaults (today)?
- **Q8.** Keep the inconsistent page-key names (proposed), or rename them with a migration?
- **Q9. Rank of Super Admin vs Account Manager.** The brief reads Super Admin as "broad administrative access". In the hierarchy, an AM manages Super Admins (`team-scope.php`). Is AM above SA intended?
- **Q10.** Who may edit which column of a client's team (`editableColumnsFor()`)? Should it stay a fixed hierarchy rule (proposed), or become permission keys?
