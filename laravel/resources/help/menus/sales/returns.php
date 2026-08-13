<?php

/**
 * Help content for: sales.returns
 * Route: admin.sales-returns.index (and create/show/edit via wildcard)
 *
 * The Sales Return page — where customers return goods they bought. A return
 * reverses the original invoice: stock goes back up, the customer's receivable
 * drops, and a credit note is generated. Posting is irreversible.
 *
 * Two-step workflow: Step 1 (Receive from customer) → Step 2 (Warehouse confirm)
 *
 * Enhanced with detailed Bangla documentation covering the full return lifecycle.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.returns',
    'module'     => 'sales',
    'title_bn'   => 'সেলস রিটার্ন — ফেরত পণ্য গ্রহণ',
    'title_en'   => 'Sales Returns — Receiving Returned Goods',
    'icon'       => 'fa-rotate-left',
    'summary'    => 'খদ্দের ফেরত দেওয়া পণ্য গ্রহণ করে ইনভয়েসের বিপরীত হিসাব করা হয়। রিটার্ন দুই ধাপে কাজ করে: ধাপ ১ — খদ্দের থেকে পণ্য গ্রহণ (রিটার্ন তৈরি, স্ট্যাটাস: Pending, কোনো GL বা স্টক চেঞ্জ নেই); ধাপ ২ — গোডাউন কনফার্ম (স্টক IN, GL পোস্ট, ক্রেডিট নোট, ক্ষতি লিংক)। রিভার্স করলে সব ফিরিয়ে আনা যায় — তবে স্টক পর্যাপ্ত থাকতে হবে।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin', 'warehouse_manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-file-circle-minus',    'text' => 'রিটার্ন তৈরি (ধাপ ১) — ইনভয়েস সার্চ করে বের করুন, কোন পণ্য, কয়টা ফেরত — তা বাছাই করুন। অফক্যানভাস থেকে সরাসরি তৈরি করা যায়, তালিকা ছাড়তে হয় না।'],
        ['icon' => 'fa-box-open',             'text' => 'গোডাউন কনফার্ম (ধাপ ২) — পণ্য পরীক্ষা করুন, প্রতিটি লাইনে Good/Damage চিহ্নিত করুন, গ্রহণের গোডাউন বাছাই করুন। কনফার্ম করলে স্টক IN, GL পোস্ট, ক্রেডিট নোট তৈরি হয়।'],
        ['icon' => 'fa-filter',               'text' => 'ফিল্টার চিপস — All / Pending / Confirmed / Reversed। পেন্ডিং ব্যাজ: "X awaiting warehouse confirm" দেখিয়ে কোন কোনটা গোডাউনে কনফার্ম হতে বাকি।'],
        ['icon' => 'fa-eye',                  'text' => 'GL ডিটেইল — রিটার্নের জার্নাল ব্লক দেখুন: Dr Sales Return / Cr AR + Dr Inventory / Cr COGS।'],
        ['icon' => 'fa-rotate-right',         'text' => 'রিভার্স প্রিভিউ — রিটার্ন বাতিলের আগে কী কী বদলাবে তার প্রিভিউ দেখুন। স্টক পর্যাপ্ত না থাকলে রিভার্স ব্লক হয়, কারণ দেখায়।'],
        ['icon' => 'fa-rotate-right',         'text' => 'রিভার্স কনফার্ম — রিটার্ন বাতিল করলে স্টক রিভার্স, GL রিভার্স, খদ্দেরের বকেয়া ফিরিয়ে আসে। কারণ (reason) লিখতে হবে।'],
        ['icon' => 'fa-print',                'text' => 'রিটার্ন স্লিপ প্রিন্ট — খদ্দেরকে দেওয়ার জন্য রিটার্ন স্লিপ প্রিন্ট করুন।'],
        ['icon' => 'fa-list-check',           'text' => 'অডিট ট্রেইল — সব রিটার্ন ইভেন্ট (তৈরি/কনফার্ম/রিভার্স) অডিট লগে দেখুন।'],
        ['icon' => 'fa-file-export',          'text' => 'এক্সপোর্ট — রিটার্ন ডেটা CSV তে এক্সপোর্ট করুন।'],
    ],

    'impacts' => [
        ['who' => 'স্টক',        'what' => 'গোডাউন কনফার্মে (ধাপ ২) পণ্য ঐ গোডাউনে ফিরে আসে (original avg_cost দিয়ে); রিভার্সে স্টক আবার কমে'],
        ['who' => 'খদ্দের',      'what' => 'কনফার্মে বকেয়া (AR) কমে; রিভার্সে বকেয়া ফিরিয়ে আসে'],
        ['who' => 'হিসাব',       'what' => 'কনফার্মে: Dr Sales Return / Cr AR + Dr Inventory / Cr COGS; রিভার্সে: উল্টো জার্নাল'],
        ['who' => 'কমিশন',      'what' => 'কনফার্মে সেলসম্যানের কমিশন হিসাব উল্টে যায়'],
        ['who' => 'ক্ষতি',       'what' => 'ফেরত পণ্য Damage হলে অটো-লিংকড ড্যামেজ রাইট-অফ তৈরি হয়'],
        ['who' => 'অডিট',       'what' => 'তৈরি/কনফার্ম/রিভার্স — প্রতিটি ইভেন্ট SalesAuditLogger দিয়ে লগ হয়'],
    ],

    'cautions' => [
        'রিটার্ন একবার গোডাউন কনফার্ম (ধাপ ২) হলে আর বদলানো যায় না — GL পোস্ট হয়ে যায়। ভুল ধরতে "রিভার্স" করতে হবে।',
        'ইনভয়েস ছাড়া রিটার্ন দেওয়া যায় না — প্রতিটি রিটার্ন একটি নির্দিষ্ট ইনভয়েসের সাথে যুক্ত থাকে।',
        'রিভার্স করতে গিয়ে স্টক পর্যাপ্ত না থাকলে (ঐ পণ্য অন্যকোথাও বিক্রি হয়ে গেছে) রিভার্স ব্লক হবে।',
        'ফেরত পণ্য গোডাউনে আসলে ফেরত-যোগ্য অবস্থায় আছে কিনা যাচাই করুন — Damage চিহ্নিত করলে ক্ষতি লিংক তৈরি হয়।',
        'পেন্ডিং রিটার্ন তৈরি হলে কোনো স্টক বা GL চেঞ্জ হয় না — শুধু গোডাউন কনফার্মে হয়। তাই পেন্ডিং রিটার্ন দ্রুত কনফার্ম করুন।',
    ],

    'related' => ['sales.invoices', 'sales.challans', 'sales.customer-payments', 'sales.returns-audit', 'inventory.damages', 'master-data.customers'],

    // No diagram here — the reverse-preview sub-page and the invoice-flow diagram
    // on sales.invoices already cover the visual story.

    'updated_at' => '2026-08-13',
];
