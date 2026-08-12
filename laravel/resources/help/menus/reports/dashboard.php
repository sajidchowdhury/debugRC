<?php

/**
 * Help content for: reports.dashboard
 * Route: dashboard (UserPerformanceDashboardController@index)
 *
 * The User Performance Dashboard — the FIRST page every logged-in user sees.
 * This is a PER-USER attribution dashboard (Phase 0–6), NOT a company-wide
 * overview. Every metric is filtered by `created_by = <user id>` so each
 * person sees only their OWN sales, collections, velocity, commission, etc.
 *
 * Key design principle (docs/USER_PERFORMANCE_DASHBOARD_PLAN.md):
 *   - NO company-wide metrics anywhere.
 *   - Default view = the logged-in user's own performance.
 *   - Super-admin (role='superadmin') sees an employee <select> dropdown
 *     at the top to view any employee's dashboard via ?employee_id=X.
 *   - Non-admin users who manually hit ?employee_id=X are silently
 *     ignored — they always see their own numbers (no peeking).
 *
 * The 6 metric phases (sections shown/hidden per the viewer's role):
 *   P1 Sales Performance   — sales KPIs, trend, by product group, top 5
 *                             customers, customer acquisition
 *   P2 Collections & Returns— collection KPIs, receivable aging, return
 *                             KPIs, payment-mode mix
 *   P3 Operational Efficiency— velocity (invoice lifecycle created→
 *                             godown_prepared→challan_issued), pipeline
 *                             snapshot, work-pattern hour histogram,
 *                             activity summary, notification engagement
 *   P4 Commission & Stock   — commission summary (salesman_id), stock
 *                             discipline, accuracy KPIs
 *   P5 Role-Aware + Approval— role→section visibility map; approval
 *                             workload (manager-only: adjustments &
 *                             damages pending my approval / approved by me)
 *   P6 Polish & Performance — 60-second cached metrics, AJAX fragment
 *                             refresh (switch period/employee without
 *                             full page reload), slow-query logging
 *
 * Period switching: ?period=today|week|month|quarter|year|custom
 * Super-admin employee switch: ?employee_id=X
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 * @see app/Http/Controllers/UserPerformanceDashboardController.php
 */

