/*
|------------------------------------------------------------------------------
| Connected apps, and the Director's AI access controls
|------------------------------------------------------------------------------
|
| The AI assistants a person has connected, on the page that person already
| uses: Settings for staff, Profile for clients. Each is one row saying how much
| it can do and whether it is short of what the person's role allows.
|
|   <link rel="stylesheet" href="assets/connected-apps.css?v=1" />
|   <div id="connected-apps" data-token-key="admin_token"></div>
|   <div id="ai-access" hidden></div>   (Settings only, shown to the Director)
|   <script src="assets/connected-apps.js?v=1"></script>
|
| After assets/confirm.js. Data: api/oauth/grants.php (anyone, their own) and
| api/oauth/admin.php (the Director). connected-apps.html sends old links here.
|
*/

(function () {

  var appsEl = document.querySelector("[data-token-key]");
  if (!appsEl) return;

  var token = localStorage.getItem(appsEl.dataset.tokenKey);

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function parseDate(value) {
    var date = new Date(String(value).replace(" ", "T"));
    return isNaN(date) ? null : date;
  }

  function day(value) {
    var date = value && parseDate(value);
    return date ? date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" }) : "";
  }

  function moment(value) {
    var date = value && parseDate(value);
    return date
      ? date.toLocaleString(undefined, { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" })
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
    var noun = missing === 1 ? "permission" : "permissions";

    var count = missing
      ? '<span class="ca-count short">' + have + " of " + total + " permissions</span>"
      : '<span class="ca-count">All ' + total + " permissions</span>";

    var warning = !missing ? "" :
      '<div class="ca-warning"><b>Missing ' + missing + " " + noun + " your role allows</b>"
      + "Connect it again from " + esc(grant.client_name) + " to ask for them. If they're still "
      + "missing, remove it from " + esc(grant.client_name) + " completely and add it again.</div>";

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

  function paintApps(grants) {
    var list = appsEl.querySelector(".ca-list");

    list.innerHTML = grants.length
      ? grants.map(appHtml).join("")
      : '<p class="ca-empty">No AI assistants are connected to your account.</p>';

    list.querySelectorAll(".ca-disconnect").forEach(function (button) {
      button.addEventListener("click", function () {
        disconnect(Number(button.dataset.id), button.dataset.name);
      });
    });
  }

  function loadApps() {
    return call("api/oauth/grants.php").then(function (data) {
      if (!data.success) throw new Error(data.message);
      paintApps(data.grants || []);
    });
  }

  function setStatus(el, text, isError) {
    el.textContent = text;
    el.className = "ca-status" + (isError ? " error" : "");
  }

  function disconnect(id, name) {
    var status = appsEl.querySelector(".ca-status");

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

  /* ── The Director's controls ───────────────────────────────────────────── */

  var accessEl = document.getElementById("ai-access");
  var activity = [];

  function toolLabel(row) {
    if (!row.tool) return row.endpoint || "";
    var words = String(row.tool).replace(/_/g, " ");
    return words.charAt(0).toUpperCase() + words.slice(1);
  }

  function optionsHtml(values) {
    return values.map(function (value) {
      return '<option value="' + esc(value) + '">' + esc(value) + "</option>";
    }).join("");
  }

  function unique(key) {
    return activity
      .map(function (row) { return row[key]; })
      .filter(function (value, i, all) { return all.indexOf(value) === i; })
      .sort();
  }

  function paintActivity() {
    var person = document.getElementById("caFilterPerson").value;
    var app = document.getElementById("caFilterApp").value;
    var result = document.getElementById("caFilterResult").value;

    var rows = activity.filter(function (row) {
      return (!person || row.person === person)
        && (!app || row.app === app)
        && (!result || (result === "ok") === (row.status < 400));
    });

    var body = document.getElementById("caActivity");
    body.innerHTML = rows.map(function (row) {
      var ok = row.status < 400;
      return "<tr>"
        + '<td data-label="When">' + esc(moment(row.at)) + "</td>"
        + '<td data-label="Who">' + esc(row.person)
        +   (row.kind === "user" ? ' <span class="ca-kind">(client)</span>' : "") + "</td>"
        + '<td data-label="App">' + esc(row.app) + "</td>"
        + '<td data-label="What" title="' + esc(row.tool || row.endpoint) + '">' + esc(toolLabel(row)) + "</td>"
        + '<td data-label="Result" class="' + (ok ? "ok" : "bad") + '">'
        +   (ok ? "OK" : "Refused (" + Number(row.status) + ")") + "</td>"
        + "</tr>";
    }).join("");

    document.getElementById("caActivityTable").hidden = !rows.length;
    document.getElementById("caNoActivity").hidden = rows.length > 0;
    document.getElementById("caNoActivity").textContent = activity.length
      ? "Nothing matches those filters."
      : "No AI activity yet.";
  }

  function paintAccess(data) {
    activity = data.activity || [];

    accessEl.querySelector(".panel-body").innerHTML =
        '<p class="ca-toggle-note">Staff can always connect an AI assistant to their own account. '
      + "Clients can only once this is on, and only to see and act on their own meetings, content, forms and projects.</p>"
      + '<label class="ca-toggle"><input type="checkbox" id="caClientsEnabled"> Allow clients to connect AI assistants</label>'
      + '<p class="ca-status" id="caAccessStatus" role="status"></p>'
      + '<h2 class="ca-activity-head">Recent AI activity</h2>'
      + '<p class="ca-activity-note">The latest 50 calls AI assistants made into the portal, across everyone.</p>'
      + '<div class="ca-filters">'
      +   '<label>Who<select id="caFilterPerson"><option value="">Everyone</option>' + optionsHtml(unique("person")) + "</select></label>"
      +   '<label>App<select id="caFilterApp"><option value="">All apps</option>' + optionsHtml(unique("app")) + "</select></label>"
      +   '<label>Result<select id="caFilterResult"><option value="">Any result</option>'
      +     '<option value="ok">OK</option><option value="refused">Refused</option></select></label>'
      + "</div>"
      + '<table class="ca-table" id="caActivityTable"><thead><tr>'
      +   "<th>When</th><th>Who</th><th>App</th><th>What</th><th>Result</th>"
      + '</tr></thead><tbody id="caActivity"></tbody></table>'
      + '<p class="ca-empty" id="caNoActivity" hidden></p>';

    ["caFilterPerson", "caFilterApp", "caFilterResult"].forEach(function (id) {
      document.getElementById(id).addEventListener("change", paintActivity);
    });
    paintActivity();

    var box = document.getElementById("caClientsEnabled");
    var status = document.getElementById("caAccessStatus");
    box.checked = !!data.clients_enabled;

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
      if (data.success) paintAccess(data);
    }).catch(function () {
      // The apps above still work; this panel simply stays out of the way.
    });
  }

  /* ── Start ─────────────────────────────────────────────────────────────── */

  appsEl.innerHTML = '<div class="ca-list"><p class="ca-empty">Loading...</p></div>'
    + '<p class="ca-status" role="status"></p>';

  loadApps().catch(function () {
    appsEl.querySelector(".ca-list").innerHTML = "";
    setStatus(appsEl.querySelector(".ca-status"), "Couldn't load your connected apps. Please reload the page.", true);
  }).then(loadAccess).then(function () {
    // The list arrives after the browser has tried the #anchor.
    var target = location.hash && document.querySelector(location.hash);
    if (target) target.scrollIntoView();
  });

})();
