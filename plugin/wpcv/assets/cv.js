/* Category filters progressively enhance the complete server-rendered CV. */
(() => {
  "use strict";

  document.querySelectorAll("[data-wpcv]").forEach((cv) => {
    const filters = cv.querySelector("[data-wpcv-filters]");
    if (!filters) return;

    const buttons = [...filters.querySelectorAll("[data-wpcv-filter]")];
    const entries = [...cv.querySelectorAll("[data-wpcv-entry]")];
    const count = cv.querySelector("[data-wpcv-count]");
    const empty = cv.querySelector("[data-wpcv-empty]");

    filters.addEventListener("click", (event) => {
      const button = event.target.closest("[data-wpcv-filter]");
      if (!button || !filters.contains(button)) return;

      const category = button.dataset.wpcvFilter;
      let visible = 0;
      entries.forEach((entry) => {
        const categories = entry.dataset.categories.split(" ");
        const matches = category === "all" || categories.includes(category);
        entry.hidden = !matches;
        if (matches) visible++;
      });
      buttons.forEach((item) => {
        item.setAttribute("aria-pressed", String(item === button));
      });
      const message = visible === 1 ? count.dataset.one : count.dataset.many;
      count.textContent = message.replace("%d", visible.toLocaleString());
      empty.hidden = visible !== 0;
    });

    filters.hidden = false;
  });
})();
