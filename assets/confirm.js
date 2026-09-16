/*
|------------------------------------------------------------------------------
| Asking before something irreversible happens
|------------------------------------------------------------------------------
|
| window.confirm() cannot name the row it is about to destroy, takes a single
| stray Enter as consent, and looks like every other browser alert. This is the
| dialog project-management.html has been using instead, lifted out so the other
| pages can have the same one rather than a lookalike.
|
|     const ok = await askConfirm({
|       title : "Delete client",
|       text  : '"Ada Lovelace" will be erased, along with ...',
|       action: "Delete client",
|       word  : "DELETE"            // omit for no typing gate
|     });
|
|     if (!ok) return;
|
| It resolves to true or false and never throws, so the caller is a plain await
| with an early return.
|
| `word` is what makes the consent deliberate: the confirm button stays disabled
| until that word has been typed. Use it where there is nothing to undo. Leave
| it off for a decision that is destructive but routine - turning away a pending
| signup, say - where a named dialog and a real button are enough and a typing
| test is only friction.
|
| Include at the end of <body>:
|
|     <script src="assets/confirm.js?v=1"></script>
|
| Nothing else on the page is needed: the markup and the styles are injected on
| first use. Escape and the backdrop cancel; Enter in the field confirms, but
| only once the word is there - otherwise Enter would be exactly the reflex
| dismissal this exists to prevent.
|
*/

