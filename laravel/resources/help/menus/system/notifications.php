<?php

/**
 * Help content for: system.notifications
 * Route: admin.notifications.rules
 *
 * Enriched Bangla guideline — explains notification rules, 18 event types,
 * 10 recipient types (including context-aware), SSE real-time delivery,
 * and the create/filter/manage workflow.
 */

return [
    'key'        => 'system.notifications',
    'module'     => 'system',
    'title_bn'   => 'নোটিফিকেশন রুল কনফিগারেশন',
    'title_en'   => 'Notification Rule Configuration',
    'icon'       => 'fa-bell',
    'summary'    => 'কোন ইভেন্ট হলে কাকে এলার্ট যাবে — সেই নিয়ম এখানে তৈরি ও নিয়ন্ত্রণ করা হয়। ১৮ ধরনের ইভেন্ট (ইনভয়েস ফাইনালাইজ, পেমেন্ট, স্টক লো, অ্যাপ্রুভাল ইত্যাদি) ও ১০ ধরনের রিসিভার টাইপ আছে। নোটিফিকেশন SSE চ্যানেল দিয়ে রিয়েল-টাইমে ইউজারদের ব্রাউজারে পৌঁছায় — পেজ রিফ্রেশ করতে হয় না।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus-circle',       'text' => 'নতুন রুল তৈরি — ইভেন্ট বেছে নিন, রিসিভার টাইপ বেছে নিন (একাধিক দেওয়া যায়), নাম ও বিবরণ দিন। "অ্যাক্টিভ করুন" টগল দিয়ে সাথে সাথে চালু করা যায়।'],
        ['icon' => 'fa-bell',              'text' => '১৮ ধরনের ইভেন্ট — সেলস ফাইনালাইজ, চালান তৈরি, পেমেন্ট রিসিভ, রিটার্ন, ব্র্যাঞ্চ ডিম্যান্ড, অ্যাপ্রুভাল সাবমিট/অ্যাপ্রুভ/রিজেক্ট, ড্যামেজ ইনভয়েস, ইউজার লগইন/লগআউট ইত্যাদি।'],
        ['icon' => 'fa-users',             'text' => '১০ ধরনের রিসিভার — all_users, admin, superadmin, sales_manager, accountant, warehouse_manager, এবং কনটেক্সট-সচেতন: warehouse_manager_of_branch, salesman_of_invoice, invoice_creator, specific_user।'],
        ['icon' => 'fa-tower-broadcast',   'text' => 'SSE রিয়েল-টাইম ডেলিভারি — ইভেন্ট হলে PostgreSQL LISTEN/NOTIFY দিয়ে Redis-এ যায়, থেকে ব্রাউজারে SSE পুশ হয়। ট্যাব বন্ধ থাকলে ইনবক্সে জমা থাকে।'],
        ['icon' => 'fa-toggle-on',         'text' => 'রুল চালু/বন্ধ — টগল সুইচ দিয়ে যেকোনো রুল অন/অফ করুন বা ডিলিট করুন।'],
        ['icon' => 'fa-filter',            'text' => 'ফিল্টার — ইভেন্ট টাইপ, রিসিভার টাইপ, "শুধু অ্যাক্টিভ রুল" চেকবক্স দিয়ে তালিকা সংকুচিত করুন।'],
        ['icon' => 'fa-rotate',            'text' => 'ডিফল্টে রিসেট — "Reset to Defaults" বাটনে ক্লিক করলে সব কাস্টম রুল মুছে গিয়ে ডিফল্ট রুল আবার সিড হবে।'],
    ],

    'impacts' => [
        ['who' => 'রিয়েল-টাইম এলার্ট',   'what' => 'ইভেন্ট হলে সাথে সাথে সংযুক্ত ইউজারদের ব্রাউজারে টোস্ট নোটিফিকেশন দেখাবে — পেজ রিফ্রেশ লাগবে না।'],
        ['who' => 'ইনবক্স',               'what' => 'ইউজার অফলাইন থাকলে বা ট্যাব বন্ধ থাকলে নোটিফিকেশন ইনবক্সে জমা থাকে, লগইন করলে দেখা যাবে।'],
        ['who' => 'কনটেক্সট-সচেতন',       'what' => 'warehouse_manager_of_branch, salesman_of_invoice — এগুলো ইভেন্টের ডাটা থেকে অটোমেটিক রিসিভার বের করে, ম্যানুয়ালি সেট করতে হয় না।'],
        ['who' => 'বেল ব্যাজ',            'what' => 'টপ-ন্যাভে বেল আইকনে আনরিড কাউন্ট দেখায় — নতুন নোটিফিকেশন এলে অটোমেটিক আপডেট হয়।'],
    ],

    'cautions' => [
        'ভুল ইভেন্ট কনফিগ করলে ইউজারদের ইনবক্স স্প্যাম হয়ে যেতে পারে — চালু করার আগে রিসিভার তালিকা মিলিয়ে নিন।',
        'SSE-এর জন্য ইউজারের লাইভ ব্রাউজার কানেকশন দরকার — ট্যাব বন্ধ থাকলে নোটিফিকেশন ইনবক্সে জমা থাকে, পুশ হয় না।',
        '"Reset to Defaults" সব কাস্টম রুল হার্ড-ডিলিট করবে — এটি আনডু করা যাবে না, সাবধানে ক্লিক করুন।',
        'specific_user রিসিভার দিলে ইউজার ড্রপডাউন থেকে নির্দিষ্ট ইউজার বেছে দিতে হবে।',
    ],

    'related' => ['system.notifications-inbox', 'system.sse', 'system.sse-status', 'system.audit', 'system.users', 'accounting.approvals'],

    'diagram' => 'notification-fan-out',

    'updated_at' => '2026-08-13',
];
