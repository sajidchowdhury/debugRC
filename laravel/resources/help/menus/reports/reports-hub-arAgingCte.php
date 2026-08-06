<?php

/**
 * Help content for: reports.reports-hub-arAgingCte
 * Route: admin.reports.arAgingCte (ReportController@arAgingCte)
 *
 * AR Aging — CTE version. Same receivable aging report but uses a Common
 * Table Expression for faster bucket aggregation on large customer bases.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-arAgingCte',
    'module'     => 'reports',
    'title_bn'   => 'AR এজিং (CTE)',
    'title_en'   => 'AR Aging (CTE)',
    'icon'       => 'fa-hourglass-half',
    'summary'    => 'রিসিভেবল এজিং এর দ্রুত ভার্সন — বড় খদ্দের তালিকার জন্য CTE অপটিমাইজড।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'অ্যাজ-অফ তারিখ সেট করা'],
        ['icon' => 'fa-bolt',           'text' => 'বড় খদ্দের তালিকার জন্য দ্রুত বালত তৈরি'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের লেজার',  'what' => 'একই পোস্ট ইনভয়েস ও পেমেন্ট থেকে বকেয়া ব্যালেন্স তৈরি হয় (receivableAging এর সমান)'],
        ['who' => 'কালেকশন',         'what' => 'সংখ্যা সমান, শুধু পারফরম্যান্স ভালো'],
    ],

    'cautions' => [
        'এটি receivableAging এর সমানুপাতিক ভার্সন — সংখ্যা মিলবে, শুধু দ্রুত রান হবে।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-receivableAging'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
