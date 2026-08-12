<?php

/**
 * Help content for: finance.consolidation-consolidated-pnl
 * Route: admin.consolidation.consolidated-pnl
 *
 * Consolidated P&L sub-page — group-level profit & loss after eliminations.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-consolidated-pnl',
    'module'     => 'finance',
    'title_bn'   => 'Consolidated P&L',
    'title_en'   => 'Consolidated P&L',
    'icon'       => 'fa-chart-bar',
    'summary'    => 'এলিমিনেশনের পর গ্রুপের প্রফিট-লস রিপোর্ট — আয় ও খরচ সব কোম্পানি একত্র।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-chart-bar',  'text' => 'কনসোলিডেটেড প্রফিট-লস দেখা'],
        ['icon' => 'fa-file-export', 'text' => 'রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — কোনো GL বা এলিমিনেশন বদলায় না'],
    ],

    'cautions' => [
        'ইন্টারকোম্পানি রিকনসাইল না হলে পিএল ভুল দেখাবে — আগে রিকনসাইল পেজ দেখুন।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-intercompany-reconciliation'],

    'updated_at' => '2026-08-07',
];
