<?php

/**
 * Help content for: master-data.customers
 * Route: admin.customers.index (and create/show/edit via wildcard)
 *
 * The Customer master page — where every buyer (dokandar, wholesaler, retailer)
 * the business sells to is registered and managed.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 */

return [
    'key'        => 'master-data.customers',
    'module'     => 'master-data',
    'title_bn'   => 'খদ্দের',
    'title_en'   => 'Customer',
    'icon'       => 'fa-users',
    'summary'    => 'যাদের কাছে পণ্য বিক্রি করেন, তাদের নাম-ঠিকানা-বকেয়া এখানে রাখা হয়। নতুন খদ্দের যোগ করা, আগের খদ্দের খুঁজা, বা হিসাব মেলানো — সব এই পেজ থেকে শুরু।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-plus',          'text' => 'নতুন খদ্দের যোগ করা (নাম, ফোন, ঠিকানা, এলাকা)'],
        ['icon' => 'fa-magnifying-glass',   'text' => 'নাম বা ফোন দিয়ে খদ্দের খুঁজে বের করা'],
        ['icon' => 'fa-pen-to-square',      'text' => 'আগের তথ্য এডিট করা (ঠিকানা বদল, ফোন আপডেট)'],
        ['icon' => 'fa-file-invoice-dollar', 'text' => 'খদ্দেরের বর্তমান বকেয়া দেখা'],
        ['icon' => 'fa-print',              'text' => 'খদ্দের ডিরেক্টরি প্রিন্ট করা'],
        ['icon' => 'fa-list-check',         'text' => 'অডিট লগ দেখা — কে কখন কী পরিবর্তন করেছে'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের',   'what' => 'তালিকায় যোগ/আপডেট হয়'],
        ['who' => 'হিসাব',     'what' => 'নতুন খদ্দের হলে তার খাতা (ledger) তৈরি হয়'],
        ['who' => 'সেলস',      'what' => 'ইনভয়েস করার সময় এই তালিকা থেকে খদ্দের বাছাই হয়'],
    ],

    'cautions' => [
        'একই ফোন নম্বর দিয়ে দুটো খদ্দের না বানান — হিসাব গোলমাল হয়।',
        'খদ্দের ডিলিট করলে তার আগের ইনভয়েস ও পেমেন্ট মুছে যায় না, কিন্তু নাম "ডিলিটেড" দেখায়।',
    ],

    'related' => ['master-data.suppliers', 'sales.invoices', 'sales.cart', 'system.archive-customerLedger'],

    // No diagram — this is a simple "list of records" page; a picture wouldn't help.

    'updated_at' => '2026-08-07',
];
