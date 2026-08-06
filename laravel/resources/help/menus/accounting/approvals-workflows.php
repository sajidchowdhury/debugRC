<?php

/**
 * Help content for: accounting.approvals-workflows
 * Route: admin.approvals.workflows
 *
 * Approval Workflows configuration page — define which role/user can approve
 * which type of posting (journal, money-transfer, supplier-payment, etc.),
 * amount thresholds, and multi-step chains.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.approvals-workflows',
    'module'     => 'accounting',
    'title_bn'   => 'অ্যাপ্রুভাল ওয়ার্কফ্লো',
    'title_en'   => 'Approval Workflows',
    'icon'       => 'fa-diagram-project',
    'summary'    => 'এটি approvals-এর কনফিগ পেজ — কে কোন ধরনের পোস্টিং অ্যাপ্রুভ করতে পারবে, অ্যামাউন্ট লিমিট কত, তা সেট করা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-diagram-project', 'text' => 'ওয়ার্কফ্লো নিয়ম তৈরি/এডিট করা (ধরন, অ্যাপ্রুভার, লিমিট)'],
        ['icon' => 'fa-eye',             'text' => 'সজল ওয়ার্কফ্লোর তালিকা দেখা'],
    ],

    'impacts' => [
        ['who' => 'অ্যাপ্রুভাল কিউ', 'what' => 'নতুন পেন্ডিং আইটেম এই নিয়ম ধরে রুট হবে'],
    ],

    'cautions' => [
        'নিয়ম বদলালে নতুন পেন্ডিং আইটেমে কাজ করবে — আগের পেন্ডিং আইটেম পুরোনো নিয়মেই থাকে।',
    ],

    'related' => ['accounting.approvals', 'accounting.manual-journals'],

    'updated_at' => '2026-08-07',
];
