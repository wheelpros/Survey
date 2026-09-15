/*
|------------------------------------------------------------------------------
| Click an image to see it full size
|------------------------------------------------------------------------------
|
| The detail panels on the content and project pages show their image in a fixed
| box - 320px tall, or a 2.4:1 strip - which is enough to recognise a post but
| not enough to read one. This opens the same image over the page at whatever
| size the screen allows, and closes again on Escape, on the backdrop or on the
| button.
|
| Nothing has to be wired up per image. Mark the element that carries it:
|
|     <div class="detail-img" id="detailImg" data-lightbox></div>
|
| and include this file at the end of <body>:
|
|     <script src="assets/lightbox.js?v=1"></script>
|
| The source is worked out at click time, in this order:
|
|   1. data-lightbox-src, when the page wants to open a different (larger)
|      file than the one it is showing;
|   2. an <img> inside the element, or the element itself when it is an <img>;
|   3. the element's own background-image.
|
| The third is what the detail panels actually use: they paint a CSS background
| rather than an <img>, and they paint it after this file has loaded. Resolving
| late is what keeps that working without the pages telling us when they have
| finished rendering.
|
| An element with no image resolves to nothing and does not open - which is the
| state every one of these panels is in before its data arrives, and the state
| a post with no image stays in.
|
*/

