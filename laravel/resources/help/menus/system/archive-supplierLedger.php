<?php

/**
 * Help content for: system.archive-supplierLedger
 * Route: admin.archive.supplier-ledger (uri: admin/archive/supplier-ledger/{supplierId})
 *
 * Drills into a single supplier's archived/historical ledger entries — the
 * read-only view of past-period payables, payments, and adjustments.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.archive-supplierLedger',
    'module'     => 'system',
    'title_bn'   => 'Supplier Ledger Archive',
    'title_en'   => 'Supplier Ledger Archive',
    'icon'       => 'fa-book',
    'summary'    => 'একজন সাপ্লায়ারের পুরোনো লেজার এন্ট্রি রিড-অনলি হিসেবে দেখা — পিরিয়ড ধরে খুঁজুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-book',             'text' => 'নির্দিষ্ট সাপ্লায়ারের পুরোনো লেজার এন্ট্রি দেখা'],
        ['icon' => 'fa-calendar-days',   'text' => 'পিরিয়ড ধরে ফিল্টার করা (মাস/কোয়ার্টার/অর্থবছর)'],
    ],

    'impacts' => [
        ['who' => 'পুরোনো ডেটা',  'what' => 'রিড-অনলি — কোনো বদল হয় না'],
        ['who' => 'কমপ্লায়েন্স', 'what' => 'পুরোনো পিরিয়ডের সাপ্লায়ার হিসাব রিটেনশনে থাকে'],
    ],

    'cautions' => [
        'আর্কাইভ এন্ট্রি এডিট/ডিলিট করা যায় না — ভুল ধরলে নতুন অ্যাডজাস্টমেন্ট লাইভ লেজারে দিতে হবে।',
    ],

    'related' => ['system.archive', 'system.archive-customerLedger'],

    'updated_at' => '2026-08-07',
];
