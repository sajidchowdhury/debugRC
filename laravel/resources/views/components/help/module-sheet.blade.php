@props([
    'helpService' => null,
])

@php
/**
 * Module Sheet — bottom-up sheet listing all 8 modules (Door 2 entry).
 * Phase 2 scaffold: Bootstrap offcanvas-bottom with module cards grid.
 * The 8 modules are pre-rendered server-side (no network call to open the sheet).
 *
 * @var \App\Services\Help\HelpService $helpService
 */
$modules = $helpService ? $helpService->modules() : [];
$colors = [
    'slate' => ['#475569', '#1e293b'],
    'amber' => ['#f59e0b', '#b45309'],
    'sky' => ['#0ea5e9', '#0369a1'],
    'emerald' => ['#10b981', '#047857'],
    'violet' => ['#8b5cf6', '#6d28d9'],
    'rose' => ['#f43f5e', '#be123c'],
    'teal' => ['#14b8a6', '#0f766e'],
    'indigo' => ['#6366f1', '#4338ca'],
];
@endphp

@if(auth()->check())
<div
    class="offcanvas offcanvas-bottom help-module-sheet"
    tabindex="-1"
    id="helpModuleSheet"
    aria-labelledby="helpModuleSheetLabel"
    data-bs-backdrop="true"
>
    <div class="offcanvas-header help-module-sheet__header">
        <h5 class="offcanvas-title" id="helpModuleSheetLabel">
            <span aria-hidden="true">🧭</span> সিস্টেম গাইড — মডিউল বাছুন
        </h5>
        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            aria-label="বন্ধ করুন"
        ></button>
    </div>
    <div class="offcanvas-body help-module-sheet__body">
        <p class="help-module-sheet__hint text-muted small mb-3">
            যে মডিউলটি সম্পর্কে জানতে চান তার কার্ডে ক্লিক করুন।
        </p>
        <div class="help-module-grid">
            @foreach($modules as $modKey => $mod)
                @php
                    [$c1, $c2] = $colors[$mod['color']] ?? $colors['slate'];
                    $menuCount = count($mod['menus'] ?? []);
                @endphp
                <button
                    type="button"
                    class="help-module-card"
                    data-module-key="{{ $modKey }}"
                    style="--mc1: {{ $c1 }}; --mc2: {{ $c2 }};"
                    aria-label="{{ $mod['title_bn'] }} — {{ $mod['title_en'] }}"
                >
                    <span class="help-module-card__icon" aria-hidden="true">
                        <i class="fa-solid {{ $mod['icon'] }}"></i>
                    </span>
                    <span class="help-module-card__body">
                        <span class="help-module-card__title-bn">{{ $mod['title_bn'] }}</span>
                        <span class="help-module-card__title-en">{{ $mod['title_en'] }}</span>
                        <span class="help-module-card__tagline">{{ $mod['tagline'] }}</span>
                    </span>
                    <span class="help-module-card__count">{{ $menuCount }} মেনু</span>
                </button>
            @endforeach
        </div>
    </div>
</div>
@endif
