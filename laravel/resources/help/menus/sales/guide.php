<?php

/**
 * Help content for: sales.guide
 * Route: admin.sales.guide (SalesGuideController@guide)
 *
 * The Sales Guide page — a walkthrough of the whole sales cycle in plain
 * Bangla. Read-only orientation page that explains cart -> invoice ->
 * challan -> delivery -> payment -> commission end to end.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.guide',
    'module'     => 'sales',
    'title_bn'   => 'সেলস গাইড',
    'title_en'   => 'Sales Guide',
    'icon'       => 'fa-book-open',
    'summary'    => 'বিক্রির পুরো সাইকেল ধাপে ধাপে বুঝিয়ে দেওয়া গাইড — কার্ট থেকে শুরু করে কমিশন পর্যন্ত।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-ol',         'text' => 'বিক্রির ধাপগুলো ক্রমান্বয়ে পড়া (cart -> invoice -> challan -> payment)'],
        ['icon' => 'fa-lightbulb',       'text' => 'প্রতিটি ধাপের টিপস ও শর্টকাট দেখা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'সাধারণ ভুলগুলো আগেই জেনে সতর্ক থাকা'],
        ['icon' => 'fa-link',            'text' => 'প্রতিটি ধাপের জন্য সংশ্লিষ্ট মেনুতে সরাসরি যাওয়া'],
    ],

    'impacts' => [
        ['who' => 'ইউজার',     'what' => 'শুধু পড়ার পেজ — কোনো হিসাব বদলায় না'],
        ['who' => 'টিম',       'what' => 'নতুন সেলসম্যান অনবোর্ডিং ও অরিয়েন্টেশন এই গাইড থেকে হয়'],
    ],

    'cautions' => [
        'এটি শুধু গাইড পেজ — এখান থেকে কোনো ডেটা বদলে না। কাজ করতে হলে সংশ্লিষ্ট মেনুতে যান।',
    ],

    'related' => ['sales.cart', 'sales.invoices', 'sales.challans', 'sales.customer-payments'],

    // No diagram — the module-level sales-cycle diagram already covers the
    // visual story on the module offcanvas.

    'updated_at' => '2026-08-07',
];
