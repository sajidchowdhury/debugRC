@php
/**
 * Menu Content — rendered server-side, fetched via /help/menu/{key} into the offcanvas.
 * Phase 2 scaffold: shows the full content when $content exists, or a friendly
 * "not yet written" empty-state card when it does not (Phase 7 authors content).
 *
 * @var string $key         e.g. 'sales.invoice'
 * @var array|null $content  The menu content array (§5.1 schema) or null.
 * @var array|null $module    The parent module meta (for colour tinting) or null.
 */

$colorToken = $module['color'] ?? 'slate';
$empty = $content === null;
@endphp

@if($empty)
    {{-- Friendly empty-state (HTTP 200, not 404) --}}
    <div class="help-empty-state" data-help-color="{{ $colorToken }}">
        <div class="help-empty-state__icon" aria-hidden="true">
            <i class="fa-regular fa-circle-question"></i>
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
        <hr class="my-3">
        <p class="small text-muted">
            🧭 পুরো সিস্টেম গাইড দেখতে নিচের "<strong>My Creative Code Guide</strong>" বাটনে ক্লিক করুন।
        </p>
    </div>
@else
    {{-- Full menu content (Phase 7) — rendered per §5.1 schema --}}
    <div class="help-menu-content" data-help-color="{{ $colorToken }}">
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

        @if(!empty($content['summary']))
            <p class="help-menu-content__summary">{{ $content['summary'] }}</p>
        @endif

        @if(!empty($content['for_roles']))
            <div class="help-menu-content__roles">
                <span class="help-section-label">কাদের জন্য:</span>
                @foreach($content['for_roles'] as $role)
                    <span class="help-role-chip">{{ $role }}</span>
                @endforeach
            </div>
        @endif

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

        @if(!empty($content['related']))
            <div class="help-menu-content__section">
                <p class="help-section-label">সম্পর্কিত:</p>
                <div class="help-related-chips">
                    @foreach($content['related'] as $relKey)
                        <button
                            type="button"
                            class="help-related-chip"
                            data-menu-key="{{ $relKey }}"
                        >{{ $relKey }}</button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif
