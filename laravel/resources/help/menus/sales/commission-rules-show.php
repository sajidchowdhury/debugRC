<?php

/**
 * Help content for: sales.commission-rules-show
 * Route: admin.commission-rules.show
 *
 * Sub-page of sales.commission-rules — read-only detail of a single rule.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.commission-rules-show',
    'module'     => 'sales',
    'title_bn'   => 'Commission Rule Detail',
    'title_en'   => 'Commission Rule Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'এটি কমিশন রুল-এর বিস্তারিত দেখার পেজ — একটি নির্দিষ্ট রুলের শর্ত ও কার্যকারিতা দেখা যায়।',

    'for_roles'  => ['manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',           'text' => 'রুলের শতাংশ, প্রযোজ্য পণ্য/সেলসম্যান দেখা'],
        ['icon' => 'fa-calendar-days', 'text' => 'কার্যকর তারিখ ও অবস্থা (active/inactive) দেখা'],
    ],

    'impacts' => [
        ['who' => 'রুল',  'what' => 'শুধু দেখার পেজ — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'এটি শুধু দেখার পেজ — রুল বদলাতে হলে কমিশন রুল তালিকা থেকে এডিট করুন।',
    ],

    'related' => ['sales.commission-rules', 'sales.commission-rules-create'],

    'updated_at' => '2026-08-07',
];
