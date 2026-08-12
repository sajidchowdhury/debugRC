<?php

/**
 * Help content for: master-data.products-audit
 * Route: admin.products.audit
 *
 * Audit trail for the Product master — read-only history of who changed what
 * and when, for every product record.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.products-audit',
    'module'     => 'master-data',
    'title_bn'   => 'প্রোডাক্ট অডিট',
    'title_en'   => 'Product Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি পণ্য পেজের অডিট ট্রেইল — কে কখন কোন পণ্যের নাম, কোড বা দাম বদলেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'salesman', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কোন পণ্য বদলেছে'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',      'text' => 'লগ এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি কোনো তথ্য বদলানো যায় না।',
    ],

    'related' => ['master-data.products', 'master-data.products-price-history', 'master-data.products-print'],

    'updated_at' => '2026-08-07',
];
