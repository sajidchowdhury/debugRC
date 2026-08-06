<?php

/**
 * Help content for: finance.shadow-mode-comparison-detail
 * Route: admin.shadow-mode.detail
 *
 * Shadow Comparison Detail sub-page — single comparison run's row-by-row diff
 * between shadow output and live (or baseline) output.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.shadow-mode-comparison-detail',
    'module'     => 'finance',
    'title_bn'   => 'Shadow Comparison Detail',
    'title_en'   => 'Shadow Comparison Detail',
    'icon'       => 'fa-magnifying-glass',
    'summary'    => 'একটি শ্যাডো কম্পারিজনের লাইন বাই লাইন পার্থক্য — কোথায় সংখ্যা মেলেনি।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-magnifying-glass', 'text' => 'শ্যাডো বনাম লাইভ লাইন-বাই-লাইন তুলনা দেখা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'মিসম্যাচ হলে কারণ ট্র্যাক করা'],
    ],

    'impacts' => [
        ['who' => 'বিশ্লেষণ', 'what' => 'শুধু রিড-অনলি — কোনো GL বা স্টক বদলায় না'],
    ],

    'cautions' => [
        'শ্যাডো ডিটেইল পরীক্ষার জন্য — এখান থেকে কাটওভার চালানো যায় না।',
    ],

    'related' => ['finance.shadow-mode', 'finance.shadow-mode-comparisons'],

    'updated_at' => '2026-08-07',
];
