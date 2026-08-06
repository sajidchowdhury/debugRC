<?php

/**
 * Help content for: purchasing.purchase-orders-audit
 * Route: admin.purchase-orders.audit
 *
 * Audit trail sub-page for Purchase Orders — read-only list of who created,
 * edited, approved, or cancelled each PO, with timestamps.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-orders-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'Purchase Order Audit',
    'title_en'   => 'Purchase Order Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি পি. অর্ডার-এর অডিট ট্রেইল পেজ — কে কখন পিও তৈরি/এডিট/অ্যাপ্রুভ করেছে তার লগ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',      'text' => 'প্রতিটি পিওর ক্রিয়েশন/এডিট/অ্যাপ্রুভ লগ দেখা'],
        ['icon' => 'fa-magnifying-glass','text' => 'সাপ্লায়ার বা তারিখ দিয়ে অডিট ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট', 'what' => 'রিড-অনলি — কোনো পিও বদলায় না'],
    ],

    'cautions' => [
        'এখান থেকে পিও এডিট বা বাতিল করা যায় না; বদলাতে হলে পি. অর্ডার পেজে ফিরে যান।',
    ],

    'related' => ['purchasing.purchase-orders', 'purchasing.purchase-audit', 'system.audit'],

    'updated_at' => '2026-08-07',
];
