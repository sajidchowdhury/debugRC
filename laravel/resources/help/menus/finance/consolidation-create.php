<?php

/**
 * Help content for: finance.consolidation-create
 * Route: admin.consolidation.create
 *
 * Create consolidation run sub-page — form to start a new consolidation run
 * (pick period, companies, elimination rule set).
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-create',
    'module'     => 'finance',
    'title_bn'   => 'Create Consolidation Run',
    'title_en'   => 'Create Consolidation Run',
    'icon'       => 'fa-plus',
    'summary'    => 'নতুন কনসোলিডেশন রান শুরু করার ফর্ম — পিরিয়ড, কোম্পানি, এলিমিনেশন রুল বেছে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',         'text' => 'নতুন কনসোলিডেশন রান তৈরি করা'],
        ['icon' => 'fa-calendar-day',  'text' => 'পিরিয়ড ও কোম্পানি নির্বাচন করা'],
    ],

    'impacts' => [
        ['who' => 'কনসোলিডেশন', 'what' => 'রান সাবমিট হলে গ্রুপ GL ও এলিমিনেশন তৈরি হয়'],
    ],

    'cautions' => [
        'সব কোম্পানির পিরিয়ড ক্লোজ না হলে রান করবেন না — সংখ্যা পরে বদলে যাবে।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-show'],

    'updated_at' => '2026-08-07',
];
