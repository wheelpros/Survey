/*
|------------------------------------------------------------------------------
| The admin session check, on every admin page
|------------------------------------------------------------------------------
|
| Which sidebar links an admin sees is decided in assets/admin-nav.js; this
| file only keeps the stored role and permissions in step with the database.
|
|   <script src="assets/role-nav.js?v=7"></script>
|
| At the end of <body>, beside notifications.js.
|
*/

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

    // The Files unlock belongs to the old session too.
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
          return;
        }

        /* The Director changed what this admin may do (Settings >
           Permissions). No sign-out: store the new list and reload, so the
           sidebar and every button are drawn from it. At most once a
           minute, so storage that will not save can never loop it. */
        var perms = data.admin.permissions || [];
        if (JSON.stringify(stored.permissions || null) !== JSON.stringify(perms)) {
          stored.permissions = perms;
          localStorage.setItem("admin_user", JSON.stringify(stored));
          var last = Number(sessionStorage.getItem("wz_perm_reload") || 0);
          if (Date.now() - last > 60000) {
            sessionStorage.setItem("wz_perm_reload", String(Date.now()));
            location.reload();
            return;
          }
        }

        /* Only labels changed: the owner set a position, a name or a photo
           (Settings, the Director card). No sign-out - store them and repaint
           the sidebar card and the popup. */
        var avatar = data.admin.avatar_path || null;
        if ((stored.position || null) !== (data.admin.position || null)
            || (stored.avatar_path || null) !== avatar
            || stored.name !== data.admin.name) {
          stored.position = data.admin.position || null;
          stored.avatar_path = avatar;
          stored.name = data.admin.name;
          localStorage.setItem("admin_user", JSON.stringify(stored));

          if (window.wzPaintAccount) window.wzPaintAccount();
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
