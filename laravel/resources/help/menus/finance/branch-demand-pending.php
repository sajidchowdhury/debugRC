<?php

/**
 * Help content for: finance.branch-demand-pending
 * Route: admin.branch-demands.pending
 *
 * "Pending for me" sub-page — lists demands waiting on the current user's
 * approval/allocate action.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.branch-demand-pending',
    'module'     => 'finance',
    'title_bn'   => 'আমার জন্য পেন্ডিং',
    'title_en'   => 'Pending for Me',
    'icon'       => 'fa-hourglass-half',
    'summary'    => 'যে ডিমান্ডগুলো আপনার অনুমোদনের জন্য অপেক্ষা করছে — সেগুলোর তালিকা।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-hourglass-half', 'text' => 'নিজের কাছে পেন্ডিং ডিমান্ড তালিকা দেখা'],
        ['icon' => 'fa-arrow-right',     'text' => 'সরাসরি মূল ডিমান্ড পেজে গিয়ে অনুমোদন/বাতিল করা'],
    ],

    'impacts' => [
        ['who' => 'ওয়ার্কফ্লো', 'what' => 'পেন্ডিং সাফ হলে স্টক বরাদ্দ এগোয় — সরাসরি GL পোস্ট হয় না'],
    ],

    'cautions' => [
        'পেন্ডিং দীর্ঘ সময় রাখলে ব্র্যাঞ্চ চালান আটকে থাকে — দ্রুত অনুমোদন বা বাতিল করুন।',
    ],

    'related' => ['finance.branch-demand', 'finance.branch-demand-audit'],

    'updated_at' => '2026-08-07',
];
