(() => {
  "use strict";
  const key = "eis-docs-pdf-refresh";
  // Reloading the top-level page recreates all menu frames as well.
  if (window.parent !== window) return;
  const channel = typeof BroadcastChannel === "function" ? new BroadcastChannel(key) : null;
  let reloading = false;
  function reload() {
    if (reloading) return;
    reloading = true;
    setTimeout(() => window.location.reload(), 150);
  }
  if (channel) channel.addEventListener("message", event => {
    if (event.data?.type === key) reload();
  });
  window.addEventListener("storage", event => {
    if (event.key === key && event.newValue) reload();
  });
  window.refreshAllDocumentPages = () => {
    const message = {type: key, id: `${Date.now()}-${Math.random()}`};
    channel?.postMessage(message);
    try { localStorage.setItem(key, JSON.stringify(message)); } catch (_) {}
    reload();
  };
})();