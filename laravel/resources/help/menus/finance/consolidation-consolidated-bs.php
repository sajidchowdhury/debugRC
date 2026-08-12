<?php

/**
 * Help content for: finance.consolidation-consolidated-bs
 * Route: admin.consolidation.consolidated-bs
 *
 * Consolidated Balance Sheet sub-page — group-level BS after eliminations.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-consolidated-bs',
    'module'     => 'finance',
    'title_bn'   => 'Consolidated Balance Sheet',
    'title_en'   => 'Consolidated Balance Sheet',
    'icon'       => 'fa-table-list',
    'summary'    => 'এলিমিনেশনের পর গ্রুপের সম্পূর্ণ ব্যালেন্স শিট — সব কোম্পানি/ব্র্যাঞ্চ এক ভিউতে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-table-list', 'text' => 'কনসোলিডেটেড ব্যালেন্স শিট দেখা'],
        ['icon' => 'fa-file-export', 'text' => 'রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — কোনো GL বা এলিমিনেশন বদলায় না'],
    ],

    'cautions' => [
        'রিপোর্ট ভুল দেখালে মূল কনসোলিডেশন রান আবার চালান — এখান থেকে রান হয় না।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-consolidated-tb'],

    'updated_at' => '2026-08-07',
];
