/*
| Puts the portal's own name on a page: api/brand.php says what it is, set on
| Settings. The HTML carries a neutral fallback, so a failed request leaves a
| page that names nobody rather than a wrong company.
|
|   data-brand="legal"            the footer's name
|   data-brand="name"             the portal's name
|   data-brand-support="email"    a mailto link; its data-brand-card ancestor
|   data-brand-support="whatsapp" stays hidden unless the channel is set
*/
(function () {
  fetch("api/brand.php")
    .then(function (res) { return res.json(); })
    .then(function (brand) {
      var name = brand.portal_name;

      document.title = document.title + " - " + name;

      document.querySelectorAll('[data-brand="name"]').forEach(function (el) {
        el.textContent = name;
      });

      document.querySelectorAll('[data-brand="legal"]').forEach(function (el) {
        el.textContent = brand.legal_name || name;
      });

      var links = {
        email: brand.support_email ? "mailto:" + brand.support_email : "",
        whatsapp: brand.support_whatsapp_link || ""
      };

      document.querySelectorAll("[data-brand-support]").forEach(function (el) {
        var href = links[el.getAttribute("data-brand-support")];
        var card = el.closest("[data-brand-card]");

        if (href && card) {
          el.href = href;
          card.hidden = false;

          var section = card.closest("[data-brand-section]");
          if (section) section.hidden = false;
        }
      });
    })
    .catch(function () {
      // The fallback text stays, and the support cards stay hidden.
    });
})();
