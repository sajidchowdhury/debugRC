<?php

/**
 * Help content for: accounting.bank-reconciliation-create
 * Route: admin.bank-reconciliation.create
 *
 * Create form for starting a new bank reconciliation session — pick the bank
 * account, statement period, opening/closing balance, then move on to
 * matching transactions.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.bank-reconciliation-create',
    'module'     => 'accounting',
    'title_bn'   => 'ব্যাংক রিকন তৈরি',
    'title_en'   => 'Create Bank Reconciliation',
    'icon'       => 'fa-plus',
    'summary'    => 'এটি bank-reconciliation-এর তৈরি ফর্ম — কোন ব্যাংক, কোন স্টেটমেন্ট পিরিয়ড, ওপেনিং/ক্লোজিং ব্যাল্যান্স দিয়ে নতুন রিকন শুরু করা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',           'text' => 'নতুন রিকন সেশন শুরু করা (ব্যাংক + পিরিয়ড + ব্যাল্যান্স দিয়ে)'],
        ['icon' => 'fa-file-import',    'text' => 'স্টেটমেন্ট ইম্পোর্টের অপশন দেখা'],
    ],

    'impacts' => [
        ['who' => 'ব্যাংক লেজার', 'what' => 'নতুন রিকন সেশন তৈরি হয় — এন্ট্রি ম্যাচ শুরু করা যায়'],
    ],

    'cautions' => [
        'সঠিক স্টেটমেন্ট তারিখ ও ব্যাল্যান্স দিন — ভুল হলে পুরো সেশন ভুল ম্যাচ দেখাবে।',
    ],

    'related' => ['accounting.bank-reconciliation', 'accounting.bank-reconciliation-import-statement'],

    'updated_at' => '2026-08-07',
];
