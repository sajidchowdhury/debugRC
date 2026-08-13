<?php

/**
 * Help content for: purchasing.purchase-audit
 * Route: admin.purchase-audit.checklist (and admin.purchase-audit.run for AJAX refresh)
 *
 * The consolidated Purchase Audit Checklist — a 12-section health-check
 * dashboard covering the WHOLE procure-to-pay cycle (PO + GRN + return).
 * Read-only (no writes). Used by managers and auditors to catch data
 * integrity issues before they cascade into the GL.
 *
 * The 12 sections (each item has status: pass | warn | fail | info):
 *   1.  Purchase module scope (informational)
 *   2.  Products (purchase SKUs) — orphans, inactive products on GRN/PO
 *   3.  Suppliers — active count, GRN without supplier, inactive supplier
 *   4.  Warehouses — branch linkage, orphans
 *   5.  Stock SSOT — warehouse_stock consistency, negative physical_qty
 *   6.  Purchase order — over-received lines (FAIL), open PO lines
 *   7.  GRN — confirmed without journal_entry_id, cancelled with unreversed JE
 *   8.  Purchase return — Damage lines must NOT have stock movements (FAIL),
 *       Good returns must have stock OUT, return_qty ≤ received
 *   9.  Supplier payments & due — payments without ledger/journal
 *   10. GL journal link columns — informational mapping
 *   11. Ledger & accounts (GL) — active inventory + AP ledgers configured
 *   12. Reporting — informational
 *
 * Three detail tables at the bottom:
 *   - Negative stock rows (limit 15)
 *   - GRNs missing journal (limit 15)
 *   - Returns missing journal (limit 15)
 *
 * "Re-run checks" button (admin/purchase-audit/run) refreshes via AJAX.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 * @see app/Services/Purchase/PurchaseAuditService.php
 */

return [
    'key'        => 'purchasing.purchase-audit',
    'module'     => 'purchasing',
    'title_bn'   => 'পারচেজ অডিট চেকলিস্ট — স্বাস্থ্য পরীক্ষা',
    'title_en'   => 'Purchase Audit Checklist — Health Checks',
    'icon'       => 'fa-clipboard-list',
    'summary'    => 'পুরো ক্রয় সাইকেলের (পিও/রিসিভ/রিটার্ন) ডেটা ইন্টিগ্রিটি চেক — ১২টি সেকশনে pass/warn/fail ব্যাজ। নেগেটিভ স্টক, মিসিং GL জার্নাল, ওভার-রিসিভ, Damage-এ ভুল স্টক মুভমেন্ট — এসব ব্যতিক্রম এক জায়গায় দেখা যায়। ৩টি ডিটেইল টেবিল: নেগেটিভ স্টক, মিসিং GRN জার্নাল, মিসিং রিটার্ন জার্নাল। শুধু পড়ার পেজ — কোনো লেনদেন বদলায় না।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-clipboard-list',    'text' => '১২টি সেকশনে হেলথ চেক দেখা — pass/warn/fail/info ব্যাজ সহ'],
        ['icon' => 'fa-triangle-exclamation','text' => 'FAIL আইটেমে সাথে সাথে নজর দেওয়া (যেমন নেগেটিভ স্টক, মিসিং জার্নাল, ওভার-রিসিভ)'],
        ['icon' => 'fa-rotate',            'text' => '"Re-run checks" বাটনে রিফ্রেশ করা (AJAX — পেজ রিলোড ছাড়া)'],
        ['icon' => 'fa-table',             'text' => '৩টি ডিটেইল টেবিল দেখা — নেগেটিভ স্টক, মিসিং GRN জার্নাল, মিসিং রিটার্ন জার্নাল'],
        ['icon' => 'fa-filter',            'text' => 'ব্র্যাঞ্চ ফিল্টার করা (admin সব ব্র্যাঞ্চ; অন্যরা নিজের সেশন ব্র্যাঞ্চ লকড)'],
        ['icon' => 'fa-magnifying-glass',  'text' => 'নির্দিষ্ট সমস্যা খুঁজে বের করা — কোথায় ডেটা ইন্টিগ্রিটি ভেঙেছে তা শনাক্ত করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট',          'what' => 'শুধু রিড-অনলি ভিউ — কোনো লেনদেন বদলায় না'],
        ['who' => 'কমপ্লায়েন্স',    'what' => 'ম্যানেজার/অডিটর চেক-অ্যান্ড-ব্যালেন্স পান; ডেটা ইন্টিগ্রিটি ভাঙার আগে ধরা যায়'],
        ['who' => 'GL ইন্টিগ্রিটি', 'what' => 'কনফার্মড GRN/রিটার্ন কিন্তু মিসিং journal_entry_id — এই গ্যাপ এখানে ধরা পড়ে'],
        ['who' => 'স্টক ইন্টিগ্রিটি','what' => 'নেগেটিভ স্টক, ওভার-রিসিভ, Damage-এ ভুল স্টক মুভমেন্ট — এখানে ফ্ল্যাগ হয়'],
    ],

    'cautions' => [
        'এটি রিড-অনলি পেজ — এখান থেকে কোনো পিও/রিসিভ/রিটার্ন এডিট বা বাতিল হয় না; বদলাতে হলে মূল পেজে যান।',
        'FAIL আইটেম থাকলে দ্রুত মূল পেজে গিয়ে ঠিক করুন — নইলে GL ও স্টক রিপোর্ট ভুল হবে।',
        '"Re-run checks" কয়েক সেকেন্ড সময় নেয় — বড় ডেটাবেজে ধৈর্য ধরুন।',
        'ব্র্যাঞ্চ সুপারভাইজার শুধু নিজের ব্র্যাঞ্চ দেখেন; সব ব্র্যাঞ্চ দেখতে admin হতে হবে।',
    ],

    'related' => ['purchasing.purchase-orders-audit', 'purchasing.purchase-receives-audit', 'purchasing.purchase-returns-audit', 'purchasing.purchase-orders', 'system.audit'],

    // No diagram here — this is a consolidated audit dashboard, not a workflow step.

    'updated_at' => '2026-08-13',
];
