<?php

/**
 * Help content for: sales.go-live-checklist
 * Route: admin.sales.go-live-checklist (GoLiveChecklistController@index)
 *
 * The Sales Go-Live Checklist page — the pre-go-live audit checklist for
 * the sales module. Verifies master data, opening balances, sequence
 * numbers, and stock counts are ready before flipping the switch.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.go-live-checklist',
    'module'     => 'sales',
    'title_bn'   => 'গো-লাইভ চেকলিস্ট',
    'title_en'   => 'Go-Live Checklist',
    'icon'       => 'fa-list-check',
    'summary'    => 'সেলস মডিউল লাইভ হওয়ার আগের চেকলিস্ট — মাস্টার ডেটা, ওপেনিং ব্যাল্যান্স, স্টক সব ঠিক আছে কিনা যাচাই।',

    'for_roles'  => ['manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',     'text' => 'চেকলিস্টের প্রতিটি আইটেম পর্যালোচনা করা'],
        ['icon' => 'fa-circle-check',   'text' => 'প্রতিটি আইটেম সম্পন্ন হলে টিক দেওয়া'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'অসম্পূর্ণ আইটেমের বিস্তারিত দেখে যাচাই করা'],
    ],

    'impacts' => [
        ['who' => 'গো-লাইভ',     'what' => 'চেকলিস্ট সম্পূর্ণ হলে মডিউল প্রস্তুত বলে গণ্য হয়'],
        ['who' => 'অডিট',         'what' => 'প্রস্তুতির অবস্থা অডিটে লেখা পড়ে'],
    ],

    'cautions' => [
        'গো-লাইভের আগে চেকলিস্ট সম্পূর্ণ করুন — অসম্পূর্ণ চেকলিস্ট নিয়ে লাইভে গেলে হিসাব গোলমাল হতে পারে।',
    ],

    'related' => ['sales.guide', 'master-data.customers', 'master-data.products', 'master-data.warehouses'],

    // No diagram — a checklist is linear, no multi-step workflow to picture.

    'updated_at' => '2026-08-07',
];
