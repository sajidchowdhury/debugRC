<?php

/**
 * Help content for: system.sse-status
 * Route: sse.status
 *
 * Live status of the SSE broadcast channel — current connected clients, last
 * heartbeat, dropped/retried connections, channel health. Diagnostics view
 * for the system.sse endpoint.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'system.sse-status',
    'module'     => 'system',
    'title_bn'   => 'SSE Status',
    'title_en'   => 'SSE Status',
    'icon'       => 'fa-signal',
    'summary'    => 'SSE চ্যানেলের লাইভ স্ট্যাটাস — কতজন ক্লায়েন্ট সংযুক্ত, শেষ হার্টবিট, ড্রপ/রিট্রাই সংখ্যা।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-signal',           'text' => 'বর্তমানে সংযুক্ত SSE ক্লায়েন্ট সংখ্যা দেখা'],
        ['icon' => 'fa-heart-pulse',      'text' => 'শেষ হার্টবিট ও চ্যানেল হেলথ চেক করা'],
        ['icon' => 'fa-clock-rotate-left','text' => 'ড্রপ/রিট্রাই হিস্ট্রি দেখা'],
    ],

    'impacts' => [
        ['who' => 'নোটিফিকেশন',  'what' => 'স্ট্যাটাস সবুজ না হলে পুশ ব্যাহত হয়'],
        ['who' => 'ডায়াগনস্টিকস', 'what' => 'রিড-অনলি — কনফিগ বদল এখান থেকে নয়'],
    ],

    'cautions' => [
        'স্ট্যাটাস লাল হলে দ্রুত system.sse ও system.system-health চেক করুন — নতুন নোটিফিকেশন পৌঁছাচ্ছে না।',
    ],

    'related' => ['system.sse', 'system.notifications', 'system.system-health'],

    'updated_at' => '2026-08-07',
];
