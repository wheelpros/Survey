/*
|------------------------------------------------------------------------------
| Connected apps, and the Director's client access switch
|------------------------------------------------------------------------------
|
| The AI assistants a person has connected, on the page that person already
| uses: Settings for staff, Profile for clients. Each is one row saying how much
| it can do and whether it is short of what the person's role allows. Long lists
| page in the browser, as api/oauth/grants.php returns them all.
|
|   <link rel="stylesheet" href="assets/connected-apps.css?v=2" />
|   <div id="ca-client-access" hidden></div>   (Settings only, shown to the Director)
|   <div data-token-key="admin_token"></div>
|   <script src="assets/connected-apps.js?v=2"></script>
|
| After assets/confirm.js. Data: api/oauth/grants.php (anyone, their own) and
| api/oauth/admin.php (the Director). connected-apps.html sends old links here.
| What assistants have been doing is on Tracking (event-log.html).
|
*/

(function () {

  var appsEl = document.querySelector("[data-token-key]");
  if (!appsEl) return;

  var token = localStorage.getItem(appsEl.dataset.tokenKey);

  var PAGE_SIZE = 5;
  var grants = [];
  var page = 1;

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function day(value) {
    var date = value && new Date(String(value).replace(" ", "T"));
    return date && !isNaN(date)
      ? date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" })
      : "";
  }

  function call(url, body) {
    return fetch(url, {
      method: body ? "POST" : "GET",
      headers: Object.assign(
        { Authorization: "Bearer " + token },
        body ? { "Content-Type": "application/json" } : {}
      ),
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.json();
    });
  }

  /* ── The apps ──────────────────────────────────────────────────────────── */

  function areasHtml(scopes, extraClass, prefix) {
    var areas = [];
    scopes.forEach(function (scope) {
      var area = areas.filter(function (a) { return a.name === scope.area; })[0];
      if (!area) {
        area = { name: scope.area, items: [] };
        areas.push(area);
      }
      area.items.push(scope);
    });

    return areas.map(function (area) {
      return '<div class="ca-area ' + extraClass + '"><h3>' + esc(prefix + area.name) + "</h3><ul>"
        + area.items.map(function (s) {
            return '<li title="' + esc(s.scope) + '">' + esc(s.description) + "</li>";
          }).join("")
        + "</ul></div>";
    }).join("");
  }

  function appHtml(grant) {
    var have = grant.scopes.length;
    var missing = grant.missing.length;
    var total = have + missing;
    var count = missing
      ? '<span class="ca-count short">' + have + " of " + total + " permissions</span>"
      : '<span class="ca-count">All ' + total + " permissions</span>";

    /* A grant never grows: scopes added after it was approved (team forms,
       say) stay off until the person connects again. The same list whoami
       gives the assistant as missing_scopes. */
    var missingAreas = [];
    grant.missing.forEach(function (scope) {
      if (missingAreas.indexOf(scope.area) === -1) missingAreas.push(scope.area);
    });
    var warning = !missing ? "" :
      '<div class="ca-warning"><b>New permissions are available — disconnect and connect again to use them</b>'
      + "Not yet included: " + esc(missingAreas.join(", ")) + ". "
      + "Disconnect here, then connect " + esc(grant.client_name) + " again and approve.</div>";

    var used = day(grant.last_used_at);

    return '<article class="ca-app">'
      + '<div class="ca-app-head">'
      +   '<div class="ca-app-who">'
      +     '<div class="ca-app-name">' + esc(grant.client_name) + count + "</div>"
      +     '<div class="ca-meta">Connected ' + esc(day(grant.created_at))
      +       " &middot; " + (used ? "last used " + esc(used) : "not used yet") + "</div>"
      +   "</div>"
      +   '<button class="ca-disconnect" type="button" data-id="' + Number(grant.id) + '"'
      +     ' data-name="' + esc(grant.client_name) + '"'
      +     ' aria-label="Disconnect ' + esc(grant.client_name) + '">Disconnect</button>'
      + "</div>"
      + warning
      + '<details class="ca-details"><summary>See what it can do</summary>'
      +   areasHtml(grant.scopes, "", "")
      +   areasHtml(grant.missing, "missing", "Missing: ")
      + "</details>"
      + "</article>";
  }

  function pagerHtml(totalPages, start, shown) {
    var numbers = "";
    for (var number = 1; number <= totalPages; number++) {
      numbers += '<button type="button" data-page="' + number + '"'
        + (number === page ? ' class="current" aria-current="page"' : "") + ">" + number + "</button>";
    }

    return '<span class="pager-info">Showing ' + (start + 1) + "-" + (start + shown) + " of " + grants.length + "</span>"
      + '<div class="pager-pages">'
      +   '<button type="button" data-page="' + (page - 1) + '"' + (page === 1 ? " disabled" : "") + ">Prev</button>"
      +   numbers
      +   '<button type="button" data-page="' + (page + 1) + '"' + (page === totalPages ? " disabled" : "") + ">Next</button>"
      + "</div>";
  }

  /* Paints the current page. A Disconnect can leave the last page empty, so the
     page is pulled back into range first. */
  function paintApps() {
    var list = appsEl.querySelector(".ca-list");
    var pager = appsEl.querySelector(".ca-pager");

    var totalPages = Math.max(1, Math.ceil(grants.length / PAGE_SIZE));
    page = Math.min(page, totalPages);

    var start = (page - 1) * PAGE_SIZE;
    var rows = grants.slice(start, start + PAGE_SIZE);

    list.innerHTML = rows.length
      ? rows.map(appHtml).join("")
      : '<p class="ca-empty">No AI assistants are connected to your account.</p>';

    list.querySelectorAll(".ca-disconnect").forEach(function (button) {
      button.addEventListener("click", function () {
        disconnect(Number(button.dataset.id), button.dataset.name);
      });
    });

    pager.hidden = grants.length <= PAGE_SIZE;
    pager.innerHTML = pager.hidden ? "" : pagerHtml(totalPages, start, rows.length);
  }

  function loadApps() {
    return call("api/oauth/grants.php").then(function (data) {
      if (!data.success) throw new Error(data.message);
      grants = data.grants || [];
      paintApps();
    });
  }

  function setStatus(el, text, isError) {
    el.textContent = text;
    el.className = "ca-status" + (isError ? " error" : "");
  }

  function disconnect(id, name) {
    var status = appsEl.querySelector(".ca-list ~ .ca-status");

    window.askConfirm({
      title: "Disconnect " + name,
      text: name + " will lose access to your account within a minute. You can connect it again later.",
      action: "Disconnect"
    }).then(function (ok) {
      if (!ok) return;

      setStatus(status, "Disconnecting...");
      call("api/oauth/grants.php", { action: "revoke", id: id }).then(function (data) {
        setStatus(status, data.message || "", !data.success);
        return loadApps();
      }).catch(function () {
        setStatus(status, "That didn't go through. Check your connection and try again.", true);
      });
    });
  }

  /* ── The Director's switch ─────────────────────────────────────────────── */

  var accessEl = document.getElementById("ca-client-access");

  function paintAccess(enabled) {
    accessEl.innerHTML =
        '<label class="ca-toggle"><input type="checkbox" id="caClientsEnabled"> Allow clients to connect AI assistants</label>'
      + '<p class="ca-toggle-note">Staff can always connect an AI assistant to their own account. '
      + "Clients can only once this is on, and only to see and act on their own meetings, content, forms and projects.</p>"
      + '<p class="ca-status" id="caAccessStatus" role="status"></p>';

    var box = document.getElementById("caClientsEnabled");
    var status = document.getElementById("caAccessStatus");
    box.checked = enabled;

    box.addEventListener("change", function () {
      var turningOn = box.checked;

      var asked = turningOn ? Promise.resolve(true) : window.askConfirm({
        title: "Turn client access off",
        text: "Every AI assistant a client has connected will be disconnected.",
        action: "Turn off"
      });

      asked.then(function (ok) {
        if (!ok) {
          box.checked = true;
          return;
        }
        setStatus(status, "Saving...");
        call("api/oauth/admin.php", { clients_enabled: turningOn }).then(function (res) {
          setStatus(status, res.message || "", !res.success);
          if (!res.success) box.checked = !turningOn;
        }).catch(function () {
          setStatus(status, "That didn't go through. Check your connection and try again.", true);
          box.checked = !turningOn;
        });
      });
    });

    accessEl.hidden = false;
  }

  function loadAccess() {
    if (!accessEl || !window.adminCan || !window.adminCan("settings.manage")) return Promise.resolve();

    return call("api/oauth/admin.php").then(function (data) {
      if (data.success) paintAccess(!!data.clients_enabled);
    }).catch(function () {
      // The apps below still work; the switch simply stays out of the way.
    });
  }

  /* ── Start ─────────────────────────────────────────────────────────────── */

  appsEl.innerHTML = '<div class="ca-list"><p class="ca-empty">Loading...</p></div>'
    + '<nav class="pager ca-pager" aria-label="Connected apps pages" hidden></nav>'
    + '<p class="ca-status" role="status"></p>';

  appsEl.querySelector(".ca-pager").addEventListener("click", function (event) {
    var button = event.target.closest("button[data-page]");
    if (!button || button.disabled) return;

    page = Number(button.dataset.page);
    paintApps();

    // The buttons were redrawn, so keep the keyboard on the page now showing.
    var current = appsEl.querySelector(".ca-pager button.current");
    if (current) current.focus();

    var panel = document.getElementById("connected-apps");
    if (panel) panel.scrollIntoView({ block: "start" });
  });

  loadApps().catch(function () {
    appsEl.querySelector(".ca-list").innerHTML = "";
    setStatus(appsEl.querySelector(".ca-list ~ .ca-status"), "Couldn't load your connected apps. Please reload the page.", true);
  }).then(loadAccess).then(holdAnchor);

  /* The list arrives after the browser has tried the #anchor, and the panels
     above it are still filling in and changing height. So the jump is repeated
     as the page settles, until someone scrolls for themselves. */
  function holdAnchor() {
    var target = location.hash && document.querySelector(location.hash);
    if (!target) return;

    target.scrollIntoView();
    if (!window.ResizeObserver) return;

    var observer = new ResizeObserver(function () { target.scrollIntoView(); });
    observer.observe(document.body);

    function stop() {
      observer.disconnect();
      ["wheel", "touchstart", "keydown", "mousedown"].forEach(function (type) {
        window.removeEventListener(type, stop);
      });
    }

    ["wheel", "touchstart", "keydown", "mousedown"].forEach(function (type) {
      window.addEventListener(type, stop, { passive: true });
    });
    setTimeout(stop, 4000);
  }

})();
