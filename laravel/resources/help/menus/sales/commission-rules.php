<?php

/**
 * Help content for: sales.commission-rules
 * Route: admin.commission-rules.index (and create/show/edit via wildcard)
 *
 * The Commission Rule page — where the business defines how salesmen earn
 * commission. Rules can be set per product, per category, or per salesman,
 * with effective dates. Commission accrues on each finalised invoice based
 * on the matching rule, and pays out at month-end.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'sales.commission-rules',
    'module'     => 'sales',
    'title_bn'   => 'কমিশন রুল',
    'title_en'   => 'Commission Rules',
    'icon'       => 'fa-percent',
    'summary'    => 'সেলসম্যান কীভাবে কমিশন পাবে তার নিয়ম এখানে ঠিক করা হয় — পণ্য, ক্যাটাগরি বা সেলসম্যান ধরে রুল দেওয়া যায়।',

    'for_roles'  => ['manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',                'text' => 'নতুন কমিশন রুল তৈরি করা'],
        ['icon' => 'fa-percent',             'text' => 'পণ্য বা ক্যাটাগরিতে কত % কমিশন — তা সেট করা'],
        ['icon' => 'fa-user-tag',            'text' => 'নির্দিষ্ট সেলসম্যানের জন্য আলাদা রুল দেওয়া'],
        ['icon' => 'fa-calendar-days',       'text' => 'রুলের কার্যকর তারিখ (effective date) সেট করা'],
        ['icon' => 'fa-eye',                  'text' => 'বিস্তারিত রুল দেখা ও অ্যাক্টিভেট করা'],
    ],

    'impacts' => [
        ['who' => 'কমিশন',      'what' => 'ইনভয়েস ফাইনাল হলে রুল ম্যাচ করে কমিশন জমা (accrue)'],
        ['who' => 'পেরোল',       'what' => 'মাস শেষে সেলসম্যান পেআউট এই হিসাব থেকে হয়'],
        ['who' => 'অডিট',        'what' => 'রুল পরিবর্তন অডিট ট্রেইলে লেখা পড়ে'],
    ],

    'cautions' => [
        'রুল পরিবর্তন শুধু ভবিষ্যৎ ইনভয়েসে কাজে লাগে — আগের ইনভয়েসের কমিশন বদলায় না (prospective)।',
        'একই পণ্যে একাধিক রুল সক্রিয় থাকলে কনফ্লিক্ট হয় — কোনটা প্রযোজ্য তা যাচাই করুন।',
    ],

    'related' => ['sales.invoices', 'sales.returns', 'master-data.employees', 'master-data.products'],

    'diagram' => 'commission-calc',

    'updated_at' => '2026-08-07',
];
