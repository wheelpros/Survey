/*
|------------------------------------------------------------------------------
| Voice typing: a mic button on description and notes boxes
|------------------------------------------------------------------------------
|
| Mark a box and include the script; nothing else is needed:
|
|     <textarea id="notes" data-voice></textarea>
|     <script src="assets/voice-input.js?v=1" defer></script>
|
| Boxes rendered later (innerHTML, template strings) are picked up on their
| own, so render code only has to carry the attribute. The button sits in the
| box's top-right corner. A single-line
| <input data-voice> works too and gets the button centred vertically.
|
| Dictated text goes in at the caret rather than replacing the box, stops at
| the box's maxlength, and fires a real "input" event on every update so the
| page's autosave, counters and dirty flags see it like typing.
|
| It uses the browser's own speech engine (Chrome, Edge, Safari). Where that
| doesn't exist - Firefox - no button is drawn at all.
|
| English only for now. Language detection (EN/AR) needs a different engine;
| docs/voice-input-whisper.md has the proposal. Everything engine-specific is
| in createEngine(), so a server-side engine replaces that one function.
|
*/
(function () {
  "use strict";

  var LANG = "en-US";
  var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!Recognition) return;

  var ICON_MIC =
    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/></svg>';
  var ICON_STOP =
    '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor"/></svg>';

  var ERRORS = {
    "not-allowed": "Microphone access is blocked. Allow it from the lock icon in the address bar.",
    "service-not-allowed": "Microphone access is blocked. Allow it from the lock icon in the address bar.",
    "audio-capture": "No microphone was found on this device.",
    "no-speech": "Didn't catch anything. Try again a little closer to the mic.",
    "network": "Voice typing needs an internet connection."
  };

  var active = null; // { field, button, engine }

  injectStyles();

  /* ── The engine: the only part that knows about SpeechRecognition ───────── */

  function createEngine(handlers) {
    var rec = new Recognition();
    rec.lang = LANG;
    rec.continuous = true;
    rec.interimResults = true;
    rec.maxAlternatives = 1;

    var finalText = "";

    rec.onresult = function (event) {
      var interim = "";
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var piece = event.results[i][0].transcript;
        if (event.results[i].isFinal) finalText += piece;
        else interim += piece;
      }
      handlers.onText(finalText + interim);
    };
    rec.onerror = function (event) { handlers.onError(event.error); };
    rec.onend = function () { handlers.onEnd(); };

    return {
      start: function () { rec.start(); },
      stop: function () { try { rec.stop(); } catch (e) { /* already stopped */ } }
    };
  }

  /* ── Attaching buttons ─────────────────────────────────────────────────── */

  function enhance(field) {
    if (field.dataset.voiceReady || field.readOnly) return;
    field.dataset.voiceReady = "1";

    var wrap = document.createElement("div");
    wrap.className = "voice-wrap" + (field.tagName === "INPUT" ? " voice-wrap--line" : "");
    field.parentNode.insertBefore(wrap, field);
    wrap.appendChild(field);

    var button = document.createElement("button");
    button.type = "button";
    button.className = "voice-btn";
    button.setAttribute("aria-label", "Dictate");
    button.setAttribute("aria-pressed", "false");
    button.title = "Dictate";
    button.innerHTML = ICON_MIC;
    wrap.appendChild(button);

    var hint = document.createElement("div");
    hint.className = "voice-hint";
    hint.setAttribute("role", "status");
    hint.hidden = true;
    // Outside the wrap, so the button stays pinned to the box's corner.
    wrap.parentNode.insertBefore(hint, wrap.nextSibling);

    button.addEventListener("click", function () {
      if (active && active.field === field) stop();
      else start(field, button, hint);
    });

    field.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && active && active.field === field) {
        event.preventDefault();
        stop();
      }
    });
  }

  function scan(root) {
    if (!root || root.nodeType !== 1) return;
    if (root.matches("textarea[data-voice], input[data-voice]")) enhance(root);
    var found = root.querySelectorAll("textarea[data-voice], input[data-voice]");
    for (var i = 0; i < found.length; i++) enhance(found[i]);
  }

  /* ── Recording ─────────────────────────────────────────────────────────── */

  function start(field, button, hint) {
    stop();
    if (field.disabled) return;

    hint.hidden = true;

    var value = field.value;
    var from = typeof field.selectionStart === "number" ? field.selectionStart : value.length;
    var to = typeof field.selectionEnd === "number" ? field.selectionEnd : value.length;
    // The caret is at 0 in a box that was never focused; append instead.
    if (document.activeElement !== field) from = to = value.length;

    var before = value.slice(0, from);
    var after = value.slice(to);
    if (before && !/\s$/.test(before)) before += " ";

    var max = field.maxLength > 0 ? field.maxLength : Infinity;

    var engine = createEngine({
      onText: function (text) {
        text = text.replace(/^\s+/, "");
        if (after && text && !/^\s/.test(after)) text += " ";
        var room = Math.max(0, max - before.length - after.length);
        if (text.length > room) text = text.slice(0, room);

        field.value = before + text + after;
        var caret = before.length + text.length;
        try { field.setSelectionRange(caret, caret); } catch (e) { /* input types without selection */ }
        field.dispatchEvent(new Event("input", { bubbles: true }));
      },
      onError: function (code) {
        if (code === "aborted") return;
        hint.textContent = ERRORS[code] || "Voice typing stopped. Try again.";
        hint.hidden = false;
      },
      onEnd: function () {
        if (active && active.engine === engine) reset();
        field.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });

    active = { field: field, button: button, engine: engine };
    button.classList.add("is-recording");
    button.setAttribute("aria-pressed", "true");
    button.setAttribute("aria-label", "Stop dictating");
    button.title = "Stop dictating";
    button.innerHTML = ICON_STOP;

    try {
      engine.start();
    } catch (e) {
      reset();
      hint.textContent = "Voice typing couldn't start. Try again.";
      hint.hidden = false;
    }
  }

  function stop() {
    if (!active) return;
    var engine = active.engine;
    reset();
    engine.stop();
  }

  function reset() {
    if (!active) return;
    var button = active.button;
    button.classList.remove("is-recording");
    button.setAttribute("aria-pressed", "false");
    button.setAttribute("aria-label", "Dictate");
    button.title = "Dictate";
    button.innerHTML = ICON_MIC;
    active = null;
  }

  /* ── Styles ────────────────────────────────────────────────────────────── */

  function injectStyles() {
    var css =
      ".voice-wrap{position:relative;display:block;width:100%}" +
      // Physical right, not inline-end: the boxes are dir="auto", so Arabic text
      // would flip a logical padding to the left while the button stays right.
      ".voice-wrap>textarea,.voice-wrap>input{padding-right:46px!important}" +
      // Top corner: the bottom one belongs to the textarea's resize grip.
      ".voice-btn{position:absolute;right:8px;top:8px;width:32px;height:32px;" +
        "display:flex;align-items:center;justify-content:center;padding:0;margin:0;border:none;" +
        "border-radius:8px;background:transparent;color:#667085;cursor:pointer;" +
        "transition:background-color .15s,color .15s}" +
      ".voice-wrap--line>.voice-btn{top:50%;transform:translateY(-50%)}" +
      ".voice-btn:hover{background:#f2f4f7;color:#101828}" +
      ".voice-btn:focus-visible{outline:2px solid var(--brand,#c9000b);outline-offset:1px}" +
      ".voice-btn.is-recording{background:#fdecec;color:var(--brand,#c9000b);animation:voice-pulse 1.2s ease-in-out infinite}" +
      ".voice-hint{margin-top:6px;font-size:12.5px;line-height:1.4;color:#b42318}" +
      ".voice-hint[hidden]{display:none}" +
      "@keyframes voice-pulse{0%,100%{box-shadow:0 0 0 0 rgba(201,0,11,.28)}50%{box-shadow:0 0 0 6px rgba(201,0,11,0)}}" +
      "@media (prefers-reduced-motion:reduce){.voice-btn.is-recording{animation:none}}";
    var style = document.createElement("style");
    style.id = "voice-input-styles";
    style.textContent = css;
    document.head.appendChild(style);
  }

  /* ── Boot ──────────────────────────────────────────────────────────────── */

  function boot() {
    scan(document.body);
    new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var added = mutations[i].addedNodes;
        for (var j = 0; j < added.length; j++) scan(added[j]);
      }
      // The box being dictated into was re-rendered away.
      if (active && !document.body.contains(active.field)) stop();
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
