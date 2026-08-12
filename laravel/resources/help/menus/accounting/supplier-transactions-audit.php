<?php

/**
 * Help content for: accounting.supplier-transactions-audit
 * Route: admin.supplier-transactions.audit
 *
 * Audit trail for supplier transactions — read-only history of who entered
 * which supplier payment/adjustment, when, and what changed.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.supplier-transactions-audit',
    'module'     => 'accounting',
    'title_bn'   => 'সাপ্লায়ার পেমেন্ট অডিট',
    'title_en'   => 'Supplier Payment Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি supplier-transactions-এর অডিট ট্রেইল — কে কখন কোন পেমেন্ট/অ্যাডজাস্টমেন্ট এন্ট্রি করেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'অডিট লগ দেখা — কে কখন কোন পেমেন্ট এন্ট্রি করেছে'],
        ['icon' => 'fa-filter',      'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ', 'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি পেমেন্ট বদলানো যায় না।',
    ],

    'related' => ['accounting.supplier-transactions', 'accounting.supplier-transactions-show'],

    'updated_at' => '2026-08-07',
];
