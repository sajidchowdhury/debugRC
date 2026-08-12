<?php

/**
 * Help content for: reports.reports-hub-cashFlow
 * Route: admin.reports.cashFlow (ReportController@cashFlow)
 *
 * Cash Flow statement — operating, investing, and financing cash movements
 * for a period. Shows actual cash in/out vs P&L accrual.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-cashFlow',
    'module'     => 'reports',
    'title_bn'   => 'ক্যাশফ্লো',
    'title_en'   => 'Cash Flow',
    'icon'       => 'fa-money-bill-wave',
    'summary'    => 'অপারেটিং, ইনভেস্টিং, ফাইন্যান্সিং — কোথা থেকে নগদ এলো আর কোথায় গেল।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-eye',            'text' => 'অপারেটিং/ইনভেস্টিং/ফাইন্যান্সিং ক্যাশ মুভমেন্ট দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'PDF/CSV তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'GL',         'what' => 'ক্যাশ ও ব্যাংক লেজার থেকে নগদ মুভমেন্ট টানা হয়'],
        ['who' => 'হিসাব',         'what' => 'পিএল ও ব্যালেন্স শিট থেকে নন-ক্যাশ অ্যাডজাস্টমেন্ট আসে'],
    ],

    'cautions' => [
        'ক্যাশফ্লো পিএল এর সাথে মিলবে না — পিএল অ্যাক্রুয়াল ভিত্তিক, ক্যাশফ্লো নগদ ভিত্তিক।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-dailyCashBook'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
