<?php

/**
 * Help content for: purchasing.purchase-receives-audit
 * Route: admin.purchase-receives.audit
 *
 * Audit trail sub-page for Purchase Receives — read-only list of who
 * received goods, when, against which PO/supplier, with timestamps.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-receives-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'Purchase Receive Audit',
    'title_en'   => 'Purchase Receive Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি পি. রিসিভ-এর অডিট ট্রেইল পেজ — কে কখন মাল রিসিভ করেছে তার লগ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',      'text' => 'প্রতিটি রিসিভের ক্রিয়েশন/এডিট লগ দেখা'],
        ['icon' => 'fa-magnifying-glass','text' => 'সাপ্লায়ার, পিও বা তারিখ দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট', 'what' => 'রিড-অনলি — কোনো রিসিভ বদলায় না'],
    ],

    'cautions' => [
        'রিসিভ বাতিল বা এডিট করতে হলে পি. রিসিভ পেজে যান; এই পেজ শুধু পড়ার জন্য।',
    ],

    'related' => ['purchasing.purchase-receives', 'purchasing.purchase-audit', 'system.audit'],

    'updated_at' => '2026-08-07',
];
