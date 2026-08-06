<?php

/**
 * Help content for: sales.invoices-print-blank-godown
 * Route: admin.sales-invoices.print-blank-godown
 *
 * Sub-page of sales.invoices — print a blank godown copy template for an
 * invoice (for godown staff to fill by hand if needed).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.invoices-print-blank-godown',
    'module'     => 'sales',
    'title_bn'   => 'Print Blank Godown Copy',
    'title_en'   => 'Print Blank Godown Copy',
    'icon'       => 'fa-print',
    'summary'    => 'এটি সেলস ইনভয়েস-এর ব্লাঙ্ক গোডাউন কপি প্রিন্ট পেজ — খালি গোডাউন কপি হাতে লেখার জন্য প্রিন্ট করা হয়।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'ব্লাঙ্ক গোডাউন কপি প্রিন্ট করা'],
        ['icon' => 'fa-eye',    'text' => 'কপির ফরম্যাট পর্যালোচনা করা'],
    ],

    'impacts' => [
        ['who' => 'গোডাউন',  'what' => 'শুধু প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'এটি খালি ফরম্যাট — এখানে তথ্য লিখলে সিস্টেমে সেভ হয় না। আসল চালান চালান-ফর্ম থেকে তৈরি করুন।',
    ],

    'related' => ['sales.invoices', 'sales.challans-blank-godown-form'],

    'updated_at' => '2026-08-07',
];
