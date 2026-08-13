<?php

/**
 * Help content for: purchasing.purchase-receives-audit
 * Route: admin.purchase-receives.audit
 *
 * Audit trail sub-page for Purchase Receives (GRN) — read-only list of who
 * created, confirmed, or cancelled each GRN, with timestamps. Filtered from
 * user_audit_log where action LIKE 'purchase_receive_%'.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-receives-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'পারচেজ রিসিভ অডিট লগ',
    'title_en'   => 'Purchase Receive Audit Log',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি পি. রিসিভ (GRN)-এর অডিট ট্রেইল — কে কখন মাল রিসিভ/কনফার্ম/বাতিল করেছে তার লগ। শুধু পড়ার পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'প্রতিটি GRN-এর ক্রিয়েশন/কনফার্ম/বাতিল/রিভার্স লগ দেখা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'সাপ্লায়ার, পিও, তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-clock-rotate-left','text' => 'ক্রোনোলজিক্যাল টাইমলাইন দেখা — কোন GRN কখন কনফার্ম হয়েছে'],
        ['icon' => 'fa-user-shield',     'text' => 'মেকার-চেকার ট্রেইল — warehouse_manager ড্রাফট করেছে, manager কনফার্ম করেছে কিনা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',     'what' => 'রিড-অনলি — কোনো রিসিভ বদলায় না'],
        ['who' => 'কমপ্লায়েন্স','what' => 'কনফার্ম (ডিস্ট্রাকটিভ) অপারেশনের দ্বৈত-নিয়ন্ত্রণ যাচাই করা যায়'],
    ],

    'cautions' => [
        'রিসিভ এডিট বা বাতিল করতে হলে পি. রিসিভ পেজে যান; এই পেজ শুধু পড়ার জন্য।',
        'কনফার্ম ও বাতিল লগ শুধু admin/manager-এর থাকবে — warehouse_manager-এর নয় (তারা শুধু ড্রাফট তৈরি করতে পারে)।',
    ],

    'related' => ['purchasing.purchase-receives', 'purchasing.purchase-audit', 'system.audit'],

    'updated_at' => '2026-08-13',
];
