<?php

/**
 * Help content for: master-data.ledgers-audit
 * Route: admin.ledgers.audit
 *
 * Audit trail for the Ledger / chart-of-accounts master — read-only history
 * of who changed what and when, for every ledger head.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.ledgers-audit',
    'module'     => 'master-data',
    'title_bn'   => 'লেজার অডিট',
    'title_en'   => 'Ledger Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি লেজার পেজের অডিট ট্রেইল — কে কখন কোন খাতা বদলেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কোন খাতা বদলেছে'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',      'text' => 'লগ এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি কোনো তথ্য বদলানো যায় না।',
    ],

    'related' => ['master-data.ledgers', 'master-data.ledgers-print'],

    'updated_at' => '2026-08-07',
];
