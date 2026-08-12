<?php

/**
 * Help content for: inventory.stock-take-abc-report
 * Route: admin.stock-take.abc-report
 *
 * ABC analysis sub-page for stock take — classifies counted products by
 * variance impact (A = high-value/variance, B = medium, C = low).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-take-abc-report',
    'module'     => 'inventory',
    'title_bn'   => 'ABC Report',
    'title_en'   => 'ABC Report',
    'icon'       => 'fa-arrow-down-a-z',
    'summary'    => 'এটি স্টক টেকের ABC রিপোর্ট পেজ — পণ্যকে ভ্যারিয়েন্স ভিত্তিতে A/B/C শ্রেণিতে ভাগ করে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-arrow-down-a-z', 'text' => 'পণ্যকে A/B/C শ্রেণিতে সাজানো দেখা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'সবচেয়ে বেশি ভ্যারিয়েন্সের পণ্য চিহ্নিত করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'শুধু দেখা যায় — কোনো তথ্য বদলে না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — সরাসরি তথ্য বদলানো যায় না।',
    ],

    'related' => [
        'inventory.stock-take',
        'inventory.stock-take-health-summary',
    ],

    'updated_at' => '2026-08-07',
];
