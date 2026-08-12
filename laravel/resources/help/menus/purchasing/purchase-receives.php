<?php

/**
 * Help content for: purchasing.purchase-receives
 * Route: admin.purchase-receives.index (and create/show/confirm/cancel via wildcard)
 *
 * The Purchase Receive (GRN) page — where goods actually arrive from the
 * supplier and are booked into the godown. Two-phase: create draft → confirm.
 *
 * The CONFIRM step is the atomic moment that:
 *   1. Posts STOCK IN (StockService, reference_type='purchase_receive')
 *      — recalculates warehouse_stock.qty + avg_cost (IN rule)
 *   2. Posts GL (JournalPostingService): Dr Inventory / Cr Accounts Payable
 *      — stores journal_entry_id on the GRN row
 *   3. Posts supplier_ledger CREDIT (SubLedgerService) — we owe the supplier more
 *   4. Updates purchase_order_items.received_qty (auto-flips PO: partial/received)
 *   5. Stamps confirmed_by/at on the GRN (G-039 fast lookup)
 *
 * Can be against a PO (sent/partial) or DIRECT (no PO). A direct GRN
 * inherits supplier + branch + warehouse from the form (no PO linkage).
 *
 * Over-receive guard (G-038): received_qty > ordered_qty + tolerance → throws.
 * Tolerance from config('purchase.over_receive_tolerance', 0.0001).
 *
 * Net-of-discount rate (G-117): header discount + tax pro-rated to each line
 * so Σ(netRate × qty) = total_amount exactly.
 *
 * Cancel rule (BUG-5): cannot cancel a confirmed GRN if it has active
 * (non-reversed) purchase returns — those returns depend on this GRN's stock.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 * @see app/Services/Purchase/PurchaseReceiveService.php
 */

return [
    'key'        => 'purchasing.purchase-receives',
    'module'     => 'purchasing',
    'title_bn'   => 'পি. রিসিভ (GRN)',
    'title_en'   => 'P. Receive (GRN)',
    'icon'       => 'fa-truck-ramp-box',
    'summary'    => 'সাপ্লায়ারের মাল গোডাউনে ঢুকলে এখানে রিসিভ হয়। ড্রাফট তৈরি করে কনফার্ম করলেই স্টক বাড়ে, সাপ্লায়ার পেয়েবল তৈরি হয়, আর GL-এ ডাবল-এন্ট্রি পড়ে। পিও থেকে বা সরাসরি (ডিরেক্ট) রিসিভ করা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'warehouse_manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-truck-ramp-box',  'text' => 'পিওর বিপরীতে মাল রিসিভ করা (GRN তৈরি) — অথবা সরাসরি ডিরেক্ট রিসিভ (পিও ছাড়া)'],
        ['icon' => 'fa-check-double',    'text' => 'কোয়ান্টিটি ও কোয়ালিটি যাচাই করে ড্রাফট সেভ করা'],
        ['icon' => 'fa-circle-check',     'text' => 'কনফার্ম করা — এই ধাপে স্টক বাড়ে, পেয়েবল বাড়ে, GL-এ ড্র ইনভেন্টরি/ক্রেডিট পেয়েবল পড়ে'],
        ['icon' => 'fa-layer-group',     'text' => 'পার্শিয়াল রিসিভ করা (PO-এর অংশ মাত্র) — বাকি মাল পরে আসলে আরেকটা GRN করা যায়'],
        ['icon' => 'fa-warehouse',       'text' => 'প্রতিটি লাইনে আলাদা গোডাউন সেট করা (মাল কোন গোডাউনে ঢুকবে তা নির্দিষ্ট)'],
        ['icon' => 'fa-magnifying-glass', 'text' => 'পিও সিলেক্ট করলে আইটেম + remaining_qty অটো-ফিল হয় (AJAX po-details)'],
        ['icon' => 'fa-file-export',     'text' => 'GRN তালিকা CSV-তে এক্সপোর্ট করা'],
        ['icon' => 'fa-eye',             'text' => 'GRN ডিটেইলস দেখা — স্টক মুভমেন্ট, সাপ্লায়ার লেজার, এই GRN-এর বিপরীতে রিটার্ন'],
        ['icon' => 'fa-ban',             'text' => 'ভুল গ্রহণ বাতিল করা (কনফার্মড হলে স্টক+GL+পেয়েবল রিভার্স হয় — তবে অ্যাকটিভ রিটার্ন থাকলে নিষেধ)],
    ],

    'impacts' => [
        ['who' => 'স্টক',           'what' => 'কনফার্মে পণ্য বাড়ে (inventory in) — গোডাউন avg_cost রিক্যালকুলেট হয়'],
        ['who' => 'সাপ্লায়ার পেয়েবল', 'what' => 'কনফার্মে পেয়েবল বাড়ে (credit entry) — এই টাকা দিতে হবে'],
        ['who' => 'হিসাব (GL)',      'what' => 'ড্র ইনভেন্টরি / ক্রেডিট পেয়েবল — journal_entry_id GRN-এ সেভ থাকে'],
        ['who' => 'পি. অর্ডার',       'what' => 'received_qty আপডেট হয় — সব আইটেম পূরণ হলে PO স্ট্যাটাস "received", অংশ হলে "partial"'],
        ['who' => 'অডিট',           'what' => 'রিসিভ লগ user_audit_log-এ লেখা পড়ে (purchase_receive_created/confirmed/cancelled)'],
    ],

    'cautions' => [
        'ড্রাফট সেভ করলে কিছু বদলায় না — শুধু কনফার্ম করলেই স্টক+GL+পেয়েবল বদলায়। ভুল হলে ড্রাফট এডিট করুন, কনফার্ম করবেন না।',
        'কনফার্ম করলেই সাপ্লায়ার পেয়েবল বাড়ে — ভুল কনফার্ম সরাসরি হিসাবে আঘাত দেয়; সাবধানে যাচাই করে কনফার্ম করুন।',
        'পিও-এর চেয়ে বেশি কোয়ান্টিটি রিসিভ করলে সিস্টেম ফ্ল্যাগ করে (over-receive guard G-038); ওভার-রিসিভ অনুমোদিত না হলে এড়িয়ে চলুন।',
        'কনফার্মড GRN-এর বিপরীতে অ্যাকটিভ (রিভার্স না হওয়া) রিটার্ন থাকলে বাতিল করা যায় না (BUG-5 গার্ড) — আগে রিটার্ন বাতিল করুন।',
        'কনফার্ম ও বাতিল শুধু admin/manager করতে পারে — warehouse_manager ড্রাফট তৈরি করতে পারে কিন্তু কনফার্ম করতে না (ডিস্ট্রাকটিভ অপারেশন)।',
        'খুটি বা ক্ষতিগ্রস্ত মাল রিসিভ করলে পরে আলাদা রিটার্ন (Damage কন্ডিশন) দিতে হবে — রিসিভটি বাতিল করবেন না।',
        'রেট হেডার ডিসকাউন্ট/ট্যাক্স প্রো-রেটা করে প্রতি লাইনে ভাগ হয় (G-117) — তাই Σ(line amount) = total_amount হয়।',
    ],

    'related' => ['purchasing.purchase-orders', 'purchasing.purchase-returns', 'purchasing.purchase-receives-audit', 'master-data.suppliers', 'inventory.stock-transactions', 'accounting.supplier-transactions'],

    // No diagram here — the procure-to-pay diagram lives on purchase-orders (the start of the cycle).

    'updated_at' => '2026-08-07',
];
