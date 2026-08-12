<?php

/**
 * Help content for: system.users-security-audit
 * Route: admin.users.security (uri: admin/users/{user}/security)
 *
 * Per-user security audit — login history, failed-login attempts, password
 * reset events, active sessions, and policy-compliance flags for one user.
 * Use to investigate a suspected compromised or locked account.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.users-security-audit',
    'module'     => 'system',
    'title_bn'   => 'User Security Audit',
    'title_en'   => 'User Security Audit',
    'icon'       => 'fa-user-shield',
    'summary'    => 'একজন ইউজারের লগইন হিস্ট্রি, ফেইল্ড অ্যাটেম্পট, পাসওয়ার্ড রিসেট ও সক্রিয় সেশন — সিকিউরিটি তদন্ত।',

    'for_roles'  => ['admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-shield',        'text' => 'ইউজারের লগইন হিস্ট্রি ও সক্রিয় সেশন দেখা'],
        ['icon' => 'fa-triangle-exclamation','text' => 'ফেইল্ড লগইন ও লকআউট ঘটনা দেখা'],
        ['icon' => 'fa-key',                 'text' => 'পাসওয়ার্ড রিসেট ও পলিসি ভায়োলেশন দেখা'],
    ],

    'impacts' => [
        ['who' => 'ইউজার',       'what' => 'রিড-অনলি — অ্যাকাউন্ট বদলায় না'],
        ['who' => 'কমপ্লায়েন্স', 'what' => 'সিকিউরিটি তদন্তের প্রমাণ এখান থেকে আসে'],
    ],

    'cautions' => [
        'সন্দেহজনক লগইন ধরা পড়লে ইউজারকে ডিঅ্যাক্টিভেট করে পাসওয়ার্ড রিসেট করুন — এখান থেকে সরাসরি কোনো বদল হয় না।',
    ],

    'related' => ['system.users', 'system.users-audit', 'system.audit', 'system.compliance'],

    'updated_at' => '2026-08-07',
];
