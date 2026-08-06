/* =========================================================================
   Help System JS — Phase 2 scaffold.
   ~150 lines vanilla JS. No jQuery, no Alpine. Uses Bootstrap 5 data-API
   for show/hide; this file wires the fetch + content injection.
   ========================================================================= */
(function () {
    'use strict';

    var CFG = window.HELP_CONFIG || {};
    if (!CFG.endpoints || !CFG.endpoints.menu) return;

    // Bootstrap Offcanvas instances (lazily resolved)
    function getOffcanvas(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        // bootstrap.Offcanvas.getOrCreateInstance (BS 5.3+)
        if (window.bootstrap && window.bootstrap.Offcanvas) {
            return window.bootstrap.Offcanvas.getOrCreateInstance(el);
        }
        return null;
    }

    function showOffcanvas(id) {
        var oc = getOffcanvas(id);
        if (oc) oc.show();
        return oc;
    }

    function hideOffcanvas(id) {
        var oc = getOffcanvas(id);
        if (oc) oc.hide();
    }

    // Fetch a URL and inject HTML into a target element's body.
    function loadInto(targetBodyId, url, afterInject) {
        var body = document.getElementById(targetBodyId);
        if (!body) return;
        body.innerHTML =
            '<div class="help-loading-placeholder">' +
            '<div class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></div>' +
            '<span class="ms-2 text-muted">লোড হচ্ছে…</span></div>';
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
            .then(function (html) { body.innerHTML = html; if (afterInject) afterInject(body); })
            .catch(function () {
                body.innerHTML =
                    '<div class="help-empty-state" data-help-color="slate">' +
                    '<div class="help-empty-state__icon"><i class="fa-regular fa-circle-question"></i></div>' +
                    '<h6 class="help-empty-state__title">লোড করা যায়নি</h6>' +
                    '<p class="help-empty-state__text">সাহায্য তথ্য আনা যায়নি। আবার চেষ্টা করুন।</p></div>';
            });
    }

    // Build a help endpoint URL by replacing the __KEY__ placeholder.
    function urlFor(endpointTpl, key) {
        return endpointTpl.replace('__KEY__', encodeURIComponent(key));
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
            }
            showOffcanvas('helpOffcanvas');
            return;
        }
        loadInto('helpOffcanvasBody', urlFor(CFG.endpoints.menu, menuKey), function (body) {
            applyTintFromContent(body, 'helpOffcanvas');
        });
        showOffcanvas('helpOffcanvas');
    }

    // Apply the module colour tint to the offcanvas header based on injected content.
    function applyTintFromContent(body, offcanvasId) {
        var oc = document.getElementById(offcanvasId);
        if (!oc) return;
        var tinted = body.querySelector('[data-help-color]');
        var color = tinted ? tinted.getAttribute('data-help-color') : 'slate';
        var header = oc.querySelector('.offcanvas-header');
        if (header) header.setAttribute('data-help-color', color);
        // map color token to CSS vars
        var map = {
            slate: ['#475569', '#1e293b'], amber: ['#f59e0b', '#b45309'],
            sky: ['#0ea5e9', '#0369a1'], emerald: ['#10b981', '#047857'],
            violet: ['#8b5cf6', '#6d28d9'], rose: ['#f43f5e', '#be123c'],
            teal: ['#14b8a6', '#0f766e'], indigo: ['#6366f1', '#4338ca']
        };
        var pair = map[color] || map.slate;
        oc.style.setProperty('--help-tint-c1', pair[0]);
        oc.style.setProperty('--help-tint-c2', pair[1]);
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
        });
        showOffcanvas('helpModuleOffcanvas');
    }

    // ---- Door 2: menu chip inside module offcanvas → menu offcanvas ----
    function openMenuFromModule(menuKey) {
        hideOffcanvas('helpModuleOffcanvas');
        openMenuOffcanvas(menuKey);
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
        // Ignore when typing in a field.
        var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
        if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            var fab = document.getElementById('helpButton');
            if (fab) fab.click();
        }
    });

    // Console banner (helps confirm the script loaded during Phase 2 testing).
    if (window.console && console.debug) {
        console.debug('[help-system] initialised; currentMenuKey=' + (CFG.currentMenuKey || '(none)'));
    }
})();
