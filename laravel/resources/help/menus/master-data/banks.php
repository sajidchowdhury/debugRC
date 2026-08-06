<?php

/**
 * Help content for: master-data.banks
 * Route: admin.banks.index (and create/show/edit via wildcard)
 *
 * The Bank master page — every business bank account (name, account number,
 * branch) is registered here. Money transfers, receipts and bank reconciliation
 * all reference this list.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.banks',
    'module'     => 'master-data',
    'title_bn'   => 'ব্যাংক',
    'title_en'   => 'Bank',
    'icon'       => 'fa-building-columns',
    'summary'    => 'ব্যবসার সব ব্যাংক অ্যাকাউন্ট এখানে রেখে ট্র্যাক করুন — নাম, অ্যাকাউন্ট নম্বর, ব্র্যাঞ্চ। ট্রান্সফার ও রিকনসিলিয়েশন এই তালিকা ধরে চলে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-building-columns',      'text' => 'নতুন ব্যাংক যোগ করা (নাম, অ্যাকাউন্ট নম্বর, ব্র্যাঞ্চ)'],
        ['icon' => 'fa-pen-to-square',         'text' => 'ব্যাংকের তথ্য এডিট করা (ঠিকানা, অ্যাকাউন্ট বদল)'],
        ['icon' => 'fa-file-invoice-dollar',  'text' => 'ব্যাংকের সাথে যুক্ত লেনদেন দেখা'],
        ['icon' => 'fa-magnifying-glass',      'text' => 'নাম দিয়ে ব্যাংক খুঁজে বের করা'],
        ['icon' => 'fa-print',                 'text' => 'ব্যাংক ডিরেক্টরি প্রিন্ট করা'],
        ['icon' => 'fa-list-check',            'text' => 'অডিট লগ দেখা — কে কখন কী পরিবর্তন করেছে'],
    ],

    'impacts' => [
        ['who' => 'ব্যাংক',       'what' => 'তালিকায় যোগ/আপডেট হয়'],
        ['who' => 'হিসাব',        'what' => 'ব্যাংকের লেজার খাতা তৈরি/আপডেট হয়'],
        ['who' => 'লেনদেন',       'what' => 'মানি ট্রান্সফার ও রিসিট এই ব্যাংক ধরে চলে'],
    ],

    'cautions' => [
        'লাইভ ট্রানজেকশন থাকা ব্যাংক ডিলিট করবেন না — রিপোর্ট আর রিকনসিলিয়েশন ভেঙে যায়।',
        'একই অ্যাকাউন্ট নম্বর দিয়ে দুটো এন্ট্রি করলে ব্যাংক রিকনসিলিয়েশন গোলমাল হয়।',
    ],

    'related' => ['master-data.ledgers', 'accounting.money-transfers', 'accounting.bank-reconciliation', 'master-data.banks-audit', 'master-data.banks-print'],

    // No diagram — a flat list of bank records; a picture wouldn't add value.

    'updated_at' => '2026-08-07',
];
