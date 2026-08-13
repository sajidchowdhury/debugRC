<?php

/**
 * Help content for: sales.invoices-audit
 * Route: admin.sales.audit (SalesInvoiceController@auditTrail)
 *
 * Sub-page of sales.invoices — the consolidated sales audit trail.
 * This is the audit LOG page that shows all sales events (invoice, godown, challan, payment).
 *
 * Enhanced with detailed Bangla documentation covering the three-layer audit architecture.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.invoices-audit',
    'module'     => 'sales',
    'title_bn'   => 'সেলস অডিট ট্রেইল — সব লেনদেনের ইতিহাস',
    'title_en'   => 'Sales Audit Trail — Complete Transaction History',
    'icon'       => 'fa-list-check',
    'summary'    => 'সেলস মডিউলের সব ইভেন্টের অডিট ট্রেইল — ইনভয়েস তৈরি, গোডাউন সেভ, চালান ইস্যু, পেমেন্ট গ্রহণ, রিভার্স — সব কিছু কে কখন কী করেছে তা এখানে লেখা পড়ে। তিন লেয়ার অডিট সিস্টেম: লেয়ার ১ — হ্যাশ-চেইন্ড ফাইন্যান্সিয়াল অডিট লগ (SHA-256 row_hash, UPDATE/DELETE রিভোকড); লেয়ার ২ — ইউজার-অ্যাকশন লগ (jsonb details + IP + user_agent, DB + ফাইলে দ্বৈত লেখা); লেয়ার ৩ — প্রতি-মডিউল ভিউ (ইনভয়েস/চালান/পেমেন্ট/রিটার্ন ইভেন্ট)।',

    'for_roles'  => ['manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',                'text' => 'অডিট ট্রেইল দেখা — সব সেলস ইভেন্টের টেবিল: সময়, ইউজার, অ্যাকশন, রেফারেন্স ID, ডিটেইল, IP। অ্যাকশন প্রিফিক্স: sale_, godown_, challan_, payment_।'],
        ['icon' => 'fa-magnifying-glass',    'text' => 'সার্চ ও ফিল্টার — তারিখ, খদ্দের, সেলসম্যান, অ্যাকশন টাইপ দিয়ে ফিল্টার করুন। নির্দিষ্ট ইনভয়েস/চালান/রিটার্ন/পেমেন্ট কোড দিয়ে খুঁজুন।'],
        ['icon' => 'fa-file-lines',          'text' => 'ডিটেইল কী দেখা — invoice_code, challan_code, return_code, payment_code, total_amount, amount, items_reversed, transport_adjustment, override_reason, reason।'],
        ['icon' => 'fa-shield-halved',       'text' => 'হ্যাশ-চেইন যাচাই — প্রতিটি রো-এর SHA-256 row_hash আগের রো-র সাথে চেইন্ড। UPDATE/DELETE ডেটাবেস লেভেলে রিভোকড — কেউ লগ বদলাতে পারে না।'],
        ['icon' => 'fa-file-export',         'text' => 'অডিট রেকর্ড এক্সপোর্ট — CSV তে নামিয়ে কমপ্লায়ান্স বা অডিট রিপোর্টে ব্যবহার করুন।'],
    ],

    'impacts' => [
        ['who' => 'অডিট',       'what' => 'শুধু দেখার পেজ — কোনো হিসাব বা স্টক বদলায় না'],
        ['who' => 'কমপ্লায়ান্স', 'what' => 'অডিট ট্রেইল কমপ্লায়ান্স ও ইন্টারনাল কন্ট্রোল চেকে ব্যবহৃত'],
        ['who' => 'নিরাপত্তা',   'what' => 'হ্যাশ-চেইন ও রিভোকড UPDATE/DELETE নিশ্চিত করে লগ ট্যাম্পার-প্রুফ'],
    ],

    'cautions' => [
        'অডিট পেজ শুধু পঠনযোগ্য — কোনো হিসাব বা ডেটা বদলায় না। ভুল ধরা পড়লে রিটার্ন বা ম্যানুয়াল জার্নাল দিয়ে সংশোধন করতে হবে।',
        'financial_audit_log টেবিলে UPDATE/DELETE রিভোকড — সরাসরি SQL দিয়েও লগ বদলানো যাবে না।',
        'ইউজার-অ্যাকশন লগ DB ও ফাইলে দ্বৈত লেখা হয় — একটা ডিলিট করলেও অন্যটা থেকে রিকভারি করা যায়।',
    ],

    'related' => ['sales.invoices', 'sales.returns-audit', 'system.audit', 'system.compliance'],

    'updated_at' => '2026-08-13',
];
