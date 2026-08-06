<?php

/**
 * Help content for: accounting.employee-transactions-audit
 * Route: admin.employee-transactions.audit
 *
 * Audit trail for employee transactions — read-only history of who entered
 * which salary/advance/payment, when, and what changed.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.employee-transactions-audit',
    'module'     => 'accounting',
    'title_bn'   => 'কর্মচারী লেনদেন অডিট',
    'title_en'   => 'Employee Transaction Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি employee-transactions-এর অডিট ট্রেইল — কে কখন কোন বেতন/অগ্রিম/পেমেন্ট এন্ট্রি করেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'অডিট লগ দেখা — কে কখন কী এন্ট্রি করেছে'],
        ['icon' => 'fa-filter',      'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ', 'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি কোনো এন্ট্রি বদলানো যায় না।',
    ],

    'related' => ['accounting.employee-transactions', 'accounting.employee-transactions-show'],

    'updated_at' => '2026-08-07',
];
