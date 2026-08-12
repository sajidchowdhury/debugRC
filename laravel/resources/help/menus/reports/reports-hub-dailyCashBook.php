<?php

/**
 * Help content for: reports.reports-hub-dailyCashBook
 * Route: admin.reports.dailyCashBook (ReportController@dailyCashBook)
 *
 * Daily Cash Book — day-by-day cash in/out, opening and closing cash balance.
 * Used by cashiers and accountants to reconcile the cash drawer.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-dailyCashBook',
    'module'     => 'reports',
    'title_bn'   => 'ডেইলি ক্যাশ বুক',
    'title_en'   => 'Daily Cash Book',
    'icon'       => 'fa-book-open',
    'summary'    => 'দিন ধরে নগদ ইন/আউট ও ক্লোজিং ব্যালেন্স — ক্যাশ ড্রয়ার মেলানোর জন্য।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-day',   'text' => 'নির্দিষ্ট তারিখ/তারিখ সীমা সেট করা'],
        ['icon' => 'fa-eye',            'text' => 'দৈনিক ক্যাশ ইন, আউট, ক্লোজিং ব্যালেন্স দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'ক্যাশ লেজার',   'what' => 'ক্যাশ ইন/আউট এন্ট্রি থেকে দৈনিক মুভমেন্ট টানা হয়'],
        ['who' => 'হিসাব',           'what' => 'ওপেনিং + মুভমেন্ট = ক্লোজিং ক্যাশ ব্যালেন্স মিলিয়ে যায়'],
    ],

    'cautions' => [
        'ক্যাশ রিসিট ও ভাউচার পোস্ট হলে তবেই এই বুকে আসবে — পেন্ডিং এন্ট্রি বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-cashFlow'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
