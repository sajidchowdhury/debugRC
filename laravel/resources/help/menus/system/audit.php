<?php

/**
 * Help content for: system.audit
 * Route: admin.audit.index
 *
 * The Global Audit page — system-wide read-only trail of every meaningful
 * action across modules: who created/edited/deleted what, when, from which
 * IP, with before/after values where applicable. The compliance backbone.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.audit',
    'module'     => 'system',
    'title_bn'   => 'গ্লোবাল অডিট',
    'title_en'   => 'Global Audit',
    'icon'       => 'fa-shield-halved',
    'summary'    => 'পুরো সিস্টেম জুড়ে কে কখন কী বদলেছে — তার অ্যাপেন্ড-অনলি ট্রেইল; কমপ্লায়েন্স ও তদন্তের মূল জায়গা।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-shield-halved',   'text' => 'পুরো সিস্টেমের অডিট ট্রেইল দেখা'],
        ['icon' => 'fa-filter',          'text' => 'ইউজার / তারিখ / অ্যাকশন (create/update/delete) দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-eye',             'text' => 'নির্দিষ্ট এন্ট্রির ডিটেইলে ঢুকে আগে-পরের মান দেখা'],
        ['icon' => 'fa-file-export',     'text' => 'অডিট লগ সিএসভি/পিডিএফ হিসেবে এক্সপোর্ট করা'],
        ['icon' => 'fa-magnifying-glass','text' => 'কোনো নির্দিষ্ট রেকর্ডের ইতিহাস খুঁজে বের করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',    'what' => 'শুধু রিড-অনলি — মূল ডেটা বা ইউজার বদলায় না'],
        ['who' => 'কমপ্লায়েন্স', 'what' => 'কমপ্লায়েন্স চেক ও বাহ্যিক অডিটের প্রমাণ এখান থেকে আসে'],
    ],

    'cautions' => [
        'অডিট লগ অ্যাপেন্ড-অনলি — কেউ এখান থেকে কোনো এন্ট্রি মুছতে বা এডিট করতে পারে না।',
        'পুরোনো লগ আর্কাইভে চলে যেতে পারে — পুরোনো পিরিয়ড দেখতে system.archive ব্যবহার করুন।',
    ],

    'related' => ['system.audit-show', 'system.archive', 'system.compliance', 'system.users-security-audit', 'system.users-audit'],

    'updated_at' => '2026-08-07',
];
