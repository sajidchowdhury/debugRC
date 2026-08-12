<?php

/**
 * Help content for: accounting.money-transfers
 * Route: admin.money-transfers.index (and show/audit/slip via wildcard)
 *
 * The Money Transfer page — moving cash between internal accounts (cash ↔ bank,
 * bank ↔ bank). Each transfer posts a balanced journal: source ledger
 * credited, destination ledger debited.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.money-transfers',
    'module'     => 'accounting',
    'title_bn'   => 'মানি ট্রান্সফার',
    'title_en'   => 'Money Transfer',
    'icon'       => 'fa-money-bill-transfer',
    'summary'    => 'ব্যবসার ক্যাশ আর ব্যাংক অ্যাকাউন্টের মধ্যে টাকা সরানোর পেজ — প্রতিটা ট্রান্সফার ব্যাল্যান্সড জার্নাল তৈরি করে।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-money-bill-transfer', 'text' => 'নতুন ট্রান্সফার তৈরি — কোন অ্যাকাউন্ট থেকে কোনটায়, কত টাকা'],
        ['icon' => 'fa-receipt',             'text' => 'ট্রান্সফার স্লিপ প্রিন্ট করা'],
        ['icon' => 'fa-magnifying-glass',    'text' => 'আগের ট্রান্সফার খুঁজে দেখা (তারিখ, অ্যাকাউন্ট ধরে)'],
        ['icon' => 'fa-eye',                'text' => 'ট্রান্সফারের বিস্তারিত ভিউ দেখা'],
        ['icon' => 'fa-list-check',         'text' => 'অডিট লগ দেখা — কে কখন ট্রান্সফার করেছে'],
    ],

    'impacts' => [
        ['who' => 'সোর্স অ্যাকাউন্ট', 'what' => 'লেজার ব্যাল্যান্স কমে (ক্রেডিট)'],
        ['who' => 'ডেস্টিনেশন অ্যাকাউন্ট', 'what' => 'লেজার ব্যাল্যান্স বাড়ে (ডেবিট)'],
        ['who' => 'হিসাব',          'what' => 'ব্যাল্যান্সড জার্নাল পোস্ট হয় — টোটাল ক্যাশ অপরিবর্তিত'],
        ['who' => 'অডিট',          'what' => 'প্রতিটা ট্রান্সফার অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'ট্রান্সফার একই কারেন্সির দুটো অ্যাকাউন্টের মধ্যে হতে হবে — ভিন্ন কারেন্সি হলে এক্সচেঞ্জ রেট আলাদা এন্ট্রি করতে হবে।',
        'ট্রান্সফার সংগেই পোস্ট হয়ে যায় — পরে এডিট করা যায় না, রিভার্স করতে নতুন ট্রান্সফার দিতে হবে।',
    ],

    'related' => ['master-data.banks', 'master-data.ledgers', 'accounting.money-transfers-audit', 'accounting.money-transfers-slip', 'accounting.bank-reconciliation'],

    'updated_at' => '2026-08-07',
];
