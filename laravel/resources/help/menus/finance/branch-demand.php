<?php

/**
 * Help content for: finance.branch-demand
 * Route: admin.branch-demands.index (and audit/checklist/pending/weekly/reconcile/shadow via sub-routes — 13 total)
 *
 * The Branch Demand hub — where branches (regional sales points) submit their
 * product demands to HQ, HQ approves/allocates stock, and receipts are confirmed.
 * This is the richest finance page in the system; the surrounding sub-pages
 * (audit, checklist, pending, pending-receipt, weekly-report, reconcile,
 * price-range-comparison, shadow) all hang off this single screen.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §5.1 (schema), §5.3 (diagram), Appendix A.8 (branch-demand hub)
 */

return [
    'key'        => 'finance.branch-demand',
    'module'     => 'finance',
    'title_bn'   => 'ব্র্যাঞ্চ ডিমান্ড',
    'title_en'   => 'Branch Demand',
    'icon'       => 'fa-clipboard-question',
    'summary'    => 'ব্র্যাঞ্চগুলো পণ্যের চাহিদা পাঠায়, হেড অফিস অনুমোদন করে স্টক বরাদ্দ দেয় — পুরো ফ্লো এখানে।',

    'for_roles'  => ['admin', 'superadmin', 'manager', 'accountant'],

    'what_you_can_do' => [
        ['icon' => 'fa-inbox',              'text' => 'ব্র্যাঞ্চ থেকে আসা ডিমান্ড তালিকা দেখা ও ফিল্টার করা'],
        ['icon' => 'fa-circle-check',       'text' => 'ডিমান্ড অনুমোদন (approve) বা বাতিল (reject) করা'],
        ['icon' => 'fa-share-from-square',  'text' => 'অনুমোদিত ডিমান্ডে স্টক বরাদ্দ (allocate) দেওয়া'],
        ['icon' => 'fa-hourglass-half',     'text' => '"আমার জন্য পেন্ডিং" ভিউতে নিজের কাজ দেখা'],
        ['icon' => 'fa-file-invoice',       'text' => 'রিসিট কনফার্মেশন — ব্র্যাঞ্চে মাল পৌঁছালে তা কনফার্ম করা'],
        ['icon' => 'fa-calendar-week',      'text' => 'সাপ্তাহিক ডিমান্ড রিপোর্ট দেখা ও ড্রিল-ডাউন করা'],
    ],

    'impacts' => [
        ['who' => 'স্টক রিজার্ভেশন', 'what' => 'অনুমোদন হলে মাল বরাদ্দ/রিজার্ভ হয় — অন্য কাউকে বিক্রি করা যায় না'],
        ['who' => 'ব্র্যাঞ্চ অ্যালোকেশন', 'what' => 'ব্র্যাঞ্চ মুভমেন্ট লেজারে চালান তৈরি হয়'],
        ['who' => 'হিসাব (GL)',       'what' => 'স্টক আউট ও ব্র্যাঞ্চ ইনভেন্টরি ট্রান্সফার জার্নাল পোস্ট হয়'],
        ['who' => 'রিপোর্ট',          'what' => 'ব্র্যাঞ্চ ওয়াইজ ডিমান্ড রিপোর্টে দেখা যায়'],
    ],

    'cautions' => [
        'অনুমোদন একবার দিলে স্টক বরাদ্দ কমিট হয়ে যায় — পরে ক্যানসেল করলে রিজার্ভ কোটা ছাড়তে হয়।',
        'শ্যাডো মোডে থাকলে ডিমান্ড লাইভ পোস্ট হয় না — কাটওভারের আগে কোনো GL এন্ট্রি বসে না।',
        'রিসিট কনফার্ম না করলে চালান "ট্রানজিটে" আটকে থাকে — রিকনসাইল পেজ থেকেই মেলাতে হবে।',
    ],

    'related' => [
        'finance.branch-demand-pending',
        'finance.branch-demand-pending-receipt',
        'finance.branch-demand-reconcile',
        'finance.branch-demand-weekly-report',
        'finance.shadow-mode',
    ],

    // No diagram — the Branch Demand cycle is itself a hub; sub-pages orbit around this screen.
    // The finance module's canonical diagram (consolidation-flow) lives on finance.consolidation.

    'updated_at' => '2026-08-07',
];
