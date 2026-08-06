<?php

/**
 * Help content for: reports.reports-hub-salesAuditChecklist
 * Route: admin.reports.salesAuditChecklist (ReportController@salesAuditChecklist)
 *
 * Sales Audit Checklist — list of audit checks (invoice vs challan vs payment
 * vs commission) to run on a period. Used to prepare for month-end close.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-salesAuditChecklist',
    'module'     => 'reports',
    'title_bn'   => 'সেলস অডিট চেকলিস্ট',
    'title_en'   => 'Sales Audit Checklist',
    'icon'       => 'fa-list-check',
    'summary'    => 'মাস শেষে সেলস হিসাব ঠিক আছে কি না — ইনভয়েস, চালান, পেমেন্ট, কমিশন মেলানোর চেকলিস্ট।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-list-check',    'text' => 'প্রতিটা চেক আইটেম ধরে পাস/ফেইল দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'সেলস',   'what' => 'ইনভয়েস, চালান, পেমেন্ট ও কমিশন থেকে চেক লাইন টানা হয়'],
        ['who' => 'অডিট',   'what' => 'ফেইল চেক অডিট রেজোলিউশন কাজ তৈরি করে'],
    ],

    'cautions' => [
        'চেকলিস্ট শুধু পোস্ট ডেটা থেকে চলে — পেন্ডিং ইনভয়েস বা কমিশন বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-salesAuditRun'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
