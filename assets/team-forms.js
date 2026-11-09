/* Team forms - helpers the three team form pages share. Talks to
   api/team-forms.php with the admin's session token. */
(function () {
  const token = localStorage.getItem("admin_token");

  window.tfEsc = function (value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  };

  window.tfDate = function (value) {
    if (!value) return "";
    const d = new Date(String(value).replace(" ", "T"));
    return isNaN(d) ? "" : d.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
  };

  window.tfStatus = function (el, message, kind) {
    el.textContent = message || "";
    el.className = "tf-status" + (kind ? " " + kind : "");
  };

  function signedOut() {
    localStorage.removeItem("admin_token");
    localStorage.removeItem("admin_user");
    window.location.href = "admin-login.html";
  }

  /** GET with ?query, or POST a JSON body (or FormData). Resolves to the JSON reply. */
  window.tfApi = async function (query, body) {
    const opts = { headers: { Authorization: "Bearer " + token } };
    if (body instanceof FormData) {
      opts.method = "POST";
      opts.body = body;
    } else if (body) {
      opts.method = "POST";
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }
    const res = await fetch("api/team-forms.php" + (query ? "?" + query : ""), opts);
    if (res.status === 401) signedOut();
    const data = await res.json().catch(() => ({ success: false, message: "Something went wrong." }));
    data.httpStatus = res.status;
    return data;
  };

  /** Downloads a file the endpoint serves behind the session token. */
  window.tfDownload = async function (query, fallbackName) {
    const res = await fetch("api/team-forms.php?" + query, { headers: { Authorization: "Bearer " + token } });
    if (res.status === 401) return signedOut();
    if (!res.ok) throw new Error("download failed");
    const blob = await res.blob();
    const cd = res.headers.get("Content-Disposition") || "";
    const m = cd.match(/filename="([^"]+)"/);
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = m ? m[1] : fallbackName;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  };

  /** The one status badge a recipient's row or a form row shows. */
  window.tfBadge = function (status, overdue, formStatus) {
    if (status === "submitted") return '<span class="tf-badge done">Answered</span>';
    if (formStatus === "closed") return '<span class="tf-badge closed">Closed</span>';
    if (overdue) return '<span class="tf-badge overdue">Overdue</span>';
    return '<span class="tf-badge pending">Waiting</span>';
  };

  window.tfLogout = signedOut;

  /* ── Answers, read back ─────────────────────────────────────────────── */

  window.tfAnswersHtml = function (answers) {
    if (!answers.length) return '<p class="tf-meta">No answers.</p>';
    return '<dl class="tf-answers">' + answers.map(function (a) {
      var dd = a.file_id
        ? '<dd><button class="tf-file-link" type="button" data-file="' + Number(a.file_id) + '" data-name="' + tfEsc(a.answer) + '">' + tfEsc(a.answer) + '</button></dd>'
        : (a.answer ? '<dd>' + tfEsc(a.answer) + '</dd>' : '<dd class="empty">Nothing ticked</dd>');
      return '<div class="ans"><dt>' + tfEsc(a.question) + '</dt>' + dd + '</div>';
    }).join("") + '</dl>';
  };

  /* ── Filling one in ─────────────────────────────────────────────────── */

  /** The questions as form fields, inside <form class="tf-fill" data-form="id">. */
  window.tfFillHtml = function (form) {
    return '<form class="tf-fill" data-form="' + Number(form.id) + '" novalidate>' + form.questions.map(function (q) {
      var id = "tfq" + q.id;
      var req = q.required ? '<span class="req" aria-hidden="true">*</span>' : "";
      var field;
      if (q.type === "textarea") {
        field = '<textarea class="tf-textarea" id="' + id + '" ' + (q.required ? "required" : "") + ' maxlength="10000"></textarea>';
      } else if (q.type === "checkbox") {
        field = '<div class="q-opts" role="group" aria-labelledby="' + id + '-l">' + q.options.map(function (o) {
          return '<label class="q-opt"><input type="checkbox" value="' + tfEsc(o) + '"> ' + tfEsc(o) + '</label>';
        }).join("") + '</div>';
      } else if (q.type === "file") {
        field = '<input type="file" id="' + id + '" ' + (q.required ? "required" : "") + '><div class="tf-hint">Up to ' + Number(q.max_file_size_mb || 5) + ' MB.</div>';
      } else {
        field = '<input class="tf-input" type="text" id="' + id + '" ' + (q.required ? "required" : "") + ' maxlength="10000">';
      }
      return '<div class="q" data-q="' + q.id + '" data-type="' + q.type + '" data-required="' + (q.required ? 1 : 0) + '" data-max="' + Number(q.max_file_size_mb || 5) + '">' +
        '<label class="q-label" id="' + id + '-l"' + (q.type === "checkbox" ? "" : ' for="' + id + '"') + '>' + tfEsc(q.text) + req + '</label>' +
        field + '<div class="q-err" id="' + id + '-e"></div></div>';
    }).join("") +
      '<button class="tf-btn block" type="submit" style="margin-top:18px">Send my answers</button>' +
      '<div class="tf-status" role="alert"></div></form>';
  };

  /** Marks what's missing; focuses the first. True when it can be sent. */
  window.tfValidate = function (fill) {
    var firstBad = null;
    fill.querySelectorAll(".q").forEach(function (el) {
      var type = el.dataset.type;
      var err = el.querySelector(".q-err");
      var msg = "";
      if (type === "file") {
        var f = el.querySelector("input[type=file]").files[0];
        if (el.dataset.required === "1" && !f) msg = "Attach a file.";
        else if (f && f.size > Number(el.dataset.max) * 1024 * 1024) msg = "That file is over " + el.dataset.max + " MB.";
      } else if (type !== "checkbox") {
        if (el.dataset.required === "1" && !el.querySelector(".tf-input, .tf-textarea").value.trim()) msg = "This one needs an answer.";
      }
      el.classList.toggle("invalid", !!msg);
      err.textContent = msg;
      var input = el.querySelector("input, textarea");
      if (input && type !== "checkbox") {
        input.setAttribute("aria-invalid", msg ? "true" : "false");
        input.setAttribute("aria-describedby", err.id);
      }
      if (msg && !firstBad) firstBad = input;
    });
    if (firstBad) firstBad.focus();
    return !firstBad;
  };

  /** Validates and sends a .tf-fill form. Resolves to the updated form, or null. */
  window.tfSubmit = async function (fill) {
    var status = fill.querySelector(".tf-status");
    if (!tfValidate(fill)) {
      tfStatus(status, "Some answers are missing - they're marked above.", "error");
      return null;
    }
    var answers = {};
    var data = new FormData();
    data.append("action", "submit");
    data.append("form_id", fill.dataset.form);
    fill.querySelectorAll(".q").forEach(function (el) {
      var id = el.dataset.q;
      if (el.dataset.type === "checkbox") {
        answers[id] = [].slice.call(el.querySelectorAll("input:checked")).map(function (i) { return i.value; }).join(", ");
      } else if (el.dataset.type === "file") {
        var f = el.querySelector("input[type=file]").files[0];
        if (f) data.append("files[" + id + "]", f);
      } else {
        answers[id] = el.querySelector(".tf-input, .tf-textarea").value.trim();
      }
    });
    data.append("answers", JSON.stringify(answers));

    var button = fill.querySelector("button[type=submit]");
    button.disabled = true;
    tfStatus(status, "Sending...");
    try {
      var res = await tfApi("", data);
      if (!res.success) {
        tfStatus(status, res.message || "Your answers didn't go through.", "error");
        button.disabled = false;
        return null;
      }
      return res.form;
    } catch (e) {
      tfStatus(status, "Connection error. Your answers are still here - try again.", "error");
      button.disabled = false;
      return null;
    }
  };

  /* ── Send to: whole team, roles, people ─────────────────────────────────
     tfSendTo(container, onChange) draws the picker (the announcement form's,
     plus By role) and loads the team. It returns:
       count()    how many it reaches now - the same union the server works out
       payload()  {scope, roles, recipient_ids} for api/team-forms.php create */

  window.tfSendTo = function (box, onChange) {
    var scope = "all", people = [], roles = [], pickedRoles = [], pickedIds = [], peopleToo = false;
    var uid = "ts" + Math.random().toString(36).slice(2, 7);
    var CHEVRON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
    var GROUP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';

    box.classList.add("tf-sendto");
    box.innerHTML =
      '<div class="radio-row" role="radiogroup" aria-label="Send to">' +
        '<label class="radio"><input type="radio" name="' + uid + '" value="all" checked><span>Whole team</span></label>' +
        '<label class="radio"><input type="radio" name="' + uid + '" value="roles"><span>By role</span></label>' +
        '<label class="radio"><input type="radio" name="' + uid + '" value="specific"><span>Specific people</span></label>' +
      '</div>' +
      '<div data-part="roles" hidden><div class="role-grid"></div>' +
        '<button class="link-btn" type="button" data-part="more">Add specific people too</button></div>' +
      '<div data-part="people" hidden>' +
        '<div class="picker"><button class="picker-toggle" type="button" aria-expanded="false"><span data-part="label">Select people</span>' + CHEVRON + '</button>' +
        '<div class="picker-panel"><div class="picker-search"><input class="tf-input" type="text" placeholder="Search by name or email" aria-label="Search people"></div>' +
        '<div class="picker-list"></div></div></div>' +
        '<div class="pills"></div></div>' +
      '<div class="reach" aria-live="polite"></div>';

    var $ = function (sel) { return box.querySelector(sel); };
    var picker = $(".picker");

    function usesPeople() { return scope === "specific" || (scope === "roles" && peopleToo); }
    function ids() {
      if (scope === "all") return people.map(function (p) { return p.id; });
      var set = {};
      if (scope === "roles") people.forEach(function (p) { if (pickedRoles.indexOf(p.role) !== -1) set[p.id] = 1; });
      if (usesPeople()) pickedIds.forEach(function (id) { set[id] = 1; });
      return Object.keys(set).map(Number);
    }
    function changed() {
      var n = ids().length;
      var reach = $(".reach");
      reach.className = "reach" + (n ? "" : " none");
      reach.innerHTML = GROUP + (n ? "Goes to " + n + (n === 1 ? " person" : " people") : "Nobody picked yet");
      $('[data-part="label"]').textContent = pickedIds.length ? pickedIds.length + " selected" : "Select people";
      $(".pills").innerHTML = !usesPeople() ? "" : pickedIds.map(function (id) {
        var p = people.filter(function (x) { return x.id === id; })[0];
        return p ? '<span class="pill">' + tfEsc(p.name) + '<button type="button" data-unpick="' + id + '" aria-label="Remove ' + tfEsc(p.name) + '">&times;</button></span>' : "";
      }).join("");
      if (onChange) onChange(n);
    }
    function closePicker() { picker.classList.remove("open"); $(".picker-toggle").setAttribute("aria-expanded", "false"); }
    function show() {
      $('[data-part="roles"]').hidden = scope !== "roles";
      $('[data-part="people"]').hidden = !usesPeople();
      $('[data-part="more"]').hidden = peopleToo;
      closePicker();
      changed();
    }
    function renderRoles() {
      $(".role-grid").innerHTML = roles.map(function (r) {
        return '<label class="tf-chip-check"><input type="checkbox" value="' + tfEsc(r.role) + '"' +
          (pickedRoles.indexOf(r.role) !== -1 ? " checked" : "") + (r.count ? "" : " disabled") + '> ' +
          tfEsc(r.name) + ' <em>' + r.count + (r.count === 1 ? " person" : " people") + '</em></label>';
      }).join("");
    }
    function renderPicker() {
      var term = $(".picker-search input").value.trim().toLowerCase();
      var visible = people.filter(function (p) { return !term || p.name.toLowerCase().indexOf(term) !== -1 || (p.email || "").toLowerCase().indexOf(term) !== -1; });
      $(".picker-list").innerHTML = !visible.length
        ? '<div class="picker-empty">' + (people.length ? "Nobody matches that search." : "There's nobody else on the team yet.") + '</div>'
        : roles.map(function (r) {
          var members = visible.filter(function (p) { return p.role === r.role; });
          if (!members.length) return "";
          return '<div class="picker-group">' + tfEsc(r.name) + '</div>' + members.map(function (p) {
            return '<label class="picker-item"><input type="checkbox" data-pick="' + p.id + '"' + (pickedIds.indexOf(p.id) !== -1 ? " checked" : "") + '>' +
              '<span><strong>' + tfEsc(p.name) + '</strong><span>' + tfEsc(p.email) + '</span></span></label>';
          }).join("");
        }).join("");
    }

    box.addEventListener("change", function (e) {
      var t = e.target;
      if (t.type === "radio") { scope = t.value; show(); return; }
      if (t.closest(".role-grid")) {
        pickedRoles = t.checked ? pickedRoles.concat(t.value) : pickedRoles.filter(function (r) { return r !== t.value; });
        changed();
      }
      if (t.dataset.pick) {
        var id = Number(t.dataset.pick);
        pickedIds = t.checked ? pickedIds.concat(id) : pickedIds.filter(function (x) { return x !== id; });
        changed();
      }
    });
    box.addEventListener("input", function (e) { if (e.target.closest(".picker-search")) renderPicker(); });
    box.addEventListener("click", function (e) {
      if (e.target.closest(".picker-toggle")) {
        var open = picker.classList.toggle("open");
        $(".picker-toggle").setAttribute("aria-expanded", open);
        if (open) $(".picker-search input").focus();
      }
      if (e.target.closest('[data-part="more"]')) { peopleToo = true; show(); $(".picker-toggle").focus(); }
      var un = e.target.closest("[data-unpick]");
      if (un) { var id = Number(un.dataset.unpick); pickedIds = pickedIds.filter(function (x) { return x !== id; }); renderPicker(); changed(); }
    });
    document.addEventListener("click", function (e) { if (!picker.contains(e.target)) closePicker(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") closePicker(); });

    var api = {
      count: function () { return ids().length; },
      payload: function () {
        return { scope: scope, roles: scope === "roles" ? pickedRoles.slice() : [], recipient_ids: usesPeople() ? pickedIds.slice() : [] };
      },
      ready: tfApi("action=recipients").then(function (res) {
        if (!res.success) throw new Error(res.message || "Could not load the team.");
        people = res.people;
        roles = res.roles;
        renderRoles();
        renderPicker();
        changed();
      })
    };
    return api;
  };
})();
