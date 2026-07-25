{{--
  x-erp.icon — inline SVG icon (Lucide-style, stroke-based).

  Usage:
    <x-erp.icon name="warehouse" class="size-5 text-amber-500" />
    <x-erp.icon name="clock" class="size-3" />

  The icon registry below covers ~22 icons used across the RC ERP design
  system. Add new icons by appending a @case. All SVGs use viewBox 0 0 24 24,
  stroke=currentColor, stroke-width=2 (Lucide convention).

  Size: pass via Tailwind `size-*` class (default size-4). Color: text-* utility.
--}}
@props([
    'name' => 'box',
])

@php
    $svgContent = '';
    switch ($name) {
        case 'warehouse':
            $svgContent = '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/><path d="M6 10h12"/>';
            break;
        case 'truck':
            $svgContent = '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>';
            break;
        case 'clock':
            $svgContent = '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>';
            break;
        case 'clipboard-list':
            $svgContent = '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>';
            break;
        case 'check-circle':
            $svgContent = '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>';
            break;
        case 'x-circle':
            $svgContent = '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>';
            break;
        case 'file-edit':
            $svgContent = '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><path d="M10.4 12.6 8 15l1.4 1.4"/><path d="M8 15h6"/>';
            break;
        case 'file-text':
            $svgContent = '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/>';
            break;
        case 'alert-triangle':
            $svgContent = '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>';
            break;
        case 'chevron-right':
            $svgContent = '<polyline points="9 18 15 12 9 6"/>';
            break;
        case 'chevron-down':
            $svgContent = '<polyline points="6 9 12 15 18 9"/>';
            break;
        case 'printer':
            $svgContent = '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>';
            break;
        case 'users':
            $svgContent = '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>';
            break;
        case 'package':
            $svgContent = '<path d="m7.5 4.27 9 5.15"/><path d="M3.5 4.27 12 9.42l8.5-5.15"/><path d="M12 21.15l-8.5-5.15V4.27l8.5 5.15"/><path d="m12 21.15 8.5-5.15V4.27L12 9.42"/>';
            break;
        case 'banknote':
            $svgContent = '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>';
            break;
        case 'pencil':
            $svgContent = '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>';
            break;
        case 'x':
            $svgContent = '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>';
            break;
        case 'bell':
            $svgContent = '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>';
            break;
        case 'map-pin':
            $svgContent = '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>';
            break;
        case 'inbox':
            $svgContent = '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>';
            break;
        case 'arrow-left':
            $svgContent = '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>';
            break;
        case 'arrow-right':
            $svgContent = '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>';
            break;
        case 'layout-grid':
            $svgContent = '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="9" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="9" x="3" y="12" rx="1"/>';
            break;
        case 'eye':
            $svgContent = '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>';
            break;
        case 'plus':
            $svgContent = '<path d="M5 12h14"/><path d="M12 5v14"/>';
            break;
        case 'search':
            $svgContent = '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>';
            break;
        case 'download':
            $svgContent = '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>';
            break;
        case 'filter':
            $svgContent = '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>';
            break;
        case 'list':
            $svgContent = '<line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/>';
            break;
        case 'rotate-ccw':
            $svgContent = '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>';
            break;
        case 'save':
            $svgContent = '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>';
            break;
        case 'check':
            $svgContent = '<polyline points="20 6 9 17 4 12"/>';
            break;
        case 'more-horizontal':
            $svgContent = '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>';
            break;
        case 'ban':
            $svgContent = '<circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 14.14 14.14"/>';
            break;
        case 'box':
        default:
            $svgContent = '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>';
            break;
    }
@endphp

<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes->merge(['class' => 'size-4']) }}>{!! $svgContent !!}</svg>
