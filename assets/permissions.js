/*
|------------------------------------------------------------------------------
| What this admin may do, for showing and hiding things on a page
|------------------------------------------------------------------------------
|
| admin_user.permissions is the list of api/permissions.php keys this admin
| holds - effectivePermissions(), handed out at sign-in and kept fresh by the
| session check in assets/role-nav.js, which reloads the page when the
| Director changes it. The Director holds every one.
|
| The one place a page asks. No page reads the role to decide what to show:
|
|   adminCan("content.publish")                   one key
|   adminCanAny(["forms.review", "forms.responses"])   any of them
|
| Presentation only: every endpoint checks the permission again, so a hidden
| button is a tidier page, never the rule. A record-level answer (may they
| edit *this* project) comes from the endpoint as can_edit and the like, not
| from here.
|
|   <script src="assets/permissions.js?v=2"></script>
|
| In <head>, so the page's own script can ask straight away.
|
*/

(function () {

  function storedAdmin() {
    try {
      return JSON.parse(localStorage.getItem("admin_user") || "{}") || {};
    } catch (error) {
      // A corrupt admin_user shows nothing extra; the page's auth check handles it.
      return {};
    }
  }

  /* The Director holds every key (permissionDecision() in api/permissions.php),
     so the list is not needed for them - an older sign-in may not carry it. */
  window.adminCan = function (perm) {
    var user = storedAdmin();
    if (user.role === "owner") return true;
    return Array.isArray(user.permissions) && user.permissions.indexOf(perm) !== -1;
  };

  window.adminCanAny = function (perms) {
    return [].concat(perms).some(function (perm) { return window.adminCan(perm); });
  };

})();
