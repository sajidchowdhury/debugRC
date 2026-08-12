<?php

/**
 * Help content for: finance.budgets
 * Route: admin.budgets.index (and create/store/show/edit via resource + variance sub-route)
 *
 * The Budget page — set per-ledger, per-branch, per-period budget amounts and
 * track actual vs budget variance. Used for monthly/quarterly expense control.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (primary card)
 */

return [
    'key'        => 'finance.budgets',
    'module'     => 'finance',
    'title_bn'   => 'বাজেট',
    'title_en'   => 'Budget',
    'icon'       => 'fa-piggy-bank',
    'summary'    => 'লেজার/ব্র্যাঞ্চ ধরে মাসিক বা সালি বাজেট বসান, আর খরচের সঙ্গে মিলিয়ে ভ্যারিয়েন্স দেখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন বাজেট তৈরি — লেজার/ব্র্যাঞ্চ/পিরিয়ড বেছে টাকা বসানো'],
        ['icon' => 'fa-list',              'text' => 'আগের সব বাজেট দেখা ও ফিল্টার করা'],
        ['icon' => 'fa-pen-to-square',      'text' => 'বাজেট পরিমাণ এডিট করা (পিরিয়ড ক্লোজের আগে)'],
        ['icon' => 'fa-chart-line',         'text' => 'বাজেট বনাম আসল খরচের ভ্যারিয়েন্স দেখা'],
        ['icon' => 'fa-file-export',       'text' => 'বাজেট রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)',     'what' => 'সরাসরি কোনো জার্নাল পোস্ট হয় না — তবে আসল খরচ এখানে মাপা হয়'],
        ['who' => 'ভ্যারিয়েন্স রিপোর্ট', 'what' => 'বাজেট বনাম আসল খরচের পার্থক্য রিপোর্টে দেখা যায়'],
        ['who' => 'পিরিয়ড ক্লোজ',     'what' => 'পিরিয়ড বন্ধ হলে ওই পিরিয়ডের বাজেট লক হয়ে যায়'],
    ],

    'cautions' => [
        'একবার পিরিয়ড ক্লোজ হলে ওই পিরিয়ডের বাজেট আর এডিট করা যায় না — আগেই ঠিকমতো বসিয়ে নিন।',
        'ভুল লেজারে বাজেট বসালে ভ্যারিয়েন্স রিপোর্ট ভুল দেখাবে — লেজার/ব্র্যাঞ্চ দুবার চেক করুন।',
    ],

    'related' => [
        'finance.budgets-variance',
        'master-data.ledgers',
        'master-data.branches',
        'accounting.period-close',
        'accounting.manual-journals',
    ],

    // No diagram — a single screen of rows; the variance sub-page carries the chart.

    'updated_at' => '2026-08-07',
];
