/*
|------------------------------------------------------------------------------
| Inquiries: the helpers all four pages of the feature need
|------------------------------------------------------------------------------
|
| The list, the detail view, the form and the responses table all mint links,
| print the same dates, guess the same names out of the same answers and show
| the same toast. One copy, on window.WZI.
|
|   <script src="assets/inquiries.js?v=1"></script>
|
| Load it before the page's own inline script and after nothing in particular -
| it touches the DOM only when something calls it.
|
*/

window.WZI = (function () {
  "use strict";

  function adminToken() {
    return localStorage.getItem("admin_token") || "";
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }

  /* ── Dates ───────────────────────────────────────────────────────────────
     MySQL hands back "2026-09-08 18:53:00". Safari refuses that string and
     every browser is free to guess at the timezone, so it is taken apart by
     hand and read as local time - which is what it is. */

  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
                "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

  function parse(value) {
    if (!value) return null;

    var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return null;

    return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0));
  }

  function fmtDate(value) {
    var d = parse(value);
    if (!d) return "—";
    return MONTHS[d.getMonth()] + " " + d.getDate() + ", " + d.getFullYear();
  }

  function fmtTime(value) {
    var d = parse(value);
    if (!d) return "";

    var h = d.getHours();
    var suffix = h >= 12 ? "PM" : "AM";
    h = h % 12 || 12;

    // Padded, so a column of times lines up: 06:53PM over 11:20AM.
    return String(h).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0") + suffix;
  }

  /* The list column prints the date as it is stored - 2026-09-08 - so a column
     of them sorts by eye. Everywhere prose is involved uses fmtDate instead. */
  function fmtDateISO(value) {
    var d = parse(value);
    if (!d) return "—";

    return d.getFullYear()
      + "-" + String(d.getMonth() + 1).padStart(2, "0")
      + "-" + String(d.getDate()).padStart(2, "0");
  }

  function fmtDateTime(value) {
    var time = fmtTime(value);
    return time ? fmtDate(value) + " · " + time : fmtDate(value);
  }

  /* ── People ──────────────────────────────────────────────────────────────
     A response has no name column - only the answers - so who sent it can
     only ever be a guess. The first field that looks like a person's name
     wins; anything company-shaped becomes the second line. When neither
     exists the lead is numbered, which is honest about not knowing. */

  function identify(fields, answers, index) {
    var nameField = null;
    var subField = null;

    (fields || []).forEach(function (field) {
      var label = String(field.field_label || "");
      var isCompany = /business|company|organi[sz]ation/i.test(label);

      if (!subField && isCompany) subField = field;
      if (!nameField && !isCompany && /name/i.test(label)) nameField = field;
    });

    var name = nameField ? String((answers || {})[nameField.id] || "").trim() : "";
    var sub = subField ? String((answers || {})[subField.id] || "").trim() : "";

    if (!name) name = "Lead " + (Number(index) + 1);

    return { name: name, sub: sub, initials: initials(name) };
  }

  function initials(name) {
    var parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return "?";
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  return {
    adminToken: adminToken,
    escapeHtml: escapeHtml,
    fmtDate: fmtDate,
    fmtDateISO: fmtDateISO,
    fmtTime: fmtTime,
    fmtDateTime: fmtDateTime,
    identify: identify,
    initials: initials
  };
})();

