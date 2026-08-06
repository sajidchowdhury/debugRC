<?php

/**
 * Help content for: sales.cart
 * Route: admin.sales.cart (SalesCartController@cart — the cart IS the index page)
 *
 * The Sales Cart page — the starting point of every sale. Pick a customer, scan/add
 * products, set quantities, apply discount, then generate an invoice. The cart is a
 * draft — nothing hits the ledger or stock until you "Generate Invoice".
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.cart',
    'module'     => 'sales',
    'title_bn'   => 'সেলস কার্ট',
    'title_en'   => 'Sales Cart',
    'icon'       => 'fa-cart-shopping',
    'summary'    => 'খদ্দেরকে পণ্য বিক্রি শুরুর জায়গা। এখানে পণ্য যোগ করে, পরিমাণ ঠিক করে, ছাড় দিয়ে ইনভয়েস তৈরি করা হয়। কার্ট ফাইনাল না হওয়া পর্যন্ত কোনো হিসাব বদলায় না।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-tag',          'text' => 'খদ্দের বাছাই করা (ইনভয়েস কার নামে হবে)'],
        ['icon' => 'fa-barcode',           'text' => 'বারকোড স্ক্যান বা নাম দিয়ে পণ্য খুঁজে কার্টে যোগ করা'],
        ['icon' => 'fa-plus-minus',        'text' => 'পরিমাণ (quantity) বাড়ানো বা কমানো'],
        ['icon' => 'fa-tag',                'text' => 'পণ্যে ছাড় (discount) দেওয়া'],
        ['icon' => 'fa-percent',           'text' => 'পুরো কার্টে একসাথে ছাড় বা ভ্যাট যোগ করা'],
        ['icon' => 'fa-file-invoice-dollar', 'text' => 'ইনভয়েস তৈরি করা (এখান থেকেই সেলস ইনভয়েস পেজে যায়)'],
        ['icon' => 'fa-trash-can',         'text' => 'ভুল পণ্য কার্ট থেকে সরানো'],
    ],

    'impacts' => [
        ['who' => 'কার্ট',      'what' => 'খসড়া (draft) হিসেবে সেভ থাকে — ফাইনাল না হলে হিসাবে কিছু হয় না'],
        ['who' => 'খদ্দের',     'what' => 'ইনভয়েস তৈরি হলে তার বকেয়া শুরু হয়'],
        ['who' => 'স্টক',        'what' => 'ইনভয়েস ফাইনাল হলে পণ্য কমে'],
    ],

    'cautions' => [
        'কার্ট ফাইনাল না করে পেজ ছেড়ে গেলে খসড়া সেভ থাকে — কিন্তু স্টক আটকে থাকে না।',
        'একই খদ্দেরের একাধিক কার্ট খোলা থাকতে পারে — ভুল কার্ট না বাছাই করেন।',
    ],

    'related' => ['sales.invoices', 'sales.challans', 'master-data.customers', 'master-data.products'],

    // No diagram — the flow is simple (pick products → set qty → generate invoice).
    // The sales-invoice-flow diagram on sales.invoice already covers the cycle.

    'updated_at' => '2026-08-07',
];
