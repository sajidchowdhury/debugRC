<?php

/**
 * Help content for: system.audit-show
 * Route: admin.audit.show (uri: admin/audit/{id})
 *
 * The detail drill-down of a single audit-log entry — who did what, when,
 * from where, with before/after JSON of the changed fields. The forensic
 * view behind every row on the system.audit list.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.audit-show',
    'module'     => 'system',
    'title_bn'   => 'Audit Entry Detail',
    'title_en'   => 'Audit Entry Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'একটি অডিট লগ এন্ট্রির ডিটেইল — কে, কখন, কোন আইপি থেকে, কী বদলেছে (আগে/পরে) তা দেখা।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',                'text' => 'একটি অডিট এন্ট্রির সম্পূর্ণ ডিটেইল দেখা'],
        ['icon' => 'fa-arrow-right-arrow-left', 'text' => 'বদলানো ফিল্ডের before/after মান তুলনা করা'],
        ['icon' => 'fa-file-export',       'text' => 'এন্ট্রি পিডিএফ/সিএসভিতে এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — কোনো বদল হয় না'],
    ],

    'cautions' => [
        'অডিট এন্ট্রি অ্যাপেন্ড-অনলি — before/after মান এখানে দেখানো হলেও এডিট করা যায় না।',
    ],

    'related' => ['system.audit', 'system.users-security-audit'],

    'updated_at' => '2026-08-07',
];
