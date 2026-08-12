<?php

/**
 * Help content for: accounting.employee-transactions
 * Route: admin.employee-transactions.index (and show/audit/slip via wildcard)
 *
 * The Employee Transaction / employee sub-ledger page — every salary,
 * advance, deduction, or repayment posted against an employee. Each posting
 * moves the employee sub-ledger and posts a balanced journal to the GL
 * (payroll expense ↔ cash/bank or advance asset).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.employee-transactions',
    'module'     => 'accounting',
    'title_bn'   => 'কর্মচারী লেনদেন',
    'title_en'   => 'Employee Transaction',
    'icon'       => 'fa-user-tag',
    'summary'    => 'কর্মচারীর বেতন, অগ্রিম, কর্টন বা পেমেন্ট এখানে লেখা হয় — প্রতিটা এন্ট্রি সাব-লেজার আর পেরোল GL দুটোতেই পড়ে।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-tag',           'text' => 'কর্মচারীর বেতন/অগ্রিম/পেমেন্ট রেকর্ড করা'],
        ['icon' => 'fa-link',               'text' => 'কর্মচারী মাস্টারের সাথে লিঙ্ক করা'],
        ['icon' => 'fa-receipt',            'text' => 'পেমেন্ট স্লিপ প্রিন্ট করা'],
        ['icon' => 'fa-book',               'text' => 'কর্মচারীর সাব-লেজার ব্যাল্যান্স দেখা'],
        ['icon' => 'fa-eye',                'text' => 'এন্ট্রির বিস্তারিত ভিউ দেখা'],
        ['icon' => 'fa-list-check',        'text' => 'অডিট লগ দেখা — কে কখন এন্ট্রি করেছে'],
    ],

    'impacts' => [
        ['who' => 'কর্মচারী',     'what' => 'সাব-লেজার ব্যাল্যান্স আপডেট হয়'],
        ['who' => 'পেরোল GL',   'what' => 'বেতন খরচ বা অগ্রিম অ্যাসেট খাতায় পোস্ট হয়'],
        ['who' => 'ক্যাশ/ব্যাংক', 'what' => 'পেমেন্ট হলে ব্যাল্যান্স কমে'],
        ['who' => 'অডিট',       'what' => 'প্রতিটা এন্ট্রি অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'অগ্রিম (advance) দিলে সেটা পরের মাসের বেতন থেকে কাটা হবে — অগ্রিম লেজারে অ্যাসেট হিসেবে জমে থাকে।',
        'ভুল কর্মচারী বেছে নিলে সাব-লেজার গোলমাল হয় — এন্ট্রির আগে নাম যাচাই করুন।',
        'পেমেন্ট একবার পোস্ট হলে এডিট করা যায় না — রিভার্স করতে নতুন অ্যাডজাস্টমেন্ট দিতে হবে।',
    ],

    'related' => ['master-data.employees', 'master-data.ledgers', 'accounting.manual-journals', 'accounting.employee-transactions-slip', 'accounting.employee-transactions-audit'],

    'updated_at' => '2026-08-07',
];
