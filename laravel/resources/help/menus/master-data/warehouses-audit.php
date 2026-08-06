<?php

/**
 * Help content for: master-data.warehouses-audit
 * Route: admin.warehouses.audit
 *
 * Audit trail for the Warehouse (godown) master — read-only history of who
 * changed what and when, for every warehouse.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.warehouses-audit',
    'module'     => 'master-data',
    'title_bn'   => 'গুদাম অডিট',
    'title_en'   => 'Warehouse Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি গুদাম পেজের অডিট ট্রেইল — কে কখন কোন গোডাউন বদলেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কোন গোডাউন বদলেছে'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',      'text' => 'লগ এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি কোনো তথ্য বদলানো যায় না।',
    ],

    'related' => ['master-data.warehouses', 'master-data.warehouses-print'],

    'updated_at' => '2026-08-07',
];
