<?php

/**
 * Help content for: reports.reports-hub-purchaseAudit
 * Route: admin.reports.purchaseAudit (ReportController@purchaseAudit)
 *
 * Purchase Audit report (legacy) — cross-checks purchase orders, receives,
 * and supplier invoices to find discrepancies. Used by internal audit.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-purchaseAudit',
    'module'     => 'reports',
    'title_bn'   => 'পারচেজ অডিট',
    'title_en'   => 'Purchase Audit (legacy)',
    'icon'       => 'fa-clipboard-list',
    'summary'    => 'পারচেজ অর্ডার, রিসিভ আর সাপ্লায়ার ইনভয়েস মিলিয়ে অসঙ্গতি খুঁজে বের করুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'সাপ্লায়ার/পারচেজ অর্ডার ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'পারচেজ',  'what' => 'পারচেজ অর্ডার, রিসিভ ও ইনভয়েস থেকে অসঙ্গতি টানা হয়'],
        ['who' => 'অডিট',     'what' => 'ফারাক অডিট লগ বা ইনভেস্টিগেশনে যায়'],
    ],

    'cautions' => [
        'লিগেসি রিপোর্ট — নতুন পারচেজ মডিউলে ডেটা স্ট্রাকচার বদলালে কিছু কলাম নাও মিলতে পারে।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-supplierWisePurchase'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
