<?php

/**
 * Help content for: accounting.bank-reconciliation-show
 * Route: admin.bank-reconciliation.show
 *
 * Detail view of a single bank reconciliation session — opening balance,
 * matched transactions, unreconciled items, closing balance, and the
 * difference (if any).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.bank-reconciliation-show',
    'module'     => 'accounting',
    'title_bn'   => 'ব্যাংক রিকন বিস্তারিত',
    'title_en'   => 'Bank Reconciliation Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি bank-reconciliation-এর বিস্তারিত ভিউ — ওপেনিং ব্যাল্যান্স, ম্যাচড এন্ট্রি, আনরিকনসাইলড আইটেম আর ক্লোজিং ব্যাল্যান্স দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',           'text' => 'রিকন সেশনের সব ম্যাচড এন্ট্রি দেখা'],
        ['icon' => 'fa-circle-exclamation', 'text' => 'আনরিকনসাইলড আইটেম দেখা'],
        ['icon' => 'fa-print',         'text' => 'রিকন সামারি প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'ব্যাংক রিকন বিস্তারিত রিপোর্ট তৈরি হয় — রিড-অনলি'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি এন্ট্রি বদলানো যায় না, পেন্ডিং আইটেম সাপ্লায়ার/খদ্দের পেজ থেকে মিলাতে হবে।',
    ],

    'related' => ['accounting.bank-reconciliation', 'accounting.bank-reconciliation-unreconciled'],

    'updated_at' => '2026-08-07',
];
