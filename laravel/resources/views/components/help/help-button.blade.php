@props([
    'menuKey' => null,
])

@php
/**
 * Help Button — floating fixed corner button (Door 1 trigger).
 * Phase 2 scaffold: visible, basic styling. Phase 8 polishes to premium gradient.
 *
 * @var string|null $menuKey  Resolved by layout from the current route.
 */
@endphp

@if(auth()->check())
<button
    type="button"
    id="helpButton"
    class="help-fab"
    aria-label="সাহায্য"
    title="সাহায্য"
    data-menu-key="{{ $menuKey ?? '' }}"
    data-help-url="{{ route('help.menu', ['key' => '__KEY__']) }}"
>
    <i class="fa-solid fa-question" aria-hidden="true"></i>
</button>
@endif
