(function () {
  // Legacy compatibility stub only.
  // Historically some integrations loaded /embed/form.js directly.
  // Keep this file non-breaking and point integrators to the official loader.
  if (typeof console !== 'undefined' && typeof console.warn === 'function') {
    console.warn('[Deprecated] /embed/form.js is legacy-only. Use /embed/embed.js instead.');
  }
})();
