<?php

/**
 * Help content for: system.archive-customerLedger
 * Route: admin.archive.customer-ledger (uri: admin/archive/customer-ledger/{customerId})
 *
 * Drills into a single customer's archived/historical ledger entries — the
 * read-only view of past-period receivables, payments, and adjustments.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.archive-customerLedger',
    'module'     => 'system',
    'title_bn'   => 'Customer Ledger Archive',
    'title_en'   => 'Customer Ledger Archive',
    'icon'       => 'fa-book',
    'summary'    => 'একজন খদ্দেরের পুরোনো লেজার এন্ট্রি রিড-অনলি হিসেবে দেখা — পিরিয়ড ধরে ফিল্টার করে খুঁজুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-book',             'text' => 'নির্দিষ্ট খদ্দেরের পুরোনো লেজার এন্ট্রি দেখা'],
        ['icon' => 'fa-calendar-days',   'text' => 'পিরিয়ড ধরে ফিল্টার করা (মাস/কোয়ার্টার/অর্থবছর)'],
    ],

    'impacts' => [
        ['who' => 'পুরোনো ডেটা',  'what' => 'রিড-অনলি — কোনো বদল হয় না'],
        ['who' => 'কমপ্লায়েন্স', 'what' => 'পুরোনো পিরিয়ডের খদ্দের হিসাব রিটেনশনে থাকে'],
    ],

    'cautions' => [
        'আর্কাইভ এন্ট্রি এডিট/ডিলিট করা যায় না — ভুল ধরলে নতুন অ্যাডজাস্টমেন্ট লাইভ লেজারে দিতে হবে।',
    ],

    'related' => ['system.archive', 'system.archive-supplierLedger'],

    'updated_at' => '2026-08-07',
];
