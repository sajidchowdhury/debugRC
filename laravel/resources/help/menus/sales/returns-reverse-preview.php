<?php

/**
 * Help content for: sales.returns-reverse-preview
 * Route: admin.sales-returns.reverse-preview
 *
 * Sub-page of sales.returns — non-posting preview of what posting this
 * return will reverse (stock↑, receivable↓, commission reversal, GL).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.returns-reverse-preview',
    'module'     => 'sales',
    'title_bn'   => 'Return Reverse Preview',
    'title_en'   => 'Return Reverse Preview',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি সেলস রিটার্ন-এর রিভার্স প্রিভিউ পেজ — রিটার্ন পোস্ট করলে কোন কোন হিসাব উল্টে যাবে তা আগে দেখা যায়।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',           'text' => 'পোস্ট করলে স্টক/বকেয়া/কমিশন কীভাবে বদলাবে দেখা'],
        ['icon' => 'fa-list-check',    'text' => 'প্রিভিউতে জার্নাল এন্ট্রি ম্যাচ যাচাই করা'],
    ],

    'impacts' => [
        ['who' => 'রিটার্ন',  'what' => 'প্রিভিউ নন-পোস্টিং — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'রিভার্স প্রিভিউ নন-পোস্টিং — এখান থেকে কোনো হিসাব বদলে না। পোস্ট করতে হলে মূল রিটার্ন পেজে যান।',
    ],

    'related' => ['sales.returns', 'sales.invoices'],

    'updated_at' => '2026-08-07',
];
