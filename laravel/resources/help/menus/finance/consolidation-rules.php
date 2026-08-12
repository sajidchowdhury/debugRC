<?php

/**
 * Help content for: finance.consolidation-rules
 * Route: admin.consolidation.rules
 *
 * Elimination Rules sub-page — define which intercompany ledger pairs cancel
 * out (e.g. Co. A "IC Receivable from B" vs Co. B "IC Payable to A").
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-rules',
    'module'     => 'finance',
    'title_bn'   => 'Elimination Rules',
    'title_en'   => 'Elimination Rules',
    'icon'       => 'fa-gears',
    'summary'    => 'কোন কোম্পানির কোন লেজার কাকে কাটবে — সেই এলিমিনেশন রুল সেট করা হয় এখানে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-gears',          'text' => 'এলিমিনেশন রুল যোগ/এডিট করা'],
        ['icon' => 'fa-arrow-right-arrow-left', 'text' => 'পে-রিসিভবল লেজার জোড়া ম্যাপ করা'],
    ],

    'impacts' => [
        ['who' => 'কনসোলিডেশন', 'what' => 'রুল বদলালে পরবর্তী রানে এলিমিনেশন নতুন রুল ধরে হবে'],
    ],

    'cautions' => [
        'রুল ব্যাল্যান্সড না হলে কনসোলিডেটেড TB মেলে না — রুল যাচাই করে রান করুন।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-companies'],

    'updated_at' => '2026-08-07',
];
