// Loaded in <head> (not deferred) so the saved theme applies before first paint.
(function () {
    var saved = null;
    try { saved = localStorage.getItem('theme'); } catch (e) { /* storage blocked */ }
    var theme = saved === 'light' || saved === 'dark'
        ? saved
        : (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-bs-theme', theme);
})();
