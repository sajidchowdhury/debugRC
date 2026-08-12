<?php

/**
 * Help content for: finance.consolidation-show
 * Route: admin.consolidation.show
 *
 * Consolidation run detail sub-page — single run's companies, eliminations,
 * consolidated TB snapshot.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (sub-page convention)
 */

return [
    'key'        => 'finance.consolidation-show',
    'module'     => 'finance',
    'title_bn'   => 'Consolidation Run Detail',
    'title_en'   => 'Consolidation Run Detail',
    'icon'       => 'fa-eye',
    'summary'    => 'একটি কনসোলিডেশন রানের ডিটেইল — কোম্পানি, এলিমিনেশন, TB স্ন্যাপশট।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-eye',         'text' => 'নির্দিষ্ট রানের কোম্পানি ও এলিমিনেশন দেখা'],
        ['icon' => 'fa-file-export',  'text' => 'রান রিপোর্ট এক্সপোর্ট করা'],
    ],

    'impacts' => [
        ['who' => 'রিপোর্ট', 'what' => 'শুধু রিড-অনলি — কোনো GL বা এলিমিনেশন বদলায় না'],
    ],

    'cautions' => [
        'রান ডিটেইল পুরোনো হতে পারে — নতুন রান চালালে সংখ্যা বদলাবে।',
    ],

    'related' => ['finance.consolidation', 'finance.consolidation-create'],

    'updated_at' => '2026-08-07',
];
