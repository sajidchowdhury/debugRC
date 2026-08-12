<?php

/**
 * Help content for: inventory.stock-take-count
 * Route: admin.stock-take.count
 *
 * Count-entry sub-page — where the counter enters the actual physical
 * quantity for each product in a count session + warehouse.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.4 (sub-page)
 */

return [
    'key'        => 'inventory.stock-take-count',
    'module'     => 'inventory',
    'title_bn'   => 'Stock Take Count',
    'title_en'   => 'Stock Take Count',
    'icon'       => 'fa-calculator',
    'summary'    => 'এখানে হাতে গুনে যা পাওয়া গেছে তা প্রতি পণ্যের ঘরে লেখা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-calculator', 'text' => 'প্রতিটি পণ্যের ফিজিক্যাল পরিমাণ এন্ট্রি করা'],
        ['icon' => 'fa-note',       'text' => 'মন্তব্য বা গোনার নোট যোগ করা'],
    ],

    'impacts' => [
        ['who' => 'ভ্যারিয়েন্স',  'what' => 'বই vs ফিজিক্যাল পার্থক্য গণনা হয়'],
    ],

    'cautions' => [
        'এন্ট্রি করার পরও পোস্ট না করলে স্টক বদলাবে না — পোস্ট করতে মূল স্টক টেক পেজে যান।',
        'একবার পোস্ট হলে কাউন্ট এডিট করা যায় না — ভুল হলে নতুন সেশন খুলতে হবে।',
    ],

    'related' => [
        'inventory.stock-take',
        'inventory.stock-take-setup',
    ],

    'updated_at' => '2026-08-07',
];
