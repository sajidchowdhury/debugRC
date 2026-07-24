{{--
  x-erp.checkbox-card — selectable card for multi-select lists (e.g. dispatcher picker).

  Usage:
    <x-erp.checkbox-card name="dispatchers[]" value="emp-1"
        label="Karim Uddin" sublabel="EMP-DSP-HO-01 • 01711-123456"
        accent="red" :checked="in_array('emp-1', old('dispatchers', []))" />

  Showcase spec (selected): border-2 border-{c}-400 bg-{c}-50 p-3 rounded-lg
  flex items-center gap-3 cursor-pointer transition-all
  (unselected): border-2 border-gray-200 hover:border-amber-300

  Note: the checkbox `accent-{c}-600` utility needs a static map (Tailwind v4
  doesn't generate dynamically interpolated classes).
--}}
@props([
    'name' => '',
    'value' => '',
    'label' => '',
    'sublabel' => '',
    'checked' => false,
    'accent' => 'amber',
    'disabled' => false,
    'id' => null,
])

@php
    $a = App\Support\Accents::get($accent);

    // Static map for the checkbox accent-color utility (Tailwind v4 safe)
    $checkboxAccentMap = [
        'amber' => 'accent-amber-600',
        'orange' => 'accent-orange-600',
        'cyan' => 'accent-cyan-600',
        'green' => 'accent-green-600',
        'red' => 'accent-red-600',
        'yellow' => 'accent-yellow-600',
        'blue' => 'accent-blue-600',
        'gray' => 'accent-gray-600',
    ];
    $checkboxAccent = $checkboxAccentMap[$accent] ?? 'accent-amber-600';

    $cardClasses = $checked
        ? implode(' ', ['p-3 rounded-lg flex items-center gap-3 cursor-pointer transition-all border-2', $a['border_400'], $a['bg_50']])
        : 'p-3 rounded-lg flex items-center gap-3 cursor-pointer transition-all border-2 border-gray-200 hover:border-amber-300';

    if ($disabled) {
        $cardClasses .= ' opacity-50 pointer-events-none';
    }

    $fieldId = $id ?: ('cb-' . str_replace(['[', ']'], '-', $name) . '-' . $value);
@endphp

<label for="{{ $fieldId }}" class="{{ $cardClasses }}">
    <input type="checkbox" id="{{ $fieldId }}" name="{{ $name }}"
        value="{{ $value }}" class="size-4 {{ $checkboxAccent }}"
        {{ $checked ? 'checked' : '' }} {{ $disabled ? 'disabled' : '' }} />
    <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-800 truncate">{{ $label }}</p>
        @if ($sublabel)
            <p class="text-xs text-gray-500 truncate">{{ $sublabel }}</p>
        @endif
    </div>
    @if ($checked)
        <x-erp.icon name="check-circle" class="size-4 {{ $a['text_600'] }}" />
    @endif
</label>
