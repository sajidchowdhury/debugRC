<?php

/**
 * Help content for: system.archive
 * Route: admin.archive.index
 *
 * The Archive hub — read-only entry point to historical/purged business data:
 * old customer ledgers, old supplier ledgers, closed-period audit trails.
 * Retained for compliance, dispute resolution, and long-tail lookups.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema)
 */

return [
    'key'        => 'system.archive',
    'module'     => 'system',
    'title_bn'   => 'আর্কাইভ',
    'title_en'   => 'Archive',
    'icon'       => 'fa-box-archive',
    'summary'    => 'পুরোনো ও ক্লোজড-পিরিয়ড ডেটা রিড-অনলি হিসেবে এখানে থাকে — কমপ্লায়েন্স রিটেনশন আর পুরোনো হিসাব খোঁজার জায়গা।',

    'for_roles'  => ['admin', 'superadmin', 'manager'],

    'what_you_can_do' => [
        ['icon' => 'fa-box-archive',    'text' => 'আর্কাইভ করা পুরোনো খদ্দের/সাপ্লায়ার লেজার দেখা'],
        ['icon' => 'fa-calendar-days',  'text' => 'পিরিয়ড ধরে ফিল্টার করা (অর্থবছর/মাস/কোয়ার্টার)'],
        ['icon' => 'fa-magnifying-glass','text' => 'নির্দিষ্ট খদ্দের/সাপ্লায়ার ধরে পুরোনো হিসাবে ঢুকে দেখা'],
        ['icon' => 'fa-file-export',    'text' => 'আর্কাইভ রেকর্ড এক্সপোর্ট করা (কমপ্লায়েন্সের জন্য)'],
        ['icon' => 'fa-shield-halved',  'text' => 'রিটেনশন পিরিয়ড মেনে চলা — কোনটা কতদিন রাখা যায় তা দেখা'],
    ],

    'impacts' => [
        ['who' => 'পুরোনো ডেটা',   'what' => 'রিড-অনলি — বদলানো বা মুছতে পারবেন না'],
        ['who' => 'কমপ্লায়েন্স',   'what' => 'আইনি রিটেনশন পিরিয়ড পূরণ হয়'],
        ['who' => 'হিসাব',         'what' => 'পুরোনো পিরিয়ডের রেফারেন্স খোঁজার সোর্স'],
    ],

    'cautions' => [
        'আর্কাইভ সম্পূর্ণ রিড-অনলি — এখান থেকে কোনো এন্ট্রি এডিট বা ডিলিট করা যায় না।',
        'ভুল করে লাইভ ডেটা আর্কাইভে নেওয়া হলে তা রিভার্ট করতে ডেভেলপার/অ্যাডমিনের সাহায্য লাগবে।',
    ],

    'related' => ['system.archive-customerLedger', 'system.archive-supplierLedger', 'system.audit', 'system.compliance'],

    'updated_at' => '2026-08-07',
];
