<?php

/**
 * Help content for: sales.invoice
 * Route: admin.sales-invoices.index (and create/show/edit via wildcard)
 *
 * The Sales Invoice page — the heart of the sales cycle. Every sale the business
 * makes becomes an invoice here. From this one document, stock decreases,
 * customer receivable increases, VAT is recorded, and income is recognised.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'sales.invoices',
    'module'     => 'sales',
    'title_bn'   => 'সেলস ইনভয়েস',
    'title_en'   => 'Sales Invoice',
    'icon'       => 'fa-file-invoice-dollar',
    'summary'    => 'খদ্দেরকে পণ্য বিক্রি করে যে বিল তৈরি হয়, এটি সেই বিল। এখান থেকেই খদ্দেরের বকেয়া ও আপনার আয় শুরু হয়।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন ইনভয়েস তৈরি করা (কার্ট থেকে বা সরাসরি)'],
        ['icon' => 'fa-list',              'text' => 'আগের সব ইনভয়েস দেখা ও খুঁজা (তারিখ/খদ্দের/নম্বর দিয়ে)'],
        ['icon' => 'fa-print',             'text' => 'ইনভয়েস প্রিন্ট বা খদ্দেরকে পাঠানো'],
        ['icon' => 'fa-undo',              'text' => 'ভুল হলে রিটার্ন (sales return) দেওয়া'],
        ['icon' => 'fa-circle-check',      'text' => 'খদ্দেরের পেমেন্ট এন্ট্রি করা (receive modal)'],
        ['icon' => 'fa-truck',             'text' => 'গোডাউন চালান (challan) তৈরি করা'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের',     'what' => 'বকেয়া (receivable) বাড়ে'],
        ['who' => 'স্টক',        'what' => 'পণ্য কমে যায় (inventory out)'],
        ['who' => 'হিসাব',       'what' => 'বিক্রয় আয় + ভ্যাট লেজারে লেখা হয়'],
        ['who' => '�মিশন',      'what' => 'সেলসম্যানের কমিশন হিসাব হয়'],
    ],

    'cautions' => [
        'ইনভয়েস একবার ফাইনাল হলে সরাসরি এডিট করা যায় না — ভুল ধরতে রিটার্ন দিতে হবে।',
        'পর্যাপ্ত স্টক না থাকলে ইনভয়েস তৈরি হবে না।',
        'পেমেন্ট না পেলেও ইনভয়েস ফাইনাল হয়ে যায় — বকেয়া আলাদাভাবে ট্র্যাক করুন।',
    ],

    'related' => ['sales.cart', 'sales.challans', 'sales.returns', 'sales.customer-payments', 'master-data.customers'],

    'diagram' => 'sales-invoice-flow',

    'updated_at' => '2026-08-07',
];
