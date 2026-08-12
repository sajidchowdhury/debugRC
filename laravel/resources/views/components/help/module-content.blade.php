@php
/**
 * Module Content — rendered server-side, fetched via /help/module/{key}.
 * Phase 2 scaffold: shows module intro + clickable menu list. Phase 7 fills
 * the intro; Phase 5 adds the content-swap to menu offcanvas.
 *
 * @var string $key           e.g. 'sales'
 * @var array|null $module    The module meta from modules.php, or null.
 */
$empty = $module === null;
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
$colorToken = $module['color'] ?? 'slate';
[$c1, $c2] = $colors[$colorToken] ?? $colors['slate'];
$menus = $module['menus'] ?? [];
@endphp

@if($empty)
    <div class="help-empty-state" data-help-color="slate">
        <div class="help-empty-state__icon"><i class="fa-regular fa-circle-question"></i></div>
        <h6 class="help-empty-state__title">মডিউল পাওয়া যায়নি</h6>
        <p class="help-empty-state__text">
            "<code>{{ $key }}</code>" মডিউলটি খুঁজে পাওয়া যায়নি।
        </p>
    </div>
@else
    <div class="help-module-content" data-help-color="{{ $colorToken }}" style="--mc1: {{ $c1 }}; --mc2: {{ $c2 }};">
        {{-- 1. Header (icon + Bangla title + English title) --}}
        <header class="help-module-content__header">
            <span class="help-module-content__icon"><i class="fa-solid {{ $module['icon'] }}"></i></span>
            <div>
                <h6 class="help-module-content__title-bn">{{ $module['title_bn'] }}</h6>
                <p class="help-module-content__title-en text-muted small mb-0">{{ $module['title_en'] }}</p>
            </div>
        </header>

        {{-- 2. Tagline --}}
        <p class="help-module-content__tagline">{{ $module['tagline'] }}</p>

        {{-- 3. Intro paragraph (1-paragraph Bangla overview — Phase 5) --}}
        @if(!empty($module['intro']))
            <p class="help-module-content__intro">{{ $module['intro'] }}</p>
        @endif

        {{-- 4. Mini cycle diagram (only if snippet was attached by HelpService) --}}
        @if(!empty($module['_diagram_mermaid']))
            <div class="help-module-content__section">
                <p class="help-section-label">এই মডিউলের সাইকেল:</p>
                <div class="help-mermaid-wrap" data-mermaid-key="{{ $module['diagram'] }}">
                    <pre class="mermaid help-mermaid-block">{{ $module['_diagram_mermaid'] }}</pre>
                </div>
            </div>
        @endif

        {{-- 5. Menu list (clickable chips — opens menu offcanvas on click) --}}
        <div class="help-module-content__section">
            <p class="help-section-label">এই মডিউলের মেনুসমূহ ({{ count($menus) }} টি):</p>
            <div class="help-module-menu-list">
                @foreach($menus as $menuKey)
                    @php
                        // Derive a friendlier label from the menu key.
                        // e.g. 'sales.cart' -> 'Cart', 'master-data.customers' -> 'Customers'.
                        // Phase 7 will replace with proper Bangla labels from authored content.
                        $seg = explode('.', $menuKey, 2);
                        $slug = $seg[1] ?? $menuKey;
                        $label = ucwords(str_replace(['-', '_'], ' ', $slug));
                    @endphp
                    <button
                        type="button"
                        class="help-module-menu-item"
                        data-menu-key="{{ $menuKey }}"
                        aria-label="{{ $label }}"
                    >
                        <span class="help-module-menu-item__icon" aria-hidden="true">
                            <i class="fa-solid fa-angle-right"></i>
                        </span>
                        <span class="help-module-menu-item__label">{{ $label }}</span>
                        <span class="help-module-menu-item__key" aria-hidden="true">{{ $menuKey }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <p class="help-module-content__hint small text-muted mt-3 mb-0">
            ℹ️ মেনুর বিস্তারিত বাংলা ব্যাখ্যা দেখতে মেনুর নামে ক্লিক করুন।
        </p>
    </div>
@endif