(function () {

  var STYLE_ID = "confirm-style";
  var BOX_ID = "confirmModal";
  var resolveCurrent = null;
  var lastFocus = null;

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;

    var style = document.createElement("style");
    style.id = STYLE_ID;

    /* Self-contained rather than leaning on the host page's tokens: this
       renders on pages whose :root does not define --brand, and a dialog that
       loses its colour is worse than one that repeats six hex values. */
    style.textContent = [
      ".cf-modal{position:fixed;inset:0;background:rgba(16,24,40,.55);",
      "display:none;align-items:center;justify-content:center;z-index:99998;padding:20px}",
      ".cf-modal.show{display:flex}",
      ".cf-box{width:100%;max-width:440px;background:#fff;border-radius:20px;",
      "box-shadow:0 24px 70px rgba(16,24,40,.28);padding:28px;",
      "font-family:Arial,sans-serif;color:#101828;text-align:left}",

      ".cf-icon{width:52px;height:52px;border-radius:14px;display:flex;",
      "align-items:center;justify-content:center;margin-bottom:18px;",
      "background:#fdecec;color:#c9000b}",
      ".cf-icon svg{width:26px;height:26px}",

      ".cf-box h2{font-size:18px;margin:0 0 8px;font-weight:700}",
      ".cf-text{font-size:13px;line-height:1.7;color:#667085;margin:0 0 20px;word-break:break-word}",

      ".cf-ack{display:block;margin-bottom:22px}",
      ".cf-ack[hidden]{display:none}",
      ".cf-ack span{display:block;font-size:11px;font-weight:800;letter-spacing:.6px;",
      "text-transform:uppercase;color:#667085;margin-bottom:7px}",
      ".cf-ack span b{color:#c9000b;font-weight:800;letter-spacing:.8px}",
      ".cf-ack input{width:100%;height:46px;border:1px solid #dfe3ea;border-radius:11px;",
      "padding:0 13px;font:inherit;font-size:14px;font-weight:700;letter-spacing:.5px;",
      "color:#101828;background:#fff;box-sizing:border-box}",
      ".cf-ack input::placeholder{font-weight:600;letter-spacing:0;color:#aeb6c4}",
      ".cf-ack input:focus{outline:none;border-color:#1b1e3a;box-shadow:0 0 0 3px rgba(27,30,58,.08)}",
      /* Green once it matches, so the button turning on is not the first sign
         the word was typed correctly. */
      ".cf-ack input.ok{border-color:#20a95a;box-shadow:0 0 0 3px rgba(32,169,90,.10)}",

      ".cf-actions{display:flex;gap:10px;justify-content:flex-end}",
      ".cf-actions button{border:none;padding:12px 18px;border-radius:9px;",
      "font:inherit;font-size:13px;font-weight:800;cursor:pointer}",
      ".cf-ghost{background:#fff;border:1px solid #dfe3ea !important;color:#101828}",
      ".cf-ghost:hover{background:#f6f7f9}",
      ".cf-go{background:#c9000b;color:#fff}",
      ".cf-go:hover:not([disabled]){background:#a80009}",
      ".cf-go[disabled]{opacity:.5;cursor:not-allowed}",

      "@media(max-width:520px){.cf-box{padding:22px}",
      ".cf-actions{flex-direction:column-reverse}",
      ".cf-actions button{width:100%}}"
    ].join("");

    document.head.appendChild(style);
  }

  /* One dialog per page, built once and reused. The id matches the one
     project-management.html already uses so a page cannot end up with both. */
  function box() {
    var existing = document.getElementById(BOX_ID);
    if (existing) return existing;

    injectStyles();

    var node = document.createElement("div");
    node.className = "cf-modal";
    node.id = BOX_ID;
    node.setAttribute("role", "dialog");
    node.setAttribute("aria-modal", "true");
    node.setAttribute("aria-labelledby", "cfTitle");

    node.innerHTML =
        '<div class="cf-box">'
      +   '<div class="cf-icon" id="cfIcon"></div>'
      +   '<h2 id="cfTitle">Delete</h2>'
      +   '<p class="cf-text" id="cfText"></p>'
      +   '<label class="cf-ack" id="cfAckWrap">'
      +     '<span>Type <b id="cfWord">DELETE</b> to confirm</span>'
      +     '<input type="text" id="cfAck" autocomplete="off"'
      +            ' autocapitalize="characters" spellcheck="false">'
      +   "</label>"
      +   '<div class="cf-actions">'
      +     '<button type="button" class="cf-ghost" id="cfCancel">Cancel</button>'
      +     '<button type="button" class="cf-go" id="cfGo">Delete</button>'
      +   "</div>"
      + "</div>";

    document.body.appendChild(node);

    var ack = node.querySelector("#cfAck");

    ack.addEventListener("input", function () {
      var given = matches();
      this.classList.toggle("ok", given);
      node.querySelector("#cfGo").disabled = !given;
    });

    ack.addEventListener("keydown", function (event) {
      if (event.key !== "Enter") return;
      event.preventDefault();
      if (matches()) settle(true);
    });

    node.querySelector("#cfGo").addEventListener("click", function () {
      if (!this.disabled) settle(true);
    });

    node.querySelector("#cfCancel").addEventListener("click", function () {
      settle(false);
    });

    // The backdrop cancels; the box itself must not.
    node.addEventListener("click", function (event) {
      if (event.target === node) settle(false);
    });

    return node;
  }

  var TRASH =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
    + ' stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/>'
    + '<path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
    + '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
    + '<path d="M10 11v6M14 11v6"/></svg>';

  var WARN =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
    + ' stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'
    + '<path d="M12 9v4M12 17h.01"/></svg>';

  /** Case-insensitive and trimmed: the point is a second deliberate action, not
      a typing test. No word required means the gate is always open. */
  function matches() {
    var node = document.getElementById(BOX_ID);
    var wanted = node.dataset.word || "";

    if (!wanted) return true;

    return node.querySelector("#cfAck").value.trim().toUpperCase() === wanted;
  }

  function settle(answer) {
    var node = document.getElementById(BOX_ID);
    if (node) node.classList.remove("show");

    document.body.style.overflow = "";

    var resolve = resolveCurrent;
    resolveCurrent = null;

    if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
    lastFocus = null;

    if (resolve) resolve(!!answer);
  }

  function askConfirm(options) {
    options = options || {};

    var node = box();

    // A dialog opened while one is already up would orphan the first promise.
    if (resolveCurrent) settle(false);

    lastFocus = document.activeElement;

    var word = (options.word || "").trim().toUpperCase();
    node.dataset.word = word;

    node.querySelector("#cfIcon").innerHTML = options.icon === "warn" ? WARN : TRASH;
    node.querySelector("#cfTitle").textContent = options.title || "Delete";
    node.querySelector("#cfText").textContent = options.text || "";
    node.querySelector("#cfGo").textContent = options.action || "Delete";

    var wrap = node.querySelector("#cfAckWrap");
    var ack = node.querySelector("#cfAck");

    wrap.hidden = !word;

    // Never inherit the previous answer: each one is acknowledged on its own.
    ack.value = "";
    ack.classList.remove("ok");
    ack.placeholder = word;
    node.querySelector("#cfWord").textContent = word;

    node.querySelector("#cfGo").disabled = !!word;

    node.classList.add("show");
    document.body.style.overflow = "hidden";

    if (word) ack.focus();
    else node.querySelector("#cfGo").focus({ preventScroll: true });

    return new Promise(function (resolve) { resolveCurrent = resolve; });
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && resolveCurrent) settle(false);
  });

  /* Pages that already define their own askConfirm (project-management.html,
     project-details.html) keep theirs - this file is additive, never a
     replacement for one that is already wired to page markup. */
  if (!window.askConfirm) window.askConfirm = askConfirm;

})();