(function () {

  var STYLE_ID = "lightbox-style";
  var overlay = null;
  var lastFocus = null;

  /* ── Styles ──────────────────────────────────────────────────────────────
     Injected rather than added to each page's <style> block, so there is one
     copy of them and adding the script to a page is the whole change. */
  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;

    var style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = [
      /* Only an element that actually has an image to show says so. The class
         is kept in step by the observer at the foot of this file. */
      "[data-lightbox].lb-ready{cursor:zoom-in}",

      ".lb-overlay{position:fixed;inset:0;z-index:100000;display:flex;",
      "align-items:center;justify-content:center;padding:24px;",
      "background:rgba(16,24,40,.82);opacity:0;transition:opacity .16s}",
      ".lb-overlay.show{opacity:1}",

      /* Never larger than the image itself, so a small logo is not blown up
         into a blurred rectangle, and never larger than the viewport. */
      ".lb-overlay img{max-width:100%;max-height:100%;width:auto;height:auto;",
      "display:block;border-radius:12px;box-shadow:0 24px 70px rgba(0,0,0,.45);",
      "transform:scale(.98);transition:transform .16s}",
      ".lb-overlay.show img{transform:scale(1)}",

      ".lb-close{position:absolute;top:18px;right:18px;width:40px;height:40px;",
      "border:none;border-radius:50%;background:#fff;color:#101828;",
      "font-size:24px;line-height:1;cursor:pointer;display:flex;",
      "align-items:center;justify-content:center}",
      ".lb-close:hover{background:#f2f4f8}",

      "@media(max-width:600px){.lb-overlay{padding:14px}",
      ".lb-close{top:10px;right:10px;width:34px;height:34px;font-size:20px}}"
    ].join("");

    document.head.appendChild(style);
  }

  /* ── Finding the image ───────────────────────────────────────────────── */

  /** The url out of a computed background-image, or "" when there is none. */
  function backgroundSrc(element) {
    var value = getComputedStyle(element).backgroundImage;

    if (!value || value === "none") return "";

    var match = /url\((['"]?)(.*?)\1\)/.exec(value);

    if (!match) return "";

    var url = match[2];

    // A gradient or an inline svg placeholder is not a photograph to open.
    return /^data:image\/svg/i.test(url) ? "" : url;
  }

  function sourceFor(element) {
    var explicit = element.getAttribute("data-lightbox-src");
    if (explicit) return explicit;

    var img = element.tagName === "IMG" ? element : element.querySelector("img");
    if (img && img.getAttribute("src")) return img.src;

    return backgroundSrc(element);
  }

  /* ── The overlay ─────────────────────────────────────────────────────── */

  function close() {
    if (!overlay) return;

    var node = overlay;
    overlay = null;

    node.classList.remove("show");

    // Removed after the fade rather than on the spot, so closing is not a jump.
    setTimeout(function () {
      if (node.parentNode) node.parentNode.removeChild(node);
    }, 180);

    document.body.style.overflow = "";

    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
    lastFocus = null;
  }

  function open(src, alt) {
    if (!src) return;

    injectStyles();
    close();

    lastFocus = document.activeElement;

    overlay = document.createElement("div");
    overlay.className = "lb-overlay";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-label", alt || "Image");

    var image = document.createElement("img");
    image.src = src;
    image.alt = alt || "";

    var button = document.createElement("button");
    button.type = "button";
    button.className = "lb-close";
    button.setAttribute("aria-label", "Close image");
    button.innerHTML = "&times;";

    overlay.appendChild(image);
    overlay.appendChild(button);

    // The backdrop closes; the image does not, so a click to drag or to
    // right-click and save does not dismiss what it was aimed at.
    overlay.addEventListener("click", function (event) {
      if (event.target === overlay) close();
    });

    button.addEventListener("click", close);

    document.body.appendChild(overlay);

    // The page behind must not scroll under the overlay.
    document.body.style.overflow = "hidden";

    requestAnimationFrame(function () {
      if (overlay) overlay.classList.add("show");
    });

    button.focus({ preventScroll: true });
  }

  /* ── Wiring ──────────────────────────────────────────────────────────── */

  document.addEventListener("click", function (event) {
    var target = event.target.closest ? event.target.closest("[data-lightbox]") : null;

    if (!target) return;

    /* A link or a button inside the image box is doing its own job - opening
       the picture would swallow the click it was meant to get. */
    if (event.target.closest("a,button")) return;

    var src = sourceFor(target);

    if (!src) return;

    event.preventDefault();
    open(src, target.getAttribute("data-lightbox-alt") || "");
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") close();
  });

  /* Enter and Space on a focusable image box, for the same reason the cards on
     these pages take them: the box is a control once it opens something. */
  document.addEventListener("keydown", function (event) {
    if (event.key !== "Enter" && event.key !== " ") return;

    var target = document.activeElement;

    if (!target || !target.matches || !target.matches("[data-lightbox]")) return;

    var src = sourceFor(target);

    if (!src) return;

    event.preventDefault();
    open(src, target.getAttribute("data-lightbox-alt") || "");
  });

  /*
  | The cursor, kept honest.
  |
  | These panels paint their image from script, after this file has run, and
  | repaint it every time a different post or task is opened. A class set once
  | on load would say "clickable" on an empty grey box and stay silent on the
  | image that replaced it, so the check re-runs whenever anything that could
  | carry an image changes - collapsed into one pass per frame, because a
  | re-render fires a burst of these.
  */
  var pending = false;

  function markReady() {
    pending = false;

    document.querySelectorAll("[data-lightbox]").forEach(function (element) {

      /* A box that is not on the screen is not a control, whatever background
         it is still carrying from the last thing it showed - these panels hide
         their image rather than clearing it when a post has none. */
      var visible = !element.hidden && element.getClientRects().length > 0;
      var ready = visible && !!sourceFor(element);

      element.classList.toggle("lb-ready", ready);

      /* Announced as a control only while it is one. Pages that already give
         the box a role keep theirs - this only fills in the gap. */
      if (ready && !element.hasAttribute("role")) {
        element.setAttribute("role", "button");
        element.setAttribute("tabindex", "0");
        element.setAttribute("aria-label",
          element.getAttribute("data-lightbox-alt") || "Open image full size");
      }
    });
  }

  function schedule() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(markReady);
  }

  function start() {
    injectStyles();
    markReady();

    if (!window.MutationObserver) return;

    new MutationObserver(schedule).observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      /* "class" is deliberately not watched: markReady() sets one, and a pass
         that re-triggers itself would never settle. The panels paint through
         the style attribute, which is watched. */
      attributeFilter: ["style", "src", "hidden", "data-lightbox-src"]
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }

  // Exposed for a page that wants to open an image it is not showing in a box.
  window.openLightbox = open;

})();
