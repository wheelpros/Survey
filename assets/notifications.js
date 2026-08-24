/*
 * Sidebar unread badge.
 *
 * Loaded by every page that renders a shell. Both audiences share one
 * endpoint, so the only thing that differs here is which token happens to be
 * in localStorage - an admin session has admin_token, a client has
 * portal_token, and no page ever has both.
 *
 * Deliberately no polling: a page load is the refresh. The pages that can
 * change the count (admin-notifications.html, notifications.html) repaint the
 * badge themselves after marking rows read.
 *
 * Every failure path is silent. A badge that never appears is the correct
 * degradation for a page whose real job is something else.
 */
(function () {
  "use strict";

  var badge = document.getElementById("notifBadge");
  if (!badge) return;

  var token = localStorage.getItem("admin_token")
           || localStorage.getItem("portal_token");
  if (!token) return;

  fetch("api/notifications.php?action=count", {
    headers: { Authorization: "Bearer " + token }
  })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data || !data.success) return;

      var count = Number(data.unread) || 0;
      if (count <= 0) return;

      // Past 9 the exact number stops mattering and starts breaking the pill's
      // width in the collapsed sidebar.
      badge.textContent = count > 9 ? "9+" : String(count);
      badge.hidden = false;
    })
    .catch(function () {
      /* Offline, logged out, or the table was never created. */
    });
})();
