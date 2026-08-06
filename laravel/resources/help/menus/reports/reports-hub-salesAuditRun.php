<?php

/**
 * Help content for: reports.reports-hub-salesAuditRun
 * Route: admin.reports.salesAuditRun (ReportController@salesAuditRun)
 *
 * Sales Audit Run — actually executes the sales audit checklist for a
 * period and produces the pass/fail result and exceptions.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-salesAuditRun',
    'module'     => 'reports',
    'title_bn'   => 'সেলস অডিট রান',
    'title_en'   => 'Sales Audit Run',
    'icon'       => 'fa-play',
    'summary'    => 'সেলস অডিট চেকলিস্ট একটা সময়ের জন্য রান করে পাস/ফেইল ও ব্যতিক্রম বের করুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করে অডিট রান করা'],
        ['icon' => 'fa-play',           'text' => 'চেকলিস্ট এক্সিকিউট করে পাস/ফেইল দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'রান রেজাল্ট CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',   'what' => 'পোস্ট ডেটা থেকে অডিট রান এক্সিকিউট হয়'],
        ['who' => 'অডিট',   'what' => 'ফেইল বা ব্যতিক্রম লাইন এক্সসেপশন রিপোর্টে যায়'],
    ],

    'cautions' => [
        'রান শুধু পোস্ট ডেটায় চলে — ড্রাফট ইনভয়েস বা পেন্ডিং কমিশন ধরা হয় না।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-salesAuditChecklist'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
