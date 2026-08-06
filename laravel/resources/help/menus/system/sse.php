<?php

/**
 * Help content for: system.sse
 * Route: sse.events
 *
 * The SSE (Server-Sent Events) endpoint — the live push channel the browser
 * holds open to receive real-time notifications. Backend broadcasts events
 * here; subscribed browsers receive them instantly without polling.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.sse',
    'module'     => 'system',
    'title_bn'   => 'SSE ইভেন্ট',
    'title_en'   => 'SSE Events',
    'icon'       => 'fa-tower-broadcast',
    'summary'    => 'রিয়েল-টাইম নোটিফিকেশনের ব্রডকাস্ট চ্যানেল — ব্রাউজার এই এন্ডপয়েন্ট খোলা রাখে আর ইভেন্ট লাইভে ঢোকে।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-tower-broadcast',  'text' => 'SSE এন্ডপয়েন্ট ও চ্যানেল কনফিগারেশন দেখা'],
        ['icon' => 'fa-signal',           'text' => 'বর্তমানে সংযুক্ত ক্লায়েন্ট সংখ্যা দেখা'],
        ['icon' => 'fa-heart-pulse',      'text' => 'কানেকশন হেলথ ও লাইভ স্ট্যাটাস চেক করা'],
        ['icon' => 'fa-vial',              'text' => 'টেস্ট ইভেন্ট ব্রডকাস্ট করে দেখা'],
    ],

    'impacts' => [
        ['who' => 'নোটিফিকেশন',     'what' => 'ইনবক্স পুশ এই চ্যানেলের ওপর নির্ভর করে'],
        ['who' => 'ব্রাউজার',         'what' => 'সংযুক্ত ক্লায়েন্টরা লাইভে আপডেট পায়'],
    ],

    'cautions' => [
        'নেটওয়ার্ক ব্লিপ হলে SSE কানেকশন ড্রপ করে — ক্লায়েন্ট অটো-রিকানেক্ট চেষ্টা করে, কিন্তু এই ফাঁকে নোটিফিকেশন ইনবক্সে জমা থাকে।',
        'একই ইউজার একাধিক ট্যাব খোলা রাখলে প্রতিটি আলাদা কানেকশন খায় — সার্ভার কানেকশন লিমিট মাথায় রাখুন।',
    ],

    'related' => ['system.sse-status', 'system.notifications', 'system.notifications-inbox'],

    'updated_at' => '2026-08-07',
];
