<?php

/**
 * Help content for: master-data.products-print
 * Route: admin.products.print
 *
 * Printable directory view of all products — for hardcopy or PDF export of the
 * current product list (typically a price list).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.products-print',
    'module'     => 'master-data',
    'title_bn'   => 'প্রোডাক্ট ডিরেক্টরি প্রিন্ট',
    'title_en'   => 'Product Directory Print',
    'icon'       => 'fa-print',
    'summary'    => 'প্রোডাক্ট ডিরেক্টরি প্রিন্ট করার ভিউ। বর্তমান তালিকা প্রিন্ট বা পিডিএফ হিসেবে নেওয়া যায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'salesman', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',        'text' => 'প্রাইস লিস্ট প্রিন্ট বা পিডিএফ ডাউনলোড করা'],
        ['icon' => 'fa-filter',        'text' => 'ক্যাটাগরি বা গ্রুপ ধরে প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট',  'what' => 'বর্তমান তালিকা থেকে প্রিন্ট তৈরি হয়'],
    ],

    'cautions' => [
        'প্রিন্টে যা দেখায়, তা বর্তমান ডেটা থেকে — লাইভ নয়, পরে দাম বদলালে পুরোনো প্রিন্ট বদলায় না।',
    ],

    'related' => ['master-data.products', 'master-data.products-audit'],

    'updated_at' => '2026-08-07',
];
