<?php

/**
 * Help content for: finance.branch-demand-audit
 * Route: admin.branch-demands.audit
 *
 * Audit trail sub-page for Branch Demand — read-only history of who changed
 * a demand, when, and what (approve/reject/allocate status changes etc.).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-audit',
    'module'     => 'finance',
    'title_bn'   => 'Branch Demand Audit',
    'title_en'   => 'Branch Demand Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'ব্র্যাঞ্চ ডিমান্ডের অডিট ট্রেইল — কে কখন কোন ডিমান্ড অনুমোদন/বাতিল করেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',  'text' => 'ডিমান্ড অনুমোদন/বাতিল/বরাদ্দের ইতিহাস দেখা'],
        ['icon' => 'fa-filter',       'text' => 'তারিখ, ইউজার, বা ব্র্যাঞ্চ দিয়ে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ', 'what' => 'শুধু রিড-অনলি — মূল ডিমান্ড বা স্টক বদলায় না'],
    ],

    'cautions' => [
        'এখান থেকে সরাসরি কোনো ডিমান্ড বদলানো যায় না — শুধু ইতিহাস দেখা যায়।',
    ],

    'related' => ['finance.branch-demand', 'finance.branch-demand-checklist'],

    'updated_at' => '2026-08-07',
];
