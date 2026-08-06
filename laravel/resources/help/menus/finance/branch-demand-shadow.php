<?php

/**
 * Help content for: finance.branch-demand-shadow
 * Route: admin.branch-demand-shadow.index
 *
 * Branch Demand Shadow index — parallel-run of branch demand logic in shadow
 * mode (no live postings). Comparisons & cutover hang off this screen.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention), Appendix A.8 (shadow mode)
 */

return [
    'key'        => 'finance.branch-demand-shadow',
    'module'     => 'finance',
    'title_bn'   => 'ব্র্যাঞ্চ ডিমান্ড শ্যাডো',
    'title_en'   => 'Branch Demand Shadow',
    'icon'       => 'fa-user-secret',
    'summary'    => 'ব্র্যাঞ্চ ডিমান্ড লজিক শ্যাডো মোডে চালানো হয় — কোনো লাইভ পোস্ট হয় না, শুধু তুলনা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-table',           'text' => 'শ্যাডো কম্পারিজন তালিকা দেখা'],
        ['icon' => 'fa-right-left',       'text' => 'কাটওভার — শ্যাডোকে লাইভে তোলা'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)', 'what' => 'শ্যাডো মোডে কোনো GL পোস্ট হয় না — কাটওভার পর্যন্ত'],
    ],

    'cautions' => [
        'শ্যাডো = শুধু পরীক্ষা — কাটওভারের আগে কোনো স্টক বরাদ্দ বা চালান লাইভ হয় না।',
    ],

    'related' => [
        'finance.branch-demand',
        'finance.shadow-mode',
        'finance.branch-demand-shadow-comparisons',
    ],

    'updated_at' => '2026-08-07',
];
