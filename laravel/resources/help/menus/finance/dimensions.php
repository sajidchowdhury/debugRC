<?php

/**
 * Help content for: finance.dimensions
 * Route: admin.dimensions.index (and segment-bs/segment-pnl sub-routes)
 *
 * The Dimension page — define reporting segments (branch, product line, channel,
 * region) and tag transactions so financial reports can be sliced by dimension.
 * Segment Balance Sheet and Segment P&L reports hang off this screen.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (primary card)
 */

return [
    'key'        => 'finance.dimensions',
    'module'     => 'finance',
    'title_bn'   => 'ডাইমেনশন',
    'title_en'   => 'Dimension',
    'icon'       => 'fa-sitemap',
    'summary'    => 'ব্র্যাঞ্চ, লাইন, চ্যানেল ধরে রিপোর্ট কাটতে ডাইমেনশন বানান আর ট্রানজেকশনে ট্যাগ দিন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',           'text' => 'নতুন ডাইমেনশন (segment) তৈরি — ব্র্যাঞ্চ/লাইন/চ্যানেল'],
        ['icon' => 'fa-tag',            'text' => 'ট্রানজেকশনে ডাইমেনশন ট্যাগ দেওয়া (ম্যানুয়াল বা অটো-ম্যাপ)'],
        ['icon' => 'fa-table-list',     'text' => 'সেগমেন্ট অনুযায়ী ব্যালেন্স শিট (Segment BS) দেখা'],
        ['icon' => 'fa-chart-bar',       'text' => 'সেগমেন্ট অনুযায়ী প্রফিট-লস (Segment P&L) দেখা'],
        ['icon' => 'fa-pen-to-square',   'text' => 'ডাইমেনশনের নাম/কোড পরিবর্তন করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্টিং', 'what' => 'সেগমেন্ট রিপোর্টে (BS/PnL) কলাম ভাগ হয়'],
        ['who' => 'ট্রানজেকশন', 'what' => 'ট্যাগ দিলে লেজার এন্ট্রি সেগমেন্টে গণনা হয় — GL ব্যাল্যান্স বদলায় না'],
    ],

    'cautions' => [
        'ট্যাগ ম্যানুয়ালি না দিলে অটো-ম্যাপ না থাকলে সেগমেন্ট রিপোর্ট ফাঁকা দেখাবে।',
        'ডাইমেনশন ডিলিট করলে আগের ট্যাগ হওয়া এন্ট্রিগুলোর সেগমেন্ট হিসাব হারিয়ে যেতে পারে।',
    ],

    'related' => [
        'finance.dimensions-segment-bs',
        'finance.dimensions-segment-pnl',
        'master-data.ledgers',
        'master-data.branches',
        'accounting.manual-journals',
    ],

    // No diagram — dimensions are an orthogonal tagging layer on top of the GL.

    'updated_at' => '2026-08-07',
];
