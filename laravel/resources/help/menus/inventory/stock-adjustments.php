<?php

/**
 * Help content for: inventory.stock-adjustments
 * Route: admin.stock-adjustments.index (and create/show/edit via wildcard)
 *
 * Manual stock corrections: surplus (book says less than physical) or
 * shortage (book says more than physical). Each adjustment needs a
 * reason code and approval before posting. Once posted, stock + GL move.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 */

return [
    'key'        => 'inventory.stock-adjustments',
    'module'     => 'inventory',
    'title_bn'   => 'স্টক অ্যাডজাস্টমেন্ট',
    'title_en'   => 'Stock Adjustment',
    'icon'       => 'fa-sliders',
    'summary'    => 'বই ও হাতের স্টকের গরমিল মেলাতে ম্যানুয়াল কারেকশন করা হয় — কারণ ও অ্যাপ্রুভাল বাধ্যতামূলক।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',            'text' => 'নতুন অ্যাডজাস্টমেন্ট তৈরি — সারপ্লাস বা শর্টেজ'],
        ['icon' => 'fa-tag',             'text' => 'রিজন কোড বাছাই — কেন কারেকশন দরকার'],
        ['icon' => 'fa-scale-balanced',  'text' => 'রিকনসাইল — বই vs হাতের স্টক মেলানো'],
        ['icon' => 'fa-circle-check',    'text' => 'অ্যাপ্রুভ ও পোস্ট করা — স্টক ও GL আপডেট হয়'],
        ['icon' => 'fa-list-check',      'text' => 'চেকলিস্ট ও অডিট ট্রেইল দেখা'],
    ],

    'impacts' => [
        ['who' => 'স্টক লেজার',  'what' => 'পোস্ট হলে স্টক বাড়ে (সারপ্লাস) বা কমে (শর্টেজ)'],
        ['who' => 'GL',         'what' => 'জার্নাল পোস্ট হয় — লস বা সারপ্লাস খাতে'],
        ['who' => 'অডিট',      'what' => 'প্রতিটি অ্যাডজাস্টমেন্ট লগ হয় — রিজন ও অ্যাপ্রুভার'],
        ['who' => 'রিপোর্ট',     'what' => 'স্টক কারেকশন রিপোর্টে দেখা যায়'],
    ],

    'cautions' => [
        'রিজন কোড ও অ্যাপ্রুভাল ছাড়া পোস্ট করা যায় না — কারণ ছাড়া কারেকশন অডিট ফেইল।',
        'পোস্ট হওয়ার আগে অবশ্যই রিকনসাইল করুন — ভুল কারেকশন দিলে স্টক আরও গরমিল হবে।',
        'বড় অ্যাডজাস্টমেন্ট হলে আগে ফিজিক্যাল কাউন্ট করুন — অনুমান না করে হিসাব মেলানো নিরাপদ।',
    ],

    'related' => [
        'inventory.stock-take',
        'inventory.stock-transactions',
        'inventory.damages',
        'accounting.manual-journals',
        'reports.reports-hub-productStockAnalysis',
    ],

    'updated_at' => '2026-08-07',
];
