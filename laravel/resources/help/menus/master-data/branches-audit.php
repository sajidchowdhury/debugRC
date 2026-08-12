<?php

/**
 * Help content for: master-data.branches-audit
 * Route: admin.branches.audit
 *
 * Audit trail for the Branch master — read-only history of who changed what
 * and when, for every branch record.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.branches-audit',
    'module'     => 'master-data',
    'title_bn'   => 'ব্র্যাঞ্চ অডিট',
    'title_en'   => 'Branch Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি ব্র্যাঞ্চ পেজের অডিট ট্রেইল — কে কখন কী বদলেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কী বদলেছে'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',      'text' => 'লগ এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি কোনো তথ্য বদলানো যায় না।',
    ],

    'related' => ['master-data.branches', 'master-data.branches-print'],

    'updated_at' => '2026-08-07',
];
