<?php

/**
 * Help content for: reports.reports-hub-productStockAnalysis
 * Route: admin.reports.productStockAnalysis (ReportController@productStockAnalysis)
 *
 * Product Stock Analysis — current stock, stock value, turnover rate, slow/
 * fast movers, dead stock. Helps purchasing and clearance decisions.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-productStockAnalysis',
    'module'     => 'reports',
    'title_bn'   => 'প্রোডাক্ট স্টক অ্যানালাইসিস',
    'title_en'   => 'Product Stock Analysis',
    'icon'       => 'fa-boxes-stacked',
    'summary'    => 'প্রোডাক্টের বর্তমান স্টক, ভ্যালু, টার্নওভার — কোন প্রোডাক্ট বসে আছে আর কোনটা দ্রুত বিকোয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'প্রোডাক্ট/ক্যাটাগরি/গোডাউন ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক লেজার',  'what' => 'বর্তমান স্টক ও গড় ক্রয় মূল্য থেকে স্টক ভ্যালু বের হয়'],
        ['who' => 'পারচেজিং',     'what' => 'স্লো মুভার ও ডেড স্টক চিহ্নিত করে রিঅর্ডার সাহায্য করে'],
    ],

    'cautions' => [
        'টার্নওভার রেট নির্ভুল হতে পুরো পিরিয়ডের সব মুভমেন্ট পোস্ট থাকতে হবে।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-productMovement'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
