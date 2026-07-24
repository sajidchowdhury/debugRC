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
