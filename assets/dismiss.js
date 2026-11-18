/*
|------------------------------------------------------------------------------
| Closing a popup from outside it
|------------------------------------------------------------------------------
|
| One rule for every popup: a click on the backdrop or Escape closes it, and
| Tab keeps the focus inside it while it's open. Two
| things make that safe for popups with a form in them:
|
|   - a popup busy saving cannot be closed - the answer would land in a
|     hidden box nobody reads;
|   - a popup whose fields changed since it opened asks before throwing the
|     typing away (askConfirm from confirm.js, or the browser's confirm).
|
|     const typed = wzDismiss.track(document.getElementById("adminForm"));
|
|     wzDismiss(document.getElementById("adminModal"), closeAdminModal, {
|       isBusy : () => adminSaving,
|       isDirty: () => typed.changed()       // omit for a read-only popup
|     });
|
|     // when it opens:
|     typed.reset();
|
| A click only counts when the press started on the backdrop too: selecting
| text in a field and letting go over the backdrop is not a request to close.
| Escape is skipped when something else already handled it (confirm.js does,
| so Escape on a question asked over a popup closes the question only), and
| when the popup is not open.
|
| Include it before the page's own script:
|
|     <script src="assets/dismiss.js?v=1"></script>
|
*/
(function () {
  "use strict";

  /** Everything typed or picked under root, as one string to compare. */
  function snapshot(root) {
    if (!root) return "";

    return Array.prototype.map.call(
      root.querySelectorAll("input, select, textarea"),
      function (field) {
        if (field.type === "checkbox" || field.type === "radio") return field.checked ? "1" : "0";
        if (field.type === "file") return "f" + (field.files ? field.files.length : 0);
        if (field.type === "button" || field.type === "submit") return "";
        return field.value;
      }
    ).join("\u0001");
  }

  function track(root) {
    var start = snapshot(root);

    return {
      reset: function () { start = snapshot(root); },
      changed: function () { return snapshot(root) !== start; }
    };
  }

  function shown(node) {
    if (!node || !node.isConnected) return false;
    var style = getComputedStyle(node);
    return style.display !== "none" && style.visibility !== "hidden";
  }

  function wzDismiss(backdrop, close, options) {
    if (!backdrop || typeof close !== "function") return null;

    options = options || {};

    var isOpen = options.isOpen || function () { return shown(backdrop); };
    var pressedOnBackdrop = false;
    var asking = false;

    function attempt() {
      if (asking || !isOpen()) return;
      if (options.isBusy && options.isBusy()) return;

      if (!(options.isDirty && options.isDirty())) {
        close();
        return;
      }

      asking = true;

      var question = window.askConfirm
        ? window.askConfirm({
            title: "Discard your changes?",
            text: "What you typed here hasn't been saved.",
            action: "Discard",
            dismiss: "Keep editing",
            icon: "warn"
          })
        : Promise.resolve(window.confirm("Discard your changes? What you typed here hasn't been saved."));

      question.then(function (discard) {
        asking = false;
        if (discard) close();
      });
    }

    backdrop.addEventListener("pointerdown", function (event) {
      pressedOnBackdrop = event.target === backdrop;
    });

    backdrop.addEventListener("click", function (event) {
      var fromBackdrop = pressedOnBackdrop;
      pressedOnBackdrop = false;
      if (event.target === backdrop && fromBackdrop) attempt();
    });

    if (options.escape !== false) {
      document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape" || event.defaultPrevented) return;
        if (!isOpen()) return;
        event.preventDefault();
        attempt();
      });
    }

    /* Tab and Shift+Tab stay inside an open popup, so focus never wanders to
       the page behind an aria-modal box. A question asked over the popup
       (confirm.js) keeps its own focus: anything inside another open dialog
       is left alone. trap: false opts out. */
    if (options.trap !== false) {
      document.addEventListener("keydown", function (event) {
        if (event.key !== "Tab" || event.defaultPrevented || !isOpen()) return;
        var active = document.activeElement;
        var elsewhere = active && !backdrop.contains(active) && active.closest && active.closest("[role=dialog], [role=alertdialog], [aria-modal=true]");
        if (elsewhere) return;

        var items = Array.prototype.filter.call(
          backdrop.querySelectorAll("a[href], button, input, select, textarea, [tabindex]:not([tabindex='-1'])"),
          function (el) { return !el.disabled && !el.hidden && el.type !== "hidden" && el.getClientRects().length > 0; }
        );
        if (!items.length) return;

        var first = items[0], last = items[items.length - 1];
        var inside = backdrop.contains(active);
        if (!inside || (event.shiftKey && active === first) || (!event.shiftKey && active === last)) {
          event.preventDefault();
          (event.shiftKey ? last : first).focus();
        }
      });
    }

    return { attempt: attempt };
  }

  wzDismiss.track = track;
  window.wzDismiss = wzDismiss;
})();
