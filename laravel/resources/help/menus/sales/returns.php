<?php

/**
 * Help content for: sales.returns
 * Route: admin.sales-returns.index (and create/show/edit via wildcard)
 *
 * The Sales Return page — where customers return goods they bought. A return
 * reverses the original invoice: stock goes back up, the customer's receivable
 * drops, and a credit note is generated. Posting is irreversible.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.returns',
    'module'     => 'sales',
    'title_bn'   => 'সেলস রিটার্ন',
    'title_en'   => 'Sales Return',
    'icon'       => 'fa-undo',
    'summary'    => 'খদ্দের ফেরত দেওয়া পণ্য গ্রহণ করে ইনভয়েসের বিপরীত হিসাব করা হয় — স্টক বাড়ে আর বকেয়া কমে।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-file-circle-minus',   'text' => 'আগের কোনো ইনভয়েসের বিরুদ্ধে রিটার্ন তৈরি করা'],
        ['icon' => 'fa-list',                'text' => 'কোন পণ্য, কয়টা, কেন ফেরত — তা বাছাই করা'],
        ['icon' => 'fa-eye',                 'text' => 'পোস্ট করার আগে রিভার্স প্রিভিউ দেখা (কী বদলাবে)'],
        ['icon' => 'fa-circle-check',        'text' => 'পোস্ট করলে ক্রেডিট নোট তৈরি হয় (পূর্বাবস্থায় ফেরানো যায় না)'],
        ['icon' => 'fa-print',               'text' => 'রিটার্ন স্লিপ প্রিন্ট করে খদ্দেরকে দেওয়া'],
        ['icon' => 'fa-list-check',          'text' => 'আগের সব রিটার্ন অডিট ট্রেইলে দেখা'],
    ],

    'impacts' => [
        ['who' => 'স্টক',        'what' => 'পণ্য গোডাউনে ফিরে আসে (stock↑)'],
        ['who' => 'খদ্দের',     'what' => 'বকেয়া (receivable) কমে যায়'],
        ['who' => 'হিসাব',       'what' => 'ক্রেডিট নোট জার্নালে লেখা হয়'],
        ['who' => 'কমিশন',      'what' => 'সেলসম্যানের কমিশন হিসাব উল্টে যায়'],
        ['who' => 'অডিট',        'what' => 'প্রতিটি রিটার্ন অডিট ট্রেইলে লেখা পড়ে'],
    ],

    'cautions' => [
        'রিটার্ন একবার পোস্ট হলে আর বদলানো যায় না — আগে রিভার্স প্রিভিউ দেখে নিশ্চিত হোন।',
        'ইনভয়েস ছাড়া রিটার্ন দেওয়া যায় না — প্রতিটি রিটার্ন একটি ইনভয়েসের সাথে যুক্ত থাকে।',
        'ফেরত পণ্য গোডাউনে আসলে ফেরত-যোগ্য অবস্থায় আছে কিনা যাচাই করুন।',
    ],

    'related' => ['sales.invoices', 'sales.customer-payments', 'master-data.customers', 'master-data.products'],

    // No diagram here — the reverse-preview sub-page and the invoice-flow diagram
    // on sales.invoices already cover the visual story.

    'updated_at' => '2026-08-07',
];
