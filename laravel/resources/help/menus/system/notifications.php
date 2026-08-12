<?php

/**
 * Help content for: system.notifications
 * Route: admin.notifications.rules
 *
 * The Notifications rules page — where the admin configures which events
 * (new invoice, payment received, low stock, audit flag) trigger a
 * notification, who receives it, and how it fans out via SSE to logged-in
 * users' inboxes.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'system.notifications',
    'module'     => 'system',
    'title_bn'   => 'নোটিফিকেশন',
    'title_en'   => 'Notifications',
    'icon'       => 'fa-bell',
    'summary'    => 'কোন ইভেন্ট হলে কাকে এলার্ট যাবে, সেই নিয়ম এখানে কনফিগার করা হয়; SSE চ্যানেল দিয়ে রিয়েল-টাইমে ইউজারদের কাছে ছড়ায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-bell',              'text' => 'নোটিফিকেশন ইভেন্ট চালু/বন্ধ করা (new invoice, payment, low stock)'],
        ['icon' => 'fa-users',            'text' => 'প্রতিটি ইভেন্টের জন্য রিসিভার ইউজার/রোল নির্ধারণ করা'],
        ['icon' => 'fa-tower-broadcast',   'text' => 'SSE চ্যানেল দিয়ে কতজনের কাছে পৌঁছালো তা দেখা (fan-out)'],
        ['icon' => 'fa-vial',              'text' => 'টেস্ট ব্রডকাস্ট পাঠিয়ে দেখা ঠিক কাজ করছে কিনা'],
        ['icon' => 'fa-shield-halved',     'text' => 'নোটিফিকেশন অডিট দেখা — কাকে কখন পৌঁছেছে'],
    ],

    'impacts' => [
        ['who' => 'ইউজার',          'what' => 'রিয়েল-টাইমে ইনবক্সে এলার্ট ঢোকে'],
        ['who' => 'SSE চ্যানেল',    'what' => 'প্রতিটি ইভেন্ট ব্রডকাস্ট হয়ে সংযুক্ত ইউজারদের কাছে ছড়ায়'],
        ['who' => 'অপারেশন',        'what' => 'ইনভয়েস/পেমেন্ট ইভেন্টে অন্য টিম সদস্য দ্রুত খবর পায়'],
    ],

    'cautions' => [
        'SSE-এর জন্য ইউজারের লাইভ ব্রাউজার কানেকশন দরকার — ট্যাব বন্ধ থাকলে নোটিফিকেশন ইনবক্সে জমা থাকে, পুশ হয় না।',
        'ভুল ইভেন্ট কনফিগ করলে ইউজারদের ইনবক্স স্প্যাম হয়ে যেতে পারে — চালু করার আগে রিসিভার তালিকা মিলিয়ে নিন।',
    ],

    'related' => ['system.notifications-inbox', 'system.sse', 'system.sse-status', 'system.audit', 'system.users'],

    'diagram' => 'notification-fan-out',

    'updated_at' => '2026-08-07',
];
