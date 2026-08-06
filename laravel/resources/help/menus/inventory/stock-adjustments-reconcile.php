<?php

/**
 * Help content for: inventory.stock-adjustments-reconcile
 * Route: admin.stock-adjustments.reconcile
 *
 * Reconcile sub-page — match the book stock vs physical (count) stock
 * before posting an adjustment, to confirm the correction is justified.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-adjustments-reconcile',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Adjustment Reconcile',
    'title_en'   => 'Stock Adjustment Reconcile',
    'icon'       => 'fa-scale-balanced',
    'summary'    => 'এটি স্টক অ্যাডজাস্টমেন্টের রিকনসাইল পেজ — বই vs হাতের স্টক মেলানো।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-scale-balanced',      'text' => 'বই ও হাতের স্টকের গরমিল চিহ্নিত করা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'বড় গরমিলের উপর অ্যাডজাস্টমেন্ট দেখানো'],
    ],

    'impacts' => [
        ['who' => 'রিকনসাইল',  'what' => 'পোস্ট পূর্ববর্তী মিল নিশ্চিত হয় — সরাসরি কোনো পোস্ট হয় না'],
    ],

    'cautions' => [
        'শুধু দেখার/মেলানোর জন্য — কারেকশন দরকার হলে মূল অ্যাডজাস্টমেন্ট পেজে যান।',
    ],

    'related' => [
        'inventory.stock-adjustments',
        'inventory.stock-adjustments-audit',
    ],

    'updated_at' => '2026-08-07',
];
