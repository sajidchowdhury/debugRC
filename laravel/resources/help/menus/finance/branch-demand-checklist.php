<?php

/**
 * Help content for: finance.branch-demand-checklist
 * Route: admin.branch-demands.checklist
 *
 * Checklist sub-page for Branch Demand — the auditor's verification checklist
 * (items that must be ticked before approving a demand run).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-checklist',
    'module'     => 'finance',
    'title_bn'   => 'অডিট চেকলিস্ট',
    'title_en'   => 'Audit Checklist',
    'icon'       => 'fa-list-check',
    'summary'    => 'ব্র্যাঞ্চ ডিমান্ড অনুমোদনের আগে যাচাইয়ের চেকলিস্ট — কোন কোন ধাপ মিলেছে তা দেখা।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check', 'text' => 'প্রতিটি ডিমান্ড রানের চেকলিস্ট আইটেম দেখা'],
        ['icon' => 'fa-check-double', 'text' => 'কোন ধাপ কমপ্লিট, কোনটা পেন্ডিং তা নিশ্চিত করা'],
    ],

    'impacts' => [
        ['who' => 'প্রক্রিয়া', 'what' => 'চেকলিস্ট মেলানো অনুমোদনের পূর্বশর্ত — সরাসরি GL বদলায় না'],
    ],

    'cautions' => [
        'চেকলিস্ট পূরণ না হলে অনুমোদন এগোতে পারে না — সব আইটেম আগে টিক দিন।',
    ],

    'related' => ['finance.branch-demand', 'finance.branch-demand-audit'],

    'updated_at' => '2026-08-07',
];
