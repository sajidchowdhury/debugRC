@php
/**
 * Menu Content — rendered server-side, fetched via /help/menu/{key} into the offcanvas.
 *
 * Renders the full §5.1 content schema when $content exists, or a friendly
 * "not yet written" empty-state card when it does not (Phase 7 authors content).
 *
 * Sections (in order):
 *   1. Header (icon + Bangla title + English subtitle)
 *   2. Summary card (tinted to module colour)
 *   3. Role chips row ("কাদের জন্য")
 *   4. "কী কাজ করা যায়" icon bullet list
 *   5. "কাদের ডেটা পরিবর্তন করে" mini table
 *   6. "সাবধানতা" callout (only if cautions non-empty)
 *   7. Mermaid diagram block (only if diagram set + snippet attached)
 *   8. Related-menu chips
 *   9. Footer (updated_at + menu key)
 *
 * @var string $key         e.g. 'sales.invoice'
 * @var array|null $content  The menu content array (§5.1 schema) or null.
 * @var array|null $module    The parent module meta (for colour tinting) or null.
 */

$colorToken = $module['color'] ?? 'slate';
$empty = $content === null;

// Friendly role labels (Bangla) — fallback to the raw role string.
$roleLabels = [
    'salesman'   => 'সেলসম্যান',
    'manager'    => 'ম্যানেজার',
    'admin'      => 'অ্যাডমিন',
    'superadmin' => 'সুপার অ্যাডমিন',
    'accountant' => 'অ্যাকাউন্ট্যান্ট',
    'warehouse'  => 'গোডাউন',
    'auditor'    => 'অডিটর',
];
@endphp

@if($empty)
    {{-- Friendly empty-state (HTTP 200, not 404). §9.4 polish: illustration + mailto --}}
    <div class="help-empty-state" data-help-color="{{ $colorToken }}">
        <div class="help-empty-state__illustration" aria-hidden="true">
            <i class="fa-solid fa-feather-pointed"></i>
        </div>
        <h6 class="help-empty-state__title">এই পেজের সাহায্য এখনও তৈরি হয়নি</h6>
        <p class="help-empty-state__text">
            এই মেনুর বাংলা ব্যাখ্যা এখনও লেখা হয়নি। শীঘ্রই যুক্ত হবে।
        </p>
        <p class="help-empty-state__hint small text-muted">
            মেনু কী: <code>{{ $key }}</code>
        </p>
        @if($module)
            <p class="help-empty-state__hint small text-muted">
                মডিউল: <strong>{{ $module['title_bn'] }}</strong>
            </p>
        @endif
        {{-- §9.4 mailto request link. Pre-fills subject + body with the menu key
             so the maintainer knows exactly which page needs content. --}}
        @php
            $supportEmail = config('app.help_support_email', 'support@example.com');
            $mailtoSubject = rawurlencode('সাহায্য লেখার অনুরোধ: ' . $key);
            $mailtoBody = rawurlencode(
                "এই মেনুর জন্য বাংলা সাহায্য লেখার অনুরোধ করা হলো।\n\n" .
                "মেনু কী: " . $key . "\n" .
                "মডিউল: " . ($module['title_bn'] ?? '(অজানা)') . "\n\n" .
                "বিস্তারিত: ..."
            );
            $mailtoHref = "mailto:{$supportEmail}?subject={$mailtoSubject}&body={$mailtoBody}";
        @endphp
        <a href="{{ $mailtoHref }}" class="help-empty-state__mailto">
            <i class="fa-solid fa-envelope" aria-hidden="true"></i>
            অনুরোধ পাঠান
        </a>
        <hr class="my-3">
        <p class="small text-muted">
            🧭 পুরো সিস্টেম গাইড দেখতে নিচের "<strong>My Creative Code Guide</strong>" বাটনে ক্লিক করুন।
        </p>
    </div>
