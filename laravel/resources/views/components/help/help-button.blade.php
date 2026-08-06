@props([
    'menuKey' => null,
])

@php
/**
 * Help Button — floating fixed corner button (Door 1 trigger).
 *
 * 48×48px circular, gradient amber→orange, soft shadow, idle float animation.
 * Position: fixed bottom-right (above the footer pill).
 * Aria-label: "সাহায্য" (Bangla for "Help").
 *
 * @var string|null $menuKey  Resolved by layout from the current route.
 */
@endphp

@if(auth()->check())
<button
    type="button"
    id="helpButton"
    class="help-fab"
    aria-label="সাহায্য — এই পেজের বাংলা ব্যাখ্যা দেখুন"
    title="সাহায্য (?)"
    data-menu-key="{{ $menuKey ?? '' }}"
    data-help-url="{{ route('help.menu', ['key' => '__KEY__']) }}"
>
    <i class="fa-solid fa-question" aria-hidden="true"></i>
    <span class="help-fab__pulse" aria-hidden="true"></span>
</button>
@endif
