<?php

/**
 * Help content for: accounting.reconciliation
 * Route: admin.reconciliation.index (and section via wildcard)
 *
 * The Reconciliation hub — read-only overview of reconciliation status across
 * every sub-ledger (customers, suppliers, employees, bank, cash). Filter by
 * section, drill into a section's reconciled vs unreconciled entries.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.reconciliation',
    'module'     => 'accounting',
    'title_bn'   => 'রিকনসিলিয়েশন',
    'title_en'   => 'Reconciliation',
    'icon'       => 'fa-scale-balanced',
    'summary'    => 'সব সাব-লেজারের রিকনসিলিয়েশন স্ট্যাটাস এক পেজে দেখা যায় — সেকশন ধরে ফিল্টার করে বিস্তারিত দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-table-list',      'text' => 'সব সেকশনের রিকন স্ট্যাটাস একসাথে দেখা (খদ্দের/সাপ্লায়ার/কর্মচারী/ব্যাংক)'],
        ['icon' => 'fa-filter',          'text' => 'সেকশন ধরে ফিল্টার করা'],
        ['icon' => 'fa-arrow-right',     'text' => 'কোনো সেকশনে ড্রিল-ডাউন করে বিস্তারিত দেখা'],
        ['icon' => 'fa-file-export',     'text' => 'স্ট্যাটাস রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু রিড-অনলি ওভারভিউ — কোনো এন্ট্রি বদলায় না'],
        ['who' => 'রিপোর্ট', 'what' => 'রিকন স্ট্যাটাস রিপোর্ট তৈরি হয়'],
    ],

    'cautions' => [
        'এটি রিড-অনলি ওভারভিউ পেজ — এখান থেকে সরাসরি কোনো এন্ট্রি বদলানো যায় না, শুধু স্ট্যাটাস দেখা যায়।',
    ],

    'related' => ['accounting.bank-reconciliation', 'accounting.supplier-transactions', 'accounting.employee-transactions', 'accounting.reconciliation-section'],

    'updated_at' => '2026-08-07',
];
