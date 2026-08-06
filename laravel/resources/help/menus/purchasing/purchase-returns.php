<?php

/**
 * Help content for: purchasing.purchase-returns
 * Route: admin.purchase-returns.index (and create/show/edit via wildcard)
 *
 * The Purchase Return page — where damaged, wrong, or excess goods are sent
 * back to the supplier. A return generates a debit note that reduces the
 * supplier payable and decreases stock from the godown.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 */

return [
    'key'        => 'purchasing.purchase-returns',
    'module'     => 'purchasing',
    'title_bn'   => 'পি. রিটার্ন',
    'title_en'   => 'P. Return',
    'icon'       => 'fa-rotate-left',
    'summary'    => 'সাপ্লায়ারকে মাল ফেরত দিলে এখানে রিটার্ন হয়; স্টক কমে আর সাপ্লায়ার পেয়েবল কমে (ডেবিট নোট)।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-rotate-left',      'text' => 'নতুন রিটার্ন তৈরি করা (রিসিভ/GRN বেছে)'],
        ['icon' => 'fa-comment-dots',     'text' => 'রিটার্নের কারণ লেখা (ক্ষতি / ভুল মাল / অতিরিক্ত)'],
        ['icon' => 'fa-list-ol',          'text' => 'ফেরত কোয়ান্টিটি ও রেট সেট করা'],
        ['icon' => 'fa-file-invoice',     'text' => 'ডেবিট নোট তৈরি করা (সাপ্লায়ার পেয়েবল কমানোর দলিল)'],
        ['icon' => 'fa-receipt',          'text' => 'রিটার্ন স্লিপ প্রিন্ট করে সাপ্লায়ারকে পাঠানো'],
    ],

    'impacts' => [
        ['who' => 'স্টক',        'what' => 'পণ্য কমে যায় (godown থেকে বের হয়)'],
        ['who' => 'সাপ্লায়ার',  'what' => 'পেয়েবল কমে (ডেবিট নোট ক্রেডিট করে)'],
        ['who' => 'হিসাব',       'what' => 'ইনভেন্টরি + পেয়েবল লেজারে ডাবল-এন্ট্রি পড়ে'],
        ['who' => 'অডিট',        'what' => 'রিটার্ন লগ অডিট ট্রেইলে যুক্ত হয়'],
    ],

    'cautions' => [
        'রিটার্নের কারণ ছাড়া সেভ করা যায় না — কারণ ছাড়া অডিটে বিপদ।',
        'রিটার্ন সরাসরি স্টক কমায় ও সাপ্লায়ার পেয়েবল কমায়; ডেবিট নোটটি সাপ্লায়ারকে জানান।',
        'মূল রিসিভের চেয়ে বেশি কোয়ান্টিটি রিটার্ন করা যায় না।',
    ],

    'related' => ['purchasing.purchase-receives', 'purchasing.purchase-returns-slip', 'purchasing.purchase-returns-audit', 'master-data.suppliers', 'accounting.supplier-transactions'],

    // No diagram here — the procure-to-pay diagram lives on purchase-orders (the start of the cycle).

    'updated_at' => '2026-08-07',
];
