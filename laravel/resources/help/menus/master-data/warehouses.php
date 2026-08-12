<?php

/**
 * Help content for: master-data.warehouses
 * Route: admin.warehouses.index (and create/show/edit via wildcard)
 *
 * The Warehouse (godown) master — every physical storage location is
 * registered here with a name, address, and parent branch. Stock ledger and
 * warehouse transfers reference this list.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.warehouses',
    'module'     => 'master-data',
    'title_bn'   => 'গুদাম',
    'title_en'   => 'Warehouse',
    'icon'       => 'fa-warehouse',
    'summary'    => 'মাল রাখার গোডাউন বা গুদাম এখানে রেজিস্টার করুন — নাম, লোকেশন, ব্র্যাঞ্চ। স্টক আর ট্রান্সফার এই তালিকা ধরে চলে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন গোডাউন যোগ করা (নাম, লোকেশন, ব্র্যাঞ্চ)'],
        ['icon' => 'fa-pen-to-square',     'text' => 'গোডাউনের তথ্য এডিট করা'],
        ['icon' => 'fa-link',              'text' => 'গোডাউনকে ব্র্যাঞ্চে লিঙ্ক করা'],
        ['icon' => 'fa-toggle-on',         'text' => 'গোডাউন অ্যাক্টিভ/ইনঅ্যাক্টিভ করা'],
        ['icon' => 'fa-boxes-stacked',     'text' => 'গোডাউনের বর্তমান স্টক দেখা'],
        ['icon' => 'fa-print',             'text' => 'গোডাউন ডিরেক্টরি প্রিন্ট করা'],
    ],

    'impacts' => [
        ['who' => 'গোডাউন',      'what' => 'তালিকায় যোগ/আপডেট হয়'],
        ['who' => 'স্টক',        'what' => 'স্টক লেজার গোডাউন ধরে ভাগ হয়'],
        ['who' => 'ট্রান্সফার',   'what' => 'গোডাউন ট্রান্সফার এই তালিকা থেকে সোর্স-ডেস্টিনেশন বাছাই হয়'],
    ],

    'cautions' => [
        'স্টক থাকা গোডাউন ইনঅ্যাক্টিভ করলে ট্রান্সফার আটকে যায় — আগে স্টক সরিয়ে নিন।',
        'একবার ট্রানজেকশন হওয়ার পর গোডাউনের ব্র্যাঞ্চ বদলালে পুরোনো স্টক গোলমাল হতে পারে।',
    ],

    'related' => ['master-data.branches', 'master-data.products', 'inventory.stock-transactions', 'inventory.warehouse-transfers', 'master-data.warehouses-audit'],

    // No diagram — a flat list of warehouse records.

    'updated_at' => '2026-08-07',
];
