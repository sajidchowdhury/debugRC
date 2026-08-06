<?php

/**
 * Help content for: accounting.reconciliation-section
 * Route: admin.reconciliation.section
 *
 * Drill-down view for a single reconciliation section (customers / suppliers /
 * employees / bank / cash) — the section's reconciled vs unreconciled entries,
 * and a status summary.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.reconciliation-section',
    'module'     => 'accounting',
    'title_bn'   => 'রিকনসিলিয়েশন সেকশন',
    'title_en'   => 'Reconciliation Section',
    'icon'       => 'fa-table-list',
    'summary'    => 'এটি reconciliation-এর সেকশন ভিউ — একটা সাব-লেজারের রিকনসাইল্ড বনাম আনরিকনসাইলড এন্ট্রি বিস্তারিত দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-table-list',  'text' => 'সেকশনের রিকনসাইল্ড ও আনরিকনসাইলড এন্ট্রি দেখা'],
        ['icon' => 'fa-filter',      'text' => 'তারিখ/পার্টি ধরে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'সেকশনের রিকন স্ট্যাটাস রিপোর্ট তৈরি হয় — রিড-অনলি'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি এন্ট্রি বদলানো যায় না, ম্যাচিং সংশ্লিষ্ট সাব-লেজার পেজ থেকে করতে হবে।',
    ],

    'related' => ['accounting.reconciliation', 'accounting.bank-reconciliation'],

    'updated_at' => '2026-08-07',
];
