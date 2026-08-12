<?php

/**
 * Help content for: accounting.other-incomes-audit
 * Route: admin.other-incomes.audit
 *
 * Audit trail for other incomes — read-only history of who entered which
 * income, when, and what changed.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.other-incomes-audit',
    'module'     => 'accounting',
    'title_bn'   => 'অন্যান্য আয় অডিট',
    'title_en'   => 'Other Income Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি other-incomes-এর অডিট ট্রেইল — কে কখন কোন আয় এন্ট্রি করেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'অডিট লগ দেখা — কে কখন কোন আয় এন্ট্রি করেছে'],
        ['icon' => 'fa-filter',      'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ', 'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি আয় এন্ট্রি বদলানো যায় না।',
    ],

    'related' => ['accounting.other-incomes', 'accounting.other-incomes-show'],

    'updated_at' => '2026-08-07',
];
