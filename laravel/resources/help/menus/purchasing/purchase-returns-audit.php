<?php

/**
 * Help content for: purchasing.purchase-returns-audit
 * Route: admin.purchase-returns.audit
 *
 * Audit trail sub-page for Purchase Returns — read-only list of who raised,
 * confirmed, or cancelled each return, with timestamps. Filtered from
 * user_audit_log where action LIKE 'purchase_return_%'.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-returns-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'পারচেজ রিটার্ন অডিট লগ',
    'title_en'   => 'Purchase Return Audit Log',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি পি. রিটার্ন-এর অডিট ট্রেইল — কে কখন সাপ্লায়ারকে মাল ফেরত দিয়েছে/কনফার্ম/বাতিল করেছে তার লগ। শুধু পড়ার পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'প্রতিটি রিটার্নের ক্রিয়েশন/কনফার্ম/বাতিল/রিভার্স লগ দেখা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'সাপ্লায়ার, GRN, তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-clock-rotate-left','text' => 'ক্রোনোলজিক্যাল টাইমলাইন দেখা — কোন রিটার্ন কখন কনফার্ম হয়েছে'],
        ['icon' => 'fa-user-shield',     'text' => 'মেকার-চেকার ট্রেইল — warehouse_manager ড্রাফট করেছে, manager কনফার্ম করেছে কিনা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',     'what' => 'রিড-অনলি — কোনো রিটার্ন বদলায় না'],
        ['who' => 'কমপ্লায়েন্স','what' => 'ডেবিট নোট (পেয়েবল কমানো) অপারেশনের দ্বৈত-নিয়ন্ত্রণ যাচাই করা যায়'],
    ],

    'cautions' => [
        'রিটার্ন এডিট বা বাতিল করতে হলে পি. রিটার্ন পেজে যান; এই পেজ শুধু পড়ার জন্য।',
        'কনফার্ম ও বাতিল লগ শুধু admin/manager-এর থাকবে — warehouse_manager-এর নয় (তারা শুধু ড্রাফট তৈরি করতে পারে)।',
    ],

    'related' => ['purchasing.purchase-returns', 'purchasing.purchase-returns-slip', 'purchasing.purchase-audit', 'system.audit'],

    'updated_at' => '2026-08-13',
];
