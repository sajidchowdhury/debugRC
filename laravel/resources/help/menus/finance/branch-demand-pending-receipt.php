<?php

/**
 * Help content for: finance.branch-demand-pending-receipt
 * Route: admin.branch-demands.pending-receipt
 *
 * Pending receipt confirmation sub-page — demands whose stock was dispatched
 * but branch receipt not yet confirmed.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-pending-receipt',
    'module'     => 'finance',
    'title_bn'   => 'রিসিট কনফার্মেশন',
    'title_en'   => 'Receipt Confirmations',
    'icon'       => 'fa-file-invoice',
    'summary'    => 'যে চালান ব্র্যাঞ্চে গেছে কিন্তু রিসিট কনফার্ম হয়নি — সেগুলো এখানে দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-file-invoice',  'text' => 'পেন্ডিং রিসিট তালিকা দেখা'],
        ['icon' => 'fa-circle-check',   'text' => 'ব্র্যাঞ্চ থেকে রিসিভ হলে কনফার্ম করা'],
    ],

    'impacts' => [
        ['who' => 'চালান', 'what' => "রিসিট কনফার্ম হলে চালানের স্ট্যাটাস 'ট্রানজিট' থেকে 'রিসিভড' হয়"],
    ],

    'cautions' => [
        'রিসিট কনফার্ম না করলে চালান রিকনসাইলে আটকে থাকে — সময়মতো কনফার্ম করুন।',
    ],

    'related' => ['finance.branch-demand', 'finance.branch-demand-reconcile'],

    'updated_at' => '2026-08-07',
];
