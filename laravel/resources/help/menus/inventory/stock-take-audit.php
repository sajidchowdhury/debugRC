<?php

/**
 * Help content for: inventory.stock-take-audit
 * Route: admin.stock-take.audit
 *
 * Audit-trail sub-page for stock take — tracks who set up, counted,
 * and posted each count session.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-take-audit',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Take Audit',
    'title_en'   => 'Stock Take Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি স্টক টেকের অডিট ট্রেইল পেজ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'সেটআপ/কাউন্ট/পোস্ট প্রতিটি ধাপের লগ দেখা'],
        ['icon' => 'fa-user',       'text' => 'কে কখন কী এন্ট্রি করেছে তা ট্র্যাক করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-take',
        'inventory.stock-take-checklist',
    ],

    'updated_at' => '2026-08-07',
];
