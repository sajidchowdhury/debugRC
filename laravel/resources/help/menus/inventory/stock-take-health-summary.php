<?php

/**
 * Help content for: inventory.stock-take-health-summary
 * Route: admin.stock-take.health-summary
 *
 * Health-summary sub-page — overall progress + status of all count sessions
 * (pending / counting / posted), with variance highlights.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-take-health-summary',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Take Health Summary',
    'title_en'   => 'Stock Take Health Summary',
    'icon'       => 'fa-heart-pulse',
    'summary'    => 'এটি স্টক টেক সেশনগুলোর অগ্রগতি ও স্বাস্থ্য সারাংশ পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-heart-pulse',        'text' => 'সব কাউন্ট সেশনের অগ্রগতি দেখা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'বড় ভ্যারিয়েন্সের সতর্কতা দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-take',
        'inventory.stock-take-abc-report',
    ],

    'updated_at' => '2026-08-07',
];