/*
| Links, clipboard, toast and the row menus. A second block rather than one
| long one, because everything above is pure - it takes values and returns
| values - while everything below talks to the network, the clipboard or the
| page.
*/
Object.assign(window.WZI, (function () {
  "use strict";

  /* ── The public link ─────────────────────────────────────────────────────
     One link per inquiry, built from its name. It is not single-use: whoever
     holds it can open the form for as long as the inquiry is active, which is
     why the Status control is the only thing that closes it.

     Built against wherever the admin page is served from, so it works the same
     on localhost and on the deployed host. */

  function publicLink(name) {
    var base = window.location.origin
      + window.location.pathname.replace(/[^/]*$/, "")
      + "inquiry.html";

    return base + "?name=" + encodeURIComponent(name || "");
  }

  /* navigator.clipboard is unavailable on a plain-http host, which is exactly
     where this gets tested, so the old textarea trick stays as the fallback. */
  async function copy(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (error) {
      /* Falls through to the textarea below. */
    }

    var box = document.createElement("textarea");
    box.value = text;
    box.setAttribute("readonly", "");
    box.style.position = "fixed";
    box.style.opacity = "0";
    document.body.appendChild(box);
    box.select();

    var ok = false;
    try { ok = document.execCommand("copy"); } catch (error) { ok = false; }

    document.body.removeChild(box);
    return ok;
  }

  // Copy the inquiry's link, and say so. The whole Copy Link button.
  async function copyLink(name) {
    var link = publicLink(name);
    var ok = await copy(link);

    toast(ok
      ? "Link copied. Anyone with it can answer while this inquiry is active."
      : "Copying was blocked - the link is in the box above.",
      !ok);

    return ok ? link : null;
  }

  /* ── Toast ─────────────────────────────────────────────────────────────── */

  var toastTimer = null;

  function toast(message, bad) {
    var el = document.getElementById("toast");

    // Pages that forgot the element still get their message.
    if (!el) {
      el = document.createElement("div");
      el.className = "toast";
      el.id = "toast";
      el.setAttribute("role", "status");
      el.setAttribute("aria-live", "polite");
      document.body.appendChild(el);
    }

    el.textContent = message;
    el.classList.toggle("bad", !!bad);
    el.classList.add("show");

    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove("show"); }, 3200);
  }

  /* ── Row menus ───────────────────────────────────────────────────────────
     Re-bound on every render because the rows are replaced wholesale, and
     opening one closes any other - two menus open at once is never what was
     meant. */

  function closeKebabs() {
    document.querySelectorAll(".kebab-wrap.open").forEach(function (wrap) {
      wrap.classList.remove("open");
      wrap.querySelector(".kebab").setAttribute("aria-expanded", "false");
    });
  }

  function wireKebabs() {
    document.querySelectorAll(".kebab-wrap").forEach(function (wrap) {
      wrap.querySelector(".kebab").addEventListener("click", function (event) {
        event.stopPropagation();

        var open = wrap.classList.contains("open");
        closeKebabs();

        if (!open) {
          wrap.classList.add("open");
          this.setAttribute("aria-expanded", "true");
        }
      });
    });
  }

  document.addEventListener("click", closeKebabs);
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeKebabs();
  });

  return {
    publicLink: publicLink,
    copy: copy,
    copyLink: copyLink,
    toast: toast,
    wireKebabs: wireKebabs,
    closeKebabs: closeKebabs
  };
})());

/*
| What an answer is, and how to show it
|------------------------------------------------------------------------------
|
| Inquiry answers are stored as plain text - the form has no field types beyond
| short and long - but an email is still an email. Recognising the few that are
| worth acting on turns a page of transcript into a page you can work from:
| tap the number, tap the address.
|
| Deliberately conservative. A wrong guess here puts a dead link in front of
| someone, which is worse than plain text, so the shape of the value has to
| agree with the question being asked.
*/
Object.assign(window.WZI, (function () {
  "use strict";

  var WZI = window.WZI;

  function answerKind(label, value) {
    var text = String(value === null || value === undefined ? "" : value).trim();
    var question = String(label || "");

    if (!text) return "empty";

    /* Strict enough that "mailto:someone@example.com" is not read as an
       address - it would have produced href="mailto:mailto:...". */
    if (/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/.test(text)) return "email";
    if (/^https?:\/\/\S+$/i.test(text)) return "url";

    /* A phone number is decided by the question as well as the answer: "11728"
       is a post code, and on its own would pass any digits-only test. */
    if (/phone|mobile|tel\b|whats ?app/i.test(question)
      && /^[0-9+][0-9+()\-.\s]{5,24}$/.test(text)) {
      return "phone";
    }

    return "text";
  }

  // The value, ready to drop into a cell - a link where that helps, the words
  // themselves where it does not, and a visible nothing where there is nothing.
  function answerHtml(label, value) {
    var text = String(value === null || value === undefined ? "" : value).trim();
    var kind = answerKind(label, text);
    var safe = WZI.escapeHtml(text);

    if (kind === "empty") return '<span class="f-empty">Not answered</span>';

    if (kind === "email") {
      return '<a class="f-link" href="mailto:' + WZI.escapeHtml(encodeURI(text)) + '">' + safe + '</a>';
    }

    if (kind === "phone") {
      return '<a class="f-link" href="tel:' + WZI.escapeHtml(text.replace(/[^0-9+]/g, "")) + '">' + safe + '</a>';
    }

    // Only ever http(s) - answerKind refuses anything else, so no scheme can
    // be smuggled into the href.
    if (kind === "url") {
      return '<a class="f-link" href="' + safe + '" target="_blank" rel="noopener noreferrer">' + safe + '</a>';
    }

    return safe;
  }

  // The first answer of a kind, so the page can offer "Email" and "Call"
  // buttons without knowing what the questions were called.
  function findAnswer(fields, answers, kind) {
    var hit = null;

    (fields || []).forEach(function (field) {
      if (hit) return;

      var value = String((answers || {})[field.id] || "").trim();
      if (answerKind(field.field_label, value) === kind) hit = value;
    });

    return hit;
  }

  return {
    answerKind: answerKind,
    answerHtml: answerHtml,
    findAnswer: findAnswer
  };
})());
