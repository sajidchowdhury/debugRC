<?php

/**
 * Help content for: accounting.period-close
 * Route: admin.accounting.period-close
 *
 * The Period Close page — close an accounting period (usually a month).
 * Pre-close checks run, the period is locked, closing entries are generated,
 * and statements (trial balance, P&L) are produced for the closed period.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'accounting.period-close',
    'module'     => 'accounting',
    'title_bn'   => 'পিরিয়ড ক্লোজ',
    'title_en'   => 'Period Close',
    'icon'       => 'fa-lock',
    'summary'    => 'মাসের শেষে হিসাবের পিরিয়ড বন্ধ করার পেজ — প্রি-ক্লোজ চেক চলে, পিরিয়ড লক হয়, ক্লোজিং এন্ট্রি তৈরি হয়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',     'text' => 'প্রি-ক্লোজ চেক চালানো — কোন পেন্ডিং জার্নাল বা আনরিকনসাইলড আইটেম আছে কিনা'],
        ['icon' => 'fa-lock',            'text' => 'পিরিয়ড লক করা — সেই তারিখে আর নতুন এন্ট্রি দেওয়া যাবে না'],
        ['icon' => 'fa-pen-nib',        'text' => 'ক্লোজিং জার্নাল তৈরি করা (অ্যাক্রুয়াল, ডিপ্রিসিয়েশন ইত্যাদি)'],
        ['icon' => 'fa-file-lines',     'text' => 'ক্লোজড পিরিয়ডের ট্রায়াল ব্যাল্যান্স ও পিএল জেনারেট করা'],
        ['icon' => 'fa-eye',            'text' => 'ক্লোজ লগ দেখা — কে কখন পিরিয়ড ক্লোজ করেছে'],
    ],

    'impacts' => [
        ['who' => 'পিরিয়ড',         'what' => 'লক হয়ে যায় — সেই তারিখে নতুন জার্নাল আটকে যায়'],
        ['who' => 'হিসাব (GL)',   'what' => 'সেই পিরিয়ডের জিএল ফ্রিজ হয়ে যায়'],
        ['who' => 'রিপোর্ট',        'what' => 'ক্লোজড পিরিয়ডের স্টেটমেন্ট তৈরি হয়'],
        ['who' => 'অডিট',         'what' => 'ক্লোজ অ্যাকশন অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'পিরিয়ড লক করা সহজে রিভার্স করা যায় না — রিওপেন করতে হলে বিশেষ পারমিশন লাগে আর অডিটে লেখা পড়ে।',
        'ক্লোজের আগে সব জার্নাল, সাব-লেজার, ব্যাংক রিকন সম্পূর্ণ করে নিন — পরে পোস্ট করতে গেলে পিরিয়ড রিওপেন করতে হবে।',
    ],

    'related' => ['accounting.manual-journals', 'accounting.bank-reconciliation', 'reports.reports-hub-trialBalance', 'reports.reports-hub-balanceSheet', 'master-data.ledgers'],

    'diagram' => 'period-close',

    'updated_at' => '2026-08-07',
];
