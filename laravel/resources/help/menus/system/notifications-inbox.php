<?php

/**
 * Help content for: system.notifications-inbox
 * Route: admin.notifications.inbox
 *
 * The per-user notification inbox — every logged-in user sees here the
 * notifications fanned out to them via SSE: new invoice assigned, payment
 * received, low stock warning, audit flag, etc. Read/unread and clear.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.notifications-inbox',
    'module'     => 'system',
    'title_bn'   => 'Notification Inbox',
    'title_en'   => 'Notification Inbox',
    'icon'       => 'fa-inbox',
    'summary'    => 'আপনার কাছে আসা রিয়েল-টাইম নোটিফিকেশনগুলো এখানে জমা হয় — পড়া/না পড়া চিহ্নিত করে রাখুন।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant', 'salesman'],

    'what_you_can_do' => [
        ['icon' => 'fa-inbox',           'text' => 'আপনাকে আসা সব নোটিফিকেশন দেখা'],
        ['icon' => 'fa-check-double',    'text' => 'পড়া/না পড়া চিহ্নিত করা (mark read/unread)'],
        ['icon' => 'fa-filter',          'text' => 'টাইপ বা তারিখ ধরে ফিল্টার করা'],
        ['icon' => 'fa-trash',           'text' => 'পুরোনো নোটিফিকেশন ক্লিয়ার করা'],
    ],

    'impacts' => [
        ['who' => 'ইউজার',       'what' => 'কেবল পড়ার অংশ — মূল ডেটা বদলায় না'],
        ['who' => 'SSE',          'what' => 'নতুন নোটিফিকেশন লাইভে এই ইনবক্সে পৌঁছায়'],
    ],

    'cautions' => [
        'ক্লিয়ার করা নোটিফিকেশন ফিরে পাওয়া যায় না — দরকারি হলে মুছবেন না।',
        'একই নোটিফিকেশন যদি অন্য ডিভাইসে পড়া হয়, এখানে আলাদাভাবে রিড চিহ্ন দিতে হতে পারে।',
    ],

    'related' => ['system.notifications', 'system.sse', 'system.sse-status'],

    'updated_at' => '2026-08-07',
];
