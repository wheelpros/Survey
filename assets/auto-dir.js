/*
|------------------------------------------------------------------------------
| Text boxes follow the language typed into them
|------------------------------------------------------------------------------
|
| Gives every free-text box dir="auto", so Arabic runs right-to-left and sits
| on the right while English stays left, each decided by the first strong
| letter typed. Boxes rendered later are covered too.
|
| Covers every box you type into, email and password included: their content
| is left-to-right, so "auto" resolves to LTR for them anyway. A box that
| already has a dir keeps it. Checkboxes, dates, numbers, files and buttons
| are skipped.
|
| Every page loads it, so nothing to remember when adding a field.
|
|   <script src="/assets/auto-dir.js?v=1" defer></script>
|
*/
(function () {
  "use strict";

  var TYPES = ["", "text", "search", "email", "url", "tel", "password"];
  var SELECTOR = "textarea:not([dir]), input:not([dir]):not([type])";
  for (var t = 1; t < TYPES.length; t++) SELECTOR += ", input[type=" + TYPES[t] + "]:not([dir])";

  function apply(root) {
    if (!root || root.nodeType !== 1) return;
    if (root.matches(SELECTOR)) root.dir = "auto";
    var found = root.querySelectorAll(SELECTOR);
    for (var i = 0; i < found.length; i++) found[i].dir = "auto";
  }

  function boot() {
    apply(document.body);
    new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var added = mutations[i].addedNodes;
        for (var j = 0; j < added.length; j++) apply(added[j]);
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
