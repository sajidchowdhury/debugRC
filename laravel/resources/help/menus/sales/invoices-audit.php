<?php

/**
 * Help content for: sales.invoices-audit
 * Route: admin.sales.audit (SalesInvoiceController@auditTrail)
 *
 * Sub-page of sales.invoices — the consolidated sales audit trail.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.invoices-audit',
    'module'     => 'sales',
    'title_bn'   => 'Sales Audit Trail',
    'title_en'   => 'Sales Audit Trail',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি সেলস ইনভয়েস-এর অডিট পেজ — সব ইনভয়েস ও সম্পর্কিত লেনদেনের ইতিহাস এখানে দেখা যায়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',            'text' => 'সব সেলস লেনদেনের অডিট ট্রেইল দেখা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'তারিখ/খদ্দের/সেলসম্যান দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',     'text' => 'অডিট রেকর্ড এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু দেখার পেজ — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'অডিট পেজ শুধু পঠনযোগ্য — ভুল ধরা পড়লে রিটার্ন বা ম্যানুয়াল জার্নাল দিয়ে সংশোধন করতে হবে।',
    ],

    'related' => ['sales.invoices', 'system.audit'],

    'updated_at' => '2026-08-07',
];
