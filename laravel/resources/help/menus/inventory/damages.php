<?php

/**
 * Help content for: inventory.damages
 * Route: admin.damages.index (and create/show/edit via wildcard)
 *
 * Log damaged / spoiled / expired goods. Each damage entry, when posted,
 * reduces stock immediately and creates a loss entry in the GL. Optional
 * photo attachment is supported for proof.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 */

return [
    'key'        => 'inventory.damages',
    'module'     => 'inventory',
    'title_bn'   => 'ক্ষতি',
    'title_en'   => 'Damage',
    'icon'       => 'fa-triangle-exclamation',
    'summary'    => 'পণ্য পচে/নষ্ট/ভাঙা গেলে এখানে লেখা হয় — পোস্ট করলে স্টক কমে ও ক্ষতি হিসাবে দাখিল হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',                  'text' => 'নতুন ক্ষতি এন্ট্রি — পণ্য, পরিমাণ, কারণ লেখা'],
        ['icon' => 'fa-paperclip',             'text' => 'ছবি বা ডকুমেন্ট অ্যাটাচ করা (প্রমাণ হিসেবে)'],
        ['icon' => 'fa-circle-check',          'text' => 'অ্যাপ্রুভ ও পোস্ট করা — স্টক ও GL আপডেট হয়'],
        ['icon' => 'fa-eye',                   'text' => 'আগের ক্ষতির বিস্তারিত দেখা'],
        ['icon' => 'fa-print',                 'text' => 'ক্ষতির চালান প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক লেজার',  'what' => 'পোস্ট হলে পণ্যের স্টক কমে যায়'],
        ['who' => 'GL',         'what' => 'ক্ষতি (loss) হিসেবে জার্নাল পোস্ট হয়'],
        ['who' => 'ক্ষতি রিপোর্ট', 'what' => 'দাবি রিপোর্টে যোগ হয়'],
        ['who' => 'অডিট',      'what' => 'কে কখন অ্যাপ্রুভ করেছে লগ হয়'],
    ],

    'cautions' => [
        'পোস্ট হয়ে গেলে স্টক ও GL একসাথে কমে — ভুল পরিমাণ দিলে রিভার্স এন্ট্রি দিতে হবে।',
        'কারণ (reason) না লিখলে অ্যাপ্রুভ করা যাবে না — ক্ষতির ধরন স্পষ্ট লিখুন (পচা, ভাঙা, মেয়াদ শেষ)।',
        'ছবি অ্যাটাচ করা থাকলে দাবি পাওয়া সহজ হয় — বীমা/সাপ্লায়ার ক্লেইমের জন্য প্রমাণ দরকার।',
    ],

    'related' => [
        'master-data.products',
        'inventory.stock-transactions',
        'inventory.stock-adjustments',
        'reports.reports-hub-damageReport',
        'accounting.manual-journals',
    ],

    'updated_at' => '2026-08-07',
];
