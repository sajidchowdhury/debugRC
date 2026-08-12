<?php

/**
 * Help content for: finance.consolidation-intercompany-reconciliation
 * Route: admin.consolidation.reconciliation
 *
 * Intercompany reconciliation sub-page — match intercompany payables of one
 * company against receivables of another before consolidation.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-intercompany-reconciliation',
    'module'     => 'finance',
    'title_bn'   => 'Intercompany Reconciliation',
    'title_en'   => 'Intercompany Reconciliation',
    'icon'       => 'fa-handshake',
    'summary'    => 'এক কোম্পানির পে-রিসিভবল আরেক কোম্পানির সঙ্গে মেলানো — এলিমিনেশনের আগে দরকার।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-handshake',           'text' => 'ইন্টারকোম্পানি পে-রিসিভবল পাশাপাশি মেলানো'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'ম্যাচ না হওয়া লাইন চিহ্নিত করা'],
    ],

    'impacts' => [
        ['who' => 'এলিমিনেশন', 'what' => 'রিকনসাইল না হলে এলিমিনেশন ভুল হবে — কোনো GL বদলায় না'],
    ],

    'cautions' => [
        'ইন্টারকোম্পানি লেনদেন মেলে না গেলে কনসোলিডেশন রান করবেন না — আগে মেলান।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-rules'],

    'updated_at' => '2026-08-07',
];
