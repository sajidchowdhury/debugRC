<?php

/**
 * Help content for: reports.dashboard
 * Route: dashboard (UserPerformanceDashboardController@index)
 *
 * The main dashboard — KPIs, today summary, quick stats. The landing screen
 * a manager sees first on login: today's sales, stock value, cash position,
 * receivables, and a quick jump into the reports hub.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.dashboard',
    'module'     => 'reports',
    'title_bn'   => 'ড্যাশবোর্ড',
    'title_en'   => 'Dashboard',
    'icon'       => 'fa-gauge-high',
    'summary'    => 'লগইন করার পর প্রথম যে পেজ দেখবেন — আজকের বিক্রি, নগদ, বকেয়া আর স্টকের এক নজরে ছবি।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant', 'salesman'],

    'what_you_can_do' => [
        ['icon' => 'fa-sun',                 'text' => 'আজকের সামারি (today summary) দেখা — বিক্রি, ক্রয়, নগদ ইন/আউট'],
        ['icon' => 'fa-chart-line',          'text' => 'সেলস ট্রেন্ড গ্রাফ দেখা (দৈনিক/সাপ্তাহিক/মাসিক)'],
        ['icon' => 'fa-boxes-stacked',       'text' => 'স্টক ভ্যালু ও টপ প্রোডাক্ট দেখা'],
        ['icon' => 'fa-money-bill-wave',     'text' => 'ক্যাশ পজিশন ও ব্যাংক ব্যালেন্স দেখা'],
        ['icon' => 'fa-hourglass-half',      'text' => 'রিসিভেবল ও পেয়েবল এজিং এক নজরে দেখা'],
        ['icon' => 'fa-arrow-up-right-from-square', 'text' => 'যেকোনো KPI থেকে সংশ্লিষ্ট রিপোর্টে ড্রিল-ডাউন করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',     'what' => 'পোস্ট হওয়া ইনভয়েস থেকে আজকের বিক্রি অ্যাগ্রিগেট হয়'],
        ['who' => 'স্টক',      'what' => 'বর্তমান স্টক ভ্যালু রিয়েল-টাইমে টানা হয়'],
        ['who' => 'ক্যাশ',     'what' => 'ক্যাশ বুক ও ব্যাংক লেজার থেকে পজিশন আসে'],
        ['who' => 'হিসাব',     'what' => 'GL ব্যালেন্স থেকে বকেয়া সংখ্যা তৈরি হয়'],
    ],

    'cautions' => [
        'ড্যাশবোর্ডের সংখ্যা শুধু পোস্ট হওয়া ডেটা দেখায় — ড্রাফট ইনভয়েস বা পেন্ডিং জার্নাল ধরা হয় না।',
        'ক্যাশে থাকা পুরোনো সংখ্যা দেখলে পেজ রিফ্রেশ করুন; নতুন এন্ট্রি এলে অটো-রিফ্রেশ হতে কয়েক সেকেন্ড লাগে।',
    ],

    'related' => ['reports.reports-hub', 'reports.reports-hub-todaySummaryCte', 'reports.reports-hub-revenueOverview', 'reports.customer-performance'],

    // No diagram — dashboard is a KPI grid; a flow diagram wouldn't add value.

    'updated_at' => '2026-08-07',
];
