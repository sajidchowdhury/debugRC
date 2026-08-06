<?php

/**
 * Help content for: sales.challans-godown
 * Route: admin.sales-challans.godown
 *
 * Sub-page of sales.challans — the godown prep view for an invoice (which
 * items to pull from which godown before issuing the challan).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.challans-godown',
    'module'     => 'sales',
    'title_bn'   => 'Godown Prep',
    'title_en'   => 'Godown Prep',
    'icon'       => 'fa-warehouse',
    'summary'    => 'এটি চালান-এর গোডাউন প্রিপ পেজ — চালানের আগে কোন পণ্য কোন গোডাউন থেকে উঠবে তা দেখা যায়।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-warehouse',  'text' => 'প্রতিটি পণ্যের গোডাউন ও স্টক দেখা'],
        ['icon' => 'fa-arrow-right', 'text' => 'গোডাউন বাছাই করে চালান ফর্মে যাওয়া'],
    ],

    'impacts' => [
        ['who' => 'চালান',  'what' => 'প্রিপ সম্পন্ন হলে চালান ইস্যুর জন্য প্রস্তুত হয়'],
    ],

    'cautions' => [
        'স্টক পর্যাপ্ত না থাকলে চালান ইস্যু হবে না — আগে ইনভেন্টরি মডিউলে স্টক যাচাই করুন।',
    ],

    'related' => ['sales.challans', 'inventory.stock-transactions'],

    'updated_at' => '2026-08-07',
];
