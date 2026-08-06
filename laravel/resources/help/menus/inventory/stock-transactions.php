<?php

/**
 * Help content for: inventory.stock-transactions
 * Route: admin.stock.transactions (and show via wildcard)
 *
 * The stock ledger — every in/out movement of every product across every
 * godown. Read-only. Reflects only posted transactions (sales, purchase
 * receive, transfer, damage, adjustment). Drift analysis & warehouse
 * snapshots live here too.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 */

return [
    'key'        => 'inventory.stock-transactions',
    'module'     => 'inventory',
    'title_bn'   => 'স্টক লেজার',
    'title_en'   => 'Stock Ledger',
    'icon'       => 'fa-right-left',
    'summary'    => 'মালামালের প্রতিটি চলাচল কালানুক্রমে লেখা পড়ে — সেলস, পুরচেস, ট্রান্সফার, ক্ষতি, অ্যাডজাস্টমেন্ট সব।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',                'text' => 'সব মুভমেন্ট কালানুক্রমে দেখা'],
        ['icon' => 'fa-filter',              'text' => 'পণ্য / গোডাউন / তারিখ দিয়ে ফিল্টার'],
        ['icon' => 'fa-arrows-left-right',   'text' => 'ড্রিফট অ্যানালাইসিস — স্টকের অস্বাভাবিক পরিবর্তন'],
        ['icon' => 'fa-warehouse',           'text' => 'গোডাউন-ভিত্তিক স্টক স্ন্যাপশট'],
        ['icon' => 'fa-arrow-up-right-from-square', 'text' => 'সোর্স ডকুমেন্টে ড্রিল ডাউন (ইনভয়েস/পিও/ট্রান্সফার)'],
    ],

    'impacts' => [
        ['who' => 'স্টক লেজার',   'what' => 'শুধু পঠনযোগ্য (read-only) — কোনো তথ্য বদলানো যায় না'],
        ['who' => 'রিপোর্ট',      'what' => 'এই লেজার থেকে সব স্টক রিপোর্ট তৈরি হয়'],
        ['who' => 'রিকনসাইল',     'what' => 'হিসাব মেলানোর ভিত্তি হিসেবে ব্যবহৃত'],
    ],

    'cautions' => [
        'এই পেজ শুধু দেখার জন্য — সরাসরি কোনো তথ্য বদলানো যায় না। কারেকশন দরকার হলে অ্যাডজাস্টমেন্ট পেজে যান।',
        'শুধু পোস্ট হওয়া ট্রানজেকশন দেখায় — ড্রাফট বা ট্রানজিটে থাকা মাল এখানে নাও দেখা তে পারে।',
        'ড্রিফট অ্যানালাইসিস বড় গোডাউনে সময়সাপেক্ষ — তারিখ রেঞ্জ ছোট রাখলে দ্রুত ফলাফল আসে।',
    ],

    'related' => [
        'master-data.products',
        'master-data.warehouses',
        'inventory.warehouse-transfers',
        'inventory.stock-adjustments',
        'reports.reports-hub-productMovement',
    ],

    'updated_at' => '2026-08-07',
];
