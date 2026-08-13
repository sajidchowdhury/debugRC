/* =========================================================================
   Help System JS — Phase 9 (Interactive Niceties).
   ~560 lines vanilla JS. No jQuery, no Alpine. Uses Bootstrap 5 data-API
   for show/hide; this file wires the fetch + content injection + Mermaid
   + the Door 2 content-swap UX (module → menu → back).

   Door 1: floating help button → right offcanvas with current page's menu content.
   Door 2: footer pill → bottom-up module sheet → module offcanvas → menu offcanvas
           (with a "← মডিউলে ফিরে যান" back button + breadcrumb when opened from a module).

   Phase 8 additions (§8.1 + §8.2):
   - Content-swap fade-OUT (120ms) → fade-IN (200ms) with CLS-safe min-height.
   - Focus trap inside every open offcanvas (Tab cycles within; Shift+Tab reverse).
   - Focus return to the trigger button on close.
   - aria-expanded synced on the FAB + footer pill.
   - `?` shortcut guarded so it won't re-trigger while a dialog is open.

   Phase 9 additions (§9.1–9.5):
   - §9.1 in-guide search (module sheet): live filter modules + menus by Bangla/English text.
   - §9.2 recently viewed: ★ button beside footer pill opens a popover listing the
     last 5 menus opened (localStorage, degrades gracefully).
   - §9.3 keyboard shortcuts: `?` (current page), `Shift+G` (module sheet).
   - §9.5 print: "প্রিন্ট করুন" button in the menu offcanvas → clean new-window print view.

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

    // ---- Door 2 navigation state (Phase 5 §4.3 content-swap UX) ----
    var currentModuleKey = null;   // module key of the open module offcanvas
    var menuFromModule = false;    // is the menu offcanvas open via the module flow?

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

    // ---- Motion preference (§8.2 prefers-reduced-motion) ----
    function prefersReducedMotion() {
        return !!(window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    // ---- Fetch + inject (§8.1 content-swap fade-OUT → fade-IN, CLS-safe) ----
    // When the body already shows real content, we fade it OUT (120ms) first,
    // preserving its height (min-height) so there is no layout shift (CLS ≈ 0),
    // then fetch + inject the new HTML and fade it IN (200ms). On the first
    // load (placeholder only) or under prefers-reduced-motion we skip the
    // fade-out and go straight to fetch + fade-in.
    function loadInto(targetBodyId, url, afterInject) {
        var body = document.getElementById(targetBodyId);
        if (!body) return;
        var hasContent = body.getAttribute('data-help-loaded') === '1';
        var reduced = prefersReducedMotion();

        function doFetch() {
            setLoadingPlaceholder(body);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r.ok) return Promise.reject(r.status);
                    return r.text();
                })
                .then(function (html) { injectBody(body, html, afterInject); })
                .catch(function () {
                    body.removeAttribute('data-help-loaded');
                    injectBody(body, errorStateHtml(), afterInject);
                });
        }

        if (hasContent && !reduced) {
            // Preserve height during the swap so the drawer does not collapse.
            body.style.minHeight = body.offsetHeight + 'px';
            body.classList.remove('help-body--fade-in');
            // Force reflow so the fade-out animation re-triggers cleanly.
            void body.offsetWidth;
            body.classList.add('help-body--fade-out');
            setTimeout(function () {
                body.classList.remove('help-body--fade-out');
                doFetch();
            }, 120);
        } else {
            doFetch();
        }
    }

    function injectBody(body, html, afterInject) {
        body.innerHTML = html;
        body.setAttribute('data-help-loaded', '1');
        body.classList.remove('help-body--fade-in');
        // Force reflow so the animation re-triggers on each inject.
        void body.offsetWidth;
        body.classList.add('help-body--fade-in');
        // Release the preserved min-height shortly after the fade-in completes
        // so the drawer can settle to its natural height.
        setTimeout(function () { body.style.minHeight = ''; }, 260);
        if (afterInject) afterInject(body);
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

    // ---- Back-bar + breadcrumb (Phase 5 §4.3) ----
    function showBackBar(moduleKey, menuTitle) {
        var bar = document.getElementById('helpOffcanvasBack');
        if (!bar) return;
        bar.hidden = false;
        var moduleTitle = (CFG.moduleTitles && CFG.moduleTitles[moduleKey]) || moduleKey || 'মডিউল';
        var modSpan = bar.querySelector('.help-breadcrumb__module');
        var menuSpan = bar.querySelector('.help-breadcrumb__menu');
        if (modSpan) modSpan.textContent = moduleTitle;
        if (menuSpan) menuSpan.textContent = menuTitle || '';
    }
    function hideBackBar() {
        var bar = document.getElementById('helpOffcanvasBack');
        if (bar) bar.hidden = true;
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
    // fromModule (bool): true when opened via the Door 2 module flow — shows the
    // back bar + breadcrumb. False (default) when opened from the FAB directly.
    function openMenuOffcanvas(menuKey, fromModule) {
        menuFromModule = !!fromModule;

        if (!menuKey) {
            // No key for this page — open the offcanvas with an empty-state directly.
            if (!menuFromModule) hideBackBar();
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
            if (menuFromModule) {
                // Build the breadcrumb from the module title + the menu's Bangla title
                // (read from the injected content's .help-menu-content__title-bn).
                var titleEl = body.querySelector('.help-menu-content__title-bn');
                var menuTitle = titleEl ? (titleEl.textContent || menuKey) : menuKey;
                showBackBar(currentModuleKey, menuTitle);
            } else {
                hideBackBar();
            }
            // §9.2 record recently-viewed + §9.5 reveal the print bar only when
            // real (non-empty-state) menu content was loaded.
            var isRealContent = !!body.querySelector('.help-menu-content');
            if (isRealContent) {
                recordRecentlyViewed(menuKey);
                showPrintBar(true);
            } else {
                showPrintBar(false);
            }
        });
        showOffcanvas('helpOffcanvas');
    }

    // ---- Door 2: footer pill → module sheet ----
    function openModuleSheet() {
        showOffcanvas('helpModuleSheet');
    }

    // ---- Door 2: module card → module offcanvas ----
    function openModuleOffcanvas(moduleKey) {
        currentModuleKey = moduleKey;
        hideOffcanvas('helpModuleSheet');
        loadInto('helpModuleOffcanvasBody', urlFor(CFG.endpoints.module, moduleKey), function (body) {
            applyTintFromContent(body, 'helpModuleOffcanvas');
            renderMermaidIn(body);
        });
        showOffcanvas('helpModuleOffcanvas');
    }

    // ---- Door 2: menu chip inside module offcanvas → menu offcanvas ----
    // Closes the module offcanvas first, then opens the menu offcanvas with the
    // back-bar visible (so the user can return to the module). The 180ms delay
    // lets the module offcanvas start closing before the menu one opens
    // (prevents both backdrops from stacking on mobile).
    function openMenuFromModule(menuKey) {
        hideOffcanvas('helpModuleOffcanvas');
        setTimeout(function () { openMenuOffcanvas(menuKey, true); }, 180);
    }

    // ---- Door 2: back button → reopen the module offcanvas ----
    function backToModule() {
        hideOffcanvas('helpOffcanvas');
        setTimeout(function () {
            if (currentModuleKey) {
                openModuleOffcanvas(currentModuleKey);
            }
        }, 180);
    }

    // ---- Event wiring (delegated) ----
    document.addEventListener('click', function (e) {
        // Help FAB
        var fab = e.target.closest('#helpButton');
        if (fab) {
            e.preventDefault();
            var key = fab.getAttribute('data-menu-key') || CFG.currentMenuKey || '';
            openMenuOffcanvas(key, false);
            return;
        }
        // Back-to-module button (inside the menu offcanvas, Phase 5)
        var backBtn = e.target.closest('#helpBackToModule');
        if (backBtn) {
            e.preventDefault();
            backToModule();
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
        // Menu item (in module offcanvas) — closes module offcanvas, opens menu offcanvas.
        var moduleMenuItem = e.target.closest('.help-module-menu-item[data-menu-key]');
        if (moduleMenuItem) {
            e.preventDefault();
            openMenuFromModule(moduleMenuItem.getAttribute('data-menu-key'));
            return;
        }
        // Related chip (inside the menu offcanvas) — in-place content swap.
        // Preserves the fromModule context (back bar stays if we came from a module).
        var relatedChip = e.target.closest('.help-related-chip[data-menu-key]');
        if (relatedChip) {
            e.preventDefault();
            openMenuOffcanvas(relatedChip.getAttribute('data-menu-key'), menuFromModule);
            return;
        }
    });

    // Reset Door 2 navigation state when the menu offcanvas closes (Esc / backdrop /
    // explicit close) so the next FAB open doesn't show a stale back bar.
    var menuOcEl = document.getElementById('helpOffcanvas');
    if (menuOcEl) {
        menuOcEl.addEventListener('hidden.bs.offcanvas', function () {
            menuFromModule = false;
            hideBackBar();
        });
    }

    // =====================================================================
    // Phase 8 — Accessibility: focus trap + focus return + aria-expanded sync
    // (§8.2). Bootstrap 5 offcanvas focuses the drawer + closes on Esc, but
    // does NOT trap Tab within the drawer or return focus to the trigger.
    // We add both, generically, for every help offcanvas.
    // =====================================================================
    var FOCUSABLE = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';

    function wireFocusManagement(ocId) {
        var ocEl = document.getElementById(ocId);
        if (!ocEl) return;
        var lastTrigger = null;
        var tabHandler = null;

        // Remember the focused element BEFORE the drawer opens (the trigger).
        ocEl.addEventListener('show.bs.offcanvas', function () {
            lastTrigger = document.activeElement;
        });

        // On open: move focus to the first focusable child (close button by
        // default) and activate the Tab trap.
        ocEl.addEventListener('shown.bs.offcanvas', function () {
            var first = ocEl.querySelector(FOCUSABLE);
            if (first) {
                try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
            } else {
                ocEl.focus();
            }
            tabHandler = function (e) {
                if (e.key !== 'Tab') return;
                var focusables = ocEl.querySelectorAll(FOCUSABLE);
                if (!focusables.length) { e.preventDefault(); return; }
                var f = focusables[0];
                var l = focusables[focusables.length - 1];
                var active = document.activeElement;
                if (e.shiftKey && active === f) { e.preventDefault(); l.focus(); }
                else if (!e.shiftKey && active === l) { e.preventDefault(); f.focus(); }
            };
            ocEl.addEventListener('keydown', tabHandler);
        });

        // On close: remove the trap and return focus to the trigger button.
        ocEl.addEventListener('hidden.bs.offcanvas', function () {
            if (tabHandler) { ocEl.removeEventListener('keydown', tabHandler); tabHandler = null; }
            if (lastTrigger && lastTrigger !== document.body &&
                typeof lastTrigger.focus === 'function') {
                try { lastTrigger.focus({ preventScroll: true }); } catch (e) { lastTrigger.focus(); }
                lastTrigger = null;
            }
        });
    }
    wireFocusManagement('helpOffcanvas');
    wireFocusManagement('helpModuleOffcanvas');
    wireFocusManagement('helpModuleSheet');

    // ---- aria-expanded sync on the two toggle triggers ----
    function syncAriaExpanded(triggerId, ocId) {
        var trig = document.getElementById(triggerId);
        var oc = document.getElementById(ocId);
        if (!trig || !oc) return;
        oc.addEventListener('shown.bs.offcanvas', function () {
            trig.setAttribute('aria-expanded', 'true');
        });
        oc.addEventListener('hidden.bs.offcanvas', function () {
            trig.setAttribute('aria-expanded', 'false');
        });
    }
    syncAriaExpanded('helpButton', 'helpOffcanvas');
    syncAriaExpanded('guideFooterPill', 'helpModuleSheet');

    // ---- Is any help offcanvas currently shown? (used to guard the `?` shortcut) ----
    function anyHelpOffcanvasOpen() {
        var ids = ['helpOffcanvas', 'helpModuleOffcanvas', 'helpModuleSheet'];
        return ids.some(function (id) {
            var el = document.getElementById(id);
            return el && el.classList.contains('show');
        });
    }

    // Keyboard: "?" toggles current-page help (Phase 9 nice-to-have, handler ready).
    // Guard: do nothing while a help drawer is already open (focus is trapped
    // inside it) so the shortcut doesn't fight the focus management.
    // §9.3 adds Shift+G to open the module sheet.
    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
        if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            if (anyHelpOffcanvasOpen()) return;
            e.preventDefault();
            var fab = document.getElementById('helpButton');
            if (fab) fab.click();
            return;
        }
        // §9.3 Shift+G → open the module sheet (Door 2 entry).
        if ((e.key === 'G' || e.key === 'g') && e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
            if (anyHelpOffcanvasOpen()) return;
            e.preventDefault();
            openModuleSheet();
            return;
        }
    });

    if (window.console && console.debug) {
        console.debug('[help-system] Phase 9 initialised; currentMenuKey=' + (CFG.currentMenuKey || '(none)'));
    }

    // =====================================================================
    // Phase 9 — Interactive Niceties
    // =====================================================================

    // ---- §9.2 Recently-viewed store (localStorage, graceful degradation) ----
    var RECENT_KEY = 'help:recent';
    var RECENT_MAX = 5;
    var recentStore = null;   // null = unavailable; [] = available but empty
    (function initRecentStore() {
        try {
            var test = '__help_test__';
            window.localStorage.setItem(test, test);
            window.localStorage.removeItem(test);
            recentStore = window.localStorage;
        } catch (e) {
            recentStore = null;   // private mode / disabled / quota
        }
    })();

    function getRecent() {
        if (!recentStore) return [];
        try {
            var raw = recentStore.getItem(RECENT_KEY);
            if (!raw) return [];
            var arr = JSON.parse(raw);
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }
    function setRecent(arr) {
        if (!recentStore) return;
        try { recentStore.setItem(RECENT_KEY, JSON.stringify(arr)); }
        catch (e) { /* quota / disabled — silent */ }
    }
    function recordRecentlyViewed(menuKey) {
        if (!menuKey || !recentStore) return;
        var arr = getRecent().filter(function (k) { return k !== menuKey; });
        arr.unshift(menuKey);
        if (arr.length > RECENT_MAX) arr = arr.slice(0, RECENT_MAX);
        setRecent(arr);
        refreshRecentButton();
    }

    // Resolve a menu_key → {label, moduleKey, color, icon} using CFG.searchIndex.
    function resolveMenuMeta(menuKey) {
        var idx = CFG.searchIndex || [];
        for (var i = 0; i < idx.length; i++) {
            var mod = idx[i];
            var menus = mod.menus || [];
            for (var j = 0; j < menus.length; j++) {
                if (menus[j].key === menuKey) {
                    return { label: menus[j].label, moduleKey: mod.key, color: mod.color, icon: mod.icon };
                }
            }
        }
        return null;
    }

    // Show/hide the ★ button depending on whether there is any history.
    function refreshRecentButton() {
        var btn = document.getElementById('helpRecentBtn');
        if (!btn) return;
        if (!recentStore) { btn.hidden = true; return; }
        var arr = getRecent();
        btn.hidden = arr.length === 0;
    }

    // ---- §9.2 Recently-viewed popover render + toggle ----
    function renderRecentPopover() {
        var list = document.getElementById('helpRecentList');
        if (!list) return;
        var arr = getRecent();
        if (!arr.length) {
            list.innerHTML = '<div class="help-recent-popover__empty">' +
                '<i class="fa-regular fa-clock" aria-hidden="true"></i>' +
                'এখনও কোনো সাহায্য দেখা হয়নি।</div>';
            return;
        }
        var html = '';
        arr.forEach(function (key) {
            var meta = resolveMenuMeta(key);
            var label = meta ? meta.label : key;
            var c1 = (COLOR_MAP[meta && meta.color] || COLOR_MAP.slate)[0];
            var c2 = (COLOR_MAP[meta && meta.color] || COLOR_MAP.slate)[1];
            var icon = meta ? meta.icon : 'fa-circle-dot';
            html +=
                '<button type="button" class="help-recent-item" data-menu-key="' + escapeAttr(key) + '" ' +
                'style="--ri-c1: ' + c1 + '; --ri-c2: ' + c2 + ';" ' +
                'aria-label="' + escapeAttr(label) + '">' +
                '<span class="help-recent-item__icon"><i class="fa-solid ' + icon + '"></i></span>' +
                '<span class="help-recent-item__body">' +
                '<span class="help-recent-item__label">' + escapeHtml(label) + '</span>' +
                '<span class="help-recent-item__key">' + escapeHtml(key) + '</span>' +
                '</span></button>';
        });
        list.innerHTML = html;
    }
    function toggleRecentPopover(forceOpen) {
        var pop = document.getElementById('helpRecentPopover');
        var btn = document.getElementById('helpRecentBtn');
        if (!pop || !btn) return;
        var willOpen = (forceOpen === undefined) ? pop.hidden : forceOpen;
        if (willOpen) {
            renderRecentPopover();
            pop.hidden = false;
            pop.classList.add('help-recent-popover--open');
            btn.setAttribute('aria-expanded', 'true');
        } else {
            pop.hidden = true;
            pop.classList.remove('help-recent-popover--open');
            btn.setAttribute('aria-expanded', 'false');
        }
    }

    // ---- §9.1 In-guide search (module sheet) ----
    function runSearch(query) {
        var q = (query || '').trim().toLowerCase();
        var grid = document.getElementById('helpModuleGrid');
        var hint = document.getElementById('helpSearchHint');
        var clearBtn = document.getElementById('helpSearchClear');
        var resultsBox = document.getElementById('helpSearchResults');
        var resultsList = document.getElementById('helpSearchResultsList');

        if (clearBtn) clearBtn.hidden = !q;

        if (!q) {
            // Reset: show all module cards + hide results.
            if (grid) { grid.querySelectorAll('.help-module-card').forEach(function (c) { c.style.display = ''; }); }
            if (hint) hint.style.display = '';
            if (resultsBox) resultsBox.style.display = 'none';
            if (resultsList) resultsList.innerHTML = '';
            return;
        }

        // Filter module cards by their data-search-text (title_bn + title_en + tagline).
        if (grid) {
            grid.querySelectorAll('.help-module-card').forEach(function (card) {
                var hay = card.getAttribute('data-search-text') || '';
                card.style.display = hay.indexOf(q) !== -1 ? '' : 'none';
            });
        }
        if (hint) hint.style.display = 'none';

        // Build flat menu results from the search index.
        var matches = [];
        var idx = CFG.searchIndex || [];
        idx.forEach(function (mod) {
            (mod.menus || []).forEach(function (m) {
                var labelLc = (m.label || '').toLowerCase();
                var keyLc = (m.key || '').toLowerCase();
                if (labelLc.indexOf(q) !== -1 || keyLc.indexOf(q) !== -1) {
                    matches.push({ key: m.key, label: m.label, moduleKey: mod.key, color: mod.color, icon: mod.icon });
                }
            });
        });

        if (!resultsBox || !resultsList) return;
        if (!matches.length) {
            resultsList.innerHTML = '<div class="help-search-results__empty">কোনো মেনু পাওয়া যায়নি।</div>';
        } else {
            var html = '';
            matches.slice(0, 30).forEach(function (m) {
                var pair = COLOR_MAP[m.color] || COLOR_MAP.slate;
                var modTitle = (CFG.moduleTitles && CFG.moduleTitles[m.moduleKey]) || m.moduleKey;
                html +=
                    '<button type="button" class="help-search-result" data-menu-key="' + escapeAttr(m.key) + '" ' +
                    'style="--sr-c1: ' + pair[0] + '; --sr-c2: ' + pair[1] + ';" ' +
                    'aria-label="' + escapeAttr(m.label) + ' (' + escapeAttr(modTitle) + ')">' +
                    '<span class="help-search-result__icon"><i class="fa-solid ' + m.icon + '"></i></span>' +
                    '<span class="help-search-result__body">' +
                    '<span class="help-search-result__label">' + escapeHtml(m.label) + '</span>' +
                    '<span class="help-search-result__meta">' + escapeHtml(modTitle) + ' · ' + escapeHtml(m.key) + '</span>' +
                    '</span></button>';
            });
            resultsList.innerHTML = html;
        }
        resultsBox.style.display = 'block';
    }

    // ---- §9.5 Print: open a clean print window with the current menu content ----
    function printCurrentMenu() {
        var body = document.getElementById('helpOffcanvasBody');
        if (!body) return;
        var content = body.querySelector('.help-menu-content');
        if (!content) return;
        var titleEl = body.querySelector('.help-menu-content__title-bn');
        var title = titleEl ? (titleEl.textContent || 'সাহায্য') : 'সাহায্য';
        var w = window.open('', '_blank', 'width=720,height=900');
        if (!w) { alert('পপ-আপ ব্লক করা হয়েছে। প্রিন্টের জন্য পপ-আপ অনুমোদন করুন।'); return; }
        w.document.open();
        w.document.write(
            '<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8">' +
            '<meta name="viewport" content="width=device-width, initial-scale=1">' +
            '<title>' + escapeHtml(title) + ' — সাহায্য</title>' +
            '<style>' +
            'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;' +
            'max-width:720px;margin:0 auto;padding:32px 24px;color:#0f172a;line-height:1.6;}' +
            'h1{font-size:1.3rem;margin:0 0 4px;}' +
            '.help-menu-content__title-en{color:#64748b;font-size:0.85rem;margin:0 0 16px;}' +
            '.help-menu-content__summary-card{background:#f8fafc;border-left:4px solid #475569;' +
            'border-radius:8px;padding:12px 14px;margin:0 0 16px;}' +
            '.help-menu-content__summary{margin:0;color:#334155;}' +
            '.help-role-chip{display:inline-block;padding:2px 10px;border-radius:999px;' +
            'background:#f1f5f9;color:#1e293b;font-size:0.78rem;margin:0 4px 4px 0;}' +
            '.help-section-label{font-size:0.74rem;font-weight:700;text-transform:uppercase;' +
            'letter-spacing:.04em;color:#64748b;margin:18px 0 8px;}' +
            '.help-icon-list{list-style:none;padding:0;margin:0;}' +
            '.help-icon-list li{padding:4px 0;display:flex;gap:8px;}' +
            '.help-icon-list li i{color:#475569;margin-top:3px;}' +
            '.help-impacts-table{width:100%;border-collapse:collapse;font-size:0.9rem;}' +
            '.help-impacts-table td{padding:6px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top;}' +
            '.help-impacts-table__who{font-weight:600;width:32%;border-left:3px solid #475569;}' +
            '.help-callout{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;' +
            'padding:10px 12px;margin:12px 0;color:#92400e;}' +
            '.help-callout__title{font-weight:700;margin-bottom:4px;}' +
            '.help-callout ul{padding-left:18px;margin:0;}' +
            '.help-mermaid-wrap{text-align:center;margin:12px 0;}' +
            '.help-menu-content__footer{margin-top:20px;padding-top:10px;' +
            'border-top:1px solid #e2e8f0;font-size:0.74rem;color:#94a3b8;' +
            'display:flex;justify-content:space-between;}' +
            'code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:0.85em;}' +
            '@media print{body{padding:0;}}' +
            '</style></head><body>'
        );
        w.document.write(content.outerHTML);
        w.document.write('</body></html>');
        w.document.close();
        // Give the new window a tick to lay out, then print.
        w.focus();
        setTimeout(function () { try { w.print(); } catch (e) {} }, 300);
    }

    // ---- small HTML/attr escape helpers ----
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function escapeAttr(s) {
        return escapeHtml(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ---- §9.5 Show/hide the print actions bar ----
    function showPrintBar(show) {
        var bar = document.getElementById('helpOffcanvasActions');
        if (!bar) return;
        bar.hidden = !show;
    }

    // ---- Phase 9 event wiring (delegated; appended to the same document) ----
    document.addEventListener('click', function (e) {
        // §9.2 Recently-viewed ★ button toggle
        var recentBtn = e.target.closest('#helpRecentBtn');
        if (recentBtn) {
            e.preventDefault();
            var pop = document.getElementById('helpRecentPopover');
            toggleRecentPopover(pop ? pop.hidden : true);
            return;
        }
        // §9.2 Recently-viewed clear-all
        var recentClear = e.target.closest('#helpRecentClear');
        if (recentClear) {
            e.preventDefault();
            setRecent([]);
            renderRecentPopover();
            refreshRecentButton();
            return;
        }
        // §9.2 Recently-viewed item → open that menu's offcanvas (Door 1 flow)
        var recentItem = e.target.closest('.help-recent-item[data-menu-key]');
        if (recentItem) {
            e.preventDefault();
            toggleRecentPopover(false);
            openMenuOffcanvas(recentItem.getAttribute('data-menu-key'), false);
            return;
        }
        // §9.1 Search result item → open that menu's offcanvas directly
        var sr = e.target.closest('.help-search-result[data-menu-key]');
        if (sr) {
            e.preventDefault();
            hideOffcanvas('helpModuleSheet');
            setTimeout(function () {
                openMenuOffcanvas(sr.getAttribute('data-menu-key'), false);
            }, 180);
            return;
        }
        // §9.5 Print button
        var printBtn = e.target.closest('#helpPrintBtn');
        if (printBtn) {
            e.preventDefault();
            printCurrentMenu();
            return;
        }
        // Click outside the popover → close it (but not when clicking inside it).
        var pop = document.getElementById('helpRecentPopover');
        var rb = document.getElementById('helpRecentBtn');
        if (pop && !pop.hidden && !pop.contains(e.target) && !(rb && rb.contains(e.target))) {
            toggleRecentPopover(false);
        }
    });

    // ---- §9.1 Search input wiring ----
    var searchInput = document.getElementById('helpSearchInput');
    var searchClearBtn = document.getElementById('helpSearchClear');
    if (searchInput) {
        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            var val = searchInput.value || '';
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { runSearch(val); }, 120);
        });
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchInput.value = '';
                runSearch('');
                searchInput.focus();
            }
        });
    }
    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', function () {
            if (searchInput) { searchInput.value = ''; searchInput.focus(); runSearch(''); }
        });
    }
    // Reset the search when the module sheet closes (so it's clean next open).
    var moduleSheetEl = document.getElementById('helpModuleSheet');
    if (moduleSheetEl) {
        moduleSheetEl.addEventListener('hidden.bs.offcanvas', function () {
            if (searchInput) searchInput.value = '';
            runSearch('');
        });
    }

    // ---- §9.2 Init recently-viewed button visibility on load ----
    refreshRecentButton();
    // Close the popover if a help offcanvas opens over it.
    ['helpOffcanvas', 'helpModuleOffcanvas', 'helpModuleSheet'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('show.bs.offcanvas', function () { toggleRecentPopover(false); });
    });

    // =====================================================================
    // Global API — expose openMenuOffcanvas so page-level ? buttons can call it.
    // Usage from any page: window.RC_HELP.open('master-data.branches')
    // Also supports data-page-help="menu-key" on any clickable element.
    // =====================================================================
    window.RC_HELP = {
        /** Open the help offcanvas for a specific menu key. */
        open: function (menuKey) {
            openMenuOffcanvas(menuKey || '', false);
        },
        /** Open the module sheet (Door 2). */
        openModuleSheet: function () {
            openModuleSheet();
        },
        /** Get the current page's menu key. */
        currentKey: function () {
            return CFG.currentMenuKey || '';
        }
    };

    // ---- Auto-wire [data-page-help] clickable elements ----
    // Any element with data-page-help="menu.key" will open that help on click.
    // Example: <button data-page-help="master-data.branches">?</button>
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-page-help]');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            var key = trigger.getAttribute('data-page-help') || CFG.currentMenuKey || '';
            openMenuOffcanvas(key, false);
        }
    });

    // ---- Sticky-bar observer (cart page finalize bar coexistence) ----
    // When a .sales-pos-sticky-bar becomes visible, add a class to <body>
    // so the CSS can lift the help footer pill + FAB above the bar.
    // This is a fallback for browsers without CSS :has() support.
    (function initStickyBarObserver() {
        var bar = document.getElementById('posStickyBar');
        if (!bar) return;

        function sync() {
            var isVisible = bar.classList.contains('visible');
            document.body.classList.toggle('help-sticky-bar-active', isVisible);
        }

        // Observe class changes on the sticky bar
        if (window.MutationObserver) {
            var observer = new MutationObserver(sync);
            observer.observe(bar, { attributes: false, attributeFilter: ['class'], subtree: false });
            // MutationObserver with attributeFilter only watches attributes; for class
            // changes we need attributes: true
            observer.disconnect();
            observer.observe(bar, { attributes: true, attributeFilter: ['class'] });
        }
        // Also sync on page load and periodic check (belt + suspenders)
        sync();
        setInterval(sync, 2000);
    })();
})();
