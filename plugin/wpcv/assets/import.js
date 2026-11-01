/* Import small server-authorized batches; state and cursor remain server-owned. */
(() => {
  "use strict";
  const root = document.querySelector("[data-wpcv-import]");
  const start = root?.querySelector("[data-import-start]");
  if (!start) return;

  const error = root.querySelector("[data-import-error]");
  const cancel = root.querySelector("[data-import-cancel] button");
  const progress = root.querySelector("progress");
  let running = false;
  let cursor = Number(root.dataset.cursor);

  const update = (state) => {
    if (!state || typeof state.cursor !== "number") return;
    cursor = state.cursor;
    progress.value = cursor;
    root.querySelector("[data-progress-count]").textContent = cursor;
    root.querySelector("[data-created]").textContent = state.created;
    root.querySelector("[data-skipped]").textContent = state.skipped;
  };

  start.addEventListener("click", async () => {
    if (running) return;
    running = true;
    start.disabled = true;
    cancel.disabled = true;
    start.textContent = wpcvImport.working;
    error.hidden = true;
    root.querySelector("[data-import-progress]").hidden = false;
    try {
      while (running) {
        const response = await fetch(wpcvImport.url, {
          method: "POST",
          credentials: "same-origin",
          body: new URLSearchParams({
            action: "wpcv_import_step",
            token: root.dataset.token,
            nonce: root.dataset.nonce,
            cursor: String(cursor),
          }),
        });
        let result;
        try {
          result = await response.json();
        } catch {
          throw new Error(wpcvImport.error);
        }
        if (!result || typeof result !== "object") throw new Error(wpcvImport.error);
        if (!response.ok || !result.success) {
          update(result.data?.progress);
          throw new Error(result.data?.message || wpcvImport.error);
        }
        const previous = cursor;
        update(result.data);
        if (result.data.finished) {
          window.location.reload();
          return;
        }
        if (cursor <= previous) throw new Error(wpcvImport.error);
      }
    } catch (failure) {
      error.querySelector("p").textContent =
        failure instanceof TypeError ? wpcvImport.error : failure.message || wpcvImport.error;
      error.hidden = false;
      start.textContent = wpcvImport.resume;
      start.disabled = false;
      cancel.disabled = false;
      running = false;
    }
  });
})();
