<?php

/**
 * Help content for: accounting.bank-reconciliation-unreconciled
 * Route: admin.bank-reconciliation.unreconciled
 *
 * The Unreconciled Entries list — bank ledger entries that have not yet been
 * matched to a statement line. These are open items that need to be cleared.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'accounting.bank-reconciliation-unreconciled',
    'module'     => 'accounting',
    'title_bn'   => 'আনরিকনসাইলড এন্ট্রি',
    'title_en'   => 'Unreconciled Entries',
    'icon'       => 'fa-circle-exclamation',
    'summary'    => 'এটি bank-reconciliation-এর আনরিকনসাইলড তালিকা — যে ব্যাংক এন্ট্রি এখনও স্টেটমেন্টের সাথে মেলেনি, সেগুলো এখানে দেখা যায়।',

    'for_roles'  => ['admin', 'superadmin', 'accountant', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-circle-exclamation', 'text' => 'আনরিকনসাইলড ব্যাংক এন্ট্রির তালিকা দেখা'],
        ['icon' => 'fa-filter',            'text' => 'তারিখ/অ্যাকাউন্ট ধরে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট', 'what' => 'শুধু রিড-অনলি তালিকা — কোনো এন্ট্রি বদলায় না'],
    ],

    'cautions' => [
        'আনরিকনসাইলড আইটেম = ওপেন আইটেম — কারণ খুঁজে বের করে ম্যাচ করতে হবে, নাহলে ব্যাংক লেজার স্টেটমেন্টের সাথে মিলবে না।',
    ],

    'related' => ['accounting.bank-reconciliation', 'accounting.bank-reconciliation-show'],

    'updated_at' => '2026-08-07',
];
