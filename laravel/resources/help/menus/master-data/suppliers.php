<?php

/**
 * Help content for: master-data.suppliers
 * Route: admin.suppliers.index (and create/show/edit via wildcard)
 *
 * The Supplier master — every vendor the business buys from is registered here
 * (name, phone, address). New suppliers get a payable ledger head automatically.
 * Purchase orders and receives reference this list.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.suppliers',
    'module'     => 'master-data',
    'title_bn'   => 'সাপ্লায়ার',
    'title_en'   => 'Supplier',
    'icon'       => 'fa-truck-field',
    'summary'    => 'যাদের কাছ থেকে মাল কেনেন, তাদের নাম-ফোন-ঠিকানা এখানে রাখা হয়। পিও আর বকেয়া এই তালিকা ধরে চলে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-plus',              'text' => 'নতুন সাপ্লায়ার যোগ করা (নাম, ফোন, ঠিকানা)'],
        ['icon' => 'fa-pen-to-square',         'text' => 'সাপ্লায়ারের তথ্য এডিট করা'],
        ['icon' => 'fa-file-invoice-dollar',   'text' => 'সাপ্লায়ারের বর্তমান বকেয়া দেখা'],
        ['icon' => 'fa-truck-ramp-box',         'text' => 'সাপ্লায়ারের সাথে কেনা মালের ইতিহাস দেখা'],
        ['icon' => 'fa-link',                   'text' => 'সাপ্লায়ারকে লেজার খাতায় লিঙ্ক করা'],
        ['icon' => 'fa-print',                  'text' => 'সাপ্লায়ার ডিরেক্টরি প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সাপ্লায়ার',    'what' => 'তালিকায় যোগ/আপডেট হয়'],
        ['who' => 'হিসাব',          'what' => 'নতুন সাপ্লায়ারের পেয়েবল লেজার খাতা তৈরি হয়'],
        ['who' => 'ক্রয়',           'what' => 'পিও ও রিসিভ এই তালিকা থেকে সাপ্লায়ার বাছাই হয়'],
    ],

    'cautions' => [
        'একই ফোন দিয়ে দুটো সাপ্লায়ার বানালে হিসাব জোড়া হয়ে যায়।',
        'বকেয়া থাকা সাপ্লায়ার ডিলিট করলে পেমেন্ট ইতিহাস মুছে যায় না, কিন্তু নাম "ডিলিটেড" দেখায়।',
    ],

    'related' => ['purchasing.purchase-orders', 'purchasing.purchase-receives', 'master-data.ledgers', 'master-data.suppliers-audit', 'master-data.suppliers-print'],

    // No diagram — a flat list of supplier records.

    'updated_at' => '2026-08-07',
];
