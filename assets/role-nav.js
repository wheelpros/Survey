/*
|------------------------------------------------------------------------------
| Sidebar links that not every admin role may see
|------------------------------------------------------------------------------
|
| The sidebar markup is copied across every admin page, so trimming it per role
| in each file means the same edit eight times and a link left behind whenever a
| page is added. This does it in one place: include the script and any link in
| the table below disappears for roles that cannot use it.
|
| Matching is on the href, so no page needs an id or a class added to its markup.
|
| This is presentation only. Every page and endpoint listed here checks the role
| itself - a hidden link is a tidier sidebar, never a permission.
|
|   <script src="assets/role-nav.js?v=1"></script>
|
| Add it beside the existing notifications.js tag, at the end of <body>.
|
*/

(function () {

  var RESTRICTED = [
    {
      // Client meetings: the roles that deal with clients, not seo_admin.
      href: "admin-calendar.html",
      roles: ["owner", "super_admin", "account_manager"]
    }
  ];

  function currentRole() {
    try {
      return (JSON.parse(localStorage.getItem("admin_user") || "{}") || {}).role || "";
    } catch (error) {
      // A corrupt admin_user should not take the whole sidebar down with it.
      return "";
    }
  }

  function apply() {
    var role = currentRole();

    // Not logged in as an admin: the page's own auth check is already
    // redirecting, so leave the markup alone.
    if (!role) return;

    RESTRICTED.forEach(function (rule) {

      if (rule.roles.indexOf(role) !== -1) return;

      // Both the bare name and any path or query form of it.
      document
        .querySelectorAll('a[href*="' + rule.href + '"]')
        .forEach(function (link) {
          link.remove();
        });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", apply);
  } else {
    apply();
  }

})();

/*
|------------------------------------------------------------------------------
| Signed out when the owner changes your role
|------------------------------------------------------------------------------
|
| Every page reads the role from the copy of the admin record saved in the
| browser at sign-in, so a tab that was already open would keep the old
| permissions until it happened to be reloaded. Changing a role clears that
| admin's session_token, and this notices.
|
| Checked on load, whenever the tab is brought back to the front, and every few
| minutes for a tab left sitting open. Cheap: one indexed lookup.
|
*/

(function () {

  var CHECK_MINUTES = 3;
  var LOGIN = "admin-login.html";

  // The login page itself has no session to watch.
  if (location.pathname.indexOf(LOGIN) !== -1) return;

  function signOut() {
    localStorage.removeItem("admin_token");
    localStorage.removeItem("admin_user");

    // The Sources & Files unlock belongs to the old session too.
    sessionStorage.removeItem("sources_token");

    alert("Please sign in again.");
    location.replace(LOGIN);
  }

  async function check() {
    var token = localStorage.getItem("admin_token");

    if (!token) return;

    try {
      var res = await fetch("api/admin-session.php", {
        headers: { Authorization: "Bearer " + token }
      });

      var data = await res.json();

      // A network or server problem is not a signal to throw someone out -
      // only a clear "this session is no longer valid" is.
      if (!data.success) return;

      if (!data.valid) {
        signOut();
        return;
      }

      /* The session survived but the role moved on. Keep the stored copy in
         step so the sidebar and the page checks match the database without
         waiting for a sign-out. */
      if (data.admin && data.admin.role) {
        var stored = {};

        try {
          stored = JSON.parse(localStorage.getItem("admin_user") || "{}");
        } catch (error) {
          stored = {};
        }

        if (stored.role && stored.role !== data.admin.role) {
          signOut();
        }
      }

    } catch (error) {
      // Offline, or the endpoint is missing. Leave the session alone.
    }
  }

  check();

  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) check();
  });

  setInterval(check, CHECK_MINUTES * 60 * 1000);

})();
