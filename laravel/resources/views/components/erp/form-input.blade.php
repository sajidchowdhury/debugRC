{{--
  x-erp.form-input — labeled text/number/email input with validation.

  Usage:
    <x-erp.form-input name="transport_name" label="Transport Name" label-bn="পরিবহন নাম"
        placeholder="Hanif Paribahan" />
    <x-erp.form-input name="transport_cost" type="number" label="Transport Cost" :value="150" required />
    <x-erp.form-input name="phone" field-class="md:col-span-2" />

  Features:
  - Bilingual label (English / Bengali) with required asterisk
  - Repopulates from old() on validation failure
  - Shows $errors->first($name) inline with red border + red message
  - focus:ring-2 focus:ring-amber-300 (showcase spec)
  - Pass-through: placeholder, min, max, step, disabled, readonly, etc.
  - `fieldClass` wraps the label+input+error in a container (for grid placement)
--}}
@props([
    'name' => '',
    'label' => '',
    'labelBn' => '',
    'value' => null,
    'type' => 'text',
    'id' => null,
    'required' => false,
    'fieldClass' => '',
])

@php
    $fieldId = $id ?: $name;
    $oldValue = old($name, $value);
    $error = isset($errors) ? $errors->first($name) : '';
    $hasError = !empty($error);

    $fieldBase = 'w-full border rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 transition-shadow placeholder:text-gray-400 disabled:bg-gray-50 disabled:text-gray-400';
    $fieldBase .= $hasError
        ? ' border-red-400 focus:ring-red-300 focus:border-red-300'
        : ' border-gray-200 focus:ring-amber-300 focus:border-amber-300';

    // Build attribute string for the <input>, excluding our managed props
    $inputAttrs = clone $attributes;
    $inputAttrs = $inputAttrs->except(['class']);
    $inputClass = $attributes->get('class', '');
@endphp

<div class="{{ $fieldClass }}">
    @if ($label)
        <label for="{{ $fieldId }}" class="block text-xs font-medium text-gray-600 mb-1">
            {{ $label }}
            @if ($labelBn)<span class="text-gray-400 font-normal ml-1">/ {{ $labelBn }}</span>@endif
            @if ($required)<span class="text-red-500 ml-0.5">*</span>@endif
        </label>
    @endif
    <input id="{{ $fieldId }}" type="{{ $type }}" name="{{ $name }}"
        value="{{ $oldValue }}" {{ $required ? 'required' : '' }}
        class="{{ $fieldBase }} {{ $inputClass }}" {{ $inputAttrs }} />
    @if ($hasError)
        <p class="text-[11px] text-red-600 mt-1">{{ $error }}</p>
    @endif
</div>
