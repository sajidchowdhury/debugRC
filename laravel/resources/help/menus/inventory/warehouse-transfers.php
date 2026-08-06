<?php

/**
 * Help content for: inventory.warehouse-transfers
 * Route: admin.warehouse-transfers.index (and create/show/edit via wildcard)
 *
 * Move stock between godowns (warehouses). Each transfer goes through:
 * create → transit → receive-confirm. Until receive-confirm, stock stays
 * "in transit" and is not yet available at the destination godown.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'inventory.warehouse-transfers',
    'module'     => 'inventory',
    'title_bn'   => 'ওয়্যারহাউস ট্রান্সফার',
    'title_en'   => 'Warehouse Transfer',
    'icon'       => 'fa-arrow-right-arrow-left',
    'summary'    => 'এক গোডাউন থেকে আরেক গোডাউনে মালামাল পাঠানোর চালান এখানে তৈরি ও রিসিভ করানো হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',                'text' => 'নতুন ট্রান্সফার তৈরি — সোর্স ও ডেস্টিনেশন গোডাউন বাছাই'],
        ['icon' => 'fa-box',                 'text' => 'পণ্য ও পরিমাণ যোগ করা (একাধিক পণ্য একসাথে)'],
        ['icon' => 'fa-truck',               'text' => 'ট্রানজিটে পাঠানো — সোর্স গোডাউন থেকে স্টক কমে'],
        ['icon' => 'fa-circle-check',        'text' => 'ডেস্টিনেশনে রিসিভ কনফার্ম — স্টক বাড়ে'],
        ['icon' => 'fa-print',               'text' => 'ট্রান্সফার চালান প্রিন্ট করা'],
        ['icon' => 'fa-list-check',          'text' => 'অডিট ট্রেইল ও চেকলিস্ট দেখা'],
    ],

    'impacts' => [
        ['who' => 'সোর্স গোডাউন',  'what' => 'ট্রানজিটে পাঠালে স্টক কমে যায়'],
        ['who' => 'ডেস্টিনেশন গোডাউন', 'what' => 'রিসিভ কনফার্ম হলে স্টক বাড়ে'],
        ['who' => 'ট্রানজিট লেজার',  'what' => 'পথে থাকা মাল আলাদা হিসাবে দেখায়'],
        ['who' => 'স্টক লেজার',     'what' => 'উভয় গোডাউনের মুভমেন্ট লেখা পড়ে'],
        ['who' => 'অডিট',         'what' => 'প্রতিটি ধাপ লগ হয় — কে কখন রিসিভ করেছে'],
    ],

    'cautions' => [
        'রিসিভ কনফার্ম না করলে মাল "ট্রানজিটে" আটকে থাকে — ডেস্টিনেশন গোডাউনে স্টক বাড়বে না।',
        'পার্শিয়াল রিসিভ সাপোর্টেড — কম মাল এলে যতটুকু এসেছে ততটুকু কনফার্ম করুন, বাকি অংশ ট্রানজিটেই থাকে।',
        'ভুল গোডাউন বাছাই করলে পুরো চালান ফিরিয়ে আনতে হবে — তাই সোর্স/ডেস্টিনেশন দুবার চেক করুন।',
    ],

    'related' => [
        'master-data.warehouses',
        'master-data.products',
        'inventory.stock-transactions',
        'inventory.warehouse-transfers-reconcile',
        'reports.reports-hub-productMovement',
    ],

    'diagram' => 'warehouse-transfer-flow',

    'updated_at' => '2026-08-07',
];
