<?php

/**
 * Help content for: finance.budgets-variance
 * Route: admin.budgets.variance
 *
 * Budget Variance Report sub-page — side-by-side view of budgeted vs actual
 * amounts per ledger/branch/period, with variance % and direction (over/under).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.budgets-variance',
    'module'     => 'finance',
    'title_bn'   => 'Budget Variance Report',
    'title_en'   => 'Budget Variance Report',
    'icon'       => 'fa-chart-line',
    'summary'    => 'বাজেট বনাম আসল খরচ পাশাপাশি — কোথায় বাজেট ছাড়িয়েছে বা কম খরচ হয়েছে তা ধরা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-chart-line',  'text' => 'লেজার/ব্র্যাঞ্চ ধরে বাজেট বনাম আসল তুলনা দেখা'],
        ['icon' => 'fa-file-export',  'text' => 'ভ্যারিয়েন্স রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — বাজেট বা GL বদলায় না'],
    ],

    'cautions' => [
        'বাজেট বদলাতে এই পেজ নয় — finance.budgets-এ গিয়ে (পিরিয়ড খোলা থাকলে) এডিট করুন।',
    ],

    'related' => ['finance.budgets', 'master-data.ledgers'],

    'updated_at' => '2026-08-07',
];
