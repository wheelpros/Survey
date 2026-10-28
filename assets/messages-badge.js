/*
 * Sidebar unread badge for Messages.
 *
 * Loaded beside notifications.js on every admin page. The same rules: one
 * fetch per page load and no polling - admin-messages.html repaints the badge
 * itself while it is open - and every failure is silent.
 *
 * Admins only: a client page has no #msgBadge, and api/admin-messages.php
 * refuses anyone but an admin anyway.
 */
(function () {
  "use strict";
  var badge = document.getElementById("msgBadge");
  if (!badge) return;
  var token = localStorage.getItem("admin_token");
  if (!token) return;
  fetch("api/admin-messages.php?action=count", {
    headers: { Authorization: "Bearer " + token }
  })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data || !data.success) return;
      var count = Number(data.unread) || 0;
      if (count <= 0) return;
      badge.textContent = count > 9 ? "9+" : String(count);
      badge.hidden = false;
    })
    .catch(function () {});
})();
