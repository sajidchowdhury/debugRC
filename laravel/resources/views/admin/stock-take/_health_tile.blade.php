{{--
  Phase 12 — Stock Take Health tile (admin dashboard partial).

  Self-contained Bootstrap card that fetches the stock-take health summary
  via AJAX from `admin.stock-take.health-summary` and renders pass/warn/fail
  badges. Renders only for users whose session role is in
  {admin, manager, accountant} — the same set the route middleware admits.

  Include it from the dashboard:
      @include('admin.stock-take._health_tile')

  The tile is intentionally a single-file partial (own <style> + <script>)
  so it can be dropped into any view without external dependencies beyond
  Bootstrap 5 (already loaded by the admin layout) and a CSRF meta tag
  (Laravel's standard `<meta name="csrf-token">`).
--}}
@php
    $role = session('role');
    $canViewTile = in_array($role, ['admin', 'manager', 'accountant'], true);
@endphp

@if ($canViewTile)
@once
<style>
    .stk-health-tile .stk-skeleton { min-height: 92px; }
    .stk-health-tile .stk-badge {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .25rem .55rem; border-radius: 999px; font-weight: 600;
        font-size: .85rem; line-height: 1;
    }
    .stk-health-tile .stk-badge .stk-dot {
        width: .5rem; height: .5rem; border-radius: 50%; display: inline-block;
    }
    .stk-health-tile .stk-fail-list {
        max-height: 140px; overflow-y: auto;
        font-size: .8rem; color: #6b7280;
    }
    .stk-health-tile .stk-fail-list li { padding: .15rem 0; }
    .stk-health-tile .stk-link {
        font-size: .8rem; text-decoration: none;
    }
    .stk-health-tile .stk-link:hover { text-decoration: underline; }
    .stk-health-tile .stk-err {
        font-size: .75rem; color: #b91c1c;
    }
</style>
@endonce

<div class="card border-0 shadow-sm stk-health-tile h-100" id="stkHealthTile">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <h3 class="h6 mb-0">
            <i class="fas fa-clipboard-check me-1 text-teal"></i> Stock Take Health
        </h3>
        <a href="{{ route('admin.stock-take.checklist') }}" class="stk-link text-muted">
            Full checklist <i class="fas fa-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body py-2">
        {{-- Skeleton shown until fetch completes --}}
        <div id="stkHealthSkeleton" class="stk-skeleton d-flex align-items-center">
            <div class="spinner-border spinner-border-sm text-muted me-2" role="status"></div>
            <span class="text-muted small">Checking stock take health…</span>
        </div>

        {{-- Real content — hidden until the fetch resolves --}}
        <div id="stkHealthContent" class="d-none">
            <div class="d-flex flex-wrap gap-2 mb-2">
                <span class="stk-badge bg-success-subtle text-success">
                    <span class="stk-dot bg-success"></span>
                    <span id="stkHealthPass">0</span> pass
                </span>
                <span class="stk-badge bg-warning-subtle text-warning">
                    <span class="stk-dot bg-warning"></span>
                    <span id="stkHealthWarn">0</span> warn
                </span>
                <span class="stk-badge bg-danger-subtle text-danger">
                    <span class="stk-dot bg-danger"></span>
                    <span id="stkHealthFail">0</span> fail
                </span>
            </div>

            <div id="stkHealthFailWrap" class="d-none mb-2">
                <div class="text-muted small mb-1 fw-semibold">Critical items:</div>
                <ul id="stkHealthFailList" class="stk-fail-list list-unstyled mb-0"></ul>
            </div>

            <div id="stkHealthErr" class="stk-err d-none"></div>

            <div class="text-muted small mt-1">
                <i class="fas fa-clock me-1"></i>
                Ran at <span id="stkHealthRanAt">—</span>
                @if (session('branch_name')) · {{ session('branch_name') }} @endif
            </div>
        </div>
    </div>
</div>

@once
<script>
(function () {
    // Defer until DOM is ready (this partial may be @included mid-page).
    function init() {
        var tile = document.getElementById('stkHealthTile');
        if (!tile) return;
        var skeleton = document.getElementById('stkHealthSkeleton');
        var content  = document.getElementById('stkHealthContent');
        var errBox   = document.getElementById('stkHealthErr');
        var url      = "{{ route('admin.stock-take.health-summary') }}";

        fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        }).then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }).then(function (data) {
            var s = data.summary || { pass: 0, warn: 0, fail: 0, info: 0, total: 0 };
            document.getElementById('stkHealthPass').textContent = s.pass || 0;
            document.getElementById('stkHealthWarn').textContent = s.warn || 0;
            document.getElementById('stkHealthFail').textContent = s.fail || 0;
            document.getElementById('stkHealthRanAt').textContent = data.ran_at || '—';

            var failWrap = document.getElementById('stkHealthFailWrap');
            var failList = document.getElementById('stkHealthFailList');
            var fails = data.critical_failures || [];
            if (fails.length > 0) {
                failWrap.classList.remove('d-none');
                failList.innerHTML = fails.map(function (f) {
                    return '<li><i class="fas fa-circle-xmark text-danger me-1"></i>'
                        + escapeHtml(f.title || f.id)
                        + (f.detail ? ' — <em>' + escapeHtml(f.detail) + '</em>' : '')
                        + '</li>';
                }).join('');
            }

            skeleton.classList.add('d-none');
            content.classList.remove('d-none');
        }).catch(function (err) {
            skeleton.classList.add('d-none');
            errBox.textContent = 'Could not load stock take health: ' + (err.message || err);
            errBox.classList.remove('d-none');
            content.classList.remove('d-none');
        });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
@endonce
@endif
