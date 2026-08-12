<?php

/**
 * Help content for: finance.shadow-mode-cutover
 * Route: admin.shadow-mode.cutover
 *
 * Shadow Cutover sub-page — flips the system from shadow (parallel-run, no
 * live postings) to live mode. After cutover, new runs post to GL normally.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention), Appendix A.8 (shadow mode)
 */

return [
    'key'        => 'finance.shadow-mode-cutover',
    'module'     => 'finance',
    'title_bn'   => 'Shadow Cutover',
    'title_en'   => 'Shadow Cutover',
    'icon'       => 'fa-right-left',
    'summary'    => 'শ্যাডো থেকে লাইভে কাটওভার — এর পর থেকে সব পোস্টিং GL-এ লাইভ হবে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-right-left',   'text' => 'শ্যাডো → লাইভ কাটওভার শুরু করা'],
        ['icon' => 'fa-circle-check',  'text' => 'আগে সব মিসম্যাচ ক্লিয়ার আছে কিনা নিশ্চিত করা'],
    ],

    'impacts' => [
        ['who' => 'হিসাব (GL)', 'what' => 'কাটওভারের পর থেকে নতুন রান GL-এ লাইভ পোস্ট হয়'],
    ],

    'cautions' => [
        'কাটওভার একবার হলে ফিরে যাওয়া যায় না — সব কম্পারিজন মিলিয়ে নিশ্চিত হয়ে তবেই করুন।',
    ],

    'related' => ['finance.shadow-mode', 'finance.shadow-mode-comparisons'],

    'updated_at' => '2026-08-07',
];
