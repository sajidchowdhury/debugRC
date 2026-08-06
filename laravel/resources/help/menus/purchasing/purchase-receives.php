<?php

/**
 * Help content for: purchasing.purchase-receives
 * Route: admin.purchase-receives.index (and create/show/edit via wildcard)
 *
 * The Purchase Receive page — where goods actually arrive from the supplier
 * and are booked into the godown. One receive against a PO generates the GRN,
 * increases stock, increases supplier payable, and posts to the GL.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (quality bar)
 */

return [
    'key'        => 'purchasing.purchase-receives',
    'module'     => 'purchasing',
    'title_bn'   => 'পি. রিসিভ',
    'title_en'   => 'P. Receive',
    'icon'       => 'fa-truck-ramp-box',
    'summary'    => 'সাপ্লায়ারের মাল গোডাউনে ঢুকলে এখানে রিসিভ হয়; স্টক বাড়ে আর সাপ্লায়ার পেয়েবল তৈরি হয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-truck-ramp-box', 'text' => 'পিওর বিপরীতে মাল রিসিভ করা (GRN তৈরি)'],
        ['icon' => 'fa-check-double',   'text' => 'কোয়ান্টিটি ও কোয়ালিটি যাচাই করা'],
        ['icon' => 'fa-warehouse',      'text' => 'মাল গোডাউনে পুট-অ্যাওয়ে (put-away) করা'],
        ['icon' => 'fa-layer-group',    'text' => 'পার্শিয়াল রিসিভ করা (PO-এর অংশ মাত্র)'],
        ['icon' => 'fa-book',           'text' => 'স্টক লেজার ও GL-এ অটো পোস্ট হওয়া'],
    ],

    'impacts' => [
        ['who' => 'স্টক',        'what' => 'পণ্য বাড়ে (inventory in)'],
        ['who' => 'সাপ্লায়ার',  'what' => 'পেয়েবল বাড়ে (এই রিসিভের টাকা দিতে হবে)'],
        ['who' => 'হিসাব',       'what' => 'ইনভেন্টরি + পেয়েবল লেজারে ডাবল-এন্ট্রি পড়ে'],
        ['who' => 'অডিট',        'what' => 'রিসিভ লগ অডিট ট্রেইলে যুক্ত হয়'],
    ],

    'cautions' => [
        'রিসিভ করলেই সাপ্লায়ার পেয়েবল বাড়ে — তাই ভুল রিসিভ সরাসরি হিসাবে আঘাত দেয়।',
        'পিও-এর চেয়ে বেশি কোয়ান্টিটি রিসিভ করলে সিস্টেম ফ্ল্যাগ করে; ওভার-রিসিভ অনুমোদিত না হলে এড়িয়ে চলুন।',
        'খুটি বা ক্ষতিগ্রস্ত মাল রিসিভ করলে পরে আলাদা রিটার্ন দিতে হবে — রিসিভটি বাতিল করবেন না।',
    ],

    'related' => ['purchasing.purchase-orders', 'purchasing.purchase-returns', 'master-data.suppliers', 'inventory.stock-transactions', 'accounting.supplier-transactions'],

    // No diagram here — the procure-to-pay diagram lives on purchase-orders (the start of the cycle).

    'updated_at' => '2026-08-07',
];
