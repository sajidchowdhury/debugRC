<?php
$title = $title ?? 'Sales Go-Live Checklist';
ob_start();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-guide.css">
<meta name="theme-color" content="#059669">

<div class="sales-guide-app sales-guide-go-live" lang="bn">
    <header class="sales-guide-hero sales-guide-hero--launch">
        <div class="sales-guide-hero-inner">
            <h1><i class="fas fa-rocket me-2"></i>সেলস মডিউল — Go-Live চেকলিস্ট</h1>
            <p>
                লাইভ যাওয়ার আগে ও পরে ম্যানেজার, অ্যাকাউন্ট্যান্ট, গুদাম ও IT-এর জন্য।
                স্টক SSOT, GL, রিভার্সাল, ক্রন ও রিপোর্ট — এক জায়গায় টিক দিন।
                বিস্তারিত ব্যবহার নির্দেশিকার জন্য <a href="<?= BASE_URL ?>sales/guide" class="text-white text-decoration-underline">Sales Guideline</a> দেখুন।
            </p>
            <div class="sales-guide-search-wrap">
                <div class="sales-guide-search-box">
                    <i class="fas fa-search sales-guide-search-icon"></i>
                    <input type="search" id="salesGuideSearch" class="sales-guide-search" placeholder="খুঁজুন — reconcile, cron, margin, reverse challan…" autocomplete="off">
                </div>
                <a href="<?= BASE_URL ?>sales/guide" class="btn btn-light sales-guide-back">
                    <i class="fas fa-compass me-1"></i> Sales Guideline
                </a>
            </div>
        </div>
    </header>

    <div class="sales-guide-layout">
        <nav class="sales-guide-nav" aria-label="চেকলিস্ট বিভাগ">
            <h2>বিভাগ</h2>
            <a href="#before">লাইভের আগে</a>
            <a href="#stock">স্টক SSOT</a>
            <a href="#gl">GL ও হিসাব</a>
            <a href="#reversal">রিভার্সাল নিয়ম</a>
            <a href="#cron">ক্রন ও অটomation</a>
            <a href="#reports">রিপোর্ট ও অডিট</a>
            <a href="#roles">দায়িত্ব স্বাক্ষর</a>
            <a href="#launch-day">লঞ্চ দিন</a>
            <a href="#limits">সীমা ও সতর্কতা</a>
        </nav>

        <main class="sales-guide-main">

            <article id="before" class="sales-guide-card" data-keywords="before go-live pre-launch training branch warehouse config">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-clipboard-list"></i> Pre-launch</span>
                </div>
                <h3>লাইভের আগে — একবার করুন</h3>
                <p class="sales-guide-lead">
                    সফটওয়্যার চালু করার <strong>আগে</strong> এইগুলো নিশ্চিত করুন। প্রতিটি লাইনে টিক দিন; প্রিন্ট করে স্বাক্ষর রাখতে পারেন।
                </p>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox"> সব সক্রিয় <strong>Branch</strong> ও <strong>Warehouse</strong> সঠিকভাবে ম্যাপ করা (পণ্য ভুল শাখায় যাবে না)</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <strong>route_roles</strong> / মেনু পারমিশন — সেলস, গুদাম, ম্যানেজার, অ্যাকাউন্ট্যান্ট আলাদা</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> স্টাফকে <a href="<?= BASE_URL ?>sales/guide">Sales Guideline</a> পড়িয়েছেন (বাংলা + ইংরেজি মেনু নাম)</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> টেস্ট ইনভয়েস: Create → Godown → Challan → Payment → Return (Good + Damage) এক পূর্ণ চক্র</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> টেস্ট <strong>Reverse Challan</strong> ও <strong>Reverse Return</strong> — স্টক ও GL মিলেছে</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <code>config/local.php</code> — মেইল, <code>RECON_ALERT_EMAIL</code> (ঐচ্ছিক), <code>SALES_STALE_DRAFT_DAYS</code> (ডিফল্ট ১৪)</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Opening stock / purchase receive দিয়ে <strong>warehouse_stock</strong> সঠিক — Product Movement দিয়ে ২–৩ SKU যাচাই</label></li>
                </ul>
            </article>

            <article id="stock" class="sales-guide-card" data-keywords="stock SSOT warehouse branch pipeline availability warehouse_stock">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-boxes-stacked"></i> Stock SSOT</span>
                </div>
                <h3>স্টক — Single Source of Truth</h3>
                <p class="sales-guide-lead">
                    <span class="sales-guide-highlight">মনে রাখুন:</span> গুদামের <strong>physical</strong> স্টক = <code>warehouse_stock</code>।
                    বিক্রয়যোগ্য = physical − open sales pipeline (<code>sales_invoice_dispatches</code>)।
                    শাখার স্টক আলাদা টেবিল নয় — সব গুদাম যোগ করলে পাওয়া যায়।
                </p>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill can">
                        <strong>Physical SSOT</strong>
                        <code>warehouse_stock</code> + <code>stock_transactions</code> (challan OUT, return IN, damage OUT)
                    </div>
                    <div class="sales-guide-pill can">
                        <strong>Available SSOT</strong>
                        <code>StockAvailabilityService</code> — POS, godown, outbound modules
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>Pipeline hold</strong>
                        ইনভয়েস finalize → soft hold; challan complete → physical OUT + hold release
                    </div>
                </div>
                <h4 class="h6 fw-semibold mt-3">সাপ্তাহিক / দৈনিক চেক</h4>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox"> <strong>Product Movement</strong> — ১–২ SKU: transaction log ≈ warehouse on-hand</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Today's Sales — অনেক দিনের <code>draft</code> pending? Stale draft cron চালু?</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Sales Audit — negative stock warning নেই</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> গুদাম বলছে «আছে», স্ক্রিনে কম — pipeline (অন্য open invoice) ব্যাখ্যা করা হয়েছে</label></li>
                </ul>
            </article>

            <article id="gl" class="sales-guide-card" data-keywords="GL accounting AR revenue COGS reconcile ledger journal">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-scale-balanced"></i> GL</span>
                </div>
                <h3>হিসাব (GL) — দুই ধাপে পোস্ট</h3>
                <p class="sales-guide-lead">
                    ডিজাইন ইচ্ছাকৃত: <strong>Revenue/AR</strong> ইনভয়েস finalize-এ; <strong>COGS/Inventory</strong> challan complete-এ।
                    মাস শেষে margin দেখতে invoice date একাই যথেষ্ট নয় — <strong>delivery basis</strong> ব্যবহার করুন।
                </p>
                <div class="sales-guide-flow">
                    <span class="sales-guide-step">Invoice → Dr AR / Cr Revenue</span>
                    <span class="sales-guide-arrow">→</span>
                    <span class="sales-guide-step">Challan → Dr COGS / Cr Inventory</span>
                    <span class="sales-guide-arrow">→</span>
                    <span class="sales-guide-step">Payment → Dr Cash / Cr AR</span>
                </div>
                <ul class="sales-guide-checklist mt-3">
                    <li><label class="sales-guide-check"><input type="checkbox"> <a href="<?= BASE_URL ?>Reconciliation/index">GL Reconciliation</a> — AR sub-ledger vs GL, inventory vs stock, COGS tie-out</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <a href="<?= BASE_URL ?>Report/grossMargin?search=1&amp;date_basis=delivery">Gross Margin report</a> (delivery basis) — finance sign-off</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <a href="<?= BASE_URL ?>Report/TrialBalance?search=1">Trial Balance</a> — balanced</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Transport/total adjust godown/challan-এ — staff জানে কাস্টমার copy আপডেট লাগতে পারে</label></li>
                </ul>
            </article>

            <article id="reversal" class="sales-guide-card" data-keywords="reverse challan return delete invoice payment undo rollback">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-rotate-left"></i> Reversals</span>
                </div>
                <h3>রিভার্সাল — কোনটা কী undo করে</h3>
                <p class="sales-guide-lead">
                    <strong>গুরুত্বপূর্ণ:</strong> Reverse Challan ≠ ইনভয়েস মুছে ফেলা। কাস্টমারের বাকি থাকে; শুধু ডেলিভারি/COGS undo হয়।
                </p>
                <div class="table-responsive">
                    <table class="table table-sm sales-guide-table mb-3">
                        <thead>
                            <tr>
                                <th>কাজ</th>
                                <th>স্টক</th>
                                <th>Ledger / GL</th>
                                <th>কখন ব্যবহার</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Delete draft invoice</strong></td>
                                <td>Pipeline clear</td>
                                <td>AR/revenue reverse</td>
                                <td>শুধু draft, godown/challan/payment নেই</td>
                            </tr>
                            <tr>
                                <td><strong>Reverse Challan</strong></td>
                                <td>IN @ issue_rate</td>
                                <td>COGS reverse; <em>AR/revenue stays</em></td>
                                <td>ট্রাক ফিরে এল, মাল আনলোড</td>
                            </tr>
                            <tr>
                                <td><strong>Reverse Return</strong></td>
                                <td>Good → OUT again</td>
                                <td>Credit note reverse</td>
                                <td>ভুল confirm</td>
                            </tr>
                            <tr>
                                <td><strong>Reverse Payment</strong></td>
                                <td>—</td>
                                <td>Cash/AR adjust</td>
                                <td>ভুল জমা</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox"> ম্যানেজার/গুদাম Reverse Challan training সম্পন্ন</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Completed return থাকলে challan reverse block — resolution order বোঝানো</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Damage return confirm → auto damage write-off (C1) — গুদাম জানে</label></li>
                </ul>
            </article>

            <article id="cron" class="sales-guide-card" data-keywords="cron scheduled job stale draft reconciliation automation">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-clock"></i> Cron</span>
                </div>
                <h3>নিয়মিত চালানো স্ক্রিপ্ট (IT / Admin)</h3>
                <p class="sales-guide-lead">Windows Task Scheduler বা Linux cron-এ রাখুন। লগ ফোল্ডার মনিটর করুন।</p>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox">
                        <code>php database/scripts/cancel_stale_sales_drafts.php</code>
                        — পুরনো draft auto-cancel (pipeline release)
                    </label></li>
                    <li><label class="sales-guide-check"><input type="checkbox">
                        <code>php database/scripts/run_gl_reconciliation.php</code>
                        — AR, inventory, COGS alerts → <code>logs/reconciliation_alerts.log</code>
                    </label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <code>RECON_ALERT_EMAIL</code> in local.php (optional) — accountant gets mail on drift</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Database backup before go-live + weekly after</label></li>
                </ul>
            </article>

            <article id="reports" class="sales-guide-card" data-keywords="report audit margin movement reconcile sales audit">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-chart-line"></i> Reports</span>
                </div>
                <h3>রিপোর্ট ও অডিট — কে কখন দেখবে</h3>
                <div class="table-responsive">
                    <table class="table table-sm sales-guide-table mb-3">
                        <thead>
                            <tr>
                                <th>রিপোর্ট / স্ক্রিন</th>
                                <th>কাদের</th>
                                <th>কখন</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><a href="<?= BASE_URL ?>Reconciliation/index">GL Reconciliation</a></td>
                                <td>Accountant, Manager</td>
                                <td>সাপ্তাহিক / মাস শেষ</td>
                            </tr>
                            <tr>
                                <td><a href="<?= BASE_URL ?>Report/grossMargin?search=1&amp;date_basis=delivery">Gross Margin</a></td>
                                <td>Accountant, Manager</td>
                                <td>মাস শেষ (delivery basis)</td>
                            </tr>
                            <tr>
                                <td><a href="<?= BASE_URL ?>Report/ProductMovement?search=1">Product Movement</a></td>
                                <td>Warehouse, Accountant</td>
                                <td>Variance তদন্ত</td>
                            </tr>
                            <tr>
                                <td><a href="<?= BASE_URL ?>sales/audit">Sales Audit</a></td>
                                <td>Manager, Accountant</td>
                                <td>Go-live week daily</td>
                            </tr>
                            <tr>
                                <td><a href="<?= BASE_URL ?>Report">Reports Command Center</a></td>
                                <td>All finance roles</td>
                                <td>As needed</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox"> Go-live সপ্তাহে প্রতিদিন Sales Audit + Reconcile একবার</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Gross margin invoice basis দিয়ে pipeline gap দেখিয়ে finance team-কে বোঝানো</label></li>
                </ul>
            </article>

            <article id="roles" class="sales-guide-card" data-keywords="roles sign-off manager accountant warehouse salesman responsibility">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-user-check"></i> Sign-off</span>
                </div>
                <h3>দায়িত্ব স্বাক্ষর (Go-Live approval)</h3>
                <p class="sales-guide-lead">লাইভের আগে প্রতিটি role holder টিক + নাম + তারিখ দিন (কাগজ বা প্রিন্ট)।</p>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox"> <strong>Branch Manager</strong> — workflow training, reverse challan authority, credit override policy</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <strong>Accountant</strong> — GL reconcile, gross margin, payment reverse process</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <strong>Warehouse Manager</strong> — godown/challan, return confirm, damage path</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <strong>Sales lead</strong> — Today's Sales, payment collection, draft discipline</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> <strong>IT / Admin</strong> — cron, backup, user roles, investigation mode policy</label></li>
                </ul>
                <div class="sales-guide-signoff mt-3">
                    <div class="sales-guide-signoff-row"><span>Manager</span><span class="sales-guide-signoff-line"></span><span>Date</span><span class="sales-guide-signoff-line sales-guide-signoff-line--short"></span></div>
                    <div class="sales-guide-signoff-row"><span>Accountant</span><span class="sales-guide-signoff-line"></span><span>Date</span><span class="sales-guide-signoff-line sales-guide-signoff-line--short"></span></div>
                    <div class="sales-guide-signoff-row"><span>Warehouse</span><span class="sales-guide-signoff-line"></span><span>Date</span><span class="sales-guide-signoff-line sales-guide-signoff-line--short"></span></div>
                </div>
            </article>

            <article id="launch-day" class="sales-guide-card" data-keywords="launch day go live first day production">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-flag-checkered"></i> Launch day</span>
                </div>
                <h3>লঞ্চ দিন — সকাল ও শেষ</h3>
                <h4 class="h6 fw-semibold">সকাল (go-live)</h4>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox"> সব user login + branch context ঠিক</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> ১টি ছোট real invoice end-to-end (sales → godown → challan → print)</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Product Movement — go-live SKU spot check</label></li>
                </ul>
                <h4 class="h6 fw-semibold mt-3">দিন শেষ</h4>
                <ul class="sales-guide-checklist">
                    <li><label class="sales-guide-check"><input type="checkbox"> Today's Sales — unpaid list reviewed; payments posted</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Pending draft/godown count acceptable</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Sales Audit — no critical warnings</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Issue log shared with IT (if any)</label></li>
                </ul>
            </article>

            <article id="limits" class="sales-guide-card" data-keywords="limitations warning partial dispatch call it a day margin timing">
                <div class="sales-guide-card-head">
                    <span class="sales-guide-menu-badge"><i class="fas fa-triangle-exclamation"></i> Limits</span>
                </div>
                <h3>জানা সীমা — বাগ নয়, নীতি</h3>
                <div class="sales-guide-grid-3">
                    <div class="sales-guide-pill cannot">
                        <strong>Partial dispatch</strong>
                        সম্পূর্ণ লাইন qty বা কিছু নয় — business rule
                    </div>
                    <div class="sales-guide-pill cannot">
                        <strong>Call it a day</strong>
                        UI filter only — accounting/stock effect নেই
                    </div>
                    <div class="sales-guide-pill result">
                        <strong>Revenue vs COGS date</strong>
                        Margin = delivery basis; invoice basis = timing gap view
                    </div>
                </div>
                <ul class="sales-guide-checklist mt-3">
                    <li><label class="sales-guide-check"><input type="checkbox"> Staff trained: challan reverse does not wipe customer balance</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> PO in-transit not soft-reserved — purchase team aware</label></li>
                    <li><label class="sales-guide-check"><input type="checkbox"> Returns in gross margin v1 — footnote only, not netted into margin total</label></li>
                </ul>
                <div class="sales-guide-tip">
                    <i class="fas fa-book me-1"></i>
                    Technical deep-dive: <code>docs/SALES_MODULE_JOURNEY_REVIEW.md</code> (architecture review).
                </div>
            </article>

            <p id="salesGuideNoResults" class="sales-guide-no-results">
                <i class="fas fa-face-frown fa-2x mb-2 d-block"></i>
                মিলছে না — «cron», «reconcile», «margin», «reverse» দিয়ে আবার খুঁজুন।
            </p>
        </main>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/sales-guide.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
