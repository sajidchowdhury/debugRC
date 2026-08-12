<?php

/**
 * Help content for: inventory.stock-take
 * Route: admin.stock-take.index (and create/show/edit via wildcard)
 *
 * Physical count cycle: setup a count session → count actual stock on the
 * floor → system computes variance (book vs physical) → post adjustment
 * to correct stock → view variance report. Posting variance is irreversible.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'inventory.stock-take',
    'module'     => 'inventory',
    'title_bn'   => 'ফিজিক্যাল কাউন্ট',
    'title_en'   => 'Physical Count',
    'icon'       => 'fa-clipboard-check',
    'summary'    => 'গোডাউনে হাতে যা আছে তা গুনে বইয়ের স্টকের সাথে মেলানো হয় — ভ্যারিয়েন্স পোস্ট করলে স্টক কারেক্ট হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-gears',           'text' => 'কাউন্ট সেটআপ — কোন গোডাউন, কোন পণ্য, কে গুনবে'],
        ['icon' => 'fa-calculator',      'text' => 'ফিজিক্যাল কাউন্ট এন্ট্রি — হাতে যা আছে তা লেখা'],
        ['icon' => 'fa-arrows-left-right', 'text' => 'ভ্যারিয়েন্স দেখা — বই vs হাতের পার্থক্য'],
        ['icon' => 'fa-sliders',         'text' => 'ভ্যারিয়েন্স পোস্ট করা — স্টক অ্যাডজাস্টমেন্ট তৈরি হয়'],
        ['icon' => 'fa-arrow-down-a-z',  'text' => 'ABC রিপোর্ট দেখা — কোন পণ্যে সবচেয়ে বেশি ভ্যারিয়েন্স'],
        ['icon' => 'fa-heart-pulse',     'text' => 'হেলথ সামারি — কাউন্ট সেশনের অগ্রগতি'],
    ],

    'impacts' => [
        ['who' => 'স্টক লেজার',   'what' => 'ভ্যারিয়েন্স পোস্ট হলে স্টক কারেক্ট হয় (বাড়ে/কমে)'],
        ['who' => 'GL',          'what' => 'অ্যাডজাস্টমেন্ট জার্নাল পোস্ট হয় (loss/surplus)'],
        ['who' => 'রিপোর্ট',      'what' => 'স্টকটেক ভ্যারিয়েন্স রিপোর্টে দেখা যায়'],
        ['who' => 'কাউন্ট সেশন',  'what' => 'লক হয়ে যায় — পোস্ট হওয়ার পর আর এডিট করা যায় না'],
    ],

    'cautions' => [
        'ভ্যারিয়েন্স পোস্ট করা একমুখী (irreversible) — এটি একটি স্টক অ্যাডজাস্টমেন্ট তৈরি করে। ভুল ধরা পড়লে নতুন করে অ্যাডজাস্টমেন্ট দিতে হবে।',
        'কাউন্ট চলাকালীন গোডাউনে মাল চলাচল (সেলস/ট্রান্সফার/পুরচেস রিসিভ) বন্ধ রাখুন — নাহলে ভ্যারিয়েন্স ভুল আসবে।',
        'ভ্যারিয়েন্স বড় হলে দুবার কাউন্ট করুন — গোনার ভুল নাকি সতেজ চুরি, সেটা নিশ্চিত হওয়া জরুরি।',
    ],

    'related' => [
        'master-data.warehouses',
        'inventory.stock-adjustments',
        'inventory.stock-transactions',
        'reports.reports-hub-stocktakeVariance',
        'inventory.stock-take-health-summary',
    ],

    'diagram' => 'stock-take-cycle',

    'updated_at' => '2026-08-07',
];
