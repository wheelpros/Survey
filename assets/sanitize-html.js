/*
|--------------------------------------------------------------------------
| sanitizeHtml(html) - the one rich-text scrubber (audit U-02, U-03, U-05)
|--------------------------------------------------------------------------
|
| Captions and phase descriptions are stored as the editor wrote them, so
| every page that hands one to innerHTML - to show it, or to load it back
| into an editor - passes it through here first. It replaces the four copies
| the pages used to carry.
|
| What survives is what the editors' toolbars can produce: b, strong, i, em,
| u, ul, ol, li, a, br, p and div; text alignment; a paragraph's direction
| (Arabic text carries dir="rtl"); and links to http:, https: and mailto:.
| Everything else is unwrapped to its text, and script and style go
| entirely, since unwrapping them would leave their source behind as text.
*/
(function () {
  var ALLOWED = ["B", "STRONG", "I", "EM", "U", "UL", "OL", "LI", "A", "BR", "P", "DIV"];
  var ALIGNMENTS = ["left", "center", "right", "justify"];
  var DIRECTIONS = ["ltr", "rtl", "auto"];
  var SCHEMES = ["http:", "https:", "mailto:"];
  var HTML_NS = "http://www.w3.org/1999/xhtml";

  /*
  | The scheme as the browser will read it when the link is clicked, not as
  | a regex reads the text: an entity can leave a tab, a newline or a control
  | character inside "javascript:", and the URL parser drops those (U-05).
  | A relative link resolves against this page, so it stays http(s).
  */
  function safeHref(value) {
    try {
      return SCHEMES.indexOf(new URL(value, location.href).protocol) !== -1;
    } catch (e) {
      return false;
    }
  }

  window.sanitizeHtml = function (html) {
    /* Parsed into a <template>, not a <div>: a template's content lives in an
       inert document, so an <img src=x onerror=...> in here never loads and
       never fires. A live element runs the handler while this is still busy
       stripping it (U-02). */
    var frame = document.createElement("template");
    frame.innerHTML = html == null ? "" : String(html);

    frame.content.querySelectorAll("*").forEach(function (node) {
      var name = String(node.localName || "").toUpperCase();
      var isHtml = node.namespaceURI === HTML_NS;

      // An SVG or MathML <script>/<style> is lower case and in its own namespace.
      if (name === "SCRIPT" || name === "STYLE") {
        node.remove();
        return;
      }

      if (!isHtml || ALLOWED.indexOf(name) === -1) {
        node.replaceWith.apply(node, Array.prototype.slice.call(node.childNodes));
        return;
      }

      // Browsers write alignment as either inline style or an align attribute.
      var align = ((node.style && node.style.textAlign) || node.getAttribute("align") || "").toLowerCase();
      var dir = (node.getAttribute("dir") || "").toLowerCase();
      var href = node.getAttribute("href");

      Array.prototype.slice.call(node.attributes).forEach(function (attr) {
        node.removeAttribute(attr.name);
      });

      if (ALIGNMENTS.indexOf(align) !== -1) node.style.textAlign = align;
      if (DIRECTIONS.indexOf(dir) !== -1) node.setAttribute("dir", dir);

      if (name === "A") {
        if (href !== null && safeHref(href)) node.setAttribute("href", href);
        node.setAttribute("target", "_blank");
        node.setAttribute("rel", "noopener noreferrer");
      }
    });

    return frame.innerHTML;
  };
})();
