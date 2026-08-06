<?php

/**
 * Help content for: accounting.supplier-transactions
 * Route: admin.supplier-transactions.index (and show/audit/slip via wildcard)
 *
 * The Supplier Payment / supplier sub-ledger page — every payment to a
 * supplier, supplier adjustment, or debit-note application is recorded here.
 * Each posting reduces supplier payable AND reduces cash/bank, and writes a
 * balanced journal to the GL automatically.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.supplier-transactions',
    'module'     => 'accounting',
    'title_bn'   => 'সাপ্লায়ার পেমেন্ট',
    'title_en'   => 'Supplier Payment',
    'icon'       => 'fa-receipt',
    'summary'    => 'সাপ্লায়ারকে টাকা দেওয়া বা অ্যাডজাস্টমেন্ট এখানে লেখা হয় — পেমেন্ট সাপ্লায়ার পেয়েবল কমায় আর ক্যাশ/ব্যাংক থেকে যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-hand-holding-dollar', 'text' => 'সাপ্লায়ারকে পেমেন্ট রেকর্ড করা (ক্যাশ/ব্যাংক বাছাই করে)'],
        ['icon' => 'fa-link',               'text' => 'পেমেন্ট কোন পিও বা রিসিভের সাথে যুক্ত তা বাছাই করা (অ্যালোকেশন)'],
        ['icon' => 'fa-sliders',             'text' => 'সাপ্লায়ার অ্যাডজাস্টমেন্ট বা ডেবিট নোট এন্ট্রি করা'],
        ['icon' => 'fa-book',                'text' => 'সাপ্লায়ারের সাব-লেজার ব্যাল্যান্স দেখা'],
        ['icon' => 'fa-receipt',             'text' => 'পেমেন্ট স্লিপ প্রিন্ট করা'],
        ['icon' => 'fa-list-check',          'text' => 'অডিট লগ দেখা — কে কখন পেমেন্ট এন্ট্রি করেছে'],
    ],

    'impacts' => [
        ['who' => 'সাপ্লায়ার',     'what' => 'পেয়েবল (বকেয়া) কমে'],
        ['who' => 'ক্যাশ/ব্যাংক',  'what' => 'ব্যাল্যান্স কমে'],
        ['who' => 'হিসাব',        'what' => 'অটো-পোস্টেড ব্যাল্যান্সড জার্নাল তৈরি হয়'],
        ['who' => 'অডিট',        'what' => 'প্রতিটা পেমেন্ট অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'পিও/রিসিভের সাথে না লিঙ্ক করলে পেমেন্টটা "আনঅ্যালোকেটেড" থাকে — সাপ্লায়ারের ক্রেডিট ব্যাল্যান্স দেখায়।',
        'ভুল সাপ্লায়ার বেছে নিলে সাব-লেজার গোলমাল হয় — স্লিপ প্রিন্ট করে আগে যাচাই করুন।',
        'পেমেন্ট একবার পোস্ট হলে এডিট করা যায় না — রিভার্স করতে নতুন অ্যাডজাস্টমেন্ট দিতে হবে।',
    ],

    'related' => ['master-data.suppliers', 'master-data.ledgers', 'purchasing.purchase-orders', 'purchasing.purchase-receives', 'accounting.supplier-transactions-slip'],

    'updated_at' => '2026-08-07',
];
