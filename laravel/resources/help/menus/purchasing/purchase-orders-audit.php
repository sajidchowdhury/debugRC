<?php

/**
 * Help content for: purchasing.purchase-orders-audit
 * Route: admin.purchase-orders.audit
 *
 * Audit trail sub-page for Purchase Orders — read-only list of who created,
 * edited, submitted, approved, rejected, marked-sent, or cancelled each PO,
 * with timestamps. Filtered from user_audit_log where action LIKE 'purchase_order_%'.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-orders-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'পারচেজ অর্ডার অডিট লগ',
    'title_en'   => 'Purchase Order Audit Log',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি পি. অর্ডার-এর অডিট ট্রেইল — কে কখন পিও তৈরি/এডিট/সাবমিট/অ্যাপ্রুভ/রিজেক্ট/সেন্ট/বাতিল করেছে তার লগ। শুধু পড়ার পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'প্রতিটি পিওর ক্রিয়েশন/এডিট/সাবমিট/অ্যাপ্রুভ/রিজেক্ট/সেন্ট/বাতিল লগ দেখা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'সাপ্লায়ার, তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-clock-rotate-left','text' => 'ক্রোনোলজিক্যাল টাইমলাইন দেখা — কোন অ্যাকশন কখন হয়েছে'],
        ['icon' => 'fa-user-shield',     'text' => 'মেকার-চেকার ট্রেইল — কে সাবমিট করেছে আর কে অ্যাপ্রুভ/রিজেক্ট করেছে তা আলাদা দেখা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',     'what' => 'রিড-অনলি — কোনো পিও বদলায় না'],
        ['who' => 'কমপ্লায়েন্স','what' => 'মেকার-চেকার পৃথকীকরণ যাচাই করা যায় (যিনি সাবমিট করেছেন তিনি অ্যাপ্রুভ করেছেন কিনা)'],
    ],

    'cautions' => [
        'এখান থেকে পিও এডিট বা বাতিল করা যায় না; বদলাতে হলে পি. অর্ডার পেজে ফিরে যান।',
        'অডিট লগ শুধু user_audit_log টেবিল থেকে আসে — ডিলিট হওয়া রেকর্ডের ইতিহাসও এখানে থাকে না (SoftDeletes থাকলেও)।',
    ],

    'related' => ['purchasing.purchase-orders', 'purchasing.purchase-audit', 'accounting.approvals', 'system.audit'],

    'updated_at' => '2026-08-13',
];
