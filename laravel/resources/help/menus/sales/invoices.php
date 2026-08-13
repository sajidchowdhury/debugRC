<?php

/**
 * Help content for: sales.invoices
 * Route: admin.sales-invoices.index (and create/show/edit via wildcard)
 *
 * The Sales Invoice page — the heart of the sales cycle. Every sale the business
 * makes becomes an invoice here. From this one document, stock decreases,
 * customer receivable increases, VAT is recorded, and income is recognised.
 *
 * Enhanced with detailed Bangla documentation covering the full invoice lifecycle.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram)
 */

return [
    'key'        => 'sales.invoices',
    'module'     => 'sales',
    'title_bn'   => 'সেলস ইনভয়েস — বিক্রির হৃদপিণ্ড',
    'title_en'   => 'Sales Invoices — The Heart of Every Sale',
    'icon'       => 'fa-file-invoice-dollar',
    'summary'    => 'খদ্দেরকে পণ্য বিক্রি করে যে বিল তৈরি হয়, এটি সেই বিল। একটা ইনভয়েস থেকেই খদ্দেরের বকেয়া বাড়ে, আয় লেখা হয়, ভ্যাট হিসাব হয়, সেলসম্যানের কমিশন শুরু হয়। এখানে আজকের সব ইনভয়েস দেখা যায়, ফিল্টার করা যায়, পেমেন্ট নেওয়া যায়, প্রিন্ট করা যায়। ড্রাফট ইনভয়েস এডিট করা যায়, ফাইনাল ইনভয়েস এডিট করা যায় না — ভুল ধরতে রিটার্ন দিতে হবে।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',                 'text' => 'নতুন ইনভয়েস — "New Sale" বাটনে ক্লিক করলে সেলস কার্টে যায়, সেখান থেকে ইনভয়েস ফাইনাল করুন।'],
        ['icon' => 'fa-list',                 'text' => 'ইনভয়েস তালিকা দেখা — আজকের সব ইনভয়েস এক নজরে। স্মার্ট সার্চ: ইনভয়েস নম্বর, খদ্দের নাম, মোবাইল, ব্র্যাঞ্চ, সেলসম্যান, পণ্য নাম দিয়ে খুঁজুন।'],
        ['icon' => 'fa-filter',               'text' => 'ফিল্টার চিপস — All / Awaiting Payment / In Progress / Draft / Godown Issued / Challan Done। দ্রুত তারিখ: আজ / গতকাল / গত ৭ দিন / এ মাস / কাস্টম রেঞ্জ।'],
        ['icon' => 'fa-pen-to-square',        'text' => 'ড্রাফট এডিট — শুধু ড্রাফট স্ট্যাটাসের ইনভয়েস এডিট করা যায়। এডিটে খদ্দের লক থাকে (বদলানো যায় না), পণ্য যোগ/বাদ দেওয়া যায়। সেভ করলে পুরোনো জার্নাল রিভার্স + নতুন জার্নাল পোস্ট হয়।'],
        ['icon' => 'fa-circle-check',         'text' => 'পেমেন্ট গ্রহণ — "Receive" বাটনে মোডাল খুলে টাকা জমা লেখুন (Cash/Bank/Bkash)। পেমেন্ট হলে Dr Cash/Bank / Cr AR জার্নাল তৈরি হয়।'],
        ['icon' => 'fa-flag-checkered',       'text' => 'কল-ইট-আ-ডে — দিনের কাজ শেষে একাধিক ইনভয়েস "Done" মার্ক করুন (শুধু UI সুবিধা, কোনো GL ইমপ্যাক্ট নেই)।'],
        ['icon' => 'fa-print',                'text' => 'ইনভয়েস প্রিন্ট — খদ্দেরের কাছে দেওয়ার জন্য প্রিন্ট কপি। ১৭ আইটেম/পেজ প্যাজিনেশন।'],
        ['icon' => 'fa-eye',                  'text' => 'GL ডিটেইল দেখা — ইনভয়েসের জার্নাল ব্লক (Dr AR / Cr Revenue / Cr Transport Revenue), চালানের COGS JE, পেমেন্টের JE লিংক।'],
        ['icon' => 'fa-file-export',          'text' => 'CSV এক্সপোর্ট — ইনভয়েস ডেটা এক্সেলে নামিয়ে বিশ্লেষণ করুন।'],
        ['icon' => 'fa-ban',                  'text' => 'ড্রাফট বাতিল — শুধু ড্রাফট ইনভয়েস মুছে ফেলা যায়। ফাইনাল ইনভয়েস বাতিল করা যায় না — রিটার্ন দিন।'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের',      'what' => 'ইনভয়েস তৈরি হলে বকেয়া (Accounts Receivable) বাড়ে; পেমেন্ট পেলে বকেয়া কমে'],
        ['who' => 'স্টক',        'what' => 'ইনভয়েসে পণ্য রিজার্ভ হয়; চালান ইস্যু হলে গোডাউন থেকে স্টক কমে'],
        ['who' => 'হিসাব',       'what' => 'Dr AR / Cr Sales Revenue + Cr Transport Revenue জার্নাল; পেমেন্টে Dr Cash / Cr AR'],
        ['who' => 'কমিশন',      'what' => 'সেলসম্যানের কমিশন রুল অনুযায়ী হিসাব শুরু হয়'],
        ['who' => 'অডিট',       'what' => 'ইনভয়েস তৈরি/এডিট/বাতিল/পেমেন্ট — সব ইভেন্ট SalesAuditLogger দিয়ে লগ হয়'],
    ],

    'cautions' => [
        'ফাইনাল ইনভয়েস সরাসরি এডিট করা যায় না — শুধু ড্রাফট এডিট করা যায়। ফাইনালে ভুল ধরতে সেলস রিটার্ন দিন।',
        'পর্যাপ্ত স্টক না থাকলে ইনভয়েস তৈরি হবে না — কার্ট ভ্যালিডেশনে স্টক চেক হয়।',
        'পেমেন্ট না পেলেও ইনভয়েস ফাইনাল হয়ে যায় — খদ্দেরের বকেয়া আলাদাভাবে ট্র্যাক করুন।',
        'ড্রাফট এডিটে খদ্দের বদলানো যায় না (লক)। ব্র্যাঞ্চ ওভাররাইড পারমিশন ছাড়া ব্র্যাঞ্চও লক।',
        'ড্রাফট এডিট সেভ করলে পুরোনো GL জার্নাল রিভার্স + নতুন GL জার্নাল পোস্ট হয় — সংখ্যা মিলিয়ে দেখুন।',
        '"কল-ইট-আ-ডে" শুধু UI মার্ক — কোনো হিসাব বা স্টকে ইমপ্যাক্ট পড়ে না।',
    ],

    'related' => ['sales.cart', 'sales.challans', 'sales.returns', 'sales.customer-payments', 'sales.invoices-audit', 'master-data.customers'],

    'diagram' => 'sales-invoice-flow',

    'updated_at' => '2026-08-13',
];
