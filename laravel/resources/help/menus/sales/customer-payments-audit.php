<?php

/**
 * Help content for: sales.customer-payments-audit
 * Route: admin.customer-payments.audit
 *
 * Sub-page of sales.customer-payments — the audit trail of all payment
 * entries (who recorded what, when, against which invoice).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.customer-payments-audit',
    'module'     => 'sales',
    'title_bn'   => 'Customer Payment Audit',
    'title_en'   => 'Customer Payment Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি কাস্টমার পেমেন্ট-এর অডিট পেজ — সব পেমেন্ট এন্ট্রির ইতিহাস ও পরিবর্তন এখানে দেখা যায়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',            'text' => 'সব পেমেন্টের অডিট ট্রেইল দেখা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'তারিখ/খদ্দের/ইনভয়েস দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু দেখার পেজ — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'অডিট পেজ শুধু পঠনযোগ্য — ভুল ধরা পড়লে মূল পেমেন্ট এন্ট্রি থেকে সংশোধন করতে হবে।',
    ],

    'related' => ['sales.customer-payments', 'system.audit'],

    'updated_at' => '2026-08-07',
];
