<?php

/**
 * Help content for: sales.audit
 * Route: admin.sales.audit (SalesAuditController@checklist)
 *
 * The Sales Audit Checklist page — health checks for the sales module.
 * Verifies GL integrity, stock consistency, missing journals, stale drafts,
 * and negative warehouse stock. Runs automated checks with pass/warn/fail status.
 *
 * Three-layer audit architecture:
 *   Layer 1 — Hash-chained financial audit log (SHA-256, UPDATE/DELETE revoked)
 *   Layer 2 — User-action log (jsonb + IP + user_agent, dual-write DB + file)
 *   Layer 3 — Per-module views (this checklist + per-type audit trails)
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.audit',
    'module'     => 'sales',
    'title_bn'   => 'সেলস অডিট চেকলিস্ট — স্বাস্থ্য পরীক্ষা',
    'title_en'   => 'Sales Audit Checklist — Health Checks',
    'icon'       => 'fa-clipboard-check',
    'summary'    => 'সেলস মডিউলের স্বাস্থ্য পরীক্ষা — GL ইন্টিগ্রিটি, স্টক কনসিস্টেন্সি, মিসিং জার্নাল, পুরোনো ড্রাফট, নেগেটিভ গোডাউন স্টক — সব অটোমেটিক চেক হয়। প্রতিটি চেক: pass (সবুজ), warn (হলদে), fail (লাল) স্ট্যাটাস দেখায়। প্রয়োজনে রি-রান করুন, পুরোনো ড্রাফট বাতিল করুন। তিন লেয়ার অডিট: হ্যাশ-চেইন্ড ফাইন্যান্সিয়াল লগ + ইউজার-অ্যাকশন লগ + এই চেকলিস্ট।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-clipboard-check',      'text' => 'অডিট চেকলিস্ট দেখা — সামারি চিপস: X pass / X warn / X fail / X reference। সেকশন TOC ন্যাভিগেশন দিয়ে দ্রুত যান।'],
        ['icon' => 'fa-circle-check',         'text' => 'প্রতিটি চেক আইটেম — স্ট্যাটাস আইকন (✓/⚠/✗), শিরোনাম, কী হওয়ার কথা, ডিটেইল, সম্পর্কিত মডিউলে যাওয়ার লিংক।'],
        ['icon' => 'fa-warehouse',             'text' => 'নেগেটিভ গোডাউন স্টক টেবিল — কোন গোডাউনে কোন পণ্যের স্টক ঋণাত্মক হয়ে গেছে তার তালিকা (গোডাউন, পণ্য, পরিমাণ)।'],
        ['icon' => 'fa-file-invoice-dollar',   'text' => 'মিসিং জার্নাল টেবিল — কোন ইনভয়েসের GL জার্নাল নেই (কোড, তারিখ, মোট, GL ডিটেইল লিংক)। কোন চালানের COGS জার্নাল নেই (চালান, ইনভয়েস, GL ডিটেইল লিংক)।'],
        ['icon' => 'fa-arrows-rotate',         'text' => 'রি-রান চেকস — "Refresh" বাটনে ক্লিক করলে সব চেক আবার চালানো হয় (async, SalesAudit/run_checks)।'],
        ['icon' => 'fa-broom',                 'text' => 'পুরোনো ড্রাফট বাতিল — "Cancel Stale Drafts" বাটনে অনেক দিনের পুরোনো ড্রাফট ইনভয়েস বাতিল করুন (পাইপলাইন রিজার্ভেশন রিলিজ হয়)।'],
    ],

    'impacts' => [
        ['who' => 'অডিট',       'what' => 'শুধু দেখার পেজ — সরাসরি কোনো হিসাব বদলায় না (শুধু "Cancel Stale Drafts" ড্রাফট বাতিল করে)'],
        ['who' => 'ড্রাফট',     'what' => '"Cancel Stale Drafts" পাইপলাইন রিজার্ভেশন রিলিজ করে — আটকে থাকা স্টক ফিরিয়ে আসে'],
        ['who' => 'কমপ্লায়ান্স', 'what' => 'চেকলিস্ট ফলাফল কমপ্লায়ান্স ও ইন্টারনাল অডিটে ব্যবহৃত'],
    ],

    'cautions' => [
        '"Cancel Stale Drafts" শুধু পুরোনো ড্রাফট ইনভয়েস বাতিল করে — ফাইনাল ইনভয়েস বা অন্য কিছু বদলায় না। তবে বাতিলের আগে নিশ্চিত হোন ঐ ড্রাফটগুলো সত্যিই পুরোনো।',
        'চেকলিস্ট পড-হক ফলাফল দেখায় — GL মিসিং বা নেগেটিভ স্টক ধরলে সংশ্লিষ্ট মডিউলে গিয়ে ম্যানুয়ালি সংশোধন করতে হবে।',
        'হ্যাশ-চেইন্ড লগ (financial_audit_log) UPDATE/DELETE রিভোকড — লগ ট্যাম্পার-প্রুফ। কিন্তু চেকলিস্ট রান করলে নতুন লগ তৈরি হয় না — শুধু বিশ্লেষণ করে।',
    ],

    'related' => ['sales.invoices', 'sales.invoices-audit', 'sales.returns-audit', 'system.audit', 'system.compliance', 'accounting.reconciliation'],

    'updated_at' => '2026-08-13',
];
