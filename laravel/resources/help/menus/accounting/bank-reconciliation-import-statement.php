<?php

/**
 * Help content for: accounting.bank-reconciliation-import-statement
 * Route: admin.bank-reconciliation.importStatementPage
 *
 * Import page for bank statements — upload a CSV/Excel of the bank statement
 * lines, parse, and pull them into the reconciliation session for matching.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.bank-reconciliation-import-statement',
    'module'     => 'accounting',
    'title_bn'   => 'ব্যাংক স্টেটমেন্ট ইম্পোর্ট',
    'title_en'   => 'Import Bank Statement',
    'icon'       => 'fa-file-import',
    'summary'    => 'এটি bank-reconciliation-এর স্টেটমেন্ট ইম্পোর্ট পেজ — CSV/এক্সেল ফাইল আপলোড করে ব্যাংক স্টেটমেন্টের লাইন রিকন সেশনে আনা হয়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-file-import', 'text' => 'স্টেটমেন্ট ফাইল (CSV/এক্সেল) আপলোড করা'],
        ['icon' => 'fa-eye',         'text' => 'ইম্পোর্ট হওয়া লাইনের প্রিভিউ দেখা'],
    ],

    'impacts' => [
        ['who' => 'রিকন সেশন', 'what' => 'স্টেটমেন্টের লাইন ম্যাচিং-এর জন্য লোড হয়'],
    ],

    'cautions' => [
        'ফাইলের কলাম ফরম্যাট টেমপ্লেট মেলাতে হবে — ভুল কলাম হলে অ্যামাউন্ট/তারিখ ভুল পার্স হবে।',
    ],

    'related' => ['accounting.bank-reconciliation', 'accounting.bank-reconciliation-create'],

    'updated_at' => '2026-08-07',
];
