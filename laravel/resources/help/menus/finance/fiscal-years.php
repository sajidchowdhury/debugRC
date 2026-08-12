<?php

/**
 * Help content for: finance.fiscal-years
 * Route: admin.fiscal-years.index (and create/store/show/edit + close-log sub-route)
 *
 * The Fiscal Year page — define accounting fiscal years (start/end dates), the
 * periods within them (monthly/quarterly), and close a year once books are final.
 * Closing a year freezes GL postings for that year.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (primary card)
 */

return [
    'key'        => 'finance.fiscal-years',
    'module'     => 'finance',
    'title_bn'   => 'ফিসকাল ইয়ার',
    'title_en'   => 'Fiscal Year',
    'icon'       => 'fa-calendar-days',
    'summary'    => 'হিসাব বছর বসান, মাসিক পিরিয়ড তৈরি করুন, আর বই ফাইনাল হলে বছর ক্লোজ করুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন ফিসকাল ইয়ার তৈরি — শুরু/শেষ তারিখ দিয়ে'],
        ['icon' => 'fa-calendar-day',      'text' => 'মাসিক বা কোয়ার্টারলি পিরিয়ড ভাগ করা'],
        ['icon' => 'fa-lock',              'text' => 'পিরিয়ড বা সাল ক্লোজ করা — এন্ট্রি লক হয়ে যায়'],
        ['icon' => 'fa-clock-rotate-left', 'text' => 'ক্লোজ লগ দেখা — কে কখন ক্লোজ করেছে'],
        ['icon' => 'fa-eye',               'text' => 'আগের ফিসকাল ইয়ার দেখা ও তুলনা করা'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)',     'what' => 'ক্লোজ হওয়া পিরিয়ডে নতুন এন্ট্রি বসে না — GL ফ্রিজ হয়'],
        ['who' => 'পিরিয়ড ক্লোজ',     'what' => 'accounting.period-close এই ফিসকাল ইয়ার ধরেই চলে'],
        ['who' => 'রিপোর্ট',         'what' => 'সালি রিপোর্ট ঠিক ফিসকাল ইয়ার সীমানায় দেখা যায়'],
    ],

    'cautions' => [
        'একবার সাল ক্লোজ করলে সেটি রিভার্স করা কঠিন — সব মাস ফাইনাল হলে তবেই ক্লোজ করুন।',
        'ওভারল্যাপিং তারিখ দিলে এন্ট্রি ভুল পিরিয়ডে বসতে পারে — শুরু/শেষ তারিখ দুবার চেক করুন।',
    ],

    'related' => [
        'finance.fiscal-years-close-log',
        'accounting.period-close',
        'accounting.manual-journals',
        'master-data.ledgers',
    ],

    // No diagram — fiscal year is a date-boundary concept; the period-close page carries the timing picture.

    'updated_at' => '2026-08-07',
];
