/*
 * Minecraft Toolkit — panel-side bootstrap.
 *
 * No external imports: this file only claims a global namespace for future
 * toolkit JS (map canvas, inventory drag) and keeps Livewire SPA navigations
 * in mind so long-lived widgets can subscribe safely per-page later.
 */
(function () {
    'use strict';

    const MCT = Object.assign({}, window.__MCTOOLKIT__ || {}, {
        version: '1.0.0',
    });

    window.__MCTOOLKIT__ = MCT;

    // Placeholder hook: pages that need re-init after SPA navigation register
    // their own callbacks here (see design-system §13 teardown discipline).
    document.addEventListener('livewire:navigated', function () {}, false);
})();
