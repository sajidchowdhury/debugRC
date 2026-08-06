<?php

/**
 * Help content for: accounting.manual-journals-audit
 * Route: admin.manual-journals.audit
 *
 * Audit trail for manual journals — read-only history of who posted which
 * journal, when, and what changed.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.manual-journals-audit',
    'module'     => 'accounting',
    'title_bn'   => 'ম্যানুয়াল জার্নাল অডিট',
    'title_en'   => 'Manual Journal Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি manual-journals-এর অডিট ট্রেইল — কে কখন কোন জার্নাল পোস্ট করেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'অডিট লগ দেখা — কে কখন কোন জার্নাল পোস্ট করেছে'],
        ['icon' => 'fa-filter',      'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ', 'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি জার্নাল বদলানো যায় না।',
    ],

    'related' => ['accounting.manual-journals', 'accounting.manual-journals-show'],

    'updated_at' => '2026-08-07',
];
