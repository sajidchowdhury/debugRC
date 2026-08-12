<?php

/**
 * Help content for: accounting.manual-journals
 * Route: admin.manual-journals.index (and create/show/audit via wildcard)
 *
 * The Manual Journal page — the canonical double-entry posting screen of the
 * ERP. Every adjustment, accrual, depreciation, and correction that doesn't
 * originate from a sub-ledger (sales/purchase/payroll) is entered here as a
 * balanced debit-credit journal.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'accounting.manual-journals',
    'module'     => 'accounting',
    'title_bn'   => 'ম্যানুয়াল জার্নাল',
    'title_en'   => 'Manual Journal',
    'icon'       => 'fa-pen-nib',
    'summary'    => 'ডাবল-এন্ট্রি হিসাবের মূল পেজ — ডেবিট-ক্রেডিট ব্যাল্যান্সড জার্নাল, অ্যাডজাস্টমেন্ট আর কারেকশন সব এখানেই লেখা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-pen-nib',           'text' => 'নতুন জার্নাল তৈরি — ডেবিট আর ক্রেডিট লেজার বাছাই করে এন্ট্রি করা'],
        ['icon' => 'fa-scale-balanced',    'text' => 'ব্যাল্যান্স চেক — ডেবিটের সমান ক্রেডিট না হলে পোস্ট হবে না'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'আগের জার্নাল খুঁজে দেখা (তারিখ, ভাউচার নম্বর, লেজার ধরে)'],
        ['icon' => 'fa-eye',              'text' => 'জার্নালের বিস্তারিত ভিউ দেখা (সব লাইন + ন্যারেশন)'],
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কোন জার্নাল পোস্ট করেছে'],
        ['icon' => 'fa-rotate-left',      'text' => 'ভুল জার্নাল রিভার্স করা (নতুন রিভার্সিং জার্নাল দিয়ে)'],
    ],

    'impacts' => [
        ['who' => 'লেজার',     'what' => 'অন্তত দুটো খাতায় পোস্ট হয় (ডেবিট একটা, ক্রেডিট আরেকটা)'],
        ['who' => 'ট্রায়াল ব্যাল্যান্স', 'what' => 'ডেবিট-ক্রেডিট মোট ব্যাল্যান্সড থাকে'],
        ['who' => 'রিপোর্ট',    'what' => 'জেনারেল লেজার, ট্রায়াল ব্যাল্যান্স, পিএল এই এন্ট্রি ধরে তৈরি হয়'],
        ['who' => 'অডিট',      'what' => 'প্রতিটা পোস্টিং অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'ডেবিট আর ক্রেডিটের সমান না হলে জার্নাল পোস্টই হবে না — সিস্টেম ব্লক করবে।',
        'একবার পোস্ট হলে এডিট বা ডিলিট করা যায় না — ভুল ধরতে নতুন রিভার্সিং জার্নাল দিতে হবে।',
        'পিরিয়ড ক্লোজ হয়ে গেলে সেই তারিখের জার্নাল আর দেওয়া যায় না — আগে পিরিয়ড রিওপেন করতে হবে।',
    ],

    'related' => ['master-data.ledgers', 'accounting.manual-journals-audit', 'accounting.manual-journals-show', 'reports.reports-hub-trialBalance', 'accounting.period-close'],

    'diagram' => 'journal-posting',

    'updated_at' => '2026-08-07',
];
