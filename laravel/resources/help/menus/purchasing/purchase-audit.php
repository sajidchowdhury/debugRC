<?php

/**
 * Help content for: purchasing.purchase-audit
 * Route: admin.purchase-audit.checklist
 *
 * The consolidated Purchase Audit page — a read-only audit view across the
 * whole purchasing cycle (PO + receive + return). Used by managers and
 * auditors to verify who did what, when, against which supplier.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 */

return [
    'key'        => 'purchasing.purchase-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'পারচেজ অডিট',
    'title_en'   => 'Purchase Audit',
    'icon'       => 'fa-clipboard-list',
    'summary'    => 'পুরো ক্রয় সাইকেলের (পিও/রিসিভ/রিটার্ন) অডিট ট্রেইল এখানে এক জায়গায়; শুধু পড়ার পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-clipboard-list',  'text' => 'পিও / রিসিভ / রিটার্ন — তিন ধরনের অডিট ট্রেইল একসাথে দেখা'],
        ['icon' => 'fa-filter',          'text' => 'সাপ্লায়ার, তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-magnifying-glass','text' => 'নির্দিষ্ট এন্ট্রি খুঁজে বের করা (কে কখন কী করেছে)'],
        ['icon' => 'fa-file-export',     'text' => 'অডিট রেকর্ড এক্সপোর্ট করা (CSV)'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু পড়ার ভিউ — কোনো লেনদেন বদলায় না'],
        ['who' => 'কমপ্লায়েন্স', 'what' => 'ম্যানেজার/অডিটর চেক-অ্যান্ড-ব্যালেন্স পান'],
    ],

    'cautions' => [
        'এটি রিড-অনলি পেজ — এখান থেকে কোনো পিও/রিসিভ/রিটার্ন এডিট বা বাতিল হয় না; বদলাতে হলে মূল পেজে যান।',
    ],

    'related' => ['purchasing.purchase-orders-audit', 'purchasing.purchase-receives-audit', 'purchasing.purchase-returns-audit', 'purchasing.purchase-orders', 'system.audit'],

    // No diagram here — this is a consolidated audit index, not a workflow step.

    'updated_at' => '2026-08-07',
];
