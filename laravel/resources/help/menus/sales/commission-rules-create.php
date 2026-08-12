<?php

/**
 * Help content for: sales.commission-rules-create
 * Route: admin.commission-rules.create
 *
 * Sub-page of sales.commission-rules — the create rule form.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.commission-rules-create',
    'module'     => 'sales',
    'title_bn'   => 'Create Commission Rule',
    'title_en'   => 'Create Commission Rule',
    'icon'       => 'fa-plus',
    'summary'    => 'এটি কমিশন রুল-এর ক্রিয়েট পেজ — নতুন কমিশন রুল এখানে তৈরি করা হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-percent',       'text' => 'কমিশনের % ও শর্ত সেট করা'],
        ['icon' => 'fa-calendar-days', 'text' => 'কার্যকর তারিখ (effective date) দেওয়া'],
        ['icon' => 'fa-circle-check',  'text' => 'রুল সেভ করে অ্যাক্টিভেট করা'],
    ],

    'impacts' => [
        ['who' => 'কমিশন',  'what' => 'নতুন রুল ভবিষ্যৎ ইনভয়েসে কাজে লাগে (prospective)'],
    ],

    'cautions' => [
        'রুলের কার্যকর তারিখ পিছনের দিনে দিলে আগের ইনভয়েসে কমিশন পুনঃহিসাব হতে পারে — সাবধানে সেট করুন।',
    ],

    'related' => ['sales.commission-rules', 'sales.commission-rules-show'],

    'updated_at' => '2026-08-07',
];
