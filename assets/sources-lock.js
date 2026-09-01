/*
 * Files password, asked before you leave the page you are on.
 *
 * Clicking Files in the sidebar used to load the Files page and ask there, so
 * the page you wanted appeared and then immediately covered itself up. The
 * question belongs on the page you are still standing on: answer it and you
 * arrive unlocked, cancel it and you never left.
 *
 * This is convenience, not protection. The Files page keeps its own gate for
 * anyone arriving by typed URL, bookmark or refresh, and the enforcement is
 * server-side either way - api/source-folders.php and api/source-files.php
 * both refuse without the unlock token, whatever any page is showing.
 *
 * Loaded by every admin page that carries the Files link.
 */
(function () {
  "use strict";

  var TARGET = "sources-files.html";

  // On the Files page itself the built-in gate handles this; intercepting
  // there would put two password boxes on one screen.
  if (location.pathname.indexOf(TARGET) !== -1) return;

  var links = document.querySelectorAll('a[href*="' + TARGET + '"]');
  if (!links.length) return;

  var adminToken = localStorage.getItem("admin_token");
  if (!adminToken) return;

  var built = false;
  var mode = "unlock";
  var busy = false;

  function el(id) { return document.getElementById(id); }

  var CSS = [
    ".sl-shield{position:fixed;inset:0;background:rgba(16,24,40,.55);display:none;",
    "  align-items:center;justify-content:center;z-index:100000;padding:20px}",
    ".sl-shield.show{display:flex}",
    ".sl-box{width:100%;max-width:430px;background:#fff;border-radius:24px;padding:34px;",
    "  box-shadow:0 24px 60px rgba(16,24,40,.28);max-height:calc(100vh - 40px);overflow:auto;",
    "  font-family:Arial,sans-serif;text-align:left}",
    ".sl-icon{width:52px;height:52px;border-radius:16px;background:#eef0f6;color:#1b1e3a;",
    "  display:flex;align-items:center;justify-content:center;margin-bottom:18px}",
    ".sl-icon svg{width:24px;height:24px}",
    ".sl-box h2{font-size:21px;color:#101828;margin:0 0 10px;font-weight:700}",
    ".sl-box p.sl-intro{font-size:13px;line-height:1.6;color:#667085;margin:0 0 22px}",
    ".sl-box label{display:block;font-size:13px;font-weight:600;margin-bottom:8px;color:#101828}",
    ".sl-box input{width:100%;height:52px;border:1px solid #dfe3ea;background:#f1f3f6;",
    "  border-radius:12px;padding:0 15px;margin-bottom:16px;outline:none;font-size:14px;",
    "  font-family:inherit;box-sizing:border-box}",
    ".sl-box input:focus{border-color:#1b1e3a;background:#fff}",
    ".sl-submit{width:100%;height:54px;border:none;background:#1b1e3a;color:#fff;",
    "  border-radius:12px;font-weight:800;font-size:14px;cursor:pointer;font-family:inherit}",
    ".sl-submit:hover{background:#282c52}",
    ".sl-submit:disabled{opacity:.6;cursor:not-allowed}",
    ".sl-status{margin-top:14px;text-align:center;font-size:13px;color:#c9000b;min-height:18px}",
    ".sl-forgot{margin-top:20px;padding-top:18px;border-top:1px solid #eef1f5;",
    "  font-size:12.5px;line-height:1.6;color:#667085;text-align:center}",
    ".sl-forgot a{color:#1b1e3a;font-weight:700}",
    ".sl-cancel{display:block;width:100%;margin-top:14px;padding:0;border:none;background:none;",
    "  text-align:center;font-size:12px;color:#98a2b3;cursor:pointer;font-family:inherit}",
    ".sl-cancel:hover{color:#101828}"
  ].join("");

  var HTML = [
    '<div class="sl-box" role="dialog" aria-modal="true" aria-labelledby="slTitle">',
    '  <div class="sl-icon">',
    '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"',
    '         stroke-linecap="round" stroke-linejoin="round">',
    '      <rect x="4" y="11" width="16" height="10" rx="2"/>',
    '      <path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    '    </svg>',
    '  </div>',
    '  <h2 id="slTitle">Enter your password</h2>',
    '  <p class="sl-intro" id="slIntro">Files has its own password, separate from your admin login.</p>',
    '  <label for="slPassword">Password</label>',
    '  <input type="password" id="slPassword" placeholder="Password" autocomplete="current-password">',
    '  <div id="slConfirmRow" style="display:none">',
    '    <label for="slConfirm">Confirm password</label>',
    '    <input type="password" id="slConfirm" placeholder="Repeat the password" autocomplete="new-password">',
    '  </div>',
    '  <button class="sl-submit" type="button" id="slSubmit">Unlock</button>',
    '  <div class="sl-status" id="slStatus"></div>',
    '  <div class="sl-forgot" id="slForgot" style="display:none">',
    '    Forgotten it? <a href="settings.html#sources-password">Reset it from Settings</a> &mdash;',
    '    a link is emailed to <span id="slEmail">your sign-in address</span>.',
    '  </div>',
    '  <button class="sl-cancel" type="button" id="slCancel">Stay on this page</button>',
    '</div>'
  ].join("");

  function build() {
    if (built) return;
    built = true;

    var style = document.createElement("style");
    style.textContent = CSS;
    document.head.appendChild(style);

    var shield = document.createElement("div");
    shield.className = "sl-shield";
    shield.id = "slShield";
    shield.innerHTML = HTML;
    document.body.appendChild(shield);

    el("slSubmit").addEventListener("click", submit);
    el("slCancel").addEventListener("click", close);

    // Clicking the backdrop is the same as cancelling; clicking the card is not.
    shield.addEventListener("click", function (e) { if (e.target === shield) close(); });

    ["slPassword", "slConfirm"].forEach(function (id) {
      el(id).addEventListener("keydown", function (e) {
        if (e.key === "Enter") submit();
      });
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") close();
    });
  }

  function open() {
    build();
    el("slStatus").textContent = "";
    el("slPassword").value = "";
    el("slConfirm").value = "";
    el("slShield").classList.add("show");
    el("slPassword").focus();
  }

  function close() {
    if (busy) return; // a request is in flight; let it finish
    if (built) el("slShield").classList.remove("show");
  }

  function go() {
    window.location.href = TARGET;
  }

  /* First run has nothing to unlock, so the box asks the admin to choose the
     password instead - the same two modes the Files page itself offers. */
  function setMode(next, email) {
    mode = next === "set" ? "set_password" : "unlock";
    var first = mode === "set_password";

    el("slTitle").textContent = first ? "Choose a password" : "Enter your password";
    el("slIntro").textContent = first
      ? "Files is protected by its own password, separate from your admin login. Choose one now — you will be asked for it each time you open it."
      : "Files has its own password, separate from your admin login.";

    el("slConfirmRow").style.display = first ? "block" : "none";
    el("slForgot").style.display = first ? "none" : "block";
    el("slSubmit").textContent = first ? "Set password and continue" : "Unlock";

    if (email) el("slEmail").textContent = email;
  }

  async function askMode() {
    try {
      var res = await fetch("api/sources-lock.php", {
        headers: {
          Authorization: "Bearer " + adminToken,
          "X-Sources-Token": sessionStorage.getItem("sources_token") || ""
        }
      });

      var data = await res.json();

      if (!data.success) {
        setMode("unlock");
        el("slStatus").textContent = data.message || "Could not check the password.";
        return;
      }

      // Unlocked already, in a tab that had simply lost its stored copy.
      if (data.unlocked) { go(); return; }

      setMode(data.has_password ? "unlock" : "set", data.email);

    } catch (err) {
      setMode("unlock");
      el("slStatus").textContent = "Connection error.";
    }
  }

  async function submit() {
    if (busy) return;

    var status = el("slStatus");
    var password = el("slPassword").value;
    var confirm = el("slConfirm").value;

    if (!password) {
      status.textContent = "Enter your password.";
      return;
    }

    busy = true;
    el("slSubmit").disabled = true;
    status.textContent = "Please wait...";

    try {
      var res = await fetch("api/sources-lock.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + adminToken
        },
        body: JSON.stringify({ action: mode, password: password, confirm: confirm })
      });

      var data = await res.json();

      if (!data.success) {
        status.textContent = data.message;
        return;
      }

      // The Files page reads this back and walks straight in.
      sessionStorage.setItem("sources_token", data.token || "");
      go();
      return;

    } catch (err) {
      status.textContent = "Connection error.";

    } finally {
      busy = false;
      if (built) el("slSubmit").disabled = false;
    }
  }

  Array.prototype.forEach.call(links, function (link) {
    link.addEventListener("click", function (event) {
      // Let modified clicks (new tab, new window) behave normally; the Files
      // page will ask for itself over there.
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

      event.preventDefault();

      // A token in hand means this tab has unlocked already. Go, and let the
      // Files page re-ask in place on the off chance it has since expired.
      if (sessionStorage.getItem("sources_token")) { go(); return; }

      open();
      askMode();
    });
  });
})();
