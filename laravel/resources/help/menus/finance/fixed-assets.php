<?php

/**
 * Help content for: finance.fixed-assets
 * Route: admin.fixed-assets.index (and depreciation/disposals/show-disposal sub-routes)
 *
 * The Fixed Asset register — every long-life asset (machinery, vehicles, furniture,
 * computers) is recorded here with its cost, depreciation method, useful life, and
 * accumulated depreciation. Depreciation posts a journal automatically; disposals
 * create gain/loss journals.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.2 (primary card)
 */

return [
    'key'        => 'finance.fixed-assets',
    'module'     => 'finance',
    'title_bn'   => 'ফিক্সড অ্যাসেট',
    'title_en'   => 'Fixed Asset',
    'icon'       => 'fa-cube',
    'summary'    => 'মেশিন, গাড়ি, আসবাব — সব দীর্ঘমেয়াদি সম্পত্তি এখানে রেকর্ড, অবচয় চলে স্বয়ংক্রিয়।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-plus',              'text' => 'নতুন অ্যাসেট রেজিস্টার — নাম, ক্রয়মূল্য, ক্রয় তারিখ, লেজার'],
        ['icon' => 'fa-sliders',           'text' => 'অবচয় মেথড (straight-line/reducing) ও আয়ুষ্কাল সেট করা'],
        ['icon' => 'fa-arrow-trend-down',   'text' => 'মাসিক অবচয় চালান (depreciation schedule)'],
        ['icon' => 'fa-trash-can',         'text' => 'অ্যাসেট ডিসপোজ (বিক্রি/স্ক্র্যাপ) করা'],
        ['icon' => 'fa-eye',               'text' => 'অ্যাসেট হিস্ট্রি — কেনা, অবচয়, ডিসপোজাল সব দেখা'],
    ],

    'impacts' => [
        ['who' => 'অ্যাসেট রেজিস্টার', 'what' => 'নতুন অ্যাসেট রেকর্ড যোগ হয়'],
        ['who' => 'হিসাব (GL)',      'what' => 'অবচয় চালালে ডেবিট-ক্রেডিট জার্নাল স্বয়ংক্রিয় পোস্ট হয়'],
        ['who' => 'ডিসপোজাল',        'what' => 'বিক্রি/স্ক্র্যাপে লাভ-ক্ষতি (gain/loss) জার্নাল তৈরি হয়'],
        ['who' => 'ব্যালেন্স শিট',    'what' => 'নিট বুক ভ্যালু অ্যাসেট হিসেবে ব্যালেন্স শিটে দেখা যায়'],
    ],

    'cautions' => [
        'অবচয় একবার চালালে তার জার্নাল পোস্ট হয়ে যায় — ভুল ধরলে রিভার্স জার্নাল দিতে হয়, ডিলিট হয় না।',
        'ডিসপোজাল করলে লাভ-ক্ষতি জার্নাল স্বয়ংক্রিয় তৈরি হয় — বিক্রয়মূল্য আগেই ঠিক বসান।',
        'অ্যাসেটের আয়ুষ্কাল/মেথড পরে বদলালে ভবিষ্যৎ অবচয় পুনঃহিসাব হয়, অতীত পরিবর্তন হয় না।',
    ],

    'related' => [
        'finance.fixed-assets-depreciation',
        'finance.fixed-assets-disposals',
        'finance.fixed-assets-show-disposal',
        'master-data.ledgers',
        'accounting.manual-journals',
    ],

    // No diagram — the asset lifecycle (acquire → depreciate → dispose) is linear & obvious.

    'updated_at' => '2026-08-07',
];
