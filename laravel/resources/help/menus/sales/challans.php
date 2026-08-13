<?php

/**
 * Help content for: sales.challans
 * Route: admin.sales-challans.index (and create/show/edit via wildcard)
 *
 * The Sales Challan page — the godown delivery note. When goods leave the
 * warehouse for delivery to a customer, a challan is generated from the
 * invoice. The challan is NOT a new sale — it just moves stock out of the
 * godown into transit, then to delivered.
 *
 * Three-step workflow: Invoice (done) → Godown (assign warehouse+transport) → Challan (stock OUT + COGS GL)
 *
 * Enhanced with detailed Bangla documentation covering the full challan lifecycle.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.challans',
    'module'     => 'sales',
    'title_bn'   => 'গোডাউন ও চালান — ডেলিভারির দলিল',
    'title_en'   => 'Godown & Challan — Delivery Documents',
    'icon'       => 'fa-truck',
    'summary'    => 'গোডাউন থেকে মাল বের হলে চালান তৈরি হয় — এটি নতুন বিক্রি নয়, শুধু ডেলিভারির দলিল। পুরো প্রক্রিয়া তিন ধাপে: ধাপ ১ — ইনভয়েস তৈরি (আগেই হয়ে গেছে); ধাপ ২ — গোডাউন প্রিপারেশন (কোন গোডাউন থেকে মাল উঠবে, ট্রান্সপোর্ট খরচ, ডিসপ্যাচার বাছাই); ধাপ ৩ — চালান ফাইনালাইজ (স্টক OUT + COGS GL + ট্রান্সপোর্ট অ্যাডজাস্টমেন্ট GL)।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin', 'dispatcher', 'warehouse_manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-list',                  'text' => 'চালান কিউ তালিকা — কোন ইনভয়েসগুলো গোডাউন চালান চাই, কোনগুলো পেন্ডিং, কোনগুলো ক্লোজড — সব এক নজরে। ব্যাজ: "X need warehouse action" / "X ready for challan"।'],
        ['icon' => 'fa-filter',                'text' => 'ওয়ার্কফ্লো ফিল্টার — Needs Warehouse / Pending Godown / Godown Saved / Challan Completed / Reversed।'],
        ['icon' => 'fa-warehouse',             'text' => 'গোডাউন প্রিপারেশন (ধাপ ২) — প্রতিটি পণ্যের জন্য কোন গোডাউন থেকে মাল উঠবে তা বাছাই করুন। CTN (কার্টন সংখ্যা) ও কন্ডিশন ফিল করুন। সেভ করলে গোডাউন লক হয়।'],
        ['icon' => 'fa-truck-ramp-box',        'text' => 'ট্রান্সপোর্ট ও ডিসপ্যাচার — ট্রান্সপোর্ট খরচ এডিট করুন (GL ডিফার্ড — চালান ইস্যুতে অ্যাডজাস্টমেন্ট পোস্ট হয়)। ডিসপ্যাচার মাল্টি-সিলেক্ট করুন।'],
        ['icon' => 'fa-file-circle-check',     'text' => 'চালান ফাইনালাইজ (ধাপ ৩) — "Finalize Challan" বাটনে চাপলে: স্টক কমে, COGS GL পোস্ট হয় (Dr COGS / Cr Inventory), ট্রান্সপোর্ট অ্যাডজাস্টমেন্ট GL, টেলিগ্রাম নোটিফিকেশন যায়।'],
        ['icon' => 'fa-print',                 'text' => 'চালান কপি প্রিন্ট — গাড়িওয়ালাকে দেওয়ার জন্য চালান কপি প্রিন্ট করুন।'],
        ['icon' => 'fa-print',                 'text' => 'গোডাউন কপি প্রিন্ট — গোডাউন ইন-চার্জের কাছে দেওয়ার জন্য গোডাউন কপি।'],
        ['icon' => 'fa-file-lines',            'text' => 'ব্লাঙ্ক গোডাউন ফর্ম — হাতে পূরণের জন্য খালি গোডাউন ফর্ম প্রিন্ট করুন।'],
        ['icon' => 'fa-eye',                   'text' => 'GL ডিটেইল — চালানের কোড, ইনভয়েস, খদ্দের, COGS জার্নাল, ট্রান্সপোর্ট অ্যাডজাস্টমেন্ট দেখুন।'],
        ['icon' => 'fa-rotate-right',          'text' => 'চালান রিভার্স — ভুল চালান বাতিল করলে স্টক ফিরিয়ে আসে, COGS GL রিভার্স, ট্রান্সপোর্ট GL রিভার্স।'],
        ['icon' => 'fa-file-export',           'text' => 'CSV এক্সপোর্ট — চালান ডেটা এক্সেলে নামিয়ে বিশ্লেষণ করুন।'],
    ],

    'impacts' => [
        ['who' => 'স্টক',         'what' => 'চালান ফাইনালাইজে গোডাউন থেকে স্টক কমে (inventory out); রিভার্সে ফিরিয়ে আসে'],
        ['who' => 'হিসাব',       'what' => 'চালানে: Dr COGS / Cr Inventory; ট্রান্সপোর্ট অ্যাডজাস্টমেন্ট GL; রিভার্সে: উল্টো জার্নাল'],
        ['who' => 'ইনভয়েস',     'what' => 'ইনভয়েসের ডেলিভারি স্টক হিসাব চালানে কনফার্ম হয়'],
        ['who' => 'ডিসপ্যাচার',  'what' => 'ডিসপ্যাচার অ্যাসাইনমেন্ট এখানে হয়'],
        ['who' => 'নোটিফিকেশন',  'what' => 'চালান ইস্যুতে টেলিগ্রাম নোটিফিকেশন যায়'],
        ['who' => 'অডিট',       'what' => 'গোডাউন সেভ/চালান ইস্যু/রিভার্স — সব ইভেন্ট SalesAuditLogger দিয়ে লগ হয়'],
    ],

    'cautions' => [
        'চালান নতুন বিক্রি নয় — ইনভয়েস ছাড়া চালান দেওয়া যায় না। প্রতিটি চালান একটি নির্দিষ্ট ইনভয়েসের সাথে যুক্ত।',
        'গোডাউন একবার সেভ হলে গোডাউন ও ডিসপ্যাচার লক হয়ে যায় — পরে বদলানো যায় না।',
        'চালান ফাইনালাইজ হলেই গোডাউন স্টক কমে যায় — ডেলিভারি না হলেও স্টক আটকে থাকে। রিভার্স না করলে ফিরবে না।',
        'ট্রান্সপোর্ট খরচ গোডাউন ধাপে এডিট করা হলেও GL তে প্রতিফলন হয় চালান ফাইনালাইজে — ডিফার্ড GL।',
        'চালান রিভার্স করলে স্টক ফিরিয়ে আসে কিন্তু ইনভয়েস বাতিল হয় না — শুধু ডেলিভারি বাতিল।',
    ],

    'related' => ['sales.invoices', 'sales.cart', 'sales.returns', 'master-data.warehouses', 'inventory.stock-transactions', 'sales.challans-godown'],

    // No diagram — the sales-invoice-flow diagram on sales.invoices already
    // pictures cart -> invoice -> challan -> delivery -> payment.

    'updated_at' => '2026-08-13',
];
