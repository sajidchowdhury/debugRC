<?php

namespace App\Support;

/**
 * Invoice status → visual config map (canonical single source of truth).
 *
 * Maps each SalesInvoice->status value to its label (EN + BN), Tailwind
 * color name, full badge class string, and icon name (for <x-erp.icon>).
 *
 * Status → color mapping (matches rc-erp-ui-showcase.html):
 *   draft                → gray
 *   finalized            → amber   ("Needs Godown")
 *   blank_godown_created → orange  ("Blank Godown")
 *   godown_prepared      → cyan    ("Ready for Challan")
 *   challan_issued       → green   ("Completed")
 *   cancelled            → red
 *
 * Usage:
 *   StatusPalette::get('blank_godown_created')  // full config array
 *   StatusPalette::label('blank_godown_created')
 *   StatusPalette::badgeClass('blank_godown_created')
 */

class StatusPalette
{
    public const DRAFT = 'draft';
    public const FINALIZED = 'finalized';
    public const BLANK_GODOWN_CREATED = 'blank_godown_created';
    public const GODOWN_PREPARED = 'godown_prepared';
    public const CHALLAN_ISSUED = 'challan_issued';
    public const CANCELLED = 'cancelled';

    /**
     * Full status config: label, label_bn, color, badge_class, icon.
     *
     * @return array{status:string,label:string,label_bn:string,color:string,badge_class:string,icon:string}
     */
    public static function get(?string $status): array
    {
        return self::all()[$status] ?? self::all()[self::DRAFT];
    }

    /** English label (may differ from DB value, e.g. "Needs Godown" for finalized). */
    public static function label(?string $status): string
    {
        return self::get($status)['label'];
    }

    /** Bengali label. */
    public static function labelBn(?string $status): string
    {
        return self::get($status)['label_bn'];
    }

    /** Full Tailwind classes for the status pill: bg + text + border. */
    public static function badgeClass(?string $status): string
    {
        return self::get($status)['badge_class'];
    }

    /** Icon name (for <x-erp.icon name="...">). */
    public static function icon(?string $status): string
    {
        return self::get($status)['icon'];
    }

    /**
     * Return the full HTML for a status pill (for use in render closures
     * inside data-table cols, where Blade components can't be nested).
     *
     * Usage in a col definition:
     *   ['key' => 'status', 'header' => 'Status', 'render' => fn($row) => StatusPalette::pillHtml($row['status'])]
     */
    public static function pillHtml(?string $status, bool $bilingual = false): string
    {
        $config = self::get($status);
        $label = $bilingual
            ? $config['label'] . ' / ' . $config['label_bn']
            : $config['label'];
        $iconPath = self::iconSvgPath($config['icon']);
        $classes = 'font-medium text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1 ' . $config['badge_class'];
        return '<span class="' . e($classes) . '">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3" aria-hidden="true">' . $iconPath . '</svg>'
            . e($label)
            . '</span>';
    }

    /** SVG path inner content for a given icon name (matches x-erp.icon registry). */
    private static function iconSvgPath(string $name): string
    {
        $paths = [
            'file-edit' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/><path d="M10.4 12.6 8 15l1.4 1.4"/><path d="M8 15h6"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
            'warehouse' => '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/><path d="M6 10h12"/>',
            'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        ];
        return $paths[$name] ?? $paths['file-edit'];
    }

    /** Ordered list of workflow statuses for dashboard stat cards / filter chips. */
    public static function workflowStatuses(): array
    {
        return [
            self::FINALIZED,
            self::BLANK_GODOWN_CREATED,
            self::GODOWN_PREPARED,
            self::CHALLAN_ISSUED,
        ];
    }

    /**
     * @return array<string, array{status:string,label:string,label_bn:string,color:string,badge_class:string,icon:string}>
     */
    public static function all(): array
    {
        return [
            self::DRAFT => [
                'status' => self::DRAFT,
                'label' => 'Draft',
                'label_bn' => 'খসড়া',
                'color' => 'gray',
                'badge_class' => 'bg-gray-100 text-gray-700 border border-gray-300',
                'icon' => 'file-edit',
            ],
            self::FINALIZED => [
                'status' => self::FINALIZED,
                'label' => 'Needs Godown',
                'label_bn' => 'গোডাউন প্রয়োজন',
                'color' => 'amber',
                'badge_class' => 'bg-amber-100 text-amber-700 border border-amber-300',
                'icon' => 'clock',
            ],
            self::BLANK_GODOWN_CREATED => [
                'status' => self::BLANK_GODOWN_CREATED,
                'label' => 'Blank Godown',
                'label_bn' => 'ব্লাঙ্ক গোডাউন',
                'color' => 'orange',
                'badge_class' => 'bg-orange-100 text-orange-700 border border-orange-300',
                'icon' => 'clipboard-list',
            ],
            self::GODOWN_PREPARED => [
                'status' => self::GODOWN_PREPARED,
                'label' => 'Ready for Challan',
                'label_bn' => 'চালানের জন্য প্রস্তুত',
                'color' => 'cyan',
                'badge_class' => 'bg-cyan-100 text-cyan-700 border border-cyan-300',
                'icon' => 'warehouse',
            ],
            self::CHALLAN_ISSUED => [
                'status' => self::CHALLAN_ISSUED,
                'label' => 'Completed',
                'label_bn' => 'সম্পন্ন',
                'color' => 'green',
                'badge_class' => 'bg-green-100 text-green-700 border border-green-300',
                'icon' => 'check-circle',
            ],
            self::CANCELLED => [
                'status' => self::CANCELLED,
                'label' => 'Cancelled',
                'label_bn' => 'বাতিল',
                'color' => 'red',
                'badge_class' => 'bg-red-100 text-red-700 border border-red-300',
                'icon' => 'x-circle',
            ],
        ];
    }
}
