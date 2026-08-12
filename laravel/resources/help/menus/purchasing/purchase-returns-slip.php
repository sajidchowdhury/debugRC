<?php

/**
 * Help content for: purchasing.purchase-returns-slip
 * Route: admin.purchase-returns.slip  (admin/purchase-returns/{id}/slip)
 *
 * Printable slip sub-page for a Purchase Return — the debit-note document
 * handed or sent to the supplier as proof of returned goods. Opens in a
 * new tab with a Print button. Shows: supplier name + mobile, GRN ref,
 * branch, return date, creator, line items (with condition + qty + rate
 * + amount), and totals.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-returns-slip',
    'module'     => 'purchasing',
    'title_bn'   => 'পি. রিটার্ন স্লিপ (ডেবিট নোট)',
    'title_en'   => 'Purchase Return Slip (Debit Note)',
    'icon'       => 'fa-receipt',
    'summary'    => 'এটি পি. রিটার্ন-এর প্রিন্টযোগ্য স্লিপ/ডেবিট নোট — সাপ্লায়ারকে দেখানোর দলিল। নতুন ট্যাবে খোলে, প্রিন্ট বাটন সহ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'warehouse_manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',           'text' => 'রিটার্ন স্লিপ (ডেবিট নোট) প্রিন্ট করা'],
        ['icon' => 'fa-paper-plane',     'text' => 'স্লিপ সাপ্লায়ারকে পাঠানো বা PDF হিসেবে সেভ করা (ব্রাউজার প্রিন্ট-টু-PDF)'],
        ['icon' => 'fa-list',            'text' => 'লাইন আইটেম দেখা — পণ্য, কোয়ান্টিটি, রেট, কন্ডিশন (Good/Damage), টোটাল'],
        ['icon' => 'fa-building',        'text' => 'সাপ্লায়ারের নাম, মোবাইল, GRN রেফারেন্স, ব্র্যাঞ্চ, রিটার্ন তারিখ দেখা'],
    ],

    'impacts' => [
        ['who' => 'সাপ্লায়ার',  'what' => 'রিড-অনলি স্লিপ — কোনো হিসাব বদলায় না'],
        ['who' => 'দলিল',       'what' => 'ডেবিট নোট হিসেবে সাপ্লায়ারের কাছে প্রমাণ থাকে'],
    ],

    'cautions' => [
        'এখান থেকে রিটার্ন এডিট করা যায় না; স্লিপ শুধু দেখায় ও প্রিন্ট হয়।',
        'শুধু confirmed রিটার্নের স্লিপ প্রিন্ট করুন — draft বা cancelled রিটার্নের স্লিপ অর্থহীন।',
        'স্লিপে যে টোটাল দেখায় তা সাপ্লায়ার পেয়েবল থেকে কমবে — সাপ্লায়ারকে এই দলিল দিলে তার বুঝতে সুবিধা হয়।',
    ],

    'related' => ['purchasing.purchase-returns', 'purchasing.purchase-returns-audit', 'accounting.supplier-transactions'],

    'updated_at' => '2026-08-07',
];
