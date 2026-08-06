<?php

/**
 * Help content for: sales.returns-audit
 * Route: admin.sales-returns.audit
 *
 * Sub-page of sales.returns — the audit trail of all sales returns.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.returns-audit',
    'module'     => 'sales',
    'title_bn'   => 'Sales Return Audit',
    'title_en'   => 'Sales Return Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি সেলস রিটার্ন-এর অডিট পেজ — সব রিটার্ন এন্ট্রির ইতিহাস ও পরিবর্তন এখানে দেখা যায়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',            'text' => 'সব সেলস রিটার্নের অডিট ট্রেইল দেখা'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'তারিখ/খদ্দের/ইনভয়েস দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু দেখার পেজ — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'অডিট পেজ শুধু পঠনযোগ্য — ভুল রিটার্ন ধরা পড়লে মূল রিটার্ন এন্ট্রি থেকে সংশোধন করতে হবে।',
    ],

    'related' => ['sales.returns', 'system.audit'],

    'updated_at' => '2026-08-07',
];
