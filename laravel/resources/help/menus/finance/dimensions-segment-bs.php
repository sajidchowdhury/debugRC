<?php

/**
 * Help content for: finance.dimensions-segment-bs
 * Route: admin.dimensions.segment-bs
 *
 * Segment Balance Sheet sub-page — Balance Sheet sliced by dimension
 * (each segment = one column).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.dimensions-segment-bs',
    'module'     => 'finance',
    'title_bn'   => 'Segment Balance Sheet',
    'title_en'   => 'Segment Balance Sheet',
    'icon'       => 'fa-table-list',
    'summary'    => 'সেগমেন্ট অনুযায়ী ব্যালেন্স শিট — প্রতিটি ব্র্যাঞ্চ/লাইন আলাদা কলামে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-table-list', 'text' => 'সেগমেন্ট-ওয়াইজ ব্যালেন্স শিট দেখা'],
        ['icon' => 'fa-file-export', 'text' => 'রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — কোনো GL বা ট্যাগ বদলায় না'],
    ],

    'cautions' => [
        'সেগমেন্ট ফাঁকা দেখালে ডাইমেনশন ট্যাগ দেওয়া হয়নি — finance.dimensions থেকে ট্যাগ করুন।',
    ],

    'related' => ['finance.dimensions', 'finance.dimensions-segment-pnl'],

    'updated_at' => '2026-08-07',
];
