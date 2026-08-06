<?php

/**
 * Help content for: sales.invoices-receive-modal
 * Route: admin.sales-invoices.receive-modal
 *
 * Sub-page of sales.invoices — the receive-payment modal opened from an
 * invoice. Lets the operator record a payment against that specific
 * invoice without leaving the invoice page.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.4 (sub-page convention)
 */

return [
    'key'        => 'sales.invoices-receive-modal',
    'module'     => 'sales',
    'title_bn'   => 'Receive Payment Modal',
    'title_en'   => 'Receive Payment Modal',
    'icon'       => 'fa-circle-dollar-to-slot',
    'summary'    => 'এটি সেলস ইনভয়েস-এর রিসিভ পেমেন্ট মোডাল — ইনভয়েস থেকে সরাসরি খদ্দেরের পেমেন্ট এন্ট্রি করা যায়।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-circle-dollar-to-slot', 'text' => 'ইনভয়েসের বিরুদ্ধে পেমেন্ট এন্ট্রি করা'],
        ['icon' => 'fa-wallet',               'text' => 'ক্যাশ বা ব্যাংক বাছাই করা'],
        ['icon' => 'fa-check',                 'text' => 'পেমেন্ট সেভ করলে বকেয়া কমে'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের',     'what' => 'বকেয়া কমে যায়'],
        ['who' => 'ক্যাশ/ব্যাংক', 'what' => 'টাকা বাড়ে'],
    ],

    'cautions' => [
        'পেমেন্ট এখান থেকে এন্ট্রি করলেও কাস্টমার পেমেন্ট তালিকায় সেটি দেখা যাবে — দুই জায়গা থেকে ডাবল এন্ট্রি করবেন না।',
    ],

    'related' => ['sales.invoices', 'sales.customer-payments'],

    'updated_at' => '2026-08-07',
];
