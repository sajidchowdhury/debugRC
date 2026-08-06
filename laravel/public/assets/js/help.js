/* =========================================================================
   Help System JS — Phase 4.
   ~200 lines vanilla JS. No jQuery, no Alpine. Uses Bootstrap 5 data-API
   for show/hide; this file wires the fetch + content injection + Mermaid.

   Door 1: floating help button → right offcanvas with current page's menu content.
   Door 2: footer pill → bottom-up module sheet → module offcanvas → menu offcanvas.

   Mermaid is lazy-loaded: when a [data-mermaid-key] block is injected into the
   DOM, this script injects the Mermaid CDN <script> tag once and calls
   mermaid.run() on the visible block.
   ========================================================================= */
(function () {
    'use strict';

    var CFG = window.HELP_CONFIG || {};
    if (!CFG.endpoints || !CFG.endpoints.menu) return;

    var MERMAID_CDN = 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js';
    var mermaidLoading = false;   // script tag in flight
    var mermaidReady = false;     // mermaid global available
    var pendingMermaid = [];     // blocks waiting for mermaid to finish loading

    // ---- Bootstrap Offcanvas helpers ----
    function getOffcanvas(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        if (window.bootstrap && window.bootstrap.Offcanvas) {
            return window.bootstrap.Offcanvas.getOrCreateInstance(el);
        }
        return null;
    }
    function showOffcanvas(id) { var oc = getOffcanvas(id); if (oc) oc.show(); return oc; }
    function hideOffcanvas(id) { var oc = getOffcanvas(id); if (oc) oc.hide(); }

    // ---- Fetch + inject ----
    function loadInto(targetBodyId, url, afterInject) {
        var body = document.getElementById(targetBodyId);
        if (!body) return;
        setLoadingPlaceholder(body);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) return Promise.reject(r.status);
                return r.text();
            })
            .then(function (html) {
                body.innerHTML = html;
                body.classList.remove('help-body--fade-in');
                // Force reflow so the animation re-triggers on each load.
                void body.offsetWidth;
                body.classList.add('help-body--fade-in');
                if (afterInject) afterInject(body);
            })
            .catch(function () {
                body.innerHTML = errorStateHtml();
            });
    }

    function setLoadingPlaceholder(body) {
        body.innerHTML =
            '<div class="help-loading-placeholder">' +
            '<div class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></div>' +
            '<span class="ms-2 text-muted">লোড হচ্ছে…</span></div>';
    }

    function errorStateHtml() {
        return '<div class="help-empty-state" data-help-color="slate">' +
            '<div class="help-empty-state__icon"><i class="fa-regular fa-circle-question"></i></div>' +
            '<h6 class="help-empty-state__title">লোড করা যায়নি</h6>' +
            '<p class="help-empty-state__text">সাহায্য তথ্য আনা যায়নি। আবার চেষ্টা করুন।</p></div>';
    }

    function urlFor(endpointTpl, key) {
        return endpointTpl.replace('__KEY__', encodeURIComponent(key));
    }

    // ---- Module colour tint application ----
    var COLOR_MAP = {
        slate:  ['#475569', '#1e293b'],
        amber:  ['#f59e0b', '#b45309'],
        sky:    ['#0ea5e9', '#0369a1'],
        emerald:['#10b981', '#047857'],
        violet: ['#8b5cf6', '#6d28d9'],
        rose:   ['#f43f5e', '#be123c'],
        teal:   ['#14b8a6', '#0f766e'],
        indigo: ['#6366f1', '#4338ca']
    };

    function applyTintFromContent(body, offcanvasId) {
        var oc = document.getElementById(offcanvasId);
        if (!oc) return;
        var tinted = body.querySelector('[data-help-color]');
        var color = tinted ? tinted.getAttribute('data-help-color') : 'slate';
        var header = oc.querySelector('.offcanvas-header');
        if (header) header.setAttribute('data-help-color', color);
        var pair = COLOR_MAP[color] || COLOR_MAP.slate;
        oc.style.setProperty('--help-tint-c1', pair[0]);
        oc.style.setProperty('--help-tint-c2', pair[1]);
    }

    // ---- Mermaid lazy-load ----
    function ensureMermaidThen(cb) {
        if (mermaidReady) { cb(); return; }
        pendingMermaid.push(cb);
        if (mermaidLoading) return;
        mermaidLoading = true;
        var s = document.createElement('script');
        s.src = MERMAID_CDN;
        s.async = true;
        s.onload = function () {
            if (window.mermaid) {
                window.mermaid.initialize({
                    startOnLoad: false,
                    theme: 'default',
                    securityLevel: 'loose',
                    fontFamily: 'inherit'
                });
                mermaidReady = true;
                var queue = pendingMermaid.splice(0);
                queue.forEach(function (fn) { try { fn(); } catch (e) {} });
            }
        };
        document.head.appendChild(s);
    }

    function renderMermaidIn(body) {
        var blocks = body.querySelectorAll('.help-mermaid-wrap[data-mermaid-key] pre.mermaid:not([data-mermaid-rendered])');
        if (!blocks.length) return;
        ensureMermaidThen(function () {
            blocks.forEach(function (el) {
                el.setAttribute('data-mermaid-rendered', '1');
                try {
                    // mermaid 10+ API: mermaid.run({ nodes: [...] })
                    window.mermaid.run({ nodes: [el] });
                } catch (e) {
                    // If rendering fails, leave the raw source visible (still readable).
                    el.classList.add('help-mermaid-block--error');
                }
            });
            // Trigger the fade-in animation on the wrapper.
            var wrap = body.querySelector('.help-mermaid-wrap');
            if (wrap) {
                wrap.classList.remove('help-mermaid-wrap--rendered');
                void wrap.offsetWidth;
                wrap.classList.add('help-mermaid-wrap--rendered');
            }
        });
    }

    // ---- Door 1: floating help button → right offcanvas with menu content ----
    function openMenuOffcanvas(menuKey) {
        if (!menuKey) {
            // No key for this page — open the offcanvas with an empty-state directly.
            var body = document.getElementById('helpOffcanvasBody');
            if (body) {
                body.innerHTML =
                    '<div class="help-empty-state" data-help-color="slate">' +
                    '<div class="help-empty-state__icon"><i class="fa-regular fa-circle-question"></i></div>' +
                    '<h6 class="help-empty-state__title">এই পেজের সাহায্য নেই</h6>' +
                    '<p class="help-empty-state__text">এই পেজের জন্য কোনো সাহায্য মেনু নেই।</p>' +
                    '<p class="small text-muted">🧭 পুরো সিস্টেম গাইড দেখতে নিচের "My Creative Code Guide" বাটনে ক্লিক করুন।</p></div>';
                applyTintFromContent(body, 'helpOffcanvas');
            }
            showOffcanvas('helpOffcanvas');
            return;
        }
        loadInto('helpOffcanvasBody', urlFor(CFG.endpoints.menu, menuKey), function (body) {
            applyTintFromContent(body, 'helpOffcanvas');
            renderMermaidIn(body);
        });
        showOffcanvas('helpOffcanvas');
    }

    // ---- Door 2: footer pill → module sheet ----
    function openModuleSheet() {
        showOffcanvas('helpModuleSheet');
    }

    // ---- Door 2: module card → module offcanvas ----
    function openModuleOffcanvas(moduleKey) {
        hideOffcanvas('helpModuleSheet');
        loadInto('helpModuleOffcanvasBody', urlFor(CFG.endpoints.module, moduleKey), function (body) {
            applyTintFromContent(body, 'helpModuleOffcanvas');
            renderMermaidIn(body);
        });
        showOffcanvas('helpModuleOffcanvas');
    }

    // ---- Door 2: menu chip inside module offcanvas → menu offcanvas ----
    function openMenuFromModule(menuKey) {
        hideOffcanvas('helpModuleOffcanvas');
        // Small delay so the module offcanvas starts closing before the menu one opens
        // (prevents both backdrops from stacking on mobile).
        setTimeout(function () { openMenuOffcanvas(menuKey); }, 180);
    }

    // ---- Event wiring (delegated) ----
    document.addEventListener('click', function (e) {
        // Help FAB
        var fab = e.target.closest('#helpButton');
        if (fab) {
            e.preventDefault();
            var key = fab.getAttribute('data-menu-key') || CFG.currentMenuKey || '';
            openMenuOffcanvas(key);
            return;
        }
        // Footer pill
        var pill = e.target.closest('#guideFooterPill');
        if (pill) {
            e.preventDefault();
            openModuleSheet();
            return;
        }
        // Module card (in the bottom sheet)
        var card = e.target.closest('.help-module-card[data-module-key]');
        if (card) {
            e.preventDefault();
            openModuleOffcanvas(card.getAttribute('data-module-key'));
            return;
        }
        // Menu item (in module offcanvas) OR related chip (in menu offcanvas)
        var menuItem = e.target.closest('[data-menu-key]');
        if (menuItem && (menuItem.classList.contains('help-module-menu-item') || menuItem.classList.contains('help-related-chip'))) {
            e.preventDefault();
            openMenuFromModule(menuItem.getAttribute('data-menu-key'));
            return;
        }
    });

    // Keyboard: "?" toggles current-page help (Phase 9 nice-to-have, but handler is ready).
    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
        if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            var fab = document.getElementById('helpButton');
            if (fab) fab.click();
        }
    });

    if (window.console && console.debug) {
        console.debug('[help-system] Phase 4 initialised; currentMenuKey=' + (CFG.currentMenuKey || '(none)'));
    }
})();
