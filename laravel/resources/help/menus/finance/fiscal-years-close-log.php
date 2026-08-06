<?php

/**
 * Help content for: finance.fiscal-years-close-log
 * Route: admin.fiscal-years.close-log
 *
 * Close-log sub-page — audit trail of every period/year close action
 * (who closed, when, which period, optional reason).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.fiscal-years-close-log',
    'module'     => 'finance',
    'title_bn'   => 'Fiscal Year Close Log',
    'title_en'   => 'Fiscal Year Close Log',
    'icon'       => 'fa-clock-rotate-left',
    'summary'    => 'কোন পিরিয়ড বা সাল কে কখন ক্লোজ করেছে — তার পুরো লগ।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-clock-rotate-left', 'text' => 'ক্লোজ লগ তালিকা দেখা ও ফিল্টার করা'],
        ['icon' => 'fa-filter',            'text' => 'তারিখ, ইউজার, বা পিরিয়ড ধরে ফিল্টার করা'],
    ],

    'impacts' => [
        ['who' => 'অডিট লগ', 'what' => 'শুধু রিড-অনলি — কোনো পিরিয়ড বা GL বদলায় না'],
    ],

    'cautions' => [
        'শুধু দেখার জন্য — এখান থেকে ক্লোজ রিভার্স করা যায় না।',
    ],

    'related' => ['finance.fiscal-years', 'accounting.period-close'],

    'updated_at' => '2026-08-07',
];
