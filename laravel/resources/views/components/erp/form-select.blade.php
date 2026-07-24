{{--
  x-erp.form-select — labeled dropdown with validation.

  Usage:
    <x-erp.form-select name="warehouse_id" label="Warehouse" label-bn="গুদাম"
        placeholder="Select warehouse"
        :options="[['value' => 'wh1', 'label' => 'WH-HO-01 Dhaka Main'], ...]" />
    {{-- Simple assoc array also works: :options="['wh1' => 'WH-HO-01', 'wh2' => 'WH-HO-02']" --}}

  Features:
  - Bilingual label, required asterisk
  - Repopulates selected from old() on validation failure
  - Inline error display
  - Pass-through: disabled, onchange, etc.
--}}
@props([
    'name' => '',
    'label' => '',
    'labelBn' => '',
    'options' => [],
    'selected' => null,
    'placeholder' => '',
    'id' => null,
    'required' => false,
    'fieldClass' => '',
])

@php
    $fieldId = $id ?: $name;
    $oldSelected = old($name, $selected);
    $error = isset($errors) ? $errors->first($name) : '';
    $hasError = !empty($error);

    $fieldBase = 'w-full border rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 transition-shadow bg-white disabled:bg-gray-50';
    $fieldBase .= $hasError
        ? ' border-red-400 focus:ring-red-300 focus:border-red-300'
        : ' border-gray-200 focus:ring-amber-300 focus:border-amber-300';

    $inputAttrs = clone $attributes;
    $inputAttrs = $inputAttrs->except(['class']);
    $inputClass = $attributes->get('class', '');

    // Normalize options to [{value, label, labelBn?}] shape
    $normalized = [];
    foreach ($options as $key => $opt) {
        if (is_array($opt) && isset($opt['value'])) {
            $normalized[] = $opt;
        } elseif (is_array($opt) && count($opt) >= 2) {
            $normalized[] = ['value' => $opt[0], 'label' => $opt[1], 'labelBn' => $opt[2] ?? null];
        } else {
            // Simple assoc: [value => label]
            $normalized[] = ['value' => $key, 'label' => $opt, 'labelBn' => null];
        }
    }
@endphp

<div class="{{ $fieldClass }}">
    @if ($label)
        <label for="{{ $fieldId }}" class="block text-xs font-medium text-gray-600 mb-1">
            {{ $label }}
            @if ($labelBn)<span class="text-gray-400 font-normal ml-1">/ {{ $labelBn }}</span>@endif
            @if ($required)<span class="text-red-500 ml-0.5">*</span>@endif
        </label>
    @endif
    <select id="{{ $fieldId }}" name="{{ $name }}" {{ $required ? 'required' : '' }}
        class="{{ $fieldBase }} {{ $inputClass }}" {{ $inputAttrs }}>
        @if ($placeholder)
            <option value="" {{ empty($oldSelected) ? 'selected' : '' }}>{{ $placeholder }}</option>
        @endif
        @foreach ($normalized as $opt)
            <option value="{{ $opt['value'] }}"
                {{ (string) $oldSelected === (string) $opt['value'] ? 'selected' : '' }}
                {{ ($opt['disabled'] ?? false) ? 'disabled' : '' }}>
                {{ $opt['label'] }}@if (!empty($opt['labelBn'])) / {{ $opt['labelBn'] }}@endif
            </option>
        @endforeach
    </select>
    @if ($hasError)
        <p class="text-[11px] text-red-600 mt-1">{{ $error }}</p>
    @endif
</div>
