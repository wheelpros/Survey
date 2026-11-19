/*
|------------------------------------------------------------------------------
| Small helpers the pages share
|------------------------------------------------------------------------------
|
| Most pages still carry their own copy of these (esc() is in about 30 of
| them). New code uses window.UI instead, and a page moves over when it's next
| worked on - not in one sweep.
|
|   UI.esc(text)               text safe to put in HTML (and in "attributes")
|   UI.fileSize(bytes)         "2.4 MB", "820 KB"
|   UI.download(url, name)     fetches with the session in the Authorization
|                              header and saves the file - the token never goes
|                              in a URL, where history, logs and the Referer
|                              would keep it. Rejects on any failure.
|
|   <script src="assets/ui-utils.js?v=1"></script>
|
*/
(function () {
  "use strict";

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  function fileSize(bytes) {
    bytes = Number(bytes) || 0;
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + " MB";
    return Math.max(1, Math.round(bytes / 1024)) + " KB";
  }

  /** The signed-in session: an admin's, else a client's. */
  function token() {
    try { return localStorage.getItem("admin_token") || localStorage.getItem("portal_token") || ""; } catch (e) { return ""; }
  }

  async function download(url, name) {
    var res = await fetch(url, { headers: { Authorization: "Bearer " + token() } });
    if (!res.ok) {
      var err = new Error("HTTP " + res.status);
      err.status = res.status;
      throw err;
    }
    var href = URL.createObjectURL(await res.blob());
    var save = document.createElement("a");
    save.href = href;
    save.download = name || "file";
    document.body.appendChild(save);
    save.click();
    save.remove();
    setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
  }

  window.UI = { esc: esc, fileSize: fileSize, download: download };
})();
