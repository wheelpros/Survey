/*
 * Sidebar unread badge for Messages, and the new message sound.
 *
 * Loaded beside notifications.js on every admin page and every client page.
 * Which token is in localStorage says which: an admin's count comes from
 * api/admin-messages.php (team chats plus, for an account manager, client
 * chats), a client's from api/client-messages.php (their account's chat).
 *
 * It asks once on load and then every 15 seconds on an admin page, every 30
 * on a client page - in a background tab too, so a new message still rings
 * on an admin page (assets/alert-sound.js) - and every failure is silent.
 * Both checks also let the server send its emails for messages left unread.
 *
 * admin-messages.html and messages.html mark their tag data-page-polls: those
 * pages keep their chats fresh, repaint this badge and ring from there.
 */
(function () {
  "use strict";
  var script = document.currentScript;
  if (script && script.hasAttribute("data-page-polls")) return;
  var badge = document.getElementById("msgBadge");
  if (!badge) return;

  var adminToken = localStorage.getItem("admin_token");
  var token = adminToken || localStorage.getItem("portal_token");
  if (!token) return;
  var url = adminToken ? "api/admin-messages.php?action=count" : "api/client-messages.php?action=count";

  function check() {
    fetch(url, { headers: { Authorization: "Bearer " + token } })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.success) return;
        var count = Number(data.unread) || 0;
        badge.textContent = count > 9 ? "9+" : String(count);
        badge.hidden = count <= 0;
        if (!adminToken || !window.WzAlertSound) return;
        window.WzAlertSound.notice("message", data.latest_id);
        // Client chats keep their own ids, so their own mark.
        window.WzAlertSound.notice("client_message", data.client_latest_id);
      })
      .catch(function () {});
  }

  check();
  setInterval(check, adminToken ? 15000 : 30000);
  // Back on the tab: catch up at once rather than waiting for the next tick.
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) check();
  });
})();
