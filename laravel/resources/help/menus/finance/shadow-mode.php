<?php

/**
 * Help content for: finance.shadow-mode
 * Route: admin.shadow-mode.index (and comparisons/detail/cutover sub-routes)
 *
 * The Shadow Mode page — parallel-run mode where the new system shadows the old
 * (or new logic shadows old) without making live postings. Comparisons identify
 * discrepancies; once matched, a cutover flips shadow → live.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (primary card), Appendix A.8 (shadow mode)
 */

return [
    'key'        => 'finance.shadow-mode',
    'module'     => 'finance',
    'title_bn'   => 'শ্যাডো মোড',
    'title_en'   => 'Shadow Mode',
    'icon'       => 'fa-user-secret',
    'summary'    => 'নতুন লজিক প্যারালালে চলে দেখা হয় — কাটওভারের আগে পর্যন্ত কোনো লাইভ পোস্টিং হয় না।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-table',            'text' => 'শ্যাডো কম্পারিজন তালিকা দেখা — প্রতিটি রান'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'কম্পারিজন ডিটেইলে ড্রিল করে পার্থক্য খুঁজা'],
        ['icon' => 'fa-right-left',       'text' => 'কাটওভার — শ্যাডোকে লাইভে তুলে আনা'],
        ['icon' => 'fa-eye',              'text' => 'শ্যাডো রানের ইতিহাস দেখা'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)', 'what' => 'শ্যাডো মোডে থাকলে কোনো লাইভ জার্নাল পোস্ট হয় না'],
        ['who' => 'কাটওভার',    'what' => 'কাটওভার হলে পরবর্তী রান থেকে পোস্টিং লাইভ হয়ে যায়'],
    ],

    'cautions' => [
        'শ্যাডো মোড শুধু পরীক্ষার জন্য — এখানকার সংখ্যা GL-এ যায় না।',
        'কাটওভার একবার করলে ফিরে যাওয়া যায় না — সব কম্পারিজন মিলিয়ে নিশ্চিত হয়ে তবেই করুন।',
    ],

    'related' => [
        'finance.shadow-mode-comparisons',
        'finance.shadow-mode-comparison-detail',
        'finance.shadow-mode-cutover',
        'finance.branch-demand-shadow',
        'finance.branch-demand',
    ],

    // No diagram — shadow mode is a comparison/cutover concept, not a cycle.

    'updated_at' => '2026-08-07',
];
