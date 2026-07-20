     

        // Initialize
        $(document).ready(function() {
            // Sidebar restore
            if (localStorage.getItem('sidebarMini') === 'true' && window.innerWidth >= 992) {
                document.getElementById('sidebar').classList.add('mini');
                document.getElementById('mainContent').classList.add('expanded');
            }

            if (typeof syncFooterSidebarOffset === 'function') syncFooterSidebarOffset();
            if (typeof initFooterDropup === 'function') initFooterDropup();

            // Mobile sidebar close
            $('#sidebarMenu a.nav-link').on('click', function() {
                if (!$(this).attr('data-bs-toggle')) closeSidebarOnMobile();
            });
        });
        
        /* Sidebar Toggle (mobile) */
        // === SIDEBAR FUNCTIONS ===
        function toggleMiniSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('mini');
            mainContent.classList.toggle('expanded');
            localStorage.setItem('sidebarMini', sidebar.classList.contains('mini'));
            if (typeof syncFooterSidebarOffset === 'function') syncFooterSidebarOffset();
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        }

        function closeSidebarOnMobile() {
            if (window.innerWidth < 992) {
                document.getElementById('sidebar').classList.remove('active');
                document.getElementById('sidebarOverlay').classList.remove('active');
            }
        }


function syncFooterSidebarOffset() {
    const sidebar = document.getElementById('sidebar');
    document.body.classList.toggle(
        'sidebar-mini-footer',
        !!(sidebar && sidebar.classList.contains('mini') && window.innerWidth >= 992)
    );
}

function initFooterDropup() {
    const footer = document.getElementById('creativeFooter');
    const trigger = document.getElementById('footerDropupTrigger');
    const panel = document.getElementById('footerDropupPanel');
    const backdrop = document.getElementById('footerDropupBackdrop');
    const closeBtn = document.getElementById('footerDropupClose');

    if (!footer || !trigger || !panel || !backdrop) {
        return;
    }

    if (footer.dataset.dropupInit === '1') {
        return;
    }
    footer.dataset.dropupInit = '1';

    function openDropup() {
        footer.classList.add('is-dropup-open');
        panel.classList.add('is-open');
        backdrop.hidden = false;
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
        });
        trigger.setAttribute('aria-expanded', 'true');
        panel.setAttribute('aria-hidden', 'false');
    }

    function closeDropup() {
        footer.classList.remove('is-dropup-open');
        panel.classList.remove('is-open');
        backdrop.classList.remove('is-visible');
        trigger.setAttribute('aria-expanded', 'false');
        panel.setAttribute('aria-hidden', 'true');
        window.setTimeout(function () {
            if (!panel.classList.contains('is-open')) {
                backdrop.hidden = true;
            }
        }, 260);
    }

    function toggleDropup() {
        if (panel.classList.contains('is-open')) {
            closeDropup();
        } else {
            openDropup();
        }
    }

    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        toggleDropup();
    });

    closeBtn && closeBtn.addEventListener('click', closeDropup);
    backdrop.addEventListener('click', closeDropup);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('is-open')) {
            closeDropup();
        }
    });

    window.addEventListener('resize', syncFooterSidebarOffset);
}

// Load saved state
if (localStorage.getItem('sidebarCollapsed') === 'true') {
    const sidebarEl = document.getElementById('sidebar');
    if (sidebarEl) {
        sidebarEl.classList.add('collapsed');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    syncFooterSidebarOffset();
    initFooterDropup();
});