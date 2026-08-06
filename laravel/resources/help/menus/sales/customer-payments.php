<?php

/**
 * Help content for: sales.customer-payments
 * Route: admin.customer-payments.index (and create/show/edit via wildcard)
 *
 * The Customer Payment page — where money received from customers against
 * invoices is recorded. Each payment reduces the customer's receivable and
 * increases cash or bank. Unallocated amounts become customer credit.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'sales.customer-payments',
    'module'     => 'sales',
    'title_bn'   => 'কাস্টমার পেমেন্ট',
    'title_en'   => 'Customer Payment',
    'icon'       => 'fa-hand-holding-dollar',
    'summary'    => 'খদ্দের থেকে টাকা পেলে এখানে এন্ট্রি করা হয় — পেমেন্ট ইনভয়েসে যুক্ত করলে বকেয়া কমে আর ক্যাশ/ব্যাংক বাড়ে।',

    'for_roles'  => ['salesman', 'manager', 'admin', 'superadmin'],

    'what_you_can_do' => [
        ['icon' => 'fa-circle-dollar-to-slot', 'text' => 'নতুন পেমেন্ট এন্ট্রি করা (ক্যাশ বা ব্যাংক বাছাই করে)'],
        ['icon' => 'fa-link',                 'text' => 'পেমেন্ট কোন ইনভয়েসের বিরুদ্ধে — তা লিংক করা'],
        ['icon' => 'fa-file-invoice-dollar',  'text' => 'খদ্দেরের বকেয়া ইনভয়েস তালিকা দেখা'],
        ['icon' => 'fa-print',                'text' => 'পেমেন্ট রিসিট বা স্লিপ প্রিন্ট করা'],
        ['icon' => 'fa-list-check',           'text' => 'আগের সব পেমেন্টের অডিট ট্রেইল দেখা'],
    ],

    'impacts' => [
        ['who' => 'খদ্দের',     'what' => 'বকেয়া (receivable) কমে যায়'],
        ['who' => 'ক্যাশ/ব্যাংক', 'what' => 'টাকা বাড়ে (cash/bank↑)'],
        ['who' => 'হিসাব',       'what' => 'ডাবল-এন্ট্রি জার্নাল তৈরি হয়'],
        ['who' => 'অডিট',        'what' => 'প্রতিটি পেমেন্ট অডিট ট্রেইলে লেখা পড়ে'],
    ],

    'cautions' => [
        'পেমেন্ট কোনো ইনভয়েসে লিংক না করলে তা খদ্দেরের ক্রেডিট হিসেবে জমে থাকে — বকেয়া কমে না।',
        'ভুল ইনভয়েসে লিংক করলে আনলিংক করে আবার লিংক করতে হয় — সরাসরি এডিট নয়।',
    ],

    'related' => ['sales.invoices', 'sales.returns', 'master-data.customers', 'accounting.manual-journals'],

    // No diagram — payment posting is a single journal entry; no multi-step
    // flow that benefits from a Mermaid picture.

    'updated_at' => '2026-08-07',
];
