<?php

/**
 * Help content for: accounting.approvals
 * Route: admin.approvals.queue (and workflows via wildcard)
 *
 * The Approval Queue — the gate through which sensitive postings (journals,
 * money transfers, supplier payments) pass before they hit the GL. Approvers
 * see pending items, approve or reject, and the workflow rules are
 * configurable.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'accounting.approvals',
    'module'     => 'accounting',
    'title_bn'   => 'অ্যাপ্রুভাল কিউ',
    'title_en'   => 'Approval Queue',
    'icon'       => 'fa-circle-check',
    'summary'    => 'সংবেদনশীল পোস্টিং যেমন জার্নাল, ট্রান্সফার, পেমেন্ট — এগুলো অ্যাপ্রুভাল পাওয়ার পরেই GL-এ পোস্ট হয়; এই পেজে পেন্ডিং আইটেম দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',           'text' => 'পেন্ডিং অ্যাপ্রুভালের তালিকা দেখা (জার্নাল/ট্রান্সফার/পেমেন্ট ধরে)'],
        ['icon' => 'fa-circle-check',  'text' => 'পেন্ডিং আইটেম অ্যাপ্রুভ করা — তাহলে GL-এ পোস্ট হবে'],
        ['icon' => 'fa-circle-xmark',   'text' => 'পেন্ডিং আইটেম রিজেক্ট করা — তাহলে ড্রাফট হিসেবে থাকবে'],
        ['icon' => 'fa-diagram-project', 'text' => 'অ্যাপ্রুভাল ওয়ার্কফ্লো কনফিগার করা (কে কোন ধরনের এন্ট্রি অ্যাপ্রুভ করতে পারবে)'],
        ['icon' => 'fa-list-check',    'text' => 'অডিট লগ দেখা — কে কখন কী অ্যাপ্রুভ/রিজেক্ট করেছে'],
    ],

    'impacts' => [
        ['who' => 'GL পোস্টিং',  'what' => 'অ্যাপ্রুভাল না পেলে পোস্ট হয় না — গেটেড'],
        ['who' => 'ড্রাফট এন্ট্রি', 'what' => 'রিজেক্ট হলে ড্রাফট হিসেবে থাকে, পরে এডিট করে আবার জমা দেওয়া যায়'],
        ['who' => 'অডিট',       'what' => 'প্রতিটা অ্যাপ্রুভ/রিজেক্ট অডিট লগে লেখা থাকে'],
    ],

    'cautions' => [
        'রিজেক্ট করা পোস্টিং মুছে যায় না — ড্রাফট হিসেবে থাকে, সংশোধন করে আবার জমা দিতে হয়।',
        'ওয়ার্কফ্লো নিয়ম বদলালে নতুন পেন্ডিং আইটেমে কাজ করবে — আগের পেন্ডিং আইটেম পুরোনো নিয়মেই থাকে।',
    ],

    'related' => ['accounting.approvals-workflows', 'accounting.manual-journals', 'accounting.money-transfers', 'accounting.supplier-transactions', 'master-data.ledgers'],

    'updated_at' => '2026-08-07',
];
