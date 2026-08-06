<?php

/**
 * Help content for: purchasing.purchase-returns-slip
 * Route: admin.purchase-returns.slip  (admin/purchase-returns/{id}/slip)
 *
 * Printable slip sub-page for a Purchase Return — the debit-note document
 * handed or sent to the supplier as proof of returned goods.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), Appendix C (sub-page convention)
 */

return [
    'key'        => 'purchasing.purchase-returns-slip',
    'module'     => 'purchasing',
    'title_bn'   => 'Purchase Return Slip',
    'title_en'   => 'Purchase Return Slip',
    'icon'       => 'fa-receipt',
    'summary'    => 'এটি পি. রিটার্ন-এর স্লিপ/ডেবিট নোট প্রিন্ট পেজ — সাপ্লায়ারকে দেখানোর দলিল।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-print',           'text' => 'রিটার্ন স্লিপ (ডেবিট নোট) প্রিন্ট করা'],
        ['icon' => 'fa-paper-plane',    'text' => 'স্লিপ সাপ্লায়ারকে পাঠানো বা ডাউনলোড করা'],
    ],

    'impacts' => [
        ['who' => 'সাপ্লায়ার', 'what' => 'রিড-অনলি স্লিপ — কোনো হিসাব বদলায় না'],
    ],

    'cautions' => [
        'এখান থেকে রিটার্ন এডিট করা যায় না; স্লিপ শুধু দেখায় ও প্রিন্ট হয়।',
    ],

    'related' => ['purchasing.purchase-returns', 'accounting.supplier-transactions'],

    'updated_at' => '2026-08-07',
];
