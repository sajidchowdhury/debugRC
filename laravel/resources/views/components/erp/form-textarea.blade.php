{{--
  x-erp.form-textarea — labeled textarea with validation.

  Usage:
    <x-erp.form-textarea name="notes" label="Dispatcher Notes" label-bn="ডিসপ্যাচার নোট"
        :rows="3" placeholder="অতিরিক্ত নির্দেশনা..." field-class="md:col-span-2" />

  Showcase spec: w-full rounded-lg border border-gray-200 p-3 text-sm
  resize-y focus:ring-2 focus:ring-amber-300
--}}
@props([
    'name' => '',
    'label' => '',
    'labelBn' => '',
    'value' => null,
    'rows' => 3,
    'id' => null,
    'required' => false,
    'fieldClass' => '',
])

@php
    $fieldId = $id ?: $name;
    $oldValue = old($name, $value);
    $error = isset($errors) ? $errors->first($name) : '';
    $hasError = !empty($error);

    $fieldBase = 'w-full border rounded-lg p-3 text-sm outline-none focus:ring-2 transition-shadow resize-y placeholder:text-gray-400 disabled:bg-gray-50';
    $fieldBase .= $hasError
        ? ' border-red-400 focus:ring-red-300 focus:border-red-300'
        : ' border-gray-200 focus:ring-amber-300 focus:border-amber-300';

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
    <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="{{ $rows }}"
        {{ $required ? 'required' : '' }}
        class="{{ $fieldBase }} {{ $inputClass }}" {{ $inputAttrs }}>{{ $oldValue }}</textarea>
    @if ($hasError)
        <p class="text-[11px] text-red-600 mt-1">{{ $error }}</p>
    @endif
</div>
