/*
 * Language selector: keep the reader on the same page when they switch language.
 *
 * Zensical's selector only links to a fixed address (the home page of the other language). The site root of the
 * current language is `__md_scope`; English lives at the root of the site and every other language under `<locale>/`.
 */
(function () {
  function otherLanguageUrl(target) {
    var scope = window.__md_scope; // URL of the site root of the current language, ends with "/"
    if (!scope) {
      return null;
    }
    var current = document.documentElement.lang.slice(0, 2);
    var path = location.pathname.slice(scope.pathname.length);
    var root = current === "en" ? scope : new URL("../", scope);
    var base = target === "en" ? root : new URL(target + "/", root);

    return base.pathname + path + location.search + location.hash;
  }

  function update() {
    document.querySelectorAll("a.md-select__link[hreflang]").forEach(function (link) {
      var url = otherLanguageUrl(link.getAttribute("hreflang"));
      if (url) {
        link.setAttribute("href", url);
      }
    });
  }

  if (window.document$ && typeof window.document$.subscribe === "function") {
    window.document$.subscribe(update); // instant navigation replaces the page without reloading the script
  } else {
    document.addEventListener("DOMContentLoaded", update);
  }
  // The hash changes without a navigation: keep the links in sync.
  window.addEventListener("hashchange", update);
})();