@else
    {{-- Full menu content (§5.1 schema) --}}
    <div class="help-menu-content" data-help-color="{{ $colorToken }}">
        {{-- 1. Header --}}
        <header class="help-menu-content__header">
            <span class="help-menu-content__icon" aria-hidden="true">
                <i class="fa-solid {{ $content['icon'] ?? 'fa-circle-info' }}"></i>
            </span>
            <div>
                <h6 class="help-menu-content__title-bn">{{ $content['title_bn'] ?? $key }}</h6>
                @if(!empty($content['title_en']))
                    <p class="help-menu-content__title-en text-muted small mb-0">{{ $content['title_en'] }}</p>
                @endif
            </div>
        </header>

        {{-- 2. Summary --}}
        @if(!empty($content['summary']))
            <div class="help-menu-content__summary-card">
                <p class="help-menu-content__summary">{{ $content['summary'] }}</p>
            </div>
        @endif

        {{-- 3. Role chips --}}
        @if(!empty($content['for_roles']))
            <div class="help-menu-content__roles">
                <span class="help-section-label">কাদের জন্য:</span>
                @foreach($content['for_roles'] as $role)
                    <span class="help-role-chip">{{ $roleLabels[$role] ?? $role }}</span>
                @endforeach
            </div>
        @endif

        {{-- 4. What you can do --}}
        @if(!empty($content['what_you_can_do']))
            <div class="help-menu-content__section">
                <p class="help-section-label">কী কাজ করা যায়:</p>
                <ul class="help-icon-list">
                    @foreach($content['what_you_can_do'] as $item)
                        <li>
                            <i class="fa-solid {{ $item['icon'] ?? 'fa-circle-dot' }}" aria-hidden="true"></i>
                            <span>{{ $item['text'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- 5. Impacts table --}}
        @if(!empty($content['impacts']))
            <div class="help-menu-content__section">
                <p class="help-section-label">কাদের ডেটা পরিবর্তন করে:</p>
                <table class="help-impacts-table">
                    <tbody>
                        @foreach($content['impacts'] as $imp)
                            <tr>
                                <td class="help-impacts-table__who">{{ $imp['who'] ?? '' }}</td>
                                <td class="help-impacts-table__what">{{ $imp['what'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- 6. Cautions callout --}}
        @if(!empty($content['cautions']))
            <div class="help-callout help-callout--caution">
                <p class="help-callout__title">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    সাবধানতা
                </p>
                <ul class="mb-0">
                    @foreach($content['cautions'] as $caution)
                        <li>{{ $caution }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- 7. Mermaid diagram (only if snippet was attached by HelpService) --}}
        @if(!empty($content['_diagram_mermaid']))
            <div class="help-menu-content__section">
                <p class="help-section-label">কিভাবে চলে:</p>
                <div class="help-mermaid-wrap" data-mermaid-key="{{ $content['diagram'] }}">
                    <pre class="mermaid help-mermaid-block">{{ $content['_diagram_mermaid'] }}</pre>
                </div>
            </div>
        @endif

        {{-- 8. Related menu chips --}}
        @if(!empty($content['related']))
            <div class="help-menu-content__section">
                <p class="help-section-label">সম্পর্কিত:</p>
                <div class="help-related-chips">
                    @foreach($content['related'] as $relKey)
                        @php
                            // Try to extract a friendly label from the menu key.
                            // e.g. 'sales.cart' -> 'Cart', 'master-data.customers' -> 'Customers'
                            $relLabel = $relKey;
                            if (str_contains($relKey, '.')) {
                                $parts = explode('.', $relKey, 2);
                                $slug = end($parts);
                                // Convert 'customer-payment' -> 'Customer Payment'
                                $relLabel = ucwords(str_replace('-', ' ', $slug));
                            }
                        @endphp
                        <button
                            type="button"
                            class="help-related-chip"
                            data-menu-key="{{ $relKey }}"
                            title="{{ $relKey }}"
                        >{{ $relLabel }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 9. Footer --}}
        <footer class="help-menu-content__footer">
            @if(!empty($content['updated_at']))
                <span class="help-menu-content__updated">
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                    আপডেট: {{ $content['updated_at'] }}
                </span>
            @endif
            <span class="help-menu-content__keyhint">কী: <code>{{ $key }}</code></span>
        </footer>
    </div>
@endif
