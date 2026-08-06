<?php

/**
 * Help content for: system.partition-health
 * Route: admin.system.partition-health
 *
 * The DB Partition Health monitor — checks whether large tables (audit log,
 * stock transactions, ledgers) are correctly partitioned, whether partitions
 * are drifting in size, and whether queries will hit the right partition.
 * Affects reporting performance.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.partition-health',
    'module'     => 'system',
    'title_bn'   => 'পার্টিশন হেলথ',
    'title_en'   => 'Partition Health',
    'icon'       => 'fa-heart-pulse',
    'summary'    => 'ডেটাবেস পার্টিশন ঠিক আছে কিনা দেখা হয় — অসুস্থ পার্টিশন রিপোর্ট ও কোয়েরি ধীর করে দেয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-heart-pulse',     'text' => 'প্রতিটি পার্টিশনের স্ট্যাটাস ও সাইজ দেখা'],
        ['icon' => 'fa-chart-column',   'text' => 'মাস/কোয়ার্টার ধরে পার্টিশন সাইজ তুলনা করা'],
        ['icon' => 'fa-triangle-exclamation', 'text' => 'ড্রিফট বা অসঙ্গতি শনাক্ত করা'],
        ['icon' => 'fa-database',        'text' => 'কোন টেবিল পার্টিশন করা আছে তার ম্যাপ দেখা'],
        ['icon' => 'fa-wrench',          'text' => 'পার্টিশন মেইনটেন্যান্স অ্যাকশনের সুপারিশ দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট পারফরম্যান্স',  'what' => 'অসুস্থ পার্টিশন থাকলে রিপোর্ট কোয়েরি ধীর হয়'],
        ['who' => 'অডিট লগ',              'what' => 'অডিট টেবিলের পার্টিশন ফুল হলে ইনসার্ট আটকে যেতে পারে'],
        ['who' => 'সিস্টেম হেলথ',         'what' => 'পার্টিশন হেলথ সিস্টেম-হেলথ ড্যাশবোর্ডে রিফ্লেক্ট হয়'],
    ],

    'cautions' => [
        'অসুস্থ পার্টিশন ধরা পড়লে দ্রুত ডিবিএ/ডেভেলপারকে জানান — রিপোর্ট আর অডিট এন্ট্রি আটকে যেতে পারে।',
        'পার্টিশন মেইনটেন্যান্স চলাকালীন বড় রিপোর্ট চালাবেন না — লক পড়ে কোয়েরি হ্যাং করতে পারে।',
    ],

    'related' => ['system.system-health', 'system.audit', 'system.archive'],

    'updated_at' => '2026-08-07',
];
