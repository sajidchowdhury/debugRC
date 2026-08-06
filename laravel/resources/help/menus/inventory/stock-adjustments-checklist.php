<?php

/**
 * Help content for: inventory.stock-adjustments-checklist
 * Route: admin.stock-adjustments.checklist
 *
 * Pre-adjustment checklist sub-page — steps to verify before posting a
 * stock adjustment (reconcile, confirm reason, get approval).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-adjustments-checklist',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Adjustment Checklist',
    'title_en'   => 'Stock Adjustment Checklist',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি স্টক অ্যাডজাস্টমেন্টের চেকলিস্ট পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',    'text' => 'পোস্ট পূর্ববর্তী চেকলিস্ট দেখা'],
        ['icon' => 'fa-circle-check', 'text' => 'রিকনসাইল ও অ্যাপ্রুভাল হয়েছে কিনা নিশ্চিত হওয়া'],
    ],

    'impacts' => [
        ['who' => 'প্রসেস',  'what' => 'চেকলিস্ট মানলে ভুল কারেকশন কমে'],
    ],

    'cautions' => [
        'শুধু দেখার/গাইডের জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-adjustments',
        'inventory.stock-adjustments-reconcile',
    ],

    'updated_at' => '2026-08-07',
];
