/*
|------------------------------------------------------------------------------
| Shared app shell: the hamburger and the drawer it opens
|------------------------------------------------------------------------------
|
| Injects the phone topbar, its hamburger and the backdrop, so no page's markup
| has to change - the styling for all of it lives in assets/shell.css, which
| keeps every piece hidden above 768px.
|
| The sidebar itself is whatever the page already has: .sidebar on the client
| pages, .admin-sidebar on the admin ones. A page with neither - login, survey,
| the standalone forms - gets nothing at all.
|
|   <link rel="stylesheet" href="assets/shell.css?v=1" />
|   <script src="assets/shell.js?v=1"></script>
|
| Add the script beside the existing notifications.js tag, at the end of <body>.
|
*/

(function () {

  var DRAWER = 768;
  var OPEN = "nav-open";

  var root = document.documentElement;
  var lastFocus = null;

  function start() {

    var sidebar = document.querySelector(".sidebar, .admin-sidebar");

    // No sidebar on this page, so there is no menu to build.
    if (!sidebar) return;

    if (!sidebar.id) sidebar.id = "wzSidebar";

    var burger = buildTopbar(sidebar);
    var scrim = buildScrim();
    var close = buildClose(sidebar);

    wrapTables();

    function isOpen() {
      return root.classList.contains(OPEN);
    }

    function open() {
      if (isOpen()) return;

      lastFocus = document.activeElement;
      root.classList.add(OPEN);
      burger.setAttribute("aria-expanded", "true");

      // First link in the drawer, so a keyboard lands inside it rather than
      // continuing through the page behind the backdrop. Only a visible one:
      // the sidebar's first button is the collapse toggle, which shell.css
      // hides in the drawer, and focusing it silently did nothing.
      var items = focusable();
      var first = items.filter(function (el) { return el.matches("a[href]"); })[0] || items[0];
      if (first) first.focus();
    }

    function focusable() {
      return Array.prototype.filter.call(
        sidebar.querySelectorAll("a[href], button:not([disabled])"),
        function (el) { return el.offsetParent !== null; }
      );
    }

    function shut() {
      if (!isOpen()) return;

      root.classList.remove(OPEN);
      burger.setAttribute("aria-expanded", "false");

      if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
      lastFocus = null;
    }

    burger.addEventListener("click", function () {
      if (isOpen()) shut(); else open();
    });

    close.addEventListener("click", shut);
    scrim.addEventListener("click", shut);

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") shut();
      if (event.key === "Tab" && isOpen()) keepFocusInside(event);
    });

    /* The page behind the backdrop cannot be clicked, so it should not be
       tabbable either: Tab past the last control wraps to the first, and
       Shift+Tab the other way. */
    function keepFocusInside(event) {

      var items = focusable();

      if (!items.length) return;

      var first = items[0];
      var last = items[items.length - 1];

      if (!sidebar.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    // Following a link leaves the page anyway, but the drawer would otherwise
    // sit open over the old one for as long as the next page takes to load.
    sidebar.addEventListener("click", function (event) {
      if (event.target.closest("a[href]")) shut();
    });

    // Resized up into the sidebar layout: the drawer is no longer on screen,
    // so the class and the scroll lock have to go with it.
    window.addEventListener("resize", function () {
      if (window.innerWidth > DRAWER) shut();
    });
  }

  /* Every table in the portal sits in a white panel whose own CSS clips
     overflow, so a phone crushes the columns to a few characters each rather
     than scrolling. Giving each table its own scroll box fixes all of them at
     once. Pages only ever re-render <tbody>, never the table element, so
     wrapping once on load holds. */
  function wrapTables() {

    Array.prototype.forEach.call(document.querySelectorAll("table"), function (table) {

      if (table.closest(".wz-tablewrap")) return;

      var wrap = document.createElement("div");
      wrap.className = "wz-tablewrap";

      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    });
  }

  function buildTopbar(sidebar) {

    var bar = document.createElement("header");
    bar.className = "wz-topbar";

    var burger = document.createElement("button");
    burger.type = "button";
    burger.className = "wz-burger";
    burger.setAttribute("aria-label", "Menu");
    burger.setAttribute("aria-expanded", "false");
    burger.setAttribute("aria-controls", sidebar.id);
    burger.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round">' +
      '<path d="M4 7h16M4 12h16M4 17h16"/></svg>';

    /* The hamburger alone, as the design has it. The logo is still one tap
       away, at the head of the drawer this opens. */
    bar.appendChild(burger);

    document.body.appendChild(bar);

    return burger;
  }

  function buildScrim() {
    var scrim = document.createElement("div");
    scrim.className = "wz-scrim";
    document.body.appendChild(scrim);
    return scrim;
  }

  function buildClose(sidebar) {

    var close = document.createElement("button");
    close.type = "button";
    close.className = "wz-close";
    close.setAttribute("aria-label", "Close menu");
    close.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round">' +
      '<path d="M6 6l12 12M18 6L6 18"/></svg>';

    // Lands where the collapse toggle sits on a desktop, which shell.css hides
    // in the drawer. Every sidebar page has a .side-head.
    var head = sidebar.querySelector(".side-head");

    if (head) head.appendChild(close);
    else sidebar.insertBefore(close, sidebar.firstChild);

    return close;
  }

  /* The account popup (#acctModal). Each page opens and closes it with its own
     openAccount() / closeAccount(), which only toggle .show - so this watches
     that class: focus moves into the popup when it opens and back to the
     account card when it shuts, and Esc or a click on the backdrop shuts it. */
  function accountPopup() {

    var modal = document.getElementById("acctModal");

    if (!modal) return;

    var opener = null;

    function shut() {
      modal.classList.remove("show");
    }

    new MutationObserver(function () {
      if (modal.classList.contains("show")) {
        opener = document.activeElement;
        var first = modal.querySelector("button");
        if (first) first.focus();
      } else if (opener && document.contains(opener)) {
        opener.focus();
        opener = null;
      }
    }).observe(modal, { attributes: true, attributeFilter: ["class"] });

    modal.addEventListener("click", function (event) {
      if (event.target === modal) shut();
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal.classList.contains("show")) shut();
    });
  }

  /* What the account card and the popup call this admin: their position
     (set by the owner on Settings, e.g. "SEO Specialist"), or their name when
     they have none. The avatar keeps the initial of the name. Pages paint the
     name themselves, from the same stored admin_user; this runs after them and
     is what role-nav.js calls again when a session check finds the position
     has changed. Admin pages only - the client pages have no #adminName. */
  function paintAccount() {

    var admin = {};

    try {
      admin = JSON.parse(localStorage.getItem("admin_user") || "{}") || {};
    } catch (error) {
      admin = {};
    }

    var name = String(admin.name || "").trim();
    var position = String(admin.position || "").trim();
    var shown = position || name || "Admin";

    [["adminName", shown], ["acctName", shown], ["adminAvatar", (name || shown).charAt(0).toUpperCase()],
     ["acctAvatar", (name || shown).charAt(0).toUpperCase()]].forEach(function (pair) {
      var el = document.getElementById(pair[0]);
      if (el) el.textContent = pair[1];
    });

    /* The photo, when the Director gave them one, in place of the initial. */
    var photo = typeof admin.avatar_path === "string"
      && /^uploads\/avatars\/[a-f0-9]+\.(jpg|png|webp)$/.test(admin.avatar_path) ? admin.avatar_path : "";

    ["adminAvatar", "acctAvatar"].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.style.backgroundImage = photo ? "url('" + photo + "')" : "";
      el.style.backgroundSize = photo ? "cover" : "";
      el.style.backgroundPosition = photo ? "center" : "";
      el.style.color = photo ? "transparent" : "";
    });
  }

  window.wzPaintAccount = paintAccount;

  function ready() {
    start();
    accountPopup();
    paintAccount();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", ready);
  } else {
    ready();
  }

})();
