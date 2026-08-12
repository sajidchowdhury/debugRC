<?php

/**
 * Help content for: finance.branch-demand-reconcile
 * Route: admin.branch-demands.reconcile
 *
 * Reconcile sub-page — match dispatched stock (transit) vs confirmed receipts
 * to detect partial / missing receipts on branch demands.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-reconcile',
    'module'     => 'finance',
    'title_bn'   => 'রিকনসিলিয়েশন',
    'title_en'   => 'Reconciliation',
    'icon'       => 'fa-scale-balanced',
    'summary'    => 'ব্র্যাঞ্চ ডিমান্ডে পাঠানো মাল বনাম রিসিভ হওয়া মাল মেলানো — পার্শিয়াল বা মিসিং রিসিট ধরা।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-scale-balanced',       'text' => 'ডিসপ্যাচ বনাম রিসিভ পরিমাণ পাশাপাশি মেলানো'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'পার্শিয়াল বা মিসিং রিসিট চিহ্নিত করা'],
    ],

    'impacts' => [
        ['who' => 'রিকনসাইল', 'what' => 'ট্রানজিট লেজার পরিষ্কার হয় — সরাসরি GL পোস্ট হয় না'],
    ],

    'cautions' => [
        'ভুল ধরা পড়লে রিকনসাইলেশন পেজ থেকে নয় — মূল পেজে গিয়ে রিসিট কনফার্ম বা কারেকশন করুন।',
    ],

    'related' => ['finance.branch-demand', 'finance.branch-demand-pending-receipt'],

    'updated_at' => '2026-08-07',
];
