<?php

/**
 * Help content for: purchasing.purchase-orders
 * Route: admin.purchase-orders.index (and create/show/edit via wildcard)
 *
 * The Purchase Order page — where every order placed on a supplier is
 * raised, costed, approved, and dispatched. The PO is a commitment to buy:
 * it locks rate + qty against a supplier, but stock and payable only move
 * when the goods are actually received against this PO.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'purchasing.purchase-orders',
    'module'     => 'purchasing',
    'title_bn'   => 'পি. অর্ডার',
    'title_en'   => 'P. Order',
    'icon'       => 'fa-file-signature',
    'summary'    => 'সাপ্লায়ারকে মাল কেনার অর্ডার এখানে তৈরি ও অ্যাপ্রুভ হয়; মাল রিসিভ হলেই স্টক আর পেয়েবল বদলায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',                'text' => 'নতুন পিও তৈরি করা (সাপ্লায়ার বাছাই করে)'],
        ['icon' => 'fa-list',               'text' => 'পণ্য যোগ করা — কোয়ান্টিটি, রেট, গ্রস/নেট প্রাইস সহ'],
        ['icon' => 'fa-calendar-day',       'text' => 'এক্সপেক্টেড ডেলিভারি তারিখ ও গোডাউন সেট করা'],
        ['icon' => 'fa-circle-check',       'text' => 'পিও অ্যাপ্রুভ করা (অ্যাপ্রুভ ছাড়া রিসিভ করা যায় না)'],
        ['icon' => 'fa-paper-plane',       'text' => 'সাপ্লায়ারকে পিও পাঠানো বা প্রিন্ট করা'],
        ['icon' => 'fa-truck-ramp-box',     'text' => 'অ্যাপ্রুভ হলে পি. রিসিভে রূপান্তর করা'],
    ],

    'impacts' => [
        ['who' => 'সাপ্লায়ার',  'what' => 'তাকে মাল সরবরাহের অর্ডার পৌঁছায় (commitment)'],
        ['who' => 'স্টক',        'what' => 'পরিকল্পিত ইনকামিং স্টক দেখায়, কিন্তু এখনও বাড়ে না'],
        ['who' => 'পেয়েবল',     'what' => 'রিসিভ না হলে সাপ্লায়ার পেয়েবল বাড়ে না'],
        ['who' => 'হিসাব',       'what' => 'অ্যাপ্রুভ হলে পিও লেজারে কমিটমেন্ট হিসেবে রেকর্ড থাকে'],
    ],

    'cautions' => [
        'পিও শুধু কেনার অঙ্গীকার — এটা তৈরি করলেই স্টক বাড়ে না, রিসিভ করলেই বাড়ে।',
        'অ্যাপ্রুভ না করা পিও থেকে রিসিভ করা যায় না; প্রথমে অ্যাপ্রুভ করুন।',
        'রেট বা কোয়ান্টিটি অ্যাপ্রুভের পর বদলালে সাপ্লায়ারকে জানাতে হবে।',
    ],

    'related' => ['purchasing.purchase-receives', 'purchasing.purchase-orders-audit', 'master-data.suppliers', 'master-data.products', 'accounting.supplier-transactions'],

    'diagram' => 'procure-to-pay',

    'updated_at' => '2026-08-07',
];
