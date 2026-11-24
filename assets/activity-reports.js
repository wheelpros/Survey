/*
|------------------------------------------------------------------------------
| Activity reports: what the three pages share
|------------------------------------------------------------------------------
|
| activity-reports.html (the lists), activity-report-plan.html (planning a day),
| activity-report-template.html and activity-report-details.html (one report: the
| employee edits and closes it there, the reviewer reviews it) all talk to
| the same two endpoints and draw the same pills, so those live here as
| window.DR.
|
|   GET  api/activity-reports.php?action=…   mine, get, list, filters,
|                                         plan_options, templates, template,
|                                         pending_count
|   POST api/activity-report-writes.php      update_activities, close, plan,
|                                         delete, review_activity, review,
|                                         template_save/_delete (JSON);
|                                         update_files (multipart: a "report"
|                                         JSON field plus files[])
|
| "Today" is the server's day (the portal's time zone): every read returns
| it and DR.todayIso() uses it once known. Who sees and changes what is
| decided by the endpoints (reports.create, reports.self, reports.review,
| reports.view_all); DR.can() and the can_* flags only tidy the page.
|
*/
(function () {
  "use strict";

  var token = localStorage.getItem("admin_token");
  var user = {};
  try { user = JSON.parse(localStorage.getItem("admin_user") || "{}") || {}; } catch (e) { user = {}; }

  if (!token || !user.role) {
    window.location.href = "admin-login.html";
  }

  function logout() {
    localStorage.removeItem("admin_token");
    localStorage.removeItem("admin_user");
    window.location.href = "admin-login.html";
  }

  // Shared helpers (assets/ui-utils.js, assets/row-menu.js), loaded first.
  var esc = window.UI.esc;

  // assets/permissions.js, the one place a page asks.
  function can(perm) {
    return !!window.adminCan && window.adminCan(perm);
  }

  function pad(n) { return String(n).padStart(2, "0"); }

  /* The day is the portal's, decided by the server: a browser can't move a
     report into another day by claiming another time zone. Until the first
     reply arrives, the device's date stands in (labels only). */
  var serverToday = "";

  function todayIso() {
    if (serverToday) return serverToday;
    var d = new Date();
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  }

  function parseIso(iso) {
    var p = String(iso || "").split("-").map(Number);
    return p.length === 3 ? new Date(p[0], p[1] - 1, p[2]) : null;
  }

  /** "Wed 18 Nov 2026"; "Today" / "Yesterday" when close. */
  function dayLabel(iso, plain) {
    var d = parseIso(iso);
    if (!d) return "";
    if (!plain) {
      var today = parseIso(todayIso());
      var diff = Math.round((today - d) / 86400000);
      if (diff === 0) return "Today";
      if (diff === 1) return "Yesterday";
    }
    return d.toLocaleDateString(undefined, { weekday: "short", day: "numeric", month: "short", year: "numeric" });
  }

  function timeLabel(seconds) {
    return seconds ? new Date(seconds * 1000).toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" }) : "";
  }

  function dateTimeLabel(seconds) {
    return seconds
      ? new Date(seconds * 1000).toLocaleString(undefined, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })
      : "";
  }

  var fileSize = window.UI.fileSize;

  var ROLE_LABELS = { owner: "Director", super_admin: "Super Admin", account_manager: "Account Manager", seo_admin: "Admin" };

  var ACTIVITY_STATUS = [
    ["todo", "To do"],
    ["in_progress", "In progress"],
    ["follow_up", "Follow up"],
    ["done", "Done"]
  ];

  /* A report's status is worked out from its activities (effective_status
     from the endpoint): awaiting while a Done activity isn't reviewed yet,
     changes while one was sent back, then reviewed / sent / in progress /
     to fill in. A report stays open until it's sent, whatever its day. */
  var REPORT_STATUS = {
    awaiting: "Awaiting review", changes: "Needs changes", reviewed: "Reviewed", sent: "Sent",
    in_progress: "In progress", planned: "To fill in"
  };

  function labelOf(pairs, key) {
    for (var i = 0; i < pairs.length; i++) if (pairs[i][0] === key) return pairs[i][1];
    return key || "";
  }

  function pill(kind, label) {
    return '<span class="pill pill-' + esc(kind) + '">' + esc(label) + "</span>";
  }

  function reportPill(status) { return pill(status, REPORT_STATUS[status] || status); }

  /** A sheet's pill: one still to fill in after its day has passed is Not sent. */
  function sheetPill(r) { return reportPill(r.effective_status || r.status); }

  /** "31 calls · 42 CVs" */
  function countsLabel(list) {
    return (list || []).map(function (c) { return Number(c.total) + " " + c.unit; }).join(" · ");
  }

  var ICON_UP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>';
  var ICON_DOWN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
  var ICON_TRASH = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';

  /*
   * The activity planner: what a creator writes for someone's day - title,
   * description and, for routine work, something to count ("calls"). Used by
   * the plan page and the template editor. Counting is a Quantity switch;
   * the number itself is entered with the activity's progress.
   *
   *   var p = DR.planner(box, { prefix: "plan", onChange: fn });
   *   p.set([{ title, description, count_unit, origin }]); p.get(); p.add(true);
   */
  /* What a counted activity counts when the planner ticks Quantity. */
  var QUANTITY_UNIT = "quantity";

  function planner(box, opts) {
    opts = opts || {};
    var prefix = opts.prefix || "p";
    var items = [];

    function changed() { if (opts.onChange) opts.onChange(items); }

    /* Your own report: each activity can be saved with where it stands and
       what came of it, not only planned. opts.progress says when (a function,
       since the page knows it's your own only once it has loaded). */
    function withProgress() { return typeof opts.progress === "function" ? !!opts.progress() : !!opts.progress; }

    /* Your own report: what came of it under the description, then one line
       with the status, the Quantity switch and, once it's on, how many. */
    function outcomeField(a, i) {
      return '<label class="field-label" for="' + prefix + "O" + i + '">Outcome <span class="optional">(optional)</span></label>'
        + '<textarea class="textarea" data-voice id="' + prefix + "O" + i + '" data-field="outcome" maxlength="5000" dir="auto" placeholder="What came of it so far">' + esc(a.outcome || "") + "</textarea>";
    }

    function quantitySwitch(counted) {
      return '<label class="check"><input type="checkbox" data-counted ' + (counted ? "checked" : "") + "> Quantity</label>";
    }

    function progressRow(a, i, counted) {
      return '<div class="qty-row">'
        + '<div class="qty-field"><label class="field-label" for="' + prefix + "S" + i + '">Status</label>'
        + '<select class="select" id="' + prefix + "S" + i + '" data-field="status">'
        + ACTIVITY_STATUS.map(function (s) {
            return '<option value="' + s[0] + '"' + ((a.status || "todo") === s[0] ? " selected" : "") + ">" + s[1] + "</option>";
          }).join("")
        + "</select></div>"
        + quantitySwitch(counted)
        + (counted
          ? '<div class="qty-field"><label class="field-label" for="' + prefix + "N" + i + '">How many</label>'
            + '<input class="input qty-input" type="number" min="0" step="1" inputmode="numeric" id="' + prefix + "N" + i + '" data-field="count_value" value="' + (a.count_value == null ? "" : esc(a.count_value)) + '"></div>'
          : "")
        + "</div>";
    }

    /* An activity the employee has worked on (or that was reviewed) is kept
       as it is: shown with its status, not editable, not removable. It can
       still move up or down. */
    function lockedCard(a, i, n) {
      return '<div class="activity is-locked" data-index="' + i + '">'
        + '<div class="activity-head"><h3>Activity ' + n + " " + activityPill(a.status) + " " + reviewPill(a) + "</h3>"
        + '<div class="activity-tools">'
        + '<button type="button" class="icon-btn" data-move="-1" ' + (i === 0 ? "disabled" : "") + ' aria-label="Move activity ' + n + ' up">' + ICON_UP + "</button>"
        + '<button type="button" class="icon-btn" data-move="1" ' + (i === items.length - 1 ? "disabled" : "") + ' aria-label="Move activity ' + n + ' down">' + ICON_DOWN + "</button>"
        + "</div></div>"
        + '<p class="locked-title" dir="auto">' + esc(a.title) + "</p>"
        + (a.description ? '<p class="locked-desc" dir="auto">' + esc(a.description) + "</p>" : "")
        + '<p class="locked-note">Already worked on, so it stays as it is.</p>'
        + "</div>";
    }

    function render(focusIndex) {
      box.innerHTML = items.map(function (a, i) {
        var n = String(i + 1).padStart(2, "0");
        if (a.locked) return lockedCard(a, i, n);
        var counted = a.count_unit != null;
        return '<div class="activity" data-index="' + i + '">'
          + '<div class="activity-head"><h3>Activity ' + n
          + (a.origin === "carried" ? ' <span class="pill pill-carry">Carried over</span>' : "") + "</h3>"
          + '<div class="activity-tools">'
          + '<button type="button" class="icon-btn" data-move="-1" ' + (i === 0 ? "disabled" : "") + ' aria-label="Move activity ' + n + ' up">' + ICON_UP + "</button>"
          + '<button type="button" class="icon-btn" data-move="1" ' + (i === items.length - 1 ? "disabled" : "") + ' aria-label="Move activity ' + n + ' down">' + ICON_DOWN + "</button>"
          + '<button type="button" class="icon-btn" data-remove aria-label="Remove activity ' + n + '">' + ICON_TRASH + "</button>"
          + "</div></div>"
          + '<label class="field-label" for="' + prefix + "T" + i + '">Activity title</label>'
          + '<input class="input" id="' + prefix + "T" + i + '" data-field="title" maxlength="200" dir="auto" placeholder="Answer and log phone calls" value="' + esc(a.title) + '">'
          + '<div class="field-error" id="' + prefix + "E" + i + '"></div>'
          + '<label class="field-label" for="' + prefix + "D" + i + '">Description</label>'
          + '<textarea class="textarea" data-voice id="' + prefix + "D" + i + '" data-field="description" maxlength="2000" dir="auto" placeholder="What needs doing, and anything they should know">' + esc(a.description) + "</textarea>"
          + (withProgress()
            ? outcomeField(a, i) + progressRow(a, i, counted)
            : '<div class="qty-row">' + quantitySwitch(counted) + "</div>")
          + "</div>";
      }).join("");
      if (focusIndex != null) {
        var f = document.getElementById(prefix + "T" + focusIndex);
        if (f) f.focus();
      }
    }

    box.addEventListener("input", function (e) {
      var card = e.target.closest(".activity");
      var field = e.target.dataset.field;
      if (!card || !field) return;
      items[Number(card.dataset.index)][field] = e.target.value;
      e.target.classList.remove("is-invalid");
      var err = document.getElementById(prefix + "E" + card.dataset.index);
      if (err) err.textContent = "";
      changed();
    });

    box.addEventListener("change", function (e) {
      if (!e.target.hasAttribute("data-counted")) return;
      var i = Number(e.target.closest(".activity").dataset.index);
      // Activities from before keep the unit they were planned with ("calls").
      items[i].count_unit = e.target.checked ? (items[i].count_unit || QUANTITY_UNIT) : null;
      render();
      var how = e.target.checked && document.getElementById(prefix + "N" + i);
      if (how) how.focus();
      changed();
    });

    box.addEventListener("click", function (e) {
      var btn = e.target.closest("button");
      if (!btn || !box.contains(btn)) return;
      var i = Number(btn.closest(".activity").dataset.index);
      if (btn.dataset.move) {
        var to = i + Number(btn.dataset.move);
        if (to < 0 || to >= items.length) return;
        var t = items[i]; items[i] = items[to]; items[to] = t;
        render();
      } else if (btn.hasAttribute("data-remove")) {
        items.splice(i, 1);
        render();
      }
      changed();
    });

    return {
      set: function (list) {
        items = (list || []).map(function (a) {
          return { id: Number(a.id) || 0, title: a.title || "", description: a.description || "",
            count_unit: a.count_unit == null ? null : a.count_unit, origin: a.origin || "planned",
            locked: !!(a.plan_locked || a.locked), status: a.status || "todo", review: a.review || null,
            outcome: a.outcome || "", count_value: a.count_value == null ? "" : a.count_value };
        });
        render();
      },
      /** Draws the cards again, after the page learns it's your own report. */
      refresh: function () { render(); },
      add: function (focus) {
        items.push({ title: "", description: "", count_unit: null, origin: "planned", status: "todo", outcome: "", count_value: "" });
        render(focus ? items.length - 1 : null);
        changed();
      },
      append: function (list) { this.set(items.concat(list)); changed(); },
      /** Replaces the activities, keeping the ones already worked on (they can't go). */
      replace: function (list) { this.set(items.filter(function (a) { return a.locked; }).concat(list || [])); changed(); },
      count: function () { return items.filter(function (a) { return a.title.trim(); }).length; },
      /** Flags cards with text but no title. */
      validate: function () {
        var first = null;
        items.forEach(function (a, i) {
          if (!a.title.trim() && (a.description.trim() || a.count_unit)) {
            var el = document.getElementById(prefix + "T" + i);
            el.classList.add("is-invalid");
            document.getElementById(prefix + "E" + i).textContent = "Give this activity a title.";
            if (!first) first = el;
          }
        });
        if (first) {
          first.scrollIntoView({ behavior: "smooth", block: "center" });
          first.focus({ preventScroll: true });
        }
        return !first;
      },
      cleaned: function () {
        var progress = withProgress();
        return items.filter(function (a) { return a.title.trim(); }).map(function (a) {
          var out = { id: a.id || 0, title: a.title.trim(), description: a.description.trim(), origin: a.origin,
            count_unit: a.count_unit != null && String(a.count_unit).trim() ? String(a.count_unit).trim() : null };
          // Worked-on activities aren't the plan's to change, so only open ones carry progress.
          if (progress && !a.locked) {
            out.status = a.status || "todo";
            out.outcome = String(a.outcome || "").trim();
            out.count_value = out.count_unit && String(a.count_value).trim() !== "" ? Number(a.count_value) : null;
          }
          return out;
        });
      }
    };
  }
  function activityPill(status) { return pill(status, labelOf(ACTIVITY_STATUS, status)); }

  /** "13:40" for today, "17 Nov, 13:40" before that. */
  function whenLabel(seconds) {
    if (!seconds) return "";
    return new Date(seconds * 1000).toDateString() === new Date().toDateString() ? timeLabel(seconds) : dateTimeLabel(seconds);
  }

  /*
   * Review of one activity, from its saved status and latest review:
   *   approved - reviewed, locked for the employee;
   *   awaiting - saved Done, not reviewed yet (again, after changes);
   *   changes  - sent back and not Done again yet;
   *   ""       - nothing to review.
   */
  function reviewState(a) {
    var r = a.review;
    if (r && r.state === "approved") return "approved";
    if (a.status === "done") return "awaiting";
    if (r && r.state === "changes") return "changes";
    return "";
  }

  var REVIEW_STATE = { approved: "Reviewed", awaiting: "Awaiting review", changes: "Needs changes" };

  function reviewPill(a) {
    var s = reviewState(a);
    return s ? pill(s, REVIEW_STATE[s]) : "";
  }

  var ICON_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>';
  var ICON_RETURN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>';

  function isMe(id) { return Number(id) === Number(user.id); }

  /** The latest review, while it still applies: "Reviewed by …", or the changes asked for. */
  function reviewLine(a) {
    var r = a.review, s = reviewState(a);
    if (s === "approved") {
      return '<div class="review-line is-approved">' + ICON_CHECK
        + '<span><span class="who">Reviewed by ' + esc(isMe(r.by) ? "you" : r.by_name) + "</span> · " + esc(whenLabel(r.at)) + "</span>"
        + (r.feedback ? '<div class="feedback" dir="auto">' + esc(r.feedback) + "</div>" : "") + "</div>";
    }
    if (s === "changes") {
      return '<div class="review-line is-changes">' + ICON_RETURN
        + '<span><span class="who">' + esc(isMe(r.by) ? "You" : r.by_name) + " asked for changes</span> · " + esc(whenLabel(r.at)) + "</span>"
        + '<div class="feedback" dir="auto">' + esc(r.feedback) + "</div></div>";
    }
    return "";
  }

  /** Changes asked for in earlier rounds, newest first. */
  function reviewHistory(a) {
    var earlier = (a.history || []).slice();
    if (a.review && a.review.state === "changes" && reviewState(a) !== "changes") earlier.push(a.review);
    return earlier.filter(function (h) { return h.state === "changes" && h.feedback; }).reverse().map(function (h) {
      return '<div class="history" dir="auto">Earlier, ' + esc(isMe(h.by) ? "you" : h.by_name) + " asked for changes (" + esc(whenLabel(h.at)) + "): "
        + "“" + esc(h.feedback) + "”</div>";
    }).join("");
  }

  function progress(done, total) {
    done = Number(done) || 0;
    total = Number(total) || 0;
    var pct = total ? Math.round(done / total * 100) : 0;
    return '<span class="progress"><span class="progress-bar" aria-hidden="true"><i style="width:' + pct + '%"></i></span>'
      + done + " of " + total + " done</span>";
  }

  /*
   * The overview the Dashboard has (admin-dashboard.html): a donut with its
   * legend, and four tiles, here counting activities by status.
   * c = {todo, in_progress, follow_up, done}; reports = how many reports
   * they're on. The ring's circumference is 100, so a share is its percentage.
   */
  var OVERVIEW = [
    ["done", "Done", "var(--c-done)", "Finished"],
    ["follow_up", "Follow up", "var(--c-follow)", "Waiting on someone else"],
    ["in_progress", "In progress", "var(--c-doing)", "Being worked on now"],
    ["todo", "To do", "var(--c-todo)", "Not started yet"]
  ];
  // The tiles read in the order the work moves.
  var TILE_ORDER = ["todo", "in_progress", "follow_up", "done"];

  function donutSvg(c) {
    var R = 15.9155;
    var parts = OVERVIEW.filter(function (o) { return Number(c[o[0]]) > 0; });
    var total = parts.reduce(function (n, o) { return n + Number(c[o[0]]); }, 0);
    var gap = parts.length > 1 ? 1.5 : 0;
    var offset = 0;
    var svg = '<circle cx="21" cy="21" r="' + R + '" fill="none" stroke="#eef1f5" stroke-width="5"></circle>';
    parts.forEach(function (o) {
      var share = Number(c[o[0]]) / total * 100;
      var len = Math.max(share - gap, 0.1);
      svg += '<circle cx="21" cy="21" r="' + R + '" fill="none" stroke="' + o[2] + '" stroke-width="5"'
        + ' stroke-dasharray="' + len + " " + (100 - len) + '" stroke-dashoffset="' + (-offset) + '"></circle>';
      offset += share;
    });
    return svg;
  }

  function overview(box, c, reports, title) {
    c = c || {};
    var total = OVERVIEW.reduce(function (n, o) { return n + (Number(c[o[0]]) || 0); }, 0);
    var byKey = {};
    OVERVIEW.forEach(function (o) { byKey[o[0]] = o; });
    var aria = total + " activities: " + OVERVIEW.map(function (o) { return (Number(c[o[0]]) || 0) + " " + o[1].toLowerCase(); }).join(", ");
    reports = Number(reports) || 0;
    box.innerHTML =
        '<div class="ov-box donut-card">'
      +   '<div class="donut" role="img" aria-label="' + esc(aria) + '"><svg viewBox="0 0 42 42" aria-hidden="true">' + donutSvg(c) + "</svg>"
      +     '<div class="donut-mid"><strong>' + total + "</strong><span>Activities</span></div></div>"
      +   '<div class="legend"><h3>' + esc(title || "Activities by status") + "</h3>"
      +     "<p>On " + reports + " report" + (reports === 1 ? "" : "s") + "</p><ul>"
      +     OVERVIEW.map(function (o) {
              return '<li><i class="key" style="background:' + o[2] + '"></i>' + o[1] + "<b>" + (Number(c[o[0]]) || 0) + "</b></li>";
            }).join("")
      +   "</ul></div>"
      + "</div>"
      + '<div class="stat-grid">'
      +   TILE_ORDER.map(function (k) {
            var o = byKey[k];
            return '<div class="ov-box ov-stat"><span class="stat-label"><i class="key" style="background:' + o[2] + '"></i>' + o[1]
              + "</span><strong>" + (Number(c[k]) || 0) + "</strong><small>" + o[3] + "</small></div>";
          }).join("")
      + "</div>";
    box.hidden = false;
  }

  /** Activities counted by status over a list of reports that carry activity_counts. */
  function activityCounts(reports) {
    var c = { todo: 0, in_progress: 0, follow_up: 0, done: 0 };
    (reports || []).forEach(function (r) {
      var a = r.activity_counts || {};
      Object.keys(c).forEach(function (k) { c[k] += Number(a[k]) || 0; });
    });
    return c;
  }

  async function reply(res) {
    if (res.status === 401) {
      logout();
      throw new Error("signed out");
    }
    var data = null;
    try { data = await res.json(); } catch (e) { /* below */ }
    if (!data) throw new Error("bad reply");
    if (typeof data.today === "string" && /^\d{4}-\d{2}-\d{2}$/.test(data.today)) serverToday = data.today;
    return data;
  }

  function get(action, params) {
    var query = new URLSearchParams(Object.assign({ action: action }, params || {}));
    return fetch("api/activity-reports.php?" + query, {
      headers: { "Authorization": "Bearer " + token }
    }).then(reply);
  }

  /** JSON for review; FormData (the report and its files) for save. */
  function post(body) {
    var isForm = typeof FormData !== "undefined" && body instanceof FormData;
    return fetch("api/activity-report-writes.php", {
      method: "POST",
      headers: isForm
        ? { "Authorization": "Bearer " + token }
        : { "Authorization": "Bearer " + token, "Content-Type": "application/json" },
      body: isForm ? body : JSON.stringify(body)
    }).then(reply);
  }

  /*
   * A report file, downloaded with the session in the Authorization header
   * (api/attachment.php) and saved as a blob - the token never goes in a URL,
   * where history, logs and the Referer would keep it.
   *   DR.download(id, name).catch(() => showError("Could not download it."));
   */
  function download(id, name) {
    return window.UI.download("api/attachment.php?id=" + encodeURIComponent(id), name).catch(function (err) {
      if (err && err.status === 401) logout();
      throw err;
    });
  }

  /*
   * A short message in the corner, read out by screen readers - in place of
   * the browser's alert(), which blocks the page and can't be styled.
   *   DR.notice("Report deleted.");  DR.notice("Could not delete it.", "error");
   * DR.flash(message) keeps one for the next page; DR.takeFlash() reads it once.
   */
  var noticeEl = null, noticeTimer = 0;

  function notice(message, kind) {
    if (!message) return;
    if (!noticeEl) {
      noticeEl = document.createElement("div");
      noticeEl.className = "dr-notice";
      noticeEl.setAttribute("role", "status");
      noticeEl.setAttribute("aria-live", "polite");
      document.body.appendChild(noticeEl);
    }
    noticeEl.textContent = message;
    noticeEl.classList.toggle("is-error", kind === "error");
    noticeEl.classList.add("show");
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(function () { noticeEl.classList.remove("show"); }, kind === "error" ? 8000 : 5000);
  }

  function flash(message) {
    try { sessionStorage.setItem("dr_flash", String(message || "")); } catch (e) { /* private mode: no message */ }
  }

  function takeFlash() {
    try {
      var m = sessionStorage.getItem("dr_flash") || "";
      sessionStorage.removeItem("dr_flash");
      return m;
    } catch (e) { return ""; }
  }

  /* The ⋮ menu on a table row: assets/row-menu.js. */
  var rowMenu = window.RowMenu.open;
  function kebab(label, onclick) { return window.RowMenu.kebab(label, onclick); }

  window.DR = {
    rowMenu: rowMenu,
    kebab: kebab,
    token: token,
    user: user,
    logout: logout,
    esc: esc,
    can: can,
    download: download,
    notice: notice,
    flash: flash,
    takeFlash: takeFlash,
    todayIso: todayIso,
    dayLabel: dayLabel,
    timeLabel: timeLabel,
    dateTimeLabel: dateTimeLabel,
    fileSize: fileSize,
    ROLE_LABELS: ROLE_LABELS,
    ACTIVITY_STATUS: ACTIVITY_STATUS,
    REPORT_STATUS: REPORT_STATUS,
    reportPill: reportPill,
    sheetPill: sheetPill,
    countsLabel: countsLabel,
    planner: planner,
    activityPill: activityPill,
    progress: progress,
    overview: overview,
    activityCounts: activityCounts,
    whenLabel: whenLabel,
    reviewState: reviewState,
    reviewPill: reviewPill,
    reviewLine: reviewLine,
    reviewHistory: reviewHistory,
    get: get,
    post: post,
    // Reports and their files: the same limits as the server.
    MAX_FILES: 5,
    MAX_FILE_BYTES: 8 * 1024 * 1024,
    FILE_TYPES: ["pdf", "txt", "md", "doc", "docx", "xls", "xlsx", "jpg", "jpeg", "png", "webp"],
    MAX_ACTIVITIES: 30
  };

  // Same name as the other admin pages, for the account popup's Log out.
  window.logoutAdmin = logout;
})();
