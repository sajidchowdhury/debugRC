<?php

/**
 * Help content for: finance.consolidation-consolidated-tb
 * Route: admin.consolidation.consolidated-tb
 *
 * Consolidated Trial Balance sub-page — group TB after eliminations, used to
 * verify the consolidation is balanced before producing BS/PnL.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-consolidated-tb',
    'module'     => 'finance',
    'title_bn'   => 'Consolidated Trial Balance',
    'title_en'   => 'Consolidated Trial Balance',
    'icon'       => 'fa-scale-balanced',
    'summary'    => 'এলিমিনেশনের পর গ্রুপ ট্রায়াল ব্যাল্যান্স — ডেবিট-ক্রেডিট মেলে কিনা যাচাই।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-scale-balanced', 'text' => 'কনসোলিডেটেড ট্রায়াল ব্যাল্যান্স দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'TB রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — কোনো GL বা এলিমিনেশন বদলায় না'],
    ],

    'cautions' => [
        'TB মেলে না গেলে এলিমিনেশন রুল যাচাই করুন — রুল না মেলালে BS/PnL ভুল হবে।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-rules'],

    'updated_at' => '2026-08-07',
];
