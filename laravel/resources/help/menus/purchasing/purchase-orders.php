<?php

/**
 * Help content for: purchasing.purchase-orders
 * Route: admin.purchase-orders.index (and create/show/edit/submit/approve/reject/cancel via wildcard)
 *
 * The Purchase Order page — where every order placed on a supplier is raised,
 * costed, approved, and dispatched. The PO is a COMMITMENT to buy: it locks
 * rate + qty against a supplier, but stock and supplier payable only move
 * when goods are actually received (GRN confirm).
 *
 * PO status state machine (8 states):
 *   draft → submitted → approved → sent → partial → received → cancelled
 *                       ↓
 *                   rejected → (edit + resubmit)
 *
 * Approval workflow (maker-checker, PURCHASING-API-2 / G-116):
 *   - Threshold: 50,000 BDT (configurable at /admin/approvals/workflows)
 *   - Total < threshold  → auto-approved (stays draft, stamped approved_by/at)
 *   - Total ≥ threshold  → enters 'submitted' state, appears in Approval Queue
 *                           (/admin/approvals) for a MANAGER (not the submitter)
 *                           to approve or reject.
 *
 * GRN gate: canReceive() = isSent() || isPartial(). A PO must be marked
 * "Sent" (not just approved) before a GRN can be raised against it. This
 * separates "internally approved" from "actually dispatched to supplier".
 *
 * GL impact: NONE at any state. The purchase_orders table has NO
 * journal_entry_id column. The PO is a planning document only.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 * @see app/Services/Purchase/PurchaseOrderService.php
 */

