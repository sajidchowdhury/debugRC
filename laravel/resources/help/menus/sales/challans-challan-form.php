<?php

/**
 * Help content for: sales.challans-challan-form
 * Route: admin.sales-challans.challan-form
 *
 * Sub-page of sales.challans — the challan issue form (create a new challan
 * from an invoice).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.challans-challan-form',
    'module'     => 'sales',
    'title_bn'   => 'Issue Challan Form',
    'title_en'   => 'Issue Challan Form',
    'icon'       => 'fa-file-lines',
    'summary'    => 'এটি চালান-এর ইস্যু ফর্ম পেজ — ইনভয়েস থেকে নতুন চালান এখানে তৈরি করা হয়।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-warehouse',  'text' => 'গোডাউন বাছাই করে চালান তৈরি করা'],
        ['icon' => 'fa-circle-check', 'text' => 'পণ্য ও পরিমাণ যাচাই করে চালান সাবমিট করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক',       'what' => 'গোডাউন থেকে মাল ট্রানজিটে চলে যায়'],
        ['who' => 'ইনভয়েস',   'what' => 'ইনভয়েসের সাথে চালান লিংক হয়'],
    ],

    'cautions' => [
        'চালান সাবমিট করলেই গোডাউন স্টক কমে যায় — ভুল গোডাউন বাছাই করলে স্টক গোলমাল হবে।',
    ],

    'related' => ['sales.challans', 'sales.invoices'],

    'updated_at' => '2026-08-07',
];
