<?php

/**
 * Help content for: accounting.bank-reconciliation
 * Route: admin.bank-reconciliation.index (and create/show/import/unreconciled via wildcard)
 *
 * The Bank Reconciliation hub — match the bank statement to the system's bank
 * ledger. Import the bank statement, match transactions by amount+date, mark
 * reconciled, and watch the unreconciled list shrink to zero.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.bank-reconciliation',
    'module'     => 'accounting',
    'title_bn'   => 'ব্যাংক রিকনসিলিয়েশন',
    'title_en'   => 'Bank Reconciliation',
    'icon'       => 'fa-scale-balanced',
    'summary'    => 'ব্যাংক স্টেটমেন্ট আর সিস্টেমের ব্যাংক লেজার মেলানোর হাব — স্টেটমেন্ট ইম্পোর্ট করে এন্ট্রি মিলিয়ে রিকনসাইল করা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-file-import',      'text' => 'ব্যাংক স্টেটমেন্ট ইম্পোর্ট করা (CSV/এক্সেল ফাইল)'],
        ['icon' => 'fa-link',             'text' => 'স্টেটমেন্টের লাইন আর সিস্টেমের এন্ট্রি মেলানো (অ্যামাউন্ট + তারিখ ধরে)'],
        ['icon' => 'fa-circle-check',     'text' => 'ম্যাচ হওয়া এন্ট্রি "রিকনসাইল্ড" মার্ক করা'],
        ['icon' => 'fa-circle-exclamation', 'text' => 'আনরিকনসাইলড এন্ট্রির তালিকা দেখা'],
        ['icon' => 'fa-eye',              'text' => 'আগের রিকন সেশন খুঁজে দেখা'],
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কী রিকনসাইল করেছে'],
    ],

    'impacts' => [
        ['who' => 'ব্যাংক লেজার',   'what' => 'রিকন স্ট্যাটাস আপডেট হয় — কোন এন্ট্রি ম্যাচড তা দেখা যায়'],
        ['who' => 'রিপোর্ট',       'what' => 'ব্যাংক রিকন স্টেটমেন্ট তৈরি হয়'],
        ['who' => 'অডিট',         'what' => 'প্রতিটা রিকন সেশন অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'আনরিকনসাইলড এন্ট্রি = ওপেন আইটেম — স্টেটমেন্টের সাথে মেলেনি, কারণ খুঁজে বের করতে হবে।',
        'ইম্পোর্ট অ্যামাউন্ট + তারিখ দিয়ে অটো-ম্যাচ করে — ডুপ্লিকেট বা পেন্ডিং এন্ট্রি আলাদা যাচাই করতে হবে।',
        'রিকন সেশন একবার সেভ করলে পরে এডিট করা কঠিন — সঠিক স্টেটমেন্ট তারিখ দিয়ে শুরু করুন।',
    ],

    'related' => ['master-data.banks', 'master-data.ledgers', 'accounting.bank-reconciliation-import-statement', 'accounting.bank-reconciliation-unreconciled', 'accounting.money-transfers'],

    'updated_at' => '2026-08-07',
];
