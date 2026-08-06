<?php

/**
 * Help content for: master-data.employees
 * Route: admin.employees.index (and create/show/edit via wildcard)
 *
 * The Employee master page — every staff member (name, designation, salary,
 * join date) is registered here. Employees can be linked to a login user and
 * to a personal ledger for payroll/advance transactions.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'master-data.employees',
    'module'     => 'master-data',
    'title_bn'   => 'কর্মচারী',
    'title_en'   => 'Employee',
    'icon'       => 'fa-user-tie',
    'summary'    => 'কর্মচারীর নাম, পদবি, যোগ দেওয়ার তারিখ, বেতন — সব এক জায়গায়। পেরোল আর অ্যাডভান্স এই তালিকা ধরে চলে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-user-plus',         'text' => 'নতুন কর্মচারী যোগ করা (নাম, পদবি, যোগের তারিখ)'],
        ['icon' => 'fa-pen-to-square',     'text' => 'কর্মচারীর তথ্য এডিট করা'],
        ['icon' => 'fa-money-bill',        'text' => 'বেতন ও অ্যাডভান্স সেট করা'],
        ['icon' => 'fa-user-shield',       'text' => 'কর্মচারীর অ্যাকাউন্ট ও লেনদেন দেখা'],
        ['icon' => 'fa-link',              'text' => 'কর্মচারীকে লগইন ইউজারে লিঙ্ক করা'],
    ],

    'impacts' => [
        ['who' => 'কর্মচারী',       'what' => 'তালিকায় যোগ/আপডেট হয়'],
        ['who' => 'পেরোল/হিসাব',    'what' => 'বেতন ও অ্যাডভান্স লেজারে পরিবর্তন হয়'],
        ['who' => 'ইউজার',         'what' => 'ইউজার ম্যাপিং বদলালে লগইন পারমিশন বদলে যায়'],
    ],

    'cautions' => [
        'বেতন বদলালে সেটা আগামী পেরোলে পড়বে — পুরোনো মাসের হিসাব বদলাবে না।',
        'কর্মচারী রিজাইন করলে ডিলিট না করে ইনঅ্যাক্টিভ করুন — পুরোনো পেরোল ইতিহাস থাকবে।',
    ],

    'related' => ['accounting.employee-transactions', 'system.users', 'master-data.employees-account', 'master-data.employees-audit', 'master-data.employees-print'],

    // No diagram — a flat list of employee records.

    'updated_at' => '2026-08-07',
];
