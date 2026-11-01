/*
 * Sidebar unread badge.
 *
 * Loaded by every page that renders a shell. Both audiences share one
 * endpoint, so the only thing that differs here is which token happens to be
 * in localStorage - an admin session has admin_token, a client has
 * portal_token, and no page ever has both.
 *
 * A client page asks once: a page load is the refresh. An admin page asks
 * again every 15 seconds, in a background tab too, and plays the new
 * notification sound (assets/alert-sound.js) when something new comes in.
 * The pages that can change the count (admin-notifications.html,
 * notifications.html) repaint the badge themselves after marking rows read.
 *
 * Every failure path is silent. A badge that never appears is the correct
 * degradation for a page whose real job is something else.
 */
(function () {
  "use strict";

  var badge = document.getElementById("notifBadge");
  if (!badge) return;

  var adminToken = localStorage.getItem("admin_token");
  var token = adminToken || localStorage.getItem("portal_token");
  if (!token) return;

  function check() {
    fetch("api/notifications.php?action=count", {
      headers: { Authorization: "Bearer " + token }
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.success) return;

        var count = Number(data.unread) || 0;

        // Past 9 the exact number stops mattering and starts breaking the pill's
        // width in the collapsed sidebar.
        badge.textContent = count > 9 ? "9+" : String(count);
        badge.hidden = count <= 0;

        if (adminToken && window.WzAlertSound) window.WzAlertSound.notice("notification", data.latest_id);
      })
      .catch(function () {
        /* Offline, logged out, or the table was never created. */
      });
  }

  check();
  if (!adminToken) return;

  setInterval(check, 15000);
  // Back on the tab: catch up at once rather than waiting for the next tick.
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) check();
  });
})();
