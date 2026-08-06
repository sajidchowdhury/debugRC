<?php

/**
 * Help content for: purchasing.purchase-returns-audit
 * Route: admin.purchase-returns.audit
 *
 * Audit trail sub-page for Purchase Returns — read-only list of who raised
 * a return, when, against which receive/supplier, with timestamps.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-returns-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'Purchase Return Audit',
    'title_en'   => 'Purchase Return Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি পি. রিটার্ন-এর অডিট ট্রেইল পেজ — কে কখন সাপ্লায়ারকে মাল ফেরত দিয়েছে তার লগ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',      'text' => 'প্রতিটি রিটার্নের ক্রিয়েশন/এডিট লগ দেখা'],
        ['icon' => 'fa-magnifying-glass','text' => 'সাপ্লায়ার, রিসিভ বা তারিখ দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট', 'what' => 'রিড-অনলি — কোনো রিটার্ন বদলায় না'],
    ],

    'cautions' => [
        'রিটার্ন বাতিল বা এডিট করতে হলে পি. রিটার্ন পেজে যান; এই পেজ শুধু পড়ার জন্য।',
    ],

    'related' => ['purchasing.purchase-returns', 'purchasing.purchase-audit', 'system.audit'],

    'updated_at' => '2026-08-07',
];
