<?php

/**
 * Help content for: reports.reports-hub-supplierWisePurchase
 * Route: admin.reports.supplierWisePurchase (ReportController@supplierWisePurchase)
 *
 * Supplier-wise Purchase report — total purchase qty, value, and average
 * lead time per supplier for a period. Helps with supplier evaluation.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'reports.reports-hub-supplierWisePurchase',
    'module'     => 'reports',
    'title_bn'   => 'সাপ্লায়ার ওয়াইজ পারচেজ',
    'title_en'   => 'Supplier-wise Purchase',
    'icon'       => 'fa-truck',
    'summary'    => 'কোন সাপ্লায়ার থেকে কত পরিমাণ ও মূল্যে ক্রয় হলো — মূল্যায়নের জন্য।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calendar-days',  'text' => 'তারিখ সীমা সেট করা'],
        ['icon' => 'fa-filter',         'text' => 'সাপ্লায়ার/প্রোডাক্ট/ব্র্যাঞ্চ ধরে ফিল্টার করা'],
        ['icon' => 'fa-file-export',    'text' => 'CSV/PDF তে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'পারচেজ',  'what' => 'পোস্ট পারচেজ অর্ডার ও রিসিভ থেকে ক্রয় অ্যাগ্রিগেট হয়'],
        ['who' => 'সাপ্লায়ার মূল্যায়ন',  'what' => 'টপ সাপ্লায়ার চিহ্নিত করে রিনেগোশিয়েশনে সাহায্য করে'],
    ],

    'cautions' => [
        'শুধু পোস্ট পারচেজ ধরা হয় — ড্রাফট পিও বা পেন্ডিং রিসিভ বাদ যায়।',
    ],

    'related' => ['reports.reports-hub', 'reports.dashboard', 'reports.reports-hub-purchaseAudit'],

    // No diagram — per plan 7g the reports module has 0 diagrams.

    'updated_at' => '2026-08-07',
];
