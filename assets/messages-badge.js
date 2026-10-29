/*
 * Sidebar unread badge for Messages, and the new message sound.
 *
 * Loaded beside notifications.js on every admin page. It asks for the count
 * once on load and then every 15 seconds - in a background tab too, so a new
 * message still rings (assets/alert-sound.js) - and every failure is silent.
 *
 * admin-messages.html marks its tag data-page-polls: that page keeps its own
 * chat list fresh, repaints this badge and rings from there.
 *
 * Admins only: a client page has no #msgBadge, and api/admin-messages.php
 * refuses anyone but an admin anyway.
 */
(function () {
  "use strict";
  var script = document.currentScript;
  if (script && script.hasAttribute("data-page-polls")) return;
  var badge = document.getElementById("msgBadge");
  if (!badge) return;
  var token = localStorage.getItem("admin_token");
  if (!token) return;

  function check() {
    fetch("api/admin-messages.php?action=count", {
      headers: { Authorization: "Bearer " + token }
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.success) return;
        var count = Number(data.unread) || 0;
        badge.textContent = count > 9 ? "9+" : String(count);
        badge.hidden = count <= 0;
        if (window.WzAlertSound) window.WzAlertSound.notice("message", data.latest_id);
      })
      .catch(function () {});
  }

  check();
  setInterval(check, 15000);
  // Back on the tab: catch up at once rather than waiting for the next tick.
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) check();
  });
})();
