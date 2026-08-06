<?php

/**
 * Help content for: sales.challans-blank-godown-form
 * Route: admin.sales-challans.blank-godown-form
 *
 * Sub-page of sales.challans — the blank godown delivery form template.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.challans-blank-godown-form',
    'module'     => 'sales',
    'title_bn'   => 'Blank Godown Form',
    'title_en'   => 'Blank Godown Form',
    'icon'       => 'fa-file-lines',
    'summary'    => 'এটি চালান-এর ব্লাঙ্ক গোডাউন ফর্ম পেজ — খালি ফরম্যাট প্রিন্ট করে গোডাউনে হাতে লেখার জন্য ব্যবহার করা হয়।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',  'text' => 'ব্লাঙ্ক গোডাউন ফর্ম প্রিন্ট করা'],
        ['icon' => 'fa-eye',    'text' => 'ফর্মের ফরম্যাট পর্যালোচনা করা'],
    ],

    'impacts' => [
        ['who' => 'গোডাউন',  'what' => 'শুধু ফর্ম প্রিন্ট — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'এটি শুধু খালি ফরম্যাট — এখানে তথ্য লিখলে সিস্টেমে সেভ হয় না। আসল চালান চালান-ফর্ম পেজ থেকে তৈরি করুন।',
    ],

    'related' => ['sales.challans', 'sales.challans-challan-form'],

    'updated_at' => '2026-08-07',
];
