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
      // continuing through the page behind the backdrop.
      var first = sidebar.querySelector("a, button");
      if (first) first.focus();
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
    });

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

    bar.appendChild(burger);

    /* Cloned rather than hardcoded: the sidebar logo is logo.png when an admin
       has uploaded one and falls back to the bundled wordmark through its own
       onerror, and the copy should follow whichever it lands on. */
    var logo = sidebar.querySelector(".logo img, .side-logo img");

    if (logo) {
      var mark = document.createElement("span");
      mark.className = "wz-mark";
      mark.appendChild(logo.cloneNode(true));
      bar.appendChild(mark);
    }

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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }

})();
