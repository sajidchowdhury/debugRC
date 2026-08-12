<?php

/**
 * Help content for: master-data.product-categories-audit
 * Route: admin.product-categories.audit
 *
 * Audit trail for the Product Category master — read-only history of who
 * changed what and when, for every category.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.product-categories-audit',
    'module'     => 'master-data',
    'title_bn'   => 'প্রোডাক্ট ক্যাটাগরি অডিট',
    'title_en'   => 'Product Category Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি প্রোডাক্ট ক্যাটাগরি পেজের অডিট ট্রেইল — কে কখন কী বদলেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'salesman', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কোন ক্যাটাগরি বদলেছে'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',      'text' => 'লগ এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি কোনো তথ্য বদলানো যায় না।',
    ],

    'related' => ['master-data.product-categories', 'master-data.product-groups'],

    'updated_at' => '2026-08-07',
];
