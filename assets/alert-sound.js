/*
 * The sound for a new message or a new notification.
 *
 * Loaded on every admin page before notifications.js and messages-badge.js,
 * which ask it to ring when their check finds something new. A short two-note
 * tone made with the Web Audio API, so there is no file to ship: rising for a
 * message, falling for a notification.
 *
 * Browsers only let a page make sound after someone has clicked or typed on
 * it, so the audio is switched on at the first click or key press on each
 * page; until then, or with no Web Audio at all, the sound is silently
 * skipped. Muting (the speaker button on admin-messages.html) is remembered
 * in this browser and covers both kinds.
 *
 * Only something that arrives while the page is open rings: the first look
 * after a page load just sets the mark. With several admin pages open, only
 * the first tab to see a new id rings for it.
 */
(function () {
  "use strict";

  var MUTE_KEY = "wz_alert_sound_muted";
  var TUNES = {
    message:      [[880, 0], [1320, 0.13]],
    notification: [[1175, 0], [784, 0.13]]
  };

  var audio = null;
  var marks = {};   // kind -> the newest id this page has accounted for

  function muted() {
    try { return localStorage.getItem(MUTE_KEY) === "1"; } catch (e) { return false; }
  }

  function setMuted(on) {
    try { localStorage.setItem(MUTE_KEY, on ? "1" : "0"); } catch (e) {}
  }

  function unlock() {
    if (!audio) {
      var Context = window.AudioContext || window.webkitAudioContext;
      if (!Context) return;
      try { audio = new Context(); } catch (e) { return; }
    }
    if (audio.state === "suspended") audio.resume().catch(function () {});
  }
  document.addEventListener("pointerdown", unlock, true);
  document.addEventListener("keydown", unlock, true);

  function play(kind) {
    if (muted() || !audio || audio.state !== "running") return;
    try {
      var now = audio.currentTime;
      (TUNES[kind] || TUNES.message).forEach(function (note) {
        var tone = audio.createOscillator();
        var volume = audio.createGain();
        var at = now + note[1];
        tone.type = "sine";
        tone.frequency.value = note[0];
        // Loud enough to hear on laptop speakers; at 0.06 it went unnoticed.
        volume.gain.setValueAtTime(0.0001, at);
        volume.gain.exponentialRampToValueAtTime(0.25, at + 0.015);
        volume.gain.exponentialRampToValueAtTime(0.0001, at + 0.35);
        tone.connect(volume).connect(audio.destination);
        tone.start(at);
        tone.stop(at + 0.38);
      });
    } catch (e) {
      /* No sound is the right failure. */
    }
  }

  /*
   * newest: the newest id the caller has just seen. ring=false only moves the
   * mark - for things the person is looking at anyway, like a chat they open.
   */
  function notice(kind, newest, ring) {
    newest = Number(newest) || 0;
    var mark = marks[kind];
    if (ring !== false && mark !== undefined && newest > mark) {
      // "id:time" of the last ring in any tab. It only has to outlast one
      // round of checks (slowed to a minute in a background tab), so after five
      // minutes it is ignored - ids starting over on a fresh database can't
      // keep the sound quiet.
      var key = "wz_alert_heard_" + kind;
      var shared = 0;
      try {
        var last = String(localStorage.getItem(key) || "").split(":");
        if (Date.now() - Number(last[1]) < 300000) shared = Number(last[0]) || 0;
      } catch (e) {}
      if (newest > shared) {
        try { localStorage.setItem(key, newest + ":" + Date.now()); } catch (e) {}
        play(kind);
      }
    }
    marks[kind] = Math.max(mark || 0, newest);
  }

  window.WzAlertSound = { muted: muted, setMuted: setMuted, notice: notice, play: play };
})();
