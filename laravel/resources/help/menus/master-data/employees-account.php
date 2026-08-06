<?php

/**
 * Help content for: master-data.employees-account
 * Route: admin.employees.account (per-employee)
 *
 * Per-employee personal ledger view — salary, advance, and payment history
 * in one read-only statement. The actual postings happen from the accounting
 * module (employee transactions).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.employees-account',
    'module'     => 'master-data',
    'title_bn'   => 'কর্মচারী অ্যাকাউন্ট',
    'title_en'   => 'Employee Account',
    'icon'       => 'fa-user-shield',
    'summary'    => 'একজন কর্মচারীর ব্যক্তিগত হিসাব — বেতন, অ্যাডভান্স, পেমেন্ট এক জায়গায়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-shield',       'text' => 'কর্মচারীর ব্যক্তিগত লেজার দেখা (বেতন, অ্যাডভান্স, পেমেন্ট)'],
        ['icon' => 'fa-filter',            'text' => 'তারিখ ধরে লেনদেন ফিল্টার করা'],
        ['icon' => 'fa-file-export',       'text' => 'স্টেটমেন্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'কর্মচারী হিসাব',  'what' => 'শুধু রিড-অনলি ভিউ; লেনদেন আলাদা পেজ থেকে পোস্ট হয়'],
    ],

    'cautions' => [
        'এখান থেকে সরাসরি এন্ট্রি হয় না — পেমেন্ট বা অ্যাডভান্স অ্যাকাউন্টিং মডিউল থেকে দিন।',
    ],

    'related' => ['master-data.employees', 'accounting.employee-transactions'],

    'updated_at' => '2026-08-07',
];
