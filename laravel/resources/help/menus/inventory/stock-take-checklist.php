<?php

/**
 * Help content for: inventory.stock-take-checklist
 * Route: admin.stock-take.checklist
 *
 * Pre-count checklist sub-page — the steps to complete before, during,
 * and after a physical count.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-take-checklist',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Take Checklist',
    'title_en'   => 'Stock Take Checklist',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি স্টক টেকের চেকলিস্ট পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',    'text' => 'কাউন্ট পূর্ববর্তী ও পরবর্তী চেকলিস্ট দেখা'],
        ['icon' => 'fa-circle-check', 'text' => 'প্রতিটি ধাপ পূরণ হয়েছে কিনা নিশ্চিত হওয়া'],
    ],

    'impacts' => [
        ['who' => 'প্রসেস',  'what' => 'চেকলিস্ট মানলে ভ্যারিয়েন্স ভুল কমে'],
    ],

    'cautions' => [
        'শুধু দেখার/গাইডের জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-take',
        'inventory.stock-take-setup',
    ],

    'updated_at' => '2026-08-07',
];
