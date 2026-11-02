/*
|------------------------------------------------------------------------------
| A list of attached files: the ones already saved, and new ones to upload
|------------------------------------------------------------------------------
|
| Used by the content form and the phase form, each with two lists - the
| team's files and the client's - saved under their own field names. The
| server side is saveAttachments() in api/upload-helpers.php.
|
|   <script src="assets/file-list.js?v=1"></script>
|
|   const teamFiles = makeFileList({
|     name: "teamFiles",          // the global this is assigned to (for onclick)
|     list: "fileList", addBtn: "fileAddBtn", input: "fileInput", error: "fileError",
|     count: "fileCount",         // optional "2 / 5" label
|     limits: () => ({ maxFiles, maxFileBytes }),
|     onChange: markDirty,        // optional
|     removeIcon: "<svg>...</svg>", // optional, &times; otherwise
|   });
|
|   teamFiles.load(data.attachments);            // what the server has
|   teamFiles.appendTo(formData, "attachments"); // on save
|
*/

(function () {

  var EXTS = ["pdf", "txt", "md", "doc", "docx", "xls", "xlsx"];

  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  function fileSize(bytes) {
    bytes = Number(bytes) || 0;
    return bytes >= 1024 * 1024
      ? (bytes / 1024 / 1024).toFixed(1) + " MB"
      : Math.max(1, Math.round(bytes / 1024)) + " KB";
  }

  function extOf(name) {
    var m = /\.([a-z0-9]+)$/i.exec(name || "");
    return m ? m[1].toLowerCase() : "";
  }

  window.makeFileList = function (ids) {
    var kept = [];   // [{id, name, ext, size}] from the server
    var added = [];  // [File]
    var touched = false;

    function limits() {
      return ids.limits ? ids.limits() : { maxFiles: 5, maxFileBytes: 8 * 1024 * 1024 };
    }

    function error(message) {
      var box = document.getElementById(ids.error);
      box.textContent = message || "";
      box.classList.toggle("show", Boolean(message));
    }

    function changed() {
      touched = true;
      if (ids.onChange) ids.onChange();
    }

    function add(files) {
      var max = limits().maxFiles;
      var bytes = limits().maxFileBytes;

      error("");
      var room = max - kept.length - added.length;
      if (files.length > room) {
        error("Up to " + max + " files can be attached.");
        files = files.slice(0, Math.max(room, 0));
      }
      files.forEach(function (file) {
        if (EXTS.indexOf(extOf(file.name)) === -1) {
          error("Only PDF, TXT, MD, Word and Excel files can be attached.");
          return;
        }
        if (file.size > bytes) {
          error('"' + file.name + '" is larger than ' + Math.round(bytes / 1024 / 1024) + " MB.");
          return;
        }
        added.push(file);
        changed();
      });
      paint();
    }

    function remove(kind, index) {
      if (kind === "kept") kept.splice(index, 1);
      else added.splice(index, 1);
      changed();
      error("");
      paint();
    }

    function paint() {
      var max = limits().maxFiles;
      var rows = kept
        .map(function (f, i) { return { name: f.name, size: f.size, kind: "kept", i: i }; })
        .concat(added.map(function (f, i) { return { name: f.name, size: f.size, kind: "new", i: i }; }));

      document.getElementById(ids.list).innerHTML = rows.map(function (r) {
        return '<li><span class="ext">' + esc(extOf(r.name) || "file") + "</span>"
          + '<span class="fname" dir="auto" title="' + esc(r.name) + '">' + esc(r.name) + "</span>"
          + "<small>" + fileSize(r.size) + "</small>"
          + '<button type="button"' + (ids.removeIcon ? ' class="icon-btn"' : "")
          + ' aria-label="Remove ' + esc(r.name) + '" onclick="' + ids.name
          + ".remove('" + r.kind + "'," + r.i + ')">'
          + (ids.removeIcon || "&times;") + "</button></li>";
      }).join("");

      if (ids.count) document.getElementById(ids.count).textContent = rows.length + " / " + max;
      document.getElementById(ids.addBtn).hidden = rows.length >= max;
    }

    document.getElementById(ids.input).addEventListener("change", function () {
      add(Array.from(this.files || []));
      this.value = "";
    });

    return {
      remove: remove,
      load: function (files) {
        kept = (files || []).slice();
        added = [];
        touched = false;
        error("");
        paint();
      },
      /* keep_<field>[] is what stays, <field>[] the new files, and
         keep_<field>_sent says the list was sent - only when changed. */
      appendTo: function (form, field) {
        if (!touched) return;
        form.append("keep_" + field + "_sent", "1");
        kept.forEach(function (f) { form.append("keep_" + field + "[]", f.id); });
        added.forEach(function (file) { form.append(field + "[]", file); });
      },
    };
  };

})();