return [
    'key'        => 'purchasing.purchase-orders',
    'module'     => 'purchasing',
    'title_bn'   => 'পারচেজ অর্ডার — কেনার অঙ্গীকার',
    'title_en'   => 'Purchase Orders — The Buying Commitment',
    'icon'       => 'fa-file-signature',
    'summary'    => 'সাপ্লায়ারকে মাল কেনার অর্ডার তৈরি, অ্যাপ্রুভ ও সাপ্লায়ারের কাছে পাঠানো হয় এখানে। পিও শুধু কেনার অঙ্গীকার — দর ও পরিমাণ লক হয় কিন্তু মাল রিসিভ (GRN) না হলে স্টক বা সাপ্লায়ার পেয়েবল বাড়ে না। অ্যাপ্রুভাল ওয়ার্কফ্লো: ৫০,০০০ টাকার কম অটো-অ্যাপ্রুভ, বেশি হলে ম্যানেজারের কিউতে যায় (মেকার-চেকার)। রিজেক্ট হলে এডিট করে আবার সাবমিট করা যায়। পিও-এর কোনো GL ইমপ্যাক্ট নেই — এটি প্ল্যানিং ডকুমেন্ট।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'warehouse_manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',                'text' => 'নতুন পিও তৈরি (সাপ্লায়ার + ব্র্যাঞ্চ + গোডাউন বাছাই, পণ্য যোগ, কোয়ান্টিটি/রেট/ডিসকাউন্ট/ট্যাক্স সহ)'],
        ['icon' => 'fa-paper-plane',         'text' => 'অ্যাপ্রুভালের জন্য সাবমিট করা (৫০,০০০+ টাকার পিও ম্যানেজারের কিউতে যায়; কম হলে অটো-অ্যাপ্রুভ)'],
        ['icon' => 'fa-circle-check',        'text' => 'ম্যানেজার পিও অ্যাপ্রুভ বা রিজেক্ট করে (রিজেক্ট হলে এডিট করে আবার সাবমিট করা যায়)'],
        ['icon' => 'fa-truck',               'text' => 'অ্যাপ্রুভ হলে "Mark Sent" করা — এটাই সাপ্লায়ারের কাছে অর্ডার পাঠানোর ধাপ'],
        ['icon' => 'fa-magnifying-glass',    'text' => 'পণ্য খুঁজে বের করা (AJAX টাইপহিন্ট দিয়ে SKU/নাম লিখলে সাজেস্ট আসে)'],
        ['icon' => 'fa-file-export',         'text' => 'পিও তালিকা CSV-তে এক্সপোর্ট করা (তারিখ/সাপ্লায়ার/স্ট্যাটাস ফিল্টার সহ)'],
        ['icon' => 'fa-eye',                 'text' => 'পিও ডিটেইলস দেখা — আইটেম, সাপ্লায়ার, এই পিওর বিপরীতে কতটা রিসিভ হয়েছে'],
        ['icon' => 'fa-ban',                 'text' => 'ভুল পিও বাতিল করা (ড্রাফট/সাবমিটেড/অ্যাপ্রুভড/সেন্ট — রিসিভ হওয়ার আগেই)'],
    ],

    'impacts' => [
        ['who' => 'সাপ্লায়ার',     'what' => '"Sent" হলে তাকে মাল সরবরাহের অর্ডার পৌঁছায় (commitment)'],
        ['who' => 'স্টক',           'what' => 'পিওতে কোনো স্টক মুভমেন্ট নেই — শুধু GRN কনফার্ম হলে স্টক বাড়ে'],
        ['who' => 'সাপ্লায়ার পেয়েবল', 'what' => 'পিওতে পেয়েবল বাড়ে না — শুধু GRN কনফার্ম হলে পেয়েবল তৈরি হয়'],
        ['who' => 'হিসাব (GL)',      'what' => 'পিওর কোনো GL এন্ট্রি নেই — পিও লেজারে কমিটমেন্ট হিসেবে রেকর্ডও থাকে না'],
        ['who' => 'অ্যাপ্রুভাল কিউ',  'what' => '৫০,০০০+ পিও /admin/approvals-এ ম্যানেজারের অ্যাপ্রুভালে অপেক্ষা করে'],
    ],

    'cautions' => [
        'পিও শুধু কেনার অঙ্গীকার — তৈরি বা অ্যাপ্রুভ করলেই স্টক বা পেয়েবল বাড়ে না; শুধু GRN কনফার্ম হলে বাড়ে।',
        'রিসিভ করতে হলে পিও অবশ্যই "Sent" স্ট্যাটাসে থাকতে হবে (শুধু অ্যাপ্রুভড হলে হবে না) — প্রথমে অ্যাপ্রুভ করে Mark Sent করুন।',
        '৫০,০০০ টাকার বেশি পিও সাবমিট করলে ম্যানেজারের অ্যাপ্রুভাল ছাড়া "Sent" করা যায় না; কম হলে অটো-অ্যাপ্রুভ।',
        'রিজেক্ট হওয়া পিও এডিট করে আবার সাবমিট করা যায় — নতুন করে তৈরি করতে হয় না।',
        'পিও বাতিল করলে সাথে থাকা পেন্ডিং অ্যাপ্রুভাল রিকোয়েস্টও বাতিল হয়ে যায় (অ্যাপ্রুভারের কিউ থেকে সরে)।',
        'রেট বা কোয়ান্টিটি অ্যাপ্রুভের পর বদলালে সাপ্লায়ারকে আলাদাভাবে জানাতে হবে — সিস্টেম সাপ্লায়ারকে মেসেজ পাঠায় না।',
        'একই পণ্য একাধিক লাইনে থাকলে রিসিভ করা নির্দিষ্ট লাইনে অ্যাট্রিবিউট হয় (G-037 ফিক্স) — ভুল লাইনে যায় না।',
    ],

    'related' => ['purchasing.purchase-receives', 'purchasing.purchase-orders-audit', 'accounting.approvals', 'master-data.suppliers', 'master-data.products', 'accounting.supplier-transactions'],

    'diagram' => 'procure-to-pay',

    'updated_at' => '2026-08-13',
];
