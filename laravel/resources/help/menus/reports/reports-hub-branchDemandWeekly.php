<?php

/**
 * Help content for: reports.reports-hub-branchDemandWeekly
 * Route: admin.reports.branchDemandWeekly (ReportController@branchDemandWeekly)
 *
 * Branch Demand Weekly (legacy) — weekly demand requested by each branch
 * for stock replenishment. Legacy version of the newer branch-demand module.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-branchDemandWeekly',
    'module'     => 'reports',
    'title_bn'   => 'সাপ্তাহিক ব্র্যাঞ্চ ডিমান্ড',
    'title_en'   => 'Branch Demand Weekly (legacy)',
    'icon'       => 'fa-calendar-week',
    'summary'    => 'ব্র্যাঞ্চ থেকে সাপ্তাহিক যে ডিমান্ড আসে — পুরোনো ভার্সন, নতুন মডিউলে চলে গেছে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-week',  'text' => 'সপ্তাহ বা তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'ব্র্যাঞ্চ/প্রোডাক্ট ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'ব্র্যাঞ্চ ডিমান্ড',  'what' => 'সাপ্তাহিক ডিমান্ড রিকোর্ড থেকে সংখ্যা টানা হয়'],
        ['who' => 'রিপ্লেনিশমেন্ট',     'what' => 'গোয়ারহাউস রিপ্লেনিশমেন্ট প্ল্যানে সাহায্য করে'],
    ],

    'cautions' => [
        'লিগেসি রিপোর্ট — নতুন branch-demand মডিউলে ডেটা স্ট্রাকচার বদলালে কিছু কলাম নাও মিলতে পারে।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'finance.branch-demand'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
