<?php

/**
 * Help content for: inventory.stock-take-setup
 * Route: admin.stock-take.setup
 *
 * Setup sub-page — configure a count session (which warehouse, which
 * products, who counts, count method) before the actual counting begins.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-take-setup',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Take Setup',
    'title_en'   => 'Stock Take Setup',
    'icon'       => 'fa-gears',
    'summary'    => 'এটি স্টক টেক সেশন সেটআপ পেজ — গোডাউন, পণ্য ও কাউন্টার নির্বাচন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-gears',         'text' => 'কাউন্ট সেশন তৈরি — গোডাউন ও পণ্য বাছাই'],
        ['icon' => 'fa-user',          'text' => 'কাউন্টার নির্বাচন ও কাউন্ট পদ্ধতি ঠিক করা'],
    ],

    'impacts' => [
        ['who' => 'সেশন',  'what' => 'নতুন কাউন্ট সেশন তৈরি হয় — এরপর count পেজে গিয়ে এন্ট্রি করতে হবে'],
    ],

    'cautions' => [
        'একবার সেশন শুরু হলে গোডাউনে মাল চলাচল বন্ধ রাখুন — নাহলে ভ্যারিয়েন্স ভুল আসবে।',
    ],

    'related' => [
        'inventory.stock-take',
        'inventory.stock-take-count',
    ],

    'updated_at' => '2026-08-07',
];
