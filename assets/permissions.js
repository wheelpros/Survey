/*
|------------------------------------------------------------------------------
| What this admin may do, for showing and hiding things on a page
|------------------------------------------------------------------------------
|
| admin_user.permissions is the list of api/permissions.php keys this admin
| holds - handed out at sign-in and kept fresh by the session check in
| assets/role-nav.js, which reloads the page when the Director changes it.
| The Director holds every one.
|
| Presentation only: every endpoint checks the permission again, so a hidden
| button is a tidier page, never the rule.
|
|   <script src="assets/permissions.js?v=1"></script>
|
| In <head>, so the page's own script can ask straight away.
|
*/

window.adminCan = function (perm) {
  var user = {};
  try {
    user = JSON.parse(localStorage.getItem("admin_user") || "{}") || {};
  } catch (error) {
    // A corrupt admin_user shows nothing extra; the page's auth check handles it.
  }
  if (user.role === "owner") return true;
  return Array.isArray(user.permissions) && user.permissions.indexOf(perm) !== -1;
};
