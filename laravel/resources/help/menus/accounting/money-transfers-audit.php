<?php

/**
 * Help content for: accounting.money-transfers-audit
 * Route: admin.money-transfers.audit
 *
 * Audit trail for money transfers — read-only history of who moved cash
 * between which accounts, when, and what changed.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.money-transfers-audit',
    'module'     => 'accounting',
    'title_bn'   => 'মানি ট্রান্সফার অডিট',
    'title_en'   => 'Money Transfer Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি money-transfers-এর অডিট ট্রেইল — কে কখন কোন অ্যাকাউন্টের মধ্যে টাকা সরিয়েছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'অডিট লগ দেখা — কে কখন কোন ট্রান্সফার করেছে'],
        ['icon' => 'fa-filter',      'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ', 'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি ট্রান্সফার বদলানো যায় না।',
    ],

    'related' => ['accounting.money-transfers', 'accounting.money-transfers-show'],

    'updated_at' => '2026-08-07',
];
