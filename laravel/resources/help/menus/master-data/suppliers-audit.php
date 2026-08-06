<?php

/**
 * Help content for: master-data.suppliers-audit
 * Route: admin.suppliers.audit
 *
 * Audit trail for the Supplier master — read-only history of who changed what
 * and when, for every supplier record.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'master-data.suppliers-audit',
    'module'     => 'master-data',
    'title_bn'   => 'সাপ্লায়ার অডিট',
    'title_en'   => 'Supplier Audit',
    'icon'       => 'fa-list-check',
    'summary'    => 'এটি সাপ্লায়ার পেজের অডিট ট্রেইল — কে কখন কী বদলেছে তার ইতিহাস।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-list-check',       'text' => 'অডিট লগ দেখা — কে কখন কোন সাপ্লায়ার বদলেছে'],
        ['icon' => 'fa-filter',           'text' => 'তারিখ বা ইউজার দিয়ে ফিল্টার করা'],
        ['icon' => 'fa-file-export',      'text' => 'লগ এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ',  'what' => 'শুধু রিড-অনলি — মূল ডেটা বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে সরাসরি কোনো তথ্য বদলানো যায় না।',
    ],

    'related' => ['master-data.suppliers', 'master-data.suppliers-print'],

    'updated_at' => '2026-08-07',
];
