<?php

/**
 * Help content for: inventory.branch-demand (LEGACY ALIAS)
 * Route: admin.branch-demands.index (same page as finance.branch-demand)
 *
 * This is the legacy sidebar entry for the Branch Demand page — same screen
 * as finance.branch-demand, kept under the Inventory menu for backward
 * compatibility with older sidebar layouts. The canonical content lives at
 * finance.branch-demand; this alias simply points there.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §7.4 (alias convention), Appendix A.8
 */

return [
    'key'        => 'inventory.branch-demand',
    'module'     => 'inventory',
    'title_bn'   => 'ব্র্যাঞ্চ ডিমান্ড',
    'title_en'   => 'Branch Demand',
    'icon'       => 'fa-clipboard-question',
    'summary'    => 'এটি ব্র্যাঞ্চ ডিমান্ড পেজের ইনভেন্টরি ভিউ (পুরোনো মেনু)। বিস্তারিত দেখুন ফাইন্যান্স মডিউলে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-arrow-right-arrow-left', 'text' => 'একই ব্র্যাঞ্চ ডিমান্ড পেজ খোলে — ফাইন্যান্স মডিউলের'],
        ['icon' => 'fa-clipboard-question',     'text' => 'পুরোনো সাইডবার থেকে এখানে আসা ইউজারদের জন্য শর্টকাট'],
    ],

    'impacts' => [
        ['who' => 'নেভিগেশন', 'what' => 'একই পেজ — কোনো আলাদা ডেটা বা GL পোস্ট হয় না'],
    ],

    'cautions' => [
        'এটি পুরোনো মেনু এন্ট্রি — আসল কন্টেন্ট finance.branch-demand-এ। নতুন ইউজাররা সরাসরি ফাইন্যান্স মডিউল ব্যবহার করুন।',
    ],

    'related' => ['finance.branch-demand', 'finance.branch-demand-pending'],

    // No diagram — alias card; the canonical diagram (if any) lives on finance.branch-demand.

    'updated_at' => '2026-08-07',
];
