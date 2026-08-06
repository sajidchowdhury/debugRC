<?php

/**
 * Help content for: sales.challans
 * Route: admin.sales-challans.index (and create/show/edit via wildcard)
 *
 * The Sales Challan page — the godown delivery note. When goods leave the
 * warehouse for delivery to a customer, a challan is generated from the
 * invoice. The challan is NOT a new sale — it just moves stock out of the
 * godown into transit, then to delivered.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.challans',
    'module'     => 'sales',
    'title_bn'   => 'চালান',
    'title_en'   => 'Challan',
    'icon'       => 'fa-truck',
    'summary'    => 'গোডাউন থেকে মাল বের হলে চালান তৈরি হয় — এটি নতুন বিক্রি নয়, শুধু ডেলিভারির দলিল।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-file-lines',          'text' => 'ইনভয়েস থেকে চালান তৈরি করা (issue challan)'],
        ['icon' => 'fa-warehouse',           'text' => 'কোন গোডাউন থেকে মাল উঠবে — তা বাছাই'],
        ['icon' => 'fa-print',                'text' => 'চালান কপি প্রিন্ট করা (গাড়িতে যাবে)'],
        ['icon' => 'fa-file-lines',           'text' => 'ব্লাঙ্ক গোডাউন ফর্ম তৈরি করা'],
        ['icon' => 'fa-circle-check',         'text' => 'ডেলিভারি হলে চালান ক্লোজ করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক',         'what' => 'গোডাউন থেকে মাল বের হয়ে ট্রানজিটে যায়'],
        ['who' => 'ইনভয়েস',     'what' => 'ইনভয়েসের স্টক কম হিসাব চালানে কনফার্ম হয়'],
        ['who' => 'অডিট',        'what' => 'প্রতিটি চালান অডিট ট্রেইলে লেখা পড়ে'],
    ],

    'cautions' => [
        'চালান নতুন বিক্রি নয় — ইনভয়েস ছাড়া চালান দেওয়া যায় না।',
        'চালান তৈরি হলেই গোডাউন স্টক কমে যায় — ডেলিভারি না হলেও স্টক আটকে থাকে।',
    ],

    'related' => ['sales.invoices', 'sales.cart', 'master-data.warehouses', 'inventory.stock-transactions'],

    // No diagram — the sales-invoice-flow diagram on sales.invoices already
    // pictures cart -> invoice -> challan -> delivery -> payment.

    'updated_at' => '2026-08-07',
];
