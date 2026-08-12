<?php

/**
 * Help content for: system.users-audit
 * Route: admin.users.audit (uri: admin/users/audit)
 *
 * Audit trail for the User master — read-only history of every user-account
 * change (create / role change / activate-deactivate / password reset),
 * who performed it, and when.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.users-audit',
    'module'     => 'system',
    'title_bn'   => 'User Audit',
    'title_en'   => 'User Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'ইউজার অ্যাকাউন্টের অডিট ট্রেইল — কে কখন কোন ইউজার তৈরি/বদল/বন্ধ করেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'ইউজার পরিবর্তনের অডিট লগ দেখা'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ / অ্যাকশন / অ্যাক্টর দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',      'text' => 'লগ এক্সপোর্ট করা (কমপ্লায়েন্স)'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — ইউজার বদলায় না'],
    ],

    'cautions' => [
        'অডিট লগ অ্যাপেন্ড-অনলি — ভুল এন্ট্রি মুছতে বা এডিট করতে পারবেন না।',
    ],

    'related' => ['system.users', 'system.users-security-audit', 'system.audit'],

    'updated_at' => '2026-08-07',
];
