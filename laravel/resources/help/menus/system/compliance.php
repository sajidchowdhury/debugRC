<?php

/**
 * Help content for: system.compliance
 * Route: admin.compliance.index
 *
 * The System Policy / Compliance page — global settings for password rules,
 * failed-login lockout, session timeout, data-retention window, and other
 * security/compliance knobs. Changes here affect every user immediately.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.compliance',
    'module'     => 'system',
    'title_bn'   => 'সিস্টেম পলিসি',
    'title_en'   => 'System Policy',
    'icon'       => 'fa-gavel',
    'summary'    => 'পাসওয়ার্ড, লকআউট, সেশন, রিটেনশন — গ্লোবাল সিকিউরিটি ও কমপ্লায়েন্স পলিসি এখানে সেট করা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-key',             'text' => 'পাসওয়ার্ড পলিসি সেট করা (দৈর্ঘ্য, কমপ্লেক্সিটি, এক্সপায়রি)'],
        ['icon' => 'fa-lock',            'text' => 'লকআউট থ্রেশহোল্ড নির্ধারণ (কয়বার ভুল পাসওয়ার্ডে লক হবে)'],
        ['icon' => 'fa-clock',           'text' => 'সেশন টাইমআউট সেট করা (কতক্ষণ না কাজ করলে লগআউট)'],
        ['icon' => 'fa-box-archive',     'text' => 'ডেটা রিটেনশন পিরিয়ড নির্ধারণ (কতদিন আর্কাইভে থাকবে)'],
        ['icon' => 'fa-clipboard-check', 'text' => 'কমপ্লায়েন্স স্ট্যাটাস ও পলিসি রিপোর্ট দেখা'],
    ],

    'impacts' => [
        ['who' => 'সব ইউজার',       'what' => 'পাসওয়ার্ড/লকআউট পলিসি সবার ওপর সাথে সাথে প্রযোজ্য হয়'],
        ['who' => 'অডিট/আর্কাইভ',     'what' => 'রিটেনশন পিরিয়ড বদলালে আর্কাইভে কতদিন থাকবে তা বদলায়'],
        ['who' => 'কমপ্লায়েন্স',     'what' => 'গ্লোবাল নিয়ম এখান থেকেই প্রকাশ পায়'],
    ],

    'cautions' => [
        'পলিসি বদলালে সব ইউজারের ওপর তাৎক্ষণিকভাবে কাজ হয় — কড়া পাসওয়ার্ড পলিসি দিলে অনেকে পরের লগইনে ব্লক খেতে পারে।',
        'রিটেনশন পিরিয়ড কমালে পুরোনো আর্কাইভ ডেটা পার্জ হয়ে যেতে পারে — আইনি প্রয়োজন মিলিয়ে তারপর কমান।',
    ],

    'related' => ['system.audit', 'system.archive', 'system.users-security-audit', 'system.users'],

    'updated_at' => '2026-08-07',
];
