<?php
$title = $title ?? 'Sales Guideline';
ob_start();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-guide.css">
<meta name="theme-color" content="#4f46e5">

<div class="sales-guide-app" lang="bn">
    <header class="sales-guide-hero">
        <div class="sales-guide-hero-inner">
            <h1><i class="fas fa-compass me-2"></i>সেলস কাজ — সহজ নির্দেশিকা</h1>
            <p>দৈনন্দিন কাজে কী করবেন, কখন গুদাম/হিসাবকে জানাবেন, আর ভুল করলে কী হবে — সেলস, গুদাম, ম্যানেজার ও অ্যাকাউন্ট্যান্ট সবার জন্য সরল ভাষায়। মেনুর নাম ইংরেজিতে থাকবে যেমন সফটওয়্যারে দেখেন।</p>
            <div class="sales-guide-search-wrap">
                <div class="sales-guide-search-box">
                    <i class="fas fa-search sales-guide-search-icon"></i>
                    <input type="search" id="salesGuideSearch" class="sales-guide-search" placeholder="খুঁজুন — যেমন: ভুল ইনভয়েস, চালান বাতিল, টাকা ফেরত…" autocomplete="off">
                </div>
                <a href="<?= BASE_URL ?>sales/today" class="btn btn-light sales-guide-back">
                    <i class="fas fa-arrow-left me-1"></i> Today's Sales
                </a>
            </div>
        </div>
    </header>

    <div class="sales-guide-layout">
        <nav class="sales-guide-nav" aria-label="বিষয়বস্তু">
            <h2>অনুচ্ছেদ</h2>
            <a href="#overview">এক নজরে</a>
            <a href="#stock">স্টক বোঝা</a>
            <a href="#flow">কাজের ক্রম</a>
            <a href="#create">Create Sales Invoice</a>
            <a href="#today">Today's Sales</a>
            <a href="#godown">Godown &amp; Challan</a>
            <a href="#returns">Sales Returns</a>
            <a href="#damage">Damage write-offs</a>
            <a href="#payment">Receive Payment</a>
            <a href="#scenarios">বাস্তব পরিস্থিতি</a>
            <a href="#roles">কে কী করবে</a>
            <a href="#faq">প্রশ্ন ও সমাধান</a>
            <a href="<?= BASE_URL ?>sales/go_live_checklist" class="sales-guide-nav-external"><i class="fas fa-rocket me-1"></i> Go-Live Checklist</a>
        </nav>

        <main class="sales-guide-main">

            <article id="overview" class="sales-guide-card" data-keywords="overview এক নজরে intro শুরু salesman warehouse">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-star"></i> শুরু করুন</span>
                </div>
                <h3>এক নজরে — বিক্রয়টা আসলে কীভাবে চলে</h3>
                <p class="sales-guide-lead">
                    সাধারণ দিনটা এমন: সেলস ইনভয়েস কাটে → গুদাম মাল বের করে দেয় → টাকা আসলে Today's Sales থেকে জমা হয় → কখনো কাস্টমার মাল ফেরত দেয়। একই ইনভয়েসে সবাই একসাথে ধাপ এড়িয়ে যেতে পারবেন না — না হলে স্টক, বাকি আর হিসাব মিলবে না। তাই সফটওয়্যার কখনো «না» বলে; সেটা বাধা নয়, আপনাকে ভুল পথে যেতে আটকায়।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>আপনার দৈনন্দিন কাজ</strong>
                        নিজ ব্রাঞ্চে বিক্রয় লিখা, তালিকা দেখা, কাস্টমারকে কপি দেওয়া, টাকা নেওয়া (যদি আপনার রোলে থাকে), গুদামে জানানো কোন ইনভয়েস আগে বের করতে হবে।
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>যেটা একাই ঠিক করবেন না</strong>
                        চালান হয়ে গেলে ইনভয়েস মুছে ফেলা; টাকা জমা হয়ে গেলে «ভুল ছিল» বলে ডিলিট; অন্য শাখার কাগজ নিজে এডিট করা।
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>যদি আটকে যান</strong>
                        ম্যানেজার বা অ্যাকাউন্ট্যান্টকে ইনভয়েস নম্বর দিয়ে বলুন — তারা Reverse Challan, Reverse Payment বা রিটার্ন ঠিক করতে পারবেন।
                    </div>
                </div>
            </article>

            <article id="stock" class="sales-guide-card" data-keywords="stock স্টক warehouse গুদাম কম দেখাচ্ছে salesman">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-boxes-stacked"></i> Stock</span>
                </div>
                <h3>স্টক — «স্ক্রিনে কম» আর «গুদামে আছে» আলাদা</h3>
                <p class="sales-guide-lead">
                    <span class="sales-guide-highlight">মনে করুন</span> আপনি Create Sales Invoice-এ পণ্য খুঁজলেন — দেখলেন ৫০ পিস। গুদাম বলল «আমার তো ৫০ই আছে»। কিন্তু আরেক জন সকালে একই মালের ইনভয়েস কেটে রেখেছে, গুদাম এখনো বের করেনি। তখন আপনি হয়তো ২০টাই পাবেন। সেটা স্বাভাবিক: বিক্রয় আগে থেকে সেই মালটা «ধরে রেখেছে», যাতে দুজন একই কার্টন দুবার না বিক্রি করে।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>ইনভয়েস কাটার পর (সেলস)</strong>
                        স্ক্রিনে স্টক কমে যায় — মানে ওই পরিমাণ আর নতুন বিক্রিতে দেওয়া যাবে না। গুদামের তাক থেকে এখনো মাল নামেনি।
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>চালান ফাইনালের পর (গুদাম)</strong>
                        Godown &amp; Challan থেকে চালান সম্পন্ন করলে তবেই মাল সত্যিকারের বের হয়। তখনই গুদামের হিসাবও বদলায়।
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>যা বলবেন না গুদামকে</strong>
                        «আমি তো ইনভয়েস দিয়েছি, তুমি কেন বলছ নেই?» — চালান না হলে গুদামের পক্ষে মাল এখনো বের হয়নি।
                    </div>
                </div>
                <div class="sales-guide-tip">
                    <i class="fas fa-lightbulb me-1"></i>
                    <strong>সেলসম্যান:</strong> কাস্টমারকে ডেলিভারি দেওয়ার আগে গুদামকে ইনভয়েস নম্বর জানান। <strong>গুদাম:</strong> বের করার সময় Today's Sales / Godown লিস্টে স্ট্যাটাস দেখুন — কোনটা এখনো «শুধু কাগজে» আছে।
                </div>
            </article>

            <article id="flow" class="sales-guide-card" data-keywords="flow ক্রম ধাপ salesman warehouse টাকা">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-route"></i> Workflow</span>
                </div>
                <h3>একটা অর্ডার শেষ করতে যা হয়</h3>
                <div class="sales-guide-flow">
                    <span class="sales-guide-step">① Create Sales Invoice — কাগজ কাটা</span>
                    <span class="sales-guide-arrow">→</span>
                    <span class="sales-guide-step">② Today's Sales — খোঁজা, ঠিক করা</span>
                    <span class="sales-guide-arrow">→</span>
                    <span class="sales-guide-step">③ Godown &amp; Challan — মাল বের</span>
                    <span class="sales-guide-arrow">→</span>
                    <span class="sales-guide-step">④ Receive Payment — টাকা</span>
                    <span class="sales-guide-arrow">→</span>
                    <span class="sales-guide-step">⑤ (দরকার হলে) Sales Returns</span>
                    <span class="sales-guide-arrow">→</span>
                    <span class="sales-guide-step">⑥ Damage (auto or manual)</span>
                </div>
                <p class="sales-guide-lead mb-0">
                    ইনভয়েস সেভ করলেই কাস্টমারের <strong>বাকি বাড়তে</strong> পারে — এটা অফিসের হিসাবের জন্য। গাড়ি চলে গেলে চালানে ভাড়া/মোট টাকা বদলাতে পারে; সেলসম্যানকে কাস্টমারকে সেটা বোঝানো লাগতে পারে। টাকা আসলে আলাদা ধাপ — ইনভয়েস আর পেমেন্ট এক জিনিস নয়।
                </p>
            </article>

            <article id="create" class="sales-guide-card" data-keywords="create invoice নতুন বিক্রয় কাস্টমার ভুল পরিমাণ finalize">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-file-invoice"></i> Create Sales Invoice</span>
                </div>
                <h3>নতুন বিক্রয় — দোকানে যা করেন</h3>
                <p class="sales-guide-lead">
                    কাস্টমার বেছে পণ্য তুলুন, দাম-ছাড় ঠিক করে Finalize চাপুন। একসাথে কয়েকজন কাস্টমারের কার্ট রাখতে পারেন — বিকেলে একেকজনের ইনভয়েস শেষ করতে সুবিধা হয়।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>স্বাভাবিক কাজ</strong>
                        নাম/পণ্য খুঁজে নেওয়া, স্টক দেখে পরিমাণ দেওয়া, কপি প্রিন্ট, Finalize করে Today's Sales-এ পাঠানো।
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>হবে না যখন</strong>
                        স্টকের চেয়ে বেশি দিলেন; ভুল শাখার কাগজ খুললেন; গুদাম ইতিমধ্যে ওই ইনভয়েস নিয়ে কাজ শুরু করেছে।
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>Finalize এর পর</strong>
                        ইনভয়েস তালিকায় চলে আসে, ওই মাল আর অন্য বিক্রিতে ধরা পড়ে, কাস্টমারের বাকিতে যোগ হতে পারে। গুদাম এখনো বের করতে পারে বা পারে না — আলাদা ধাপ।
                    </div>
                </div>
                <div class="sales-guide-tip">
                    <i class="fas fa-lightbulb me-1"></i>
                    <strong>পরিস্থিতি:</strong> দাম বা পরিমাণ ভুল বুঝলেন, কিন্তু গুদাম বলল «আমি কপি বানিয়ে ফেলেছি» — আর নিজে এডিট করবেন না। ম্যানেজার/গুদামকে বলুন; না হলে স্টক আর কাগজ মিলবে না।
                </div>
            </article>

            <article id="today" class="sales-guide-card" data-keywords="today আজকের তালিকা delete মুছুন payment ভুল ইনভয়েস">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-receipt"></i> Today's Sales</span>
                </div>
                <h3>Today's Sales — দিনের হিসাবের কেন্দ্র</h3>
                <p class="sales-guide-lead">
                    সকাল থেকে রাত পর্যন্ত কোন ইনভয়েস কোথায় আছে, টাকা এসেছে কিনা — সব এখানে। খুঁজতে ইনভয়েস নম্বর বা কাস্টমার নাম দিন; ফিল্টার দিয়ে «শুধু বাকি» বা «গুদামে যায়নি» দেখা যায়।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>আপনি যা করবেন</strong>
                        কপি দেখানো, ছোটখাটো ঠিক করা (গুদাম শুরু করার আগে), Receive Payment, দিন শেষে Call it a day (আপনার রোলে থাকলে)।
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>Delete চাপলেও মুছবে না যদি</strong>
                        গুদাম কপি বানিয়েছে, চালান হয়েছে, ট্রাকে মাল গেছে, বা কাস্টমার এক টাকাও দিয়েছে।
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>যদি মুছে যায় (খুব আগের ধাপে)</strong>
                        কাগজ বাতিল, কাস্টমারের বাকি কমে যায়, স্ক্রিনের স্টক আবার বাড়ে — কিন্তু গুদাম থেকে কিছু বের হয়নি বলে তাকে ফেরত দেওয়ার মতো কিছু হয়নি।
                    </div>
                </div>
                <div class="sales-guide-tip">
                    <i class="fas fa-lightbulb me-1"></i>
                    কাস্টমার বলল «আমি তো নিইনি» — আগে দেখুন চালান হয়েছে কিনা। হয়ে থাকলে Delete নয়; রিটার্ন বা ম্যানেজারের সিদ্ধান্ত লাগবে।
                </div>
            </article>

            <article id="godown" class="sales-guide-card" data-keywords="godown challan গুদাম চালান reverse ট্রাক বাতিল warehouse">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-warehouse"></i> Godown &amp; Challan</span>
                </div>
                <h3>Godown &amp; Challan — গুদামের কাজ</h3>
                <p class="sales-guide-lead">
                    সেলস ইনভয়েস কেটেছে মানে গুদামে এখনই মাল বের করতে হবে এমন নয়। আপনি আগে গোডাউন কপি বানান, মাল মিলান, তারপর চালান ফাইনাল — তখনই সত্যি বের হয়। ভাড়া বা মোট টাকা চালানের সময় বদলালে সেলসকে জানিয়ে দিন; কাস্টমারকে নতুন বিল দেখাতে হতে পারে।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>গুদাম / ডিসপ্যাচার</strong>
                        কপি প্রিন্ট, মাল বের করা, চালান সম্পন্ন, ভুল হলে ম্যানেজারকে বলা — তারা Reverse Challan করতে পারেন।
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>সেলসম্যান একাই</strong>
                        «চালান হয়ে গেছে, এখন ইনভয়েস মুছে দিই» — হবে না। চালান উল্টাতে ম্যানেজার লাগবে।
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>চালান শেষ হলে</strong>
                        তাক থেকে মাল নামে; অফিসের হিসাবে খরচ/স্টক আপডেট হয়। সেলসের কাগজে ইনভয়েস «গুদাম পর্ব শেষ» ধরনের অবস্থায় যায়।
                    </div>
                </div>
                <div class="sales-guide-tip">
                    <i class="fas fa-percent me-1"></i>
                    <strong>Margin reporting:</strong> Revenue is recognized when the invoice is cut; COGS only when the challan completes.
                    Gross margin is meaningful on a <strong>delivery (challan) date</strong> basis —
                    <a href="<?= BASE_URL ?>Report/grossMargin?search=1&amp;date_basis=delivery">Gross Margin report</a>.
                </div>
                <p class="sales-guide-lead"><strong>পরিস্থিতি: ট্রাক ফিরে এল, মাল আনলেড</strong></p>
                <ul class="mb-0" style="line-height:1.7;color:var(--sg-muted);">
                    <li>ম্যানেজার Reverse Challan করলে গুদামে মাল ফিরে যায়, ভাড়া-মোট আগের মতো হতে পারে।</li>
                    <li>কাস্টমারের বাকি অটোমেটিক মুছে যায় না — বিক্রয় কাগজ আছে, শুধু ডেলিভারি পিছিয়ে গেল।</li>
                    <li>পরে আবার চালান দিতে পারেন, নয়তো সেলস/হিসাব মিলিয়ে অন্য সিদ্ধান্ত নেবেন।</li>
                </ul>
            </article>

            <article id="returns" class="sales-guide-card" data-keywords="return রিটার্ন ফেরত confirm warehouse ভাঙা">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-undo-alt"></i> Sales Returns</span>
                </div>
                <h3>Sales Returns — মাল ফেরত</h3>
                <p class="sales-guide-lead">
                    কাস্টমার বলল «দুই কার্টন ভাঙা» বা «ভুল আইটেম এসেছে» — সেলস Sales Returns থেকে এন্ট্রি করেন। গুদাম মাল হাতে দেখে Confirm করলে সব শেষ; ততক্ষণ স্টক আর বাকি অপেক্ষায় থাকে।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>সেলস / ম্যানেজার</strong>
                        কোন ইনভয়েস, কত পিস, কেন ফেরত — লিখে পাঠানো। কাগজ বা ছবি রাখলে পরে ঝামেলা কম।
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>তাড়াহুড়ো করবেন না</strong>
                        মাল এখনো গুদামে না এনে Confirm; একই মাল দুবার রিটার্ন — হিসাব গুলিয়ে যাবে।
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>গুদাম Confirm (ভালো মাল) করলে</strong>
                        তাকে জমা, কাস্টমারের বাকি কমে, অফিসে রিটার্নের হিসাব হয়। কাস্টমারকে বলতে পারেন «অফিসে উঠেছে»।
                    </div>
                </div>
                <div class="sales-guide-tip">
                    <i class="fas fa-lightbulb me-1"></i>
                    ভুল করে Confirm হয়ে গেলে সেলস চিন্তা করবেন না — অ্যাকাউন্ট্যান্ট/ম্যানেজার Reverse Return করতে পারেন; কিন্তু সেটা তৎক্ষণাৎ নয়, তাই আগে পরিমাণ দুবার চেক করুন।
                </div>
            </article>

            <article id="damage" class="sales-guide-card" data-keywords="damage write-off shrinkage ভাঙা broken return sales journey">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-heart-crack"></i> Damage</span>
                </div>
                <h3>Damage write-offs — sales journey link</h3>
                <p class="sales-guide-lead">
                    When a customer returns <strong>damaged</strong> goods, warehouse confirms the return with condition <em>Damage</em> in <strong>Sales Returns → Confirm</strong>.
                    The system automatically creates a linked <strong>Damage</strong> document, writes stock off at cost, and posts GL (Dr shrinkage / Cr inventory). You do <strong>not</strong> need a separate Damage entry for that case.
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>From sales return (automatic)</strong>
                        Confirm return with Damage lines → view linked write-off on return slip or <strong>Damage</strong> list (source: sales return).
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>Manual Damage menu</strong>
                        Use <strong>Damage → Record damage</strong> for breakage found in warehouse, expiry, or other shrinkage <em>not</em> from a customer return.
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>Do not double-write-off</strong>
                        If return confirm already created linked damage, do not record the same qty again in manual Damage.
                    </div>
                </div>
                <p class="sales-guide-lead mb-0">
                    Quick links from <strong>Today's Sales</strong>: Returns and Damage buttons in the header.
                </p>
            </article>

            <article id="payment" class="sales-guide-card" data-keywords="payment পেমেন্ট receive reverse receipt বাকি টাকা">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-hand-holding-usd"></i> Receive Payment</span>
                </div>
                <h3>টাকা নেওয়া — Receive Payment</h3>
                <p class="sales-guide-lead">
                    কাস্টমার হাতে টাকা দিল বা ব্যাংকে পাঠাল — Today's Sales থেকে Receive Payment খুলে ইনভয়েস বেছে নিন। একই টাকা দুইটা বিলে ভাগ করা যায়; রসিদ প্রিন্ট করে দিলে কাস্টমারও শান্ত থাকে।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>সেলস / হিসাব (অনুমতি থাকলে)</strong>
                        আংশিক টাকা, পুরো বিল শোধ, রসিদ, পরে তালিকা এক্সপোর্ট।
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>ভুল টাকা ঢুকলে</strong>
                        সেলসম্যান নিজে «উল্টে দেব» নয় — অ্যাকাউন্ট্যান্ট বা ম্যানেজার Reverse Payment করবেন; না হলে বাকি মিলবে না।
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>জমা দিলে</strong>
                        ওই ইনভয়েসে টাকা লেগে যায়, কাস্টমারের বাকি কমে; অফিসের ক্যাশ/ব্যাংক হিসাবেও যায়।
                    </div>
                </div>
                <p class="sales-guide-lead mb-0 mt-3">
                    <span class="sales-guide-menu-badge"><i class="fas fa-scale-balanced"></i> GL Reconciliation</span>
                    — এটা <strong>অ্যাকাউন্ট্যান্ট/ম্যানেজারের</strong> মাসিক চেকলিস্ট: বাকি, গুদামের মাল আর হিসাবের খাতা মিলছে কিনা। সেলসম্যান বা গুদামের দৈনন্দিন মেনু নয়; সমস্যা হলে তারাই দেখবেন।
                </p>
            </article>

            <article id="scenarios" class="sales-guide-card" data-keywords="scenario পরিস্থিতি delete finalize reverse return ভুল">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-diagram-project"></i> Scenarios</span>
                </div>
                <h3>যা প্রতিদিন ঘটে — সরাসরি উত্তর</h3>

                <h4 class="mt-3 mb-2" style="font-size:1.05rem;">«ইনভয়েস ভুল, গুদাম এখনো ছোঁয়নি»</h4>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can"><strong>করুন</strong> Today's Sales থেকে Delete — যদি বাটন কাজ করে।</div>
                    <div class="sales-guide-pill result"><strong>ফলাফল</strong> কাগজ বাতিল, বাকি কমে যায়, স্টক আবার বিক্রির জন্য খোলা — গুদামের তাকে কিছু যায়নি।</div>
                    <div class="sales-guide-pill cannot"><strong>মনে রাখুন</strong> কাস্টমারকে বলেছিলেন «মাল দেব» কিন্তু বের হয়নি — শুধু কাগজ মুছলেই হয়ে যায়।</div>
                </div>

                <h4 class="mt-4 mb-2" style="font-size:1.05rem;">«টাকা নিয়েছি, পরে বুঝলাম ইনভয়েস ভুল»</h4>
                <div class="sales-guide-pill cannot" style="margin-bottom:0.75rem;">
                    Delete দেবেন না — সিস্টেম বাধা দেবে। অ্যাকাউন্ট্যান্টকে বলুন টাকা উল্টাতে (Reverse Payment), তারপর ইনভয়েস বা রিটার্ন নিয়ে সিদ্ধান্ত। নাহলে কাস্টমারের বাকি আর জমা টাকা মিলবে না।
                </div>

                <h4 class="mt-4 mb-2" style="font-size:1.05rem;">«ট্রাক গিয়েছিল, আবার মাল ফিরিয়ে আনলাম»</h4>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can"><strong>করুন</strong> ম্যানেজারকে বলুন Reverse Challan — গুদাম মাল ঠিক করে তাকে দেবে।</div>
                    <div class="sales-guide-pill result"><strong>ফলাফল</strong> মাল ফিরে, ভাড়া-মোট আগের মতো হতে পারে; বিক্রয় কাগজ থাকতে পারে।</div>
                    <div class="sales-guide-pill cannot"><strong>ভুল ধারণা</strong> «চালান বাতিল = কাস্টমারের বাকি শূন্য» — অটোমেটিক হয় না, সেলসকে কাস্টমারকে বোঝাতে হতে পারে।</div>
                </div>

                <h4 class="mt-4 mb-2" style="font-size:1.05rem;">«মাল ডেলিভারি হয়েছে, কাস্টমার অর্ধেক ফেরত দিল»</h4>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can"><strong>করুন</strong> Sales Returns এন্ট্রি → গুদাম মাল নিয়ে Confirm।</div>
                    <div class="sales-guide-pill result"><strong>ফলাফল</strong> তাকে মাল, বাকি কম, কাস্টমার রিলিফ।</div>
                    <div class="sales-guide-pill cannot"><strong>এড়িয়ে চলুন</strong> পুরো ইনভয়েস Delete — চালান হয়ে গেলে সেটা সাধারণত বন্ধ।</div>
                </div>
            </article>

            <article id="roles" class="sales-guide-card" data-keywords="role salesman warehouse accountant manager কে করবে">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-user-shield"></i> Roles</span>
                </div>
                <h3>কে কোন খবরটা নেবে</h3>
                <p class="sales-guide-lead">
                    মেনুতে যা দেখছেন তা আপনার <strong>রোল</strong> আর <strong>শাখা</strong> মিলিয়ে। অন্য শাখার কাগজ সাধারণত খুলতে পারবেন না; Admin ছাড়া। ঝামেলা হলে সঠিক মানুষকে ডাকাই দ্রুততম উপায়।
                </p>
                <div class="table-responsive">
                    <table class="sales-guide-role-table table table-sm">
                        <thead>
                            <tr><th>যা ঘটল</th><th>প্রথমে কে</th><th>নোট</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>নতুন বিক্রয়, কপি, বাকি</td><td>Salesman</td><td>Create Sales Invoice, Today's Sales</td></tr>
                            <tr><td>মাল বের, চালান, ট্রাক</td><td>Warehouse / Dispatcher</td><td>Godown &amp; Challan</td></tr>
                            <tr><td>ট্রাক ফিরে এল, চালান ভুল</td><td>Manager</td><td>Reverse Challan — গুদাম একাই করবেন না</td></tr>
                            <tr><td>কাস্টমার মাল ফেরত</td><td>Salesman → Warehouse</td><td>Entry তারা, Confirm গুদাম; damaged → auto Damage</td></tr>
                            <tr><td>গুদামে ভাঙা / expiry (return ছাড়া)</td><td>Warehouse / Manager</td><td>Damage → Record damage</td></tr>
                            <tr><td>ভুল টাকা জমা, বিল উল্টাতে</td><td>Accountant / Manager</td><td>Reverse Payment</td></tr>
                            <tr><td>মাস শেষে হিসাব মিল না</td><td>Accountant</td><td>GL Reconciliation</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0">সেলসম্যান বেশিরভাগ সময় নিজের বিল দেখেন; ম্যানেজার পুরো শাখা দেখতে পারেন।</p>
            </article>

            <article id="faq" class="sales-guide-card" data-keywords="faq প্রশ্ন error permission স্টক delete">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-circle-question"></i> FAQ</span>
                </div>
                <h3>প্রশ্ন যা সবাই করে</h3>
                <p class="sales-guide-lead">
                    <strong>Delete চাপছি, হচ্ছে না।</strong><br>
                    মানে ইনভয়েস আর «শুধু কাগজ» নেই — গুদাম, চালান বা টাকা লেগে গেছে। উপরের «বাস্তব পরিস্থিতি» দেখুন; না বুঝলে ম্যানেজারকে ইনভয়েস নম্বর দিন।
                </p>
                <p class="sales-guide-lead">
                    <strong>লাল লেখা — permission / forbidden।</strong><br>
                    এটা আপনার ভুল নয়; ওই বাটন আপনার চাকরির ধরনের জন্য নয়। যাকে টেবিলে লেখা আছে তাকে বলুন।
                </p>
                <p class="sales-guide-lead">
                    <strong>স্ক্রিনে স্টক কম, গুদাম বলে আছে।</strong><br>
                    অন্য কেউ আগে থেকে ওই মালের বিল কেটে রেখেছে। Today's Sales-এ আজকের pending বিলগুলো দেখুন; সেলস টিমকে জিজ্ঞেস করুন কোনটা বাতিল হবে।
                </p>
                <p class="sales-guide-lead">
                    <strong>এডিট বাটন ধূসর / কাজ করে না।</strong><br>
                    গুদাম ইতিমধ্যে কপি বানিয়েছে। এখন শুধু ম্যানেজার বা গুদাম মিলিয়ে সিদ্ধান্ত নেবে — নিজে জোর করে ঠিক করার চেষ্টা করবেন না।
                </p>
                
            </article>

            <p id="salesGuideNoResults" class="sales-guide-no-results">
                <i class="fas fa-face-frown fa-2x mb-2 d-block"></i>
                মিলছে না — «চালান», «মুছুন», «টাকা», «ফেরত» দিয়ে আবার খুঁজুন।
            </p>
        </main>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/sales-guide.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';