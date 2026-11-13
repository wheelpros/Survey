(function () {
  fetch('/api/public-config.php', { cache: 'no-store' })
    .then(function (r) { return r.ok ? r.json() : {}; })
    .then(function (cfg) {
      if (!cfg.clarity_id) return;
      (function (c, l, a, r, i, t, y) {
        c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments); };
        t = l.createElement(r); t.async = 1; t.src = "https://www.clarity.ms/tag/" + i;
        y = l.getElementsByTagName(r)[0]; y.parentNode.insertBefore(t, y);
      })(window, document, "clarity", "script", cfg.clarity_id);
    })
    .catch(function () {});
})();