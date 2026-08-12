<?php

/**
 * Help content for: reports.reports-hub-branchIntercompany
 * Route: admin.reports.branchIntercompany (ReportController@branchIntercompany)
 *
 * Branch Intercompany report — transfer of stock/cash between branches and
 * the offsetting intercompany payable/receivable balances.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-branchIntercompany',
    'module'     => 'reports',
    'title_bn'   => 'ব্র্যাঞ্চ ইন্টারকোম্পানি',
    'title_en'   => 'Branch Intercompany',
    'icon'       => 'fa-code-branch',
    'summary'    => 'ব্র্যাঞ্চের মধ্যে স্টক/ক্যাশ ট্রান্সফার ও তার পাওনা-বকেয়া — ইন্টারকোম্পানি ব্যালেন্স।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'সোর্স/ডেস্টিনেশন ব্র্যাঞ্চ ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'ইন্টারকোম্পানি GL',  'what' => 'ব্র্যাঞ্চ-টু-ব্র্যাঞ্চ ট্রান্সফার থেকে পাওনা-বকেয়া ব্যালেন্স তৈরি হয়'],
        ['who' => 'কনসলিডেশন',         'what' => 'কনসলিডেটেড রিপোর্টে ইন্টারকোম্পানি এলিমিনেশনে লাগে'],
    ],

    'cautions' => [
        'ট্রান্সফার ডকুমেন্ট পোস্ট না হলে ইন্টারকোম্পানি ব্যালেন্স সঠিক হবে না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-branchWiseLedger'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
