/*
|------------------------------------------------------------------------------
| Editing and deleting your own message - shared by both Messages pages
|------------------------------------------------------------------------------
|
| messages.html (a client's chat) and admin-messages.html (staff chats and
| client chats) draw a message's bubble, its "edited" mark and its Edit /
| Delete links through this, so both look and behave the same. The server
| decides (api/message-edits.php): only your own message, only for 5
| minutes after sending. Each row says how long it has left (editable_for,
| seconds); the links go once that runs out, and the server refuses late
| changes anyway.
|
| The page keeps its own list of messages and its own API call. It wires up
| four globals the markup calls - startEdit(id), cancelEdit(), saveEdit(id),
| deleteOwnMessage(id) - and asks this module:
|
|   WzMessageEdits.track(rows)          after receiving rows (the time left)
|   WzMessageEdits.merge(list, changed) apply a poll's "changed" rows by id
|   WzMessageEdits.bubble(m, linkify)   the bubble, or the edit box while editing it
|   WzMessageEdits.meta(m)              " · edited" for the time line
|   WzMessageEdits.actions(m)           the Edit / Delete links, while allowed
|   WzMessageEdits.keepDraft()          call before re-rendering the list
|   WzMessageEdits.expired()            true once some message's time ran out
|
| Uses assets/confirm.js for the delete question when the page has it.
*/
(function () {
  "use strict";

  var deadlines = {};   // message id -> ms timestamp when it can no longer change
  var editing = null;   // { id, draft } while a message is being edited

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  function track(rows) {
    var now = Date.now();
    (rows || []).forEach(function (m) {
      if (m.mine && !m.deleted && m.editable_for > 0) deadlines[m.id] = now + m.editable_for * 1000;
      else delete deadlines[m.id];
    });
  }

  function canChange(m) {
    return !!m.mine && !m.deleted && (deadlines[m.id] || 0) > Date.now();
  }

  /* Replaces the rows in list that changed (same id). True when any did. */
  function merge(list, changed) {
    var any = false;
    (changed || []).forEach(function (row) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].id === row.id) {
          list[i] = row;
          any = true;
        }
      }
    });
    track(changed);
    if (editing && (changed || []).some(function (r) { return r.id === editing.id && r.deleted; })) editing = null;
    return any;
  }

  function bubble(m, linkify) {
    if (m.deleted) return '<div class="bubble deleted">Message deleted</div>';
    if (editing && editing.id === m.id) {
      return '<form class="edit-box" onsubmit="event.preventDefault(); saveEdit(' + m.id + ');">' +
        '<textarea id="editInput" dir="auto" rows="2" aria-label="Edit your message" ' +
        'onkeydown="WzMessageEdits.onKey(event, ' + m.id + ')">' + esc(editing.draft) + '</textarea>' +
        '<span class="edit-error" id="editError" hidden></span>' +
        '<span class="edit-buttons">' +
        '<button type="button" class="edit-cancel" onclick="cancelEdit()">Cancel</button>' +
        '<button type="submit" class="edit-save">Save</button></span></form>';
    }
    return '<div class="bubble" dir="auto">' + linkify(m.body) + '</div>';
  }

  function meta(m) {
    return m.edited && !m.deleted ? ' <span class="edited">edited</span>' : "";
  }

  function actions(m) {
    if (!canChange(m) || (editing && editing.id === m.id)) return "";
    return '<span class="msg-actions">' +
      '<button type="button" onclick="startEdit(' + m.id + ')">Edit</button>' +
      '<button type="button" class="danger" onclick="deleteOwnMessage(' + m.id + ')">Delete</button></span>';
  }

  /* The edit box is part of the list, so a re-render would lose what is typed. */
  function keepDraft() {
    var input = document.getElementById("editInput");
    if (editing && input) editing.draft = input.value;
  }

  function focusEditor() {
    var input = document.getElementById("editInput");
    if (!input) return;
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
  }

  function begin(m) {
    if (!canChange(m)) return false;
    editing = { id: m.id, draft: m.body };
    return true;
  }

  function end() {
    editing = null;
  }

  function draft() {
    keepDraft();
    return editing ? editing.draft : "";
  }

  function showError(text) {
    var el = document.getElementById("editError");
    if (!el) return;
    el.textContent = text;
    el.hidden = !text;
  }

  // Enter saves, Shift+Enter is a new line, Escape cancels - as in the composer.
  function onKey(event, id) {
    if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      window.saveEdit(id);
    } else if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      window.cancelEdit();
    }
  }

  async function askDelete() {
    var text = "Everyone in this chat will see \"Message deleted\" in its place. This can't be undone.";
    if (window.askConfirm) {
      return window.askConfirm({ title: "Delete message", text: text, action: "Delete", focusCancel: true });
    }
    return window.confirm("Delete this message? " + text);
  }

  /* True once a message's time to change has run out since the last call, so the page redraws its links away. */
  function expired() {
    var now = Date.now();
    var any = false;
    Object.keys(deadlines).forEach(function (id) {
      if (deadlines[id] <= now) {
        delete deadlines[id];
        any = true;
      }
    });
    return any;
  }

  window.WzMessageEdits = {
    track: track, merge: merge, canChange: canChange, bubble: bubble, meta: meta, actions: actions,
    keepDraft: keepDraft, focusEditor: focusEditor, begin: begin, end: end, draft: draft,
    showError: showError, onKey: onKey, askDelete: askDelete, expired: expired,
    editingId: function () { return editing ? editing.id : null; }
  };
})();
