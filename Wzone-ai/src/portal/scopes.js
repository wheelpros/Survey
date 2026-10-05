// The scopes the portal can grant - mirrors STAFF_SCOPES and CLIENT_SCOPES
// in api/oauth/lib.php, which is the source of truth for what a role may
// actually be given. This list is only what the authorization server
// advertises in its metadata (scopes_supported).

export const STAFF_SCOPES = [
  "clients:read", "clients:write",
  "forms:read", "forms:write", "forms:review",
  "inquiries:read", "inquiries:write",
  "content:read", "content:write",
  "calendar:read", "calendar:write",
  "projects:read", "projects:write",
  "notifications:read", "notifications:write",
];

export const CLIENT_SCOPES = ["self:read", "self:write"];

export const ALL_SCOPES = [...STAFF_SCOPES, ...CLIENT_SCOPES];
