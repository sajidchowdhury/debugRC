<?php

/**
 * Help content for: master-data.branches
 * Route: admin.branches.index (and create/show/edit via wildcard)
 *
 * The Branch master page — every company branch/outlet is registered here
 * with a name, code, and address. Stock, users, and most reports can be
 * filtered by branch.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.branches',
    'module'     => 'master-data',
    'title_bn'   => 'ব্র্যাঞ্চ',
    'title_en'   => 'Branch',
    'icon'       => 'fa-code-branch',
    'summary'    => 'কোম্পানির সব শাখা এখানে রেজিস্টার করুন — নাম, কোড, ঠিকানা। স্টক, ইউজার ও রিপোর্ট ব্র্যাঞ্চ ধরে ভাগ হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন ব্র্যাঞ্চ যোগ করা (নাম, কোড, ঠিকানা)'],
        ['icon' => 'fa-pen-to-square',     'text' => 'ব্র্যাঞ্চের তথ্য এডিট করা'],
        ['icon' => 'fa-toggle-on',         'text' => 'ব্র্যাঞ্চ অ্যাক্টিভ/ইনঅ্যাক্টিভ করা'],
        ['icon' => 'fa-users',             'text' => 'ব্র্যাঞ্চের ইউজার ও স্টক দেখা'],
        ['icon' => 'fa-print',             'text' => 'ব্র্যাঞ্চ ডিরেক্টরি প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'ব্র্যাঞ্চ',     'what' => 'তালিকায় যোগ/আপডেট হয়'],
        ['who' => 'স্টক',          'what' => 'গোডাউন ও স্টক ব্র্যাঞ্চ ধরে ভাগ হয়'],
        ['who' => 'ইউজার',        'what' => 'ইউজার ব্র্যাঞ্চে বাঁধা পড়ে'],
        ['who' => 'রিপোর্ট',      'what' => 'ব্র্যাঞ্চ-ওয়াইজ রিপোর্ট এখানে ধরে চলে'],
    ],

    'cautions' => [
        'ব্র্যাঞ্চ ইনঅ্যাক্টিভ করলে তার ডেটা মুছে যায় না, কিন্তু ড্রপডাউন থেকে সরে যায়।',
        'একবার ট্রানজেকশন হওয়ার পর ব্র্যাঞ্চ কোড বদলালে পুরোনো এন্ট্রি গোলমাল হতে পারে।',
    ],

    'related' => ['master-data.warehouses', 'system.users', 'master-data.branches-audit', 'master-data.branches-print'],

    // No diagram — a flat list of branch records.

    'updated_at' => '2026-08-07',
];
