/*
 * Demo mode for the pages in demo/.
 *
 * Every page here is a copy of the real one (see build-demo.js), so it calls
 * the same api/*.php endpoints. This file loads first and answers those calls
 * from the sample data below instead, so the pages open from disk or any
 * static server with no PHP, database or login.
 *
 * - fetch() is replaced for api/ URLs only.
 * - The session keys (portal_token, admin_token, ...) are served from memory,
 *   so opening a demo never signs you out of, or into, the real portal on
 *   the same origin.
 * - What you change (accepting a meeting, submitting a form, reading a
 *   notification) is kept in sessionStorage, so it carries across the demo
 *   pages until the tab closes. "Reset demo" in the ribbon starts over.
 */
(function () {
  "use strict";

  /* ── Pages that exist in the demo; links to anything else are disabled ── */
  var DEMO_PAGES = [
    "index.html", "dashboard.html", "forms.html", "survey.html",
    "content.html", "notifications.html", "admin-form-builder.html"
  ];

  /* ── Session keys, kept off the real localStorage ── */
  var session = {
    portal_token: "demo",
    portal_user: JSON.stringify({
      id: 1, name: "Jane Cooper", email: "jane@acme.example",
      company_name: "Acme Media", profile_image: ""
    }),
    admin_token: "demo",
    admin_user: JSON.stringify({ id: 1, name: "Sarah Miller", role: "owner" })
  };

  var proto = Storage.prototype;
  var realGet = proto.getItem, realSet = proto.setItem,
      realRemove = proto.removeItem, realClear = proto.clear;

  function isLocal(store) {
    try { return store === window.localStorage; } catch (e) { return false; }
  }

  proto.getItem = function (key) {
    if (isLocal(this) && Object.prototype.hasOwnProperty.call(session, key)) return session[key];
    return realGet.call(this, key);
  };
  proto.setItem = function (key, value) {
    if (isLocal(this) && Object.prototype.hasOwnProperty.call(session, key)) { session[key] = String(value); return; }
    return realSet.call(this, key, value);
  };
  proto.removeItem = function (key) {
    if (isLocal(this) && Object.prototype.hasOwnProperty.call(session, key)) return;
    return realRemove.call(this, key);
  };
  proto.clear = function () {
    if (isLocal(this)) return; // logout() calls this; the demo never wipes real storage
    return realClear.call(this);
  };

  /* ── Dates relative to today, so the calendar always has something on ── */
  function pad(n) { return String(n).padStart(2, "0"); }
  function day(offset) {
    var d = new Date();
    d.setDate(d.getDate() + offset);
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  }
  function stamp(offset, time) { return day(offset) + " " + (time || "10:00:00"); }
  function epoch(offsetSeconds) { return Math.round(Date.now() / 1000) - offsetSeconds; }

  /* Placeholder artwork: an inline SVG, so nothing is fetched. */
  function art(label, from, to) {
    var svg =
      "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 600 600'>" +
      "<defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'>" +
      "<stop offset='0' stop-color='" + from + "'/><stop offset='1' stop-color='" + to + "'/>" +
      "</linearGradient></defs><rect width='600' height='600' fill='url(#g)'/>" +
      "<text x='50%' y='52%' text-anchor='middle' font-family='Arial' font-size='44' " +
      "font-weight='700' fill='white' opacity='.92'>" + label + "</text></svg>";
    // Pages drop this into url('...'), so a bare ' would end it early.
    return "data:image/svg+xml;charset=utf-8," +
      encodeURIComponent(svg).replace(/'/g, "%27");
  }

  /* ── Sample data ─────────────────────────────────────────────────────── */

  function seed() {
    return {
      appointments: [
        { id: 1, title: "AI Research Interview", topic: "AI Research Interview",
          notes: "Bring the Q3 deck.", date: day(0), time: "10:30:00",
          status: "pending", requested_by: "admin", admin_name: "Sarah Miller" },
        { id: 2, title: "Lead Gen Survey Review", topic: "Lead Gen Survey Review",
          notes: "", date: day(2), time: "15:00:00",
          status: "pending", requested_by: "admin", admin_name: "Rob Lee" },
        { id: 3, title: "Brand voice workshop", topic: "Brand voice workshop",
          notes: "Two hours, with the copy team.", date: day(4), time: "11:00:00",
          status: "pending", requested_by: "admin", admin_name: "Sarah Miller" },
        { id: 4, title: "مراجعة خطة المحتوى الشهرية", topic: "مراجعة خطة المحتوى الشهرية",
          notes: "مراجعة المنشورات القادمة قبل النشر.", date: day(5), time: "13:30:00",
          status: "pending", requested_by: "admin", admin_name: "Omar Haddad" },
        { id: 5, title: "Campaign results walkthrough", topic: "Campaign results walkthrough",
          notes: "", date: day(7), time: "09:00:00",
          status: "pending", requested_by: "admin", admin_name: "Rob Lee" },
        { id: 6, title: "Photo shoot planning", topic: "Photo shoot planning",
          notes: "Locations and shot list.", date: day(9), time: "14:00:00",
          status: "pending", requested_by: "admin", admin_name: "Sarah Miller" },
        { id: 7, title: "Quarterly strategy check-in with the whole leadership team",
          topic: "Quarterly strategy check-in with the whole leadership team",
          notes: "A long title on purpose, to show how rows cut it on a phone.",
          date: day(12), time: "16:00:00",
          status: "pending", requested_by: "admin", admin_name: "Rob Lee" },
        { id: 8, title: "Modern SaaS Architecture", topic: "Modern SaaS Architecture",
          notes: "Kick-off for the new build.", date: day(0), time: "09:30:00",
          status: "approved", requested_by: "admin", admin_name: "Sarah Miller" },
        { id: 9, title: "User Experience Trends", topic: "User Experience Trends",
          notes: "", date: day(1), time: "12:00:00",
          status: "approved", requested_by: "admin", admin_name: "Rob Lee" },
        { id: 10, title: "Feedback loop review", topic: "Feedback loop review",
          notes: "Walk through last month's responses.", date: day(6), time: "15:00:00",
          status: "approved", requested_by: "user" },
        { id: 11, title: "Internal Review: Q3 Roadmap", topic: "Internal Review: Q3 Roadmap",
          notes: "", date: day(3), time: "13:00:00",
          status: "pending", requested_by: "user" },
        { id: 12, title: "Content strategy", topic: "Content strategy",
          notes: "", date: day(-3), time: "11:00:00",
          status: "rejected", requested_by: "user" }
      ],

      surveys: [
        { id: 1, title: "Brand onboarding questionnaire", status: "pending", created_at: stamp(-1) },
        { id: 2, title: "استبيان رضا العملاء – الربع الثالث", status: "pending", created_at: stamp(-3) },
        { id: 3, title: "Website content brief", status: "pending", created_at: stamp(-6) },
        { id: 4, title: "Social media goals for next quarter", status: "completed", created_at: stamp(-9) },
        { id: 5, title: "Photo shoot preferences", status: "completed", created_at: stamp(-14) },
        { id: 6, title: "معلومات الحملة الإعلانية", status: "completed", created_at: stamp(-20) },
        { id: 7, title: "Event coverage checklist", status: "pending", created_at: stamp(-24) },
        { id: 8, title: "Annual review: what worked and what didn't", status: "completed", created_at: stamp(-40) }
      ],

      answers: {
        4: [
          { question_id: 101, question_label: "Company name as it should appear on posts", answer: "Acme Media" },
          { question_id: 102, question_label: "Describe your target audience",
            answer: "Marketing leads at mid-size companies, 28-45.\nThey read LinkedIn in the morning and want practical, short posts." },
          { question_id: 103, question_label: "What are the key messages you want to get across?",
            answer: "نريد التركيز على الجودة والسرعة.\nوأن نكون أقرب للعميل." },
          { question_id: 104, question_label: "Upload your logo (PNG or SVG)", answer: "acme-logo.svg" },
          { question_id: 105, question_label: "Which platforms should we cover?",
            answer: "✅ Instagram, ✅ LinkedIn" }
        ]
      },

      content: [
        { id: 1, title: "Five lessons from our spring campaign", platform: "LinkedIn",
          type_label: "Articles", content_type: "articles", orientation: "horizontal",
          media_path: art("Articles", "#1b1e3a", "#4b54a8"),
          caption: "<p>What moved the numbers this spring, and what we will do differently.</p>",
          link: "https://example.com/spring-campaign", created_at: stamp(-1, "09:15:00"),
          created_by: "Sarah Miller", publish_now: 1 },
        { id: 2, title: "Behind the scenes at the new studio", platform: "Instagram",
          type_label: "Photos", content_type: "photos", orientation: "vertical",
          media_path: art("Photos", "#c9000b", "#f2797f"),
          caption: "<p>A first look at the space where next month's shoot happens.</p>",
          link: "", created_at: stamp(-2, "17:40:00"), created_by: "Rob Lee", publish_now: 1 },
        { id: 3, title: "إطلاق حملة الصيف", platform: "X",
          type_label: "Campaign", content_type: "campaign", orientation: "horizontal",
          media_path: art("Campaign", "#17803d", "#6fd39a"),
          caption: "<p>حملة الصيف تبدأ الأسبوع القادم على جميع المنصات.</p>",
          link: "", created_at: stamp(-4, "12:00:00"), created_by: "Omar Haddad", publish_now: 1 },
        { id: 4, title: "Quarterly performance report", platform: "LinkedIn",
          type_label: "Reports", content_type: "reports", orientation: "horizontal",
          media_path: art("Reports", "#b45309", "#f5c26b"),
          caption: "<p>Reach up 34%, engagement up 12%.</p>",
          link: "", created_at: stamp(-8, "10:30:00"), created_by: "Sarah Miller", publish_now: 1 },
        { id: 5, title: "Product teaser: 15 seconds", platform: "TikTok",
          type_label: "Videos", content_type: "videos", orientation: "vertical",
          media_path: art("Videos", "#3745a4", "#9aa6ff"),
          caption: "<p>The short cut for TikTok and Reels.</p>",
          link: "", created_at: stamp(-12, "15:00:00"), created_by: "Rob Lee", publish_now: 1 },
        { id: 6, title: "Team training recap", platform: "LinkedIn",
          type_label: "Training", content_type: "training", orientation: "horizontal",
          media_path: art("Training", "#475467", "#98a2b3"),
          caption: "<p>Notes from last week's workshop.</p>",
          link: "", created_at: stamp(-20, "11:00:00"), created_by: "Sarah Miller", publish_now: 1 },
        { id: 7, title: "Event highlights: London meetup", platform: "Instagram",
          type_label: "Events", content_type: "events", orientation: "horizontal",
          media_path: art("Events", "#5b3cc4", "#c3b1ff"),
          caption: "<p>Thanks to everyone who came.</p>",
          link: "", created_at: stamp(-30, "19:00:00"), created_by: "Omar Haddad", publish_now: 1 }
      ],

      notifications: [
        { id: 9, title: "New content published", body: "Five lessons from our spring campaign",
          link: "content.html?id=1", read: false, created_ts: epoch(60 * 40) },
        { id: 8, title: "A new form is ready for you", body: "Brand onboarding questionnaire is waiting to be filled in.",
          link: "survey.html?id=1", read: false, created_ts: epoch(3600 * 20) },
        { id: 7, title: "Office closed on Friday", body: "The team is away for the company retreat.",
          link: "", read: false, created_ts: epoch(3600 * 30), announcement_id: 1 },
        { id: 6, title: "New meeting request", body: "Sarah Miller asked for time: AI Research Interview.",
          link: "dashboard.html", read: true, created_ts: epoch(86400 * 2) },
        { id: 5, title: "New content published", body: "Behind the scenes at the new studio",
          link: "content.html?id=2", read: true, created_ts: epoch(86400 * 2 + 3600) },
        { id: 4, title: "A new form is ready for you", body: "استبيان رضا العملاء – الربع الثالث is waiting to be filled in.",
          link: "survey.html?id=2", read: true, created_ts: epoch(86400 * 3) },
        { id: 3, title: "Your meeting was confirmed", body: "Feedback loop review is in the calendar.",
          link: "dashboard.html", read: true, created_ts: epoch(86400 * 5) },
        { id: 2, title: "New content published", body: "إطلاق حملة الصيف",
          link: "content.html?id=3", read: true, created_ts: epoch(86400 * 6) }
      ],

      clients: [
        { id: 1, name: "Jane Cooper", company_name: "Acme Media" },
        { id: 2, name: "Ahmed Saleh", company_name: "شركة النخبة" },
        { id: 3, name: "Lena Fischer", company_name: "Northwind Studio" }
      ]
    };
  }

  /* The questions every demo form uses: one of each field type. */
  var QUESTIONS = [
    { id: 101, question_text: "Company name as it should appear on posts", question_type: "input",
      required: 1, sort_order: 1, chips: JSON.stringify(["Acme Media", "ACME"]) },
    { id: 102, question_text: "Describe your target audience", question_type: "textarea",
      required: 1, sort_order: 2, chips: JSON.stringify(["Decision makers", "Young professionals"]) },
    { id: 103, question_text: "What are the key messages you want to get across?", question_type: "textarea",
      required: 0, sort_order: 3, chips: "" },
    { id: 104, question_text: "Upload your logo (PNG or SVG)", question_type: "file",
      required: 0, sort_order: 4, chips: "", max_file_size_mb: 5 },
    { id: 105, question_text: "Which platforms should we cover?", question_type: "checkbox",
      required: 0, sort_order: 5, chips: JSON.stringify(["Instagram", "LinkedIn", "TikTok", "X / Twitter"]) }
  ];

  var STATE_KEY = "wz_demo_state";
  var state;

  try { state = JSON.parse(sessionStorage.getItem(STATE_KEY) || "null"); } catch (e) { state = null; }
  if (!state || !state.surveys) state = seed();

  function save() {
    try { sessionStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (e) { /* private mode */ }
  }

  window.resetDemo = function () {
    try { sessionStorage.removeItem(STATE_KEY); } catch (e) {}
    location.reload();
  };

  /* ── The fake API ────────────────────────────────────────────────────── */

  function unread() {
    return state.notifications.filter(function (n) { return !n.read; }).length;
  }

  function stats() {
    var done = state.surveys.filter(function (s) { return s.status === "completed"; }).length;
    var waiting = state.surveys.filter(function (s) { return s.status === "pending"; }).length;
    return { submittedSurveys: done, pendingResponses: waiting, responsesReceived: state.surveys.length };
  }

  function bodyJson(init) {
    try { return JSON.parse((init && init.body) || "{}"); } catch (e) { return {}; }
  }

  function route(path, query, init) {
    var method = ((init && init.method) || "GET").toUpperCase();
    var action = query.get("action");
    var user = JSON.parse(session.portal_user);

    switch (path) {

      case "api/calendar.php":
        if (action === "get_user_calendar") {
          return { success: true, appointments: state.appointments };
        }
        if (action === "respond_appointment") {
          var input = bodyJson(init);
          state.appointments.forEach(function (a) {
            if (Number(a.id) === Number(input.id)) a.status = input.status;
          });
          save();
          return { success: true, message: input.status === "approved" ? "Meeting confirmed (demo)." : "Meeting declined (demo)." };
        }
        if (action === "create_user_request") {
          var req = bodyJson(init);
          var id = state.appointments.reduce(function (m, a) { return Math.max(m, a.id); }, 0) + 1;
          state.appointments.push({
            id: id, title: req.topic, topic: req.topic, notes: req.notes || "",
            date: req.date, time: (req.time || "09:00") + ":00",
            status: "pending", requested_by: "user"
          });
          save();
          return { success: true, message: "Request sent to the admin team (demo)." };
        }
        break;

      case "api/dashboard.php":
        return { success: true, user: user, stats: stats(), surveys: state.surveys, files: [] };

      case "api/survey-details.php": {
        var survey = state.surveys.filter(function (s) { return String(s.id) === query.get("id"); })[0];
        if (!survey) return { success: false, message: "Survey not found" };
        return {
          success: true,
          survey: {
            id: survey.id, title: survey.title, status: survey.status, created_at: survey.created_at,
            description: "A short demo form with one of each field type: Text, Paragraph, File Upload and Checklist."
          },
          questions: QUESTIONS,
          answers: survey.status === "completed" ? (state.answers[survey.id] || state.answers[4]) : [],
          files: {}
        };
      }

      case "api/submit-survey.php": {
        var form = init && init.body;
        var sid = form && form.get ? form.get("surveyId") : null;
        var given = [];
        try { given = JSON.parse(form.get("answers") || "[]"); } catch (e) {}
        state.surveys.forEach(function (s) { if (String(s.id) === String(sid)) s.status = "completed"; });
        state.answers[sid] = given.map(function (a) {
          return { question_id: Number(a.questionId), question_label: a.questionLabel, answer: a.answer };
        });
        save();
        return { success: true, message: "Survey submitted (demo - nothing was sent)." };
      }

      case "api/user-content.php":
        return { success: true, content: state.content };

      case "api/notifications.php":
        if (action === "count") return { success: true, unread: unread() };
        if (action === "list") {
          var rows = state.notifications.map(function (n) {
            var copy = Object.assign({}, n);
            copy.created_at = new Date(n.created_ts * 1000).toISOString().slice(0, 16).replace("T", " ");
            return copy;
          });
          return { success: true, notifications: rows, page: 1, total: rows.length, total_pages: 1, unread: unread() };
        }
        if (action === "mark_read") {
          var target = bodyJson(init).id;
          state.notifications.forEach(function (n) { if (Number(n.id) === Number(target)) n.read = true; });
          save();
          return { success: true, unread: unread() };
        }
        if (action === "mark_all_read") {
          state.notifications.forEach(function (n) { n.read = true; });
          save();
          return { success: true, unread: 0 };
        }
        break;

      case "api/announcements.php":
        return {
          success: true,
          announcement: {
            title: "Office closed on Friday",
            body: "The team is away for the company retreat. Messages sent on Friday will be answered on Monday.",
            event_date: day(3), created_by_name: "Sarah Miller", created_ts: epoch(3600 * 30),
            image_path: art("Retreat", "#1b1e3a", "#c9000b")
          }
        };

      case "api/admin-surveys.php":
        if (method === "GET" && query.get("survey_id")) {
          return {
            success: true,
            survey: {
              id: 2, title: "استبيان رضا العملاء – الربع الثالث",
              description: "Sent to every client at the end of the quarter.",
              assigned_user_id: 1, status: "pending_review", review_note: ""
            },
            questions: QUESTIONS.map(function (q) {
              return Object.assign({}, q, { chips: q.chips ? JSON.parse(q.chips) : [] });
            })
          };
        }
        if (method === "GET") return { success: true, users: state.clients };
        return { success: true, message: "Saved (demo - nothing was stored)." };

      case "api/admin-session.php":
        return { success: true, admin: JSON.parse(session.admin_user) };

      case "api/sources-lock.php":
        return { success: false, message: "Not available in the demo." };
    }

    return { success: false, message: "Not available in the demo." };
  }

  var realFetch = window.fetch ? window.fetch.bind(window) : null;

  window.fetch = function (input, init) {
    var raw = typeof input === "string" ? input : (input && input.url) || "";
    var url = new URL(raw, location.href);
    var match = url.pathname.match(/(?:^|\/)(api\/[^/]+\.php)$/);

    if (!match) {
      return realFetch ? realFetch(input, init) : Promise.reject(new Error("fetch unavailable"));
    }

    var data = route(match[1], url.searchParams, init);

    // A beat of latency, so "Loading..." states are visible for a moment.
    return new Promise(function (resolve) {
      setTimeout(function () {
        resolve(new Response(JSON.stringify(data), {
          status: 200,
          headers: { "Content-Type": "application/json" }
        }));
      }, 120);
    });
  };

  /* Built-in alert() boxes ("Status updated!") get in the way of clicking
     through; a toast says the same without blocking. */
  window.alert = function (message) { toast(message); };

  function toast(message) {
    var el = document.getElementById("demoToast");
    if (!el) {
      el = document.createElement("div");
      el.id = "demoToast";
      document.body.appendChild(el);
    }
    el.textContent = String(message || "");
    el.classList.add("show");
    clearTimeout(toast.timer);
    toast.timer = setTimeout(function () { el.classList.remove("show"); }, 2600);
  }

  /* ── Ribbon, toast styles, and links that leave the demo ── */

  var CSS =
    "#demoRibbon{position:fixed;right:14px;bottom:14px;z-index:100001;display:flex;gap:8px;align-items:center;" +
    "background:#1b1e3a;color:#fff;font:700 12px/1 Arial,sans-serif;padding:8px 10px 8px 14px;border-radius:999px;" +
    "box-shadow:0 10px 30px rgba(16,24,40,.25)}" +
    "#demoRibbon a,#demoRibbon button{color:#fff;background:rgba(255,255,255,.14);border:none;border-radius:999px;" +
    "padding:6px 10px;font:inherit;cursor:pointer;text-decoration:none}" +
    "#demoRibbon a:hover,#demoRibbon button:hover{background:rgba(255,255,255,.26)}" +
    "#demoToast{position:fixed;left:50%;bottom:70px;transform:translateX(-50%) translateY(10px);opacity:0;" +
    "z-index:100002;background:#101828;color:#fff;font:600 13px/1.4 Arial,sans-serif;padding:11px 16px;" +
    "border-radius:10px;max-width:calc(100% - 32px);pointer-events:none;transition:opacity .2s,transform .2s}" +
    "#demoToast.show{opacity:1;transform:translateX(-50%) translateY(0)}" +
    "a.demo-off{opacity:.45;cursor:not-allowed}" +
    "@media(max-width:768px){#demoRibbon{bottom:10px;right:10px}#demoRibbon .demo-label{display:none}}";

  function decorate() {
    var style = document.createElement("style");
    style.textContent = CSS;
    document.head.appendChild(style);

    if (!/(^|\/)index\.html$/.test(location.pathname) && !/\/demo\/?$/.test(location.pathname)) {
      var ribbon = document.createElement("div");
      ribbon.id = "demoRibbon";
      ribbon.innerHTML =
        '<span class="demo-label">Demo &middot; mock data</span>' +
        '<a href="index.html">All demo pages</a>' +
        '<button type="button" onclick="resetDemo()">Reset</button>';
      document.body.appendChild(ribbon);
    }

    // Static links outside the demo are greyed out up front; the click
    // handler below also catches ones a page renders later.
    document.querySelectorAll("a[href]").forEach(function (a) {
      if (!leavesDemo(a)) return;
      a.classList.add("demo-off");
      a.setAttribute("aria-disabled", "true");
      a.title = "Not part of the demo";
    });
  }

  /* Anything outside the demo would 404 here, so it is shown but inert. */
  function leavesDemo(a) {
    var href = a.getAttribute("href");
    if (!href || /^(#|https?:|mailto:|tel:|data:|javascript:)/i.test(href)) return false;
    var page = href.split(/[?#]/)[0];
    return /\.html$/.test(page) && DEMO_PAGES.indexOf(page) === -1;
  }

  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("a[href]");
    if (!a || !leavesDemo(a)) return;
    e.preventDefault();
    e.stopPropagation();
    toast("That page isn't part of the demo.");
  }, true);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", decorate);
  } else {
    decorate();
  }
})();
