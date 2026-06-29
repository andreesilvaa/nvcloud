/* Mobile Optimizations — NVCloud / StockVision
   Runs after DOM ready. Only applies logic when window width ≤ 768px.
*/
(function () {
  "use strict";

  function isMobileWidth() {
    return window.innerWidth <= 768;
  }

  function onceDOM(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  /* ─────────────────────────────────────────────────────────────
     NOTE: Legend text abbreviation (Fornecedor(Reparação) → (Rep))
     is handled purely via CSS .forn-rep-full / .forn-rep-short —
     no JavaScript needed.
  ───────────────────────────────────────────────────────────── */

  /* ─────────────────────────────────────────────────────────────
     Revisão: move the month-picker navigation to BELOW the KPI
     cards (and before the progress bar) on mobile.
  ───────────────────────────────────────────────────────────── */
  function relocateRevMonthPicker() {
    if (!isMobileWidth()) return;
    try {
      var nav = document.querySelector(".rev-header-nav");
      var kpis = document.querySelector(".rev-kpis");
      if (!nav || !kpis) return;

      // Wrap in a centred container and insert after KPIs
      var wrapper = document.createElement("div");
      wrapper.className = "rev-mes-picker-mobile";
      wrapper.appendChild(nav.cloneNode(true)); // clone so original (hidden) stays in DOM
      kpis.parentNode.insertBefore(wrapper, kpis.nextElementSibling);
    } catch (e) {
      /* silent */
    }
  }

  /* ─────────────────────────────────────────────────────────────
     Initialise on DOM ready
  ───────────────────────────────────────────────────────────── */
  onceDOM(function () {
    try {
      relocateRevMonthPicker();
    } catch (e) {}
  });
})();
