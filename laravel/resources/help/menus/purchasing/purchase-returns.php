<?php

/**
 * Help content for: purchasing.purchase-returns
 * Route: admin.purchase-returns.index (and create/show/confirm/cancel/slip via wildcard)
 *
 * The Purchase Return page — where damaged, wrong, or excess goods are sent
 * back to the supplier. Two-phase: create draft → confirm.
 *
 * Always against a confirmed GRN (purchase_receive_id NOT NULL). The return
 * inherits supplier + branch + warehouse from the GRN.
 *
 * The CONFIRM step:
 *   1. STOCK OUT for Good items only (reference_type='purchase_return',
 *      negative qty at the ORIGINAL GRN rate — preserves cost integrity)
 *      — Damage items SKIP stock movement (stock was never usable)
 *   2. GL: Dr Accounts Payable / Cr Inventory (reverse of GRN, ALL items)
 *   3. supplier_ledger DEBIT (we owe the supplier less)
 *   4. Increments purchase_receive_items.return_qty (cumulative, Good+Damage)
 *
 * Phase 5 condition: each return line is 'Good' or 'Damage'.
 *   - Good  → stock OUT + GL + ledger
 *   - Damage → GL + ledger only (NO stock movement — supplier claim only)
 *   The audit checklist §8 has a FAIL check: "Damage lines must NOT have
 *   stock movements".
 *
 * Returnable cap: return_qty ≤ received_qty - already_returned. Cannot
 * return more than was received.
 *
 * Debit note: the return IS the debit note (reduces supplier payable).
 * The printable slip (purchase-returns-slip) is the document handed to
 * the supplier.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 * @see app/Services/Purchase/PurchaseReturnService.php
 */

return [
    'key'        => 'purchasing.purchase-returns',
    'module'     => 'purchasing',
    'title_bn'   => 'পি. রিটার্ন',
    'title_en'   => 'P. Return',
    'icon'       => 'fa-rotate-left',
    'summary'    => 'সাপ্লায়ারকে মাল ফেরত দিলে এখানে রিটার্ন হয়। কনফার্ম করলে স্টক কমে (Good মাল), সাপ্লায়ার পেয়েবল কমে (ডেবিট নোট), GL-এ ডাবল-এন্ট্রি পড়ে। Damage মালে স্টক কমে না — শুধু পেয়েবল কমে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'warehouse_manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-rotate-left',      'text' => 'নতুন রিটার্ন তৈরি (confirmed GRN বেছে — পিও নয়, রিসিভ বেছে দিতে হয়)'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'GRN খুঁজে বের করা (AJAX typeahead — confirmed, non-reversed, returnable মাল আছে এমন)'],
        ['icon' => 'fa-list-ol',          'text' => 'ফেরত কোয়ান্টিটি সেট করা (returnable_qty cap — received থেকে বেশি দেওয়া যায় না)'],
        ['icon' => 'fa-tag',              'text' => 'প্রতিটি লাইনে কন্ডিশন চিহ্নিত করা: Good (ভাল মাল) বা Damage (ক্ষতিগ্রস্ত)'],
        ['icon' => 'fa-circle-check',     'text' => 'কনফার্ম করা — Good মাল স্টক থেকে কমে, Damage মাল স্টক ছুঁয়ে না; দুটোতেই পেয়েবল কমে'],
        ['icon' => 'fa-file-invoice',    'text' => 'ডেবিট নোট তৈরি হয় (সাপ্লায়ার পেয়েবল কমানোর দলিল)'],
        ['icon' => 'fa-receipt',          'text' => 'রিটার্ন স্লিপ প্রিন্ট করে সাপ্লায়ারকে পাঠানো'],
        ['icon' => 'fa-file-export',      'text' => 'রিটার্ন তালিকা CSV-তে এক্সপোর্ট করা'],
        ['icon' => 'fa-ban',              'text' => 'ভুল রিটার্ন বাতিল করা (কনফার্মড হলে স্টক+GL+পেয়েবল রিভার্স হয়)'],
    ],

    'impacts' => [
        ['who' => 'স্টক',           'what' => 'Good আইটেম কমে (godown থেকে বের হয়, original rate-এ) — Damage আইটেম স্টক ছুঁয়ে না'],
        ['who' => 'সাপ্লায়ার পেয়েবল', 'what' => 'কমে (debit entry) — Good+Damage দুটোতেই কমে'],
        ['who' => 'হিসাব (GL)',      'what' => 'ড্র পেয়েবল / ক্রেডিট ইনভেন্টরি — journal_entry_id রিটার্নে সেভ থাকে'],
        ['who' => 'GRN',            'what' => 'return_qty বাড়ে (cumulative) — কতটা ফেরত দেওয়া হয়েছে ট্র্যাক থাকে'],
        ['who' => 'অডিট',           'what' => 'রিটার্ন লগ user_audit_log-এ লেখা পড়ে (purchase_return_created/confirmed/reversed)'],
    ],

    'cautions' => [
        'রিটার্ন সবসময় একটি confirmed GRN-এর বিপরীতে হতে হবে — সরাসরি (পিও ছাড়া) রিটার্ন করা যায় না।',
        'রিটার্ন কোয়ান্টিটি received_qty - already_returned-এর বেশি দেওয়া যায় না (returnable_qty cap)।',
        'Good মাল স্টক থেকে কমে কিন্তু Damage মাল স্টক ছুঁয়ে না — Damage-এ শুধু সাপ্লায়ার পেয়েবল কমে (সাপ্লায়ারের কাছে ক্লেইম)।',
        'কনফার্ম ও বাতিল শুধু admin/manager করতে পারে — warehouse_manager ড্রাফট তৈরি করতে পারে কিন্তু কনফার্ম করতে না।',
        'রিটার্ন কনফার্ম করলে ডেবিট নোট তৈরি হয় — সাপ্লায়ারকে জানাতে স্লিপ প্রিন্ট করে পাঠান।',
        'ভুল রিটার্ন বাতিল করলে স্টক ফিরে আসে (Good আইটেম), GL রিভার্স হয়, পেয়েবল ফিরে যায় — কিন্তু GRN-এর return_qty ও কমে।',
        'রিটার্নের rate সাধারণত GRN-এর original rate থেকে আসে — বদলালে সাপ্লায়ারকে জানাতে হবে।',
    ],

    'related' => ['purchasing.purchase-receives', 'purchasing.purchase-returns-slip', 'purchasing.purchase-returns-audit', 'master-data.suppliers', 'accounting.supplier-transactions'],

    // No diagram here — the procure-to-pay diagram lives on purchase-orders (the start of the cycle).

    'updated_at' => '2026-08-07',
];
