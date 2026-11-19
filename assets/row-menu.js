/*
|------------------------------------------------------------------------------
| The ⋮ menu on a table row, for every page
|------------------------------------------------------------------------------
|
| One menu element, reused by every row and positioned under whichever ⋮ was
| pressed - a menu per row is hundreds of hidden nodes, and a menu inside the
| row gets clipped by any panel with overflow:hidden. It's position:fixed, so
| it's measured against the window: right-aligned under the button, flipped
| above it when there's no room below.
|
|   RowMenu.open(button, [
|     { label: "View", href: "page.html?id=4" },
|     { label: "Edit", icon: SVG, onSelect: fn },
|     { separator: true },
|     { label: "Delete", danger: true, onSelect: fn },   // positive: true for green
|   ]);
|   RowMenu.kebab("Actions for Hana", "openMenu(this, 4)")   // the ⋮ button's HTML
|
| Only what the row allows goes in: an item the person can't use isn't shown.
| A second press on the same ⋮ closes it. Arrow keys, Home and End move between
| items; Escape closes and puts the focus back on the ⋮; Tab, a click outside,
| scrolling or resizing close it. Choosing an item closes the menu first, so it
| never floats over the popup the item opens.
|
|   <script src="assets/row-menu.js?v=1"></script>
|
*/
(function () {
  "use strict";

  var STYLE =
    ".rm-pop{position:fixed;z-index:100000;min-width:170px;max-width:min(280px,calc(100vw - 16px));background:#fff;" +
    "border:1px solid #eaecf0;border-radius:12px;box-shadow:0 12px 32px rgba(16,24,40,.14);padding:6px;font-family:inherit}" +
    ".rm-pop a,.rm-pop button{display:flex;align-items:center;gap:10px;width:100%;text-align:left;font:inherit;font-size:13px;" +
    "font-weight:600;color:#101828;background:none;border:0;border-radius:8px;padding:9px 12px;text-decoration:none;cursor:pointer}" +
    ".rm-pop a:hover,.rm-pop button:hover,.rm-pop a:focus-visible,.rm-pop button:focus-visible{background:#f2f4f7;outline:none}" +
    ".rm-pop .rm-danger{color:#b42318}.rm-pop .rm-positive{color:#067647}" +
    ".rm-pop svg{width:16px;height:16px;flex:none}" +
    ".rm-pop hr{border:0;border-top:1px solid #eaecf0;margin:6px 4px}";

  var KEBAB_SVG = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';

  var menu = null;
  var anchor = null;

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  function addStyle() {
    if (document.getElementById("rmStyle")) return;
    var style = document.createElement("style");
    style.id = "rmStyle";
    style.textContent = STYLE;
    document.head.appendChild(style);
  }

  function items() {
    return menu ? Array.prototype.slice.call(menu.querySelectorAll("[role=menuitem]")) : [];
  }

  function close(refocus) {
    if (!menu) return;
    menu.remove();
    menu = null;
    if (anchor) {
      anchor.setAttribute("aria-expanded", "false");
      if (refocus) anchor.focus();
    }
    anchor = null;
  }

  function place(button) {
    var rect = button.getBoundingClientRect();
    var box = menu.getBoundingClientRect();
    var gap = 6;
    var left = Math.min(Math.max(8, rect.right - box.width), window.innerWidth - box.width - 8);
    var below = rect.bottom + gap;
    var top = below + box.height > window.innerHeight - 8 ? Math.max(8, rect.top - gap - box.height) : below;
    menu.style.left = left + "px";
    menu.style.top = top + "px";
  }

  function open(button, list) {
    if (anchor === button) { close(true); return; }
    close(false);
    list = (list || []).filter(Boolean);
    if (!list.some(function (i) { return !i.separator; })) return;

    addStyle();
    menu = document.createElement("div");
    menu.className = "rm-pop";
    menu.setAttribute("role", "menu");
    if (button.getAttribute("aria-label")) menu.setAttribute("aria-label", button.getAttribute("aria-label"));

    list.forEach(function (item) {
      if (item.separator) {
        menu.appendChild(document.createElement("hr"));
        return;
      }
      var el = document.createElement(item.href ? "a" : "button");
      el.setAttribute("role", "menuitem");
      el.tabIndex = -1;
      if (item.href) el.href = item.href; else el.type = "button";
      if (item.danger) el.className = "rm-danger";
      else if (item.positive) el.className = "rm-positive";
      el.innerHTML = (item.icon || "") + "<span>" + esc(item.label) + "</span>";
      el.addEventListener("click", function () {
        close(false);
        if (item.onSelect) item.onSelect();
      });
      menu.appendChild(el);
    });

    document.body.appendChild(menu);
    place(button);
    anchor = button;
    button.setAttribute("aria-expanded", "true");
    // preventScroll: a scroll would trip the listener below and close it again.
    var first = items()[0];
    if (first) first.focus({ preventScroll: true });
  }

  /** The ⋮ button's HTML; onclick is the page's own code, run with this = the button. */
  function kebab(label, onclick, className) {
    return '<button type="button" class="' + esc(className || "kebab") + '" aria-haspopup="menu" aria-expanded="false" aria-label="'
      + esc(label) + '" onclick="event.stopPropagation(); ' + esc(onclick) + '">' + KEBAB_SVG + "</button>";
  }

  document.addEventListener("click", function (e) {
    if (menu && !menu.contains(e.target) && (!anchor || !anchor.contains(e.target))) close(false);
  });
  document.addEventListener("keydown", function (e) {
    if (!menu) return;
    var all = items();
    var at = all.indexOf(document.activeElement);
    if (e.key === "Escape") { e.preventDefault(); close(true); }
    else if (e.key === "Tab") { close(false); }
    else if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      all[(at + (e.key === "ArrowDown" ? 1 : all.length - 1)) % all.length].focus();
    } else if (e.key === "Home" || e.key === "End") {
      e.preventDefault();
      all[e.key === "Home" ? 0 : all.length - 1].focus();
    }
  });
  window.addEventListener("resize", function () { close(false); });
  window.addEventListener("scroll", function (e) {
    if (menu && !(e.target && e.target.nodeType === 1 && menu.contains(e.target))) close(false);
  }, true);

  window.RowMenu = { open: open, close: close, kebab: kebab };
})();
