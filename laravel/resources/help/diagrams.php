<?php

/**
 * Help System — Mermaid Diagram Snippets.
 *
 * Keyed by diagram key (referenced from menu content files via the 'diagram' field
 * and from module content files via the 'diagram' field).
 *
 * Lazy-loaded by help.js: when a [data-mermaid] block is injected into the DOM,
 * help.js injects the Mermaid CDN script tag once and calls mermaid.run() on it.
 *
 * Authoring rule (plan §5.3): only add a diagram when the workflow has ≥3 steps
 * AND a picture genuinely helps. Don't force a diagram onto a trivial "list of X" menu.
 *
 * @return array<string,string>
 */
return [

    // ---- Sales module ----
    'sales-invoice-flow' => <<<'MERMAID'
flowchart LR
    A["🛒 কার্ট"] --> B["📄 ইনভয়েস তৈরি"]
    B --> C["📦 গোডাউন চালান"]
    C --> D["🚚 ডেলিভারি"]
    D --> E["💰 পেমেন্ট গ্রহণ"]
    B -.-> F[("📉 স্টক কমে")]
    B -.-> G[("📈 খদ্দের বকেয়া বাড়ে")]
    MERMAID,

    'sales-cycle' => <<<'MERMAID'
flowchart LR
    A["🛒 কার্ট"] --> B["📄 ইনভয়েস"]
    B --> C["📦 চালান"]
    C --> D["🚚 ডেলিভারি"]
    D --> E["💰 পেমেন্ট"]
    E --> F["佣金 কমিশন"]
    MERMAID,

    // ---- Master Data module ----
    'chart-of-accounts-tree' => <<<'MERMAID'
flowchart TD
    A["📊 Chart of Accounts"] --> B["Assets সম্পত্তি"]
    A --> C["Liabilities দায়"]
    A --> D["Income আয়"]
    A --> E["Expense ব্যয়"]
    B --> B1["Cash নগদ"]
    B --> B2["Bank ব্যাংক"]
    B --> B3["Receivable পাওনা"]
    C --> C1["Payable দেনা"]
    C --> C2["VAT ভ্যাট"]
    MERMAID,

    // ---- Inventory module (Phase 7b will add) ----
    'stock-take-cycle' => <<<'MERMAID'
flowchart LR
    A["📋 সেটআপ"] --> B["🔢 ফিজিক্যাল কাউন্ট"]
    B --> C["🔀 ভ্যারিয়েন্স গণনা"]
    C --> D["✅ অ্যাডজাস্টমেন্ট"]
    D --> E["📊 রিপোর্ট"]
    MERMAID,

    'warehouse-transfer-flow' => <<<'MERMAID'
flowchart LR
    A["📤 সোর্স গোডাউন"] --> B["📝 ট্রান্সফার অর্ডার"]
    B --> C["🚛 ট্রানজিট"]
    C --> D["📥 ডেস্টিনেশন গোডাউন"]
    D --> E["✅ রিসিভ কনফার্ম"]
    MERMAID,

    // ---- Purchasing module (Phase 7c) ----
    'procure-to-pay' => <<<'MERMAID'
flowchart LR
    A["📝 পিও তৈরি"] --> B["✅ অ্যাপ্রুভাল"]
    B --> C["📦 রিসিভ"]
    C --> D["🧾 ইনভয়েস ম্যাচ"]
    D --> E["💰 সাপ্লায়ার পেমেন্ট"]
    MERMAID,

    // ---- Accounting module (Phase 7e) ----
    'journal-posting' => <<<'MERMAID'
flowchart LR
    A["📝 জার্নাল এন্ট্রি"] --> B["⚖️ ডেবিট-ক্রেডিট ব্যাল্যান্স"]
    B --> C["✅ পোস্ট"]
    C --> D["📊 লেজার আপডেট"]
    D --> E["📈 ট্রায়াল ব্যাল্যান্স"]
    MERMAID,

    'period-close' => <<<'MERMAID'
flowchart LR
    A["📋 প্রি-ক্লোজ চেক"] --> B["🔒 পিরিয়ড লক"]
    B --> C["📊 ক্লোজিং এন্ট্রি"]
    C --> D["📈 ফাইন্যান্সিয়াল স্টেটমেন্ট"]
    MERMAID,

    // ---- Finance module (Phase 7f) ----
    'consolidation-flow' => <<<'MERMAID'
flowchart TD
    A["🏢 ব্র্যাঞ্চ A"] --> C["📊 কনসোলিডেশন"]
    B["🏢 ব্র্যাঞ্চ B"] --> C
    C --> D["🔄 ইন্টারকোম্পানি এলিমিনেশন"]
    D --> E["📈 গ্রুপ রিপোর্ট"]
    MERMAID,

    // ---- System module (Phase 7h) ----
    'notification-fan-out' => <<<'MERMAID'
flowchart TD
    A["🔔 ইভেন্ট ট্রিগার"] --> B["📡 SSE ব্রডকাস্ট"]
    B --> C["👤 ইউজার ১"]
    B --> D["👤 ইউজার ২"]
    B --> E["👤 ইউজার ৩"]
    MERMAID,

];