return [
    'key'        => 'reports.dashboard',
    'module'     => 'reports',
    'title_bn'   => 'আমার পারফরম্যান্স ড্যাশবোর্ড',
    'title_en'   => 'My Performance Dashboard',
    'icon'       => 'fa-gauge-high',
    'summary'    => 'লগইন করার পর প্রথম পেজ — আপনার নিজের বিক্রি, কালেকশন, ভেলোসিটি, কমিশন এবং অ্যাপ্রুভাল ওয়ার্কলোডের এক নজরে ছবি। প্রতিটি সংখ্যা শুধু আপনার নামে অ্যাট্রিবিউট করা — পুরো কোম্পানির ডেটা নয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant', 'salesman', 'warehouse_manager', 'dispatcher', 'hr', 'user', 'other'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-check',            'text' => 'নিজের পারফরম্যান্স দেখা — প্রতিটি KPI শুধু আপনার তৈরি করা ডেটা থেকে আসে (created_by = আপনি)'],
        ['icon' => 'fa-calendar-days',         'text' => 'পিরিয়ড বদলানো — আজ / সপ্তাহ / মাস / কোয়ার্টার / বছর / কাস্টম রেঞ্জ (পেজ রিলোড ছাড়া AJAX-এ)'],
        ['icon' => 'fa-chart-line',             'text' => 'সেলস ট্রেন্ড গ্রাফ দেখা — দৈনিক/সাপ্তাহিক বিক্রির ধাপ'],
        ['icon' => 'fa-boxes-stacked',          'text' => 'প্রোডাক্ট গ্রুপ অনুযায়ী বিক্রি ও টপ ৫ খদ্দের দেখা'],
        ['icon' => 'fa-hand-holding-dollar',   'text' => 'কালেকশন KPI, রিসিভেবল এজিং ও রিটার্ন সারাংশ দেখা'],
        ['icon' => 'fa-gauge',                  'text' => 'অপারেশনাল ভেলোসিটি দেখা — ইনভয়েস তৈরি → গোডাউন প্রস্তুত → চালান ইস্যু কত দ্রুত হচ্ছে'],
        ['icon' => 'fa-clock',                  'text' => 'ওয়ার্ক প্যাটার্ন (২৪-ঘণ্টা হিস্টোগ্রাম) দেখা — আপনি দিনের কোন সময়ে সবচেয়ে বেশি সক্রিয়'],
        ['icon' => 'fa-coins',                  'text' => 'কমিশন সারাংশ ও স্টক ডিসিপ্লিন দেখা (সেলসম্যান রোলের জন্য)'],
        ['icon' => 'fa-list-check',            'text' => 'অ্যাপ্রুভাল ওয়ার্কলোড দেখা (ম্যানেজার রোল) — আপনার অ্যাপ্রুভালে অপেক্ষমান স্টক অ্যাডজাস্টমেন্ট/ক্ষতি'],
        ['icon' => 'fa-user-gear',             'text' => 'সুপার-অ্যাডমিন হলে উপরের ড্রপডাউন থেকে যেকোনো কর্মচারীর ড্যাশবোর্ড দেখা (?employee_id=X)'],
    ],

    'impacts' => [
        ['who' => 'ব্যক্তিগত অ্যাকাউন্টেবিলিটি', 'what' => 'প্রতিটি সংখ্যা একটি নির্দিষ্ট ব্যক্তির নামে — কে কত বিক্রি/কালেকশন/অ্যাপ্রুভ করেছে স্বচ্ছ'],
        ['who' => 'রোল-ভিত্তিক ভিউ',          'what' => 'সেলসম্যান কমিশন সেকশন দেখে, ম্যানেজার অ্যাপ্রুভাল ওয়ার্কলোড দেখে — প্রত্যেকে নিজের প্রাসঙ্গিক অংশ'],
        ['who' => 'পিরিয়ড তুলনা',             'what' => 'এই সপ্তাহ vs গত সপ্তাহ, এই মাস vs গত মাস — ট্রেন্ড থেকে উন্নতি/পতন টের পাওয়া যায়'],
        ['who' => 'ক্যাশ',                    'what' => '৬০ সেকেন্ড ক্যাশে — নতুন এন্ট্রি এলে ১ মিনিটের মধ্যে ড্যাশবোর্ডে দেখা যায়'],
    ],

    'cautions' => [
        'এখানে পুরো কোম্পানির সংখ্যা নেই — শুধু আপনার নিজের (বা সুপার-অ্যাডমিন হলে নির্বাচিত কর্মচারীর) ডেটা। কোম্পানি-ওয়াইড ছবির জন্য Reports Hub দেখুন।',
        'সাধারণ ইউজার যদি URL-এ ?employee_id= দিয়ে অন্যের ড্যাশবোর্ড দেখতে চায়, সিস্টেম তা উপেক্ষা করে — সবসময় নিজের ডেটাই দেখাবে। শুধু সুপার-অ্যাডমিন অন্যের দেখতে পারে।',
        '৬০ সেকেন্ড ক্যাশে থাকায় এই মুহূর্তের এন্ট্রি সাথে সাথে দেখা নাও যেতে পারে — এক মিনিট পরে রিফ্রেশ করুন বা পিরিয়ড বদলে আবার দেখুন।',
        'ইউজার অ্যাকাউন্ট যদি কোনো employee রেকর্ডের সাথে লিংক না থাকে, ড্যাশবোর্ড খালি দেখাবে — অ্যাডমিনের সাথে কথা বলে employee লিংক ঠিক করুন।',
    ],

    'related' => ['reports.reports-hub', 'reports.customer-performance', 'accounting.approvals', 'master-data.employees', 'system.audit'],

    // No diagram — the dashboard is a role-aware KPI grid; a static flow
    // diagram wouldn't capture the per-user/role-section variability. The
    // 6-phase section list above documents the structure textually.

    'updated_at' => '2026-08-07',
];
