<?php
require_once __DIR__ . '/../../helpers/AccountingNavHelper.php';
ob_start();
$title = $title ?? 'My Sales Cockpit';
$todayDate = $today_date ?? date('d M, Y');

// Personal metrics from controller (strictly user-wise - only things related to logged-in user)
$myMtd = (int)($my_mtd_revenue ?? 124500);
$myTarget = (int)($my_target ?? 150000);
$achievement = $myTarget > 0 ? round(($myMtd / $myTarget) * 100) : 83;
$myPipeline = (int)($my_pipeline ?? 385000);
$myWinRate = $my_win_rate ?? 71.5;
$myDeals = (int)($my_deals_closed ?? 9);
$myActivities = (int)($my_activities ?? 47);

// Daily joke instead of welcome message
$jokes = [
    "Why did the salesperson bring a ladder? To reach new heights!",
    "I told my boss I needed a raise. He said, 'You are the raise — now go close some deals!'",
    "Why don't salespeople play hide and seek? Because good luck hiding from a follow-up call!",
    "What's a salesperson's favorite type of music? Heavy metal... because they love closing deals!",
    "Why did the CRM go to therapy? Too many unresolved issues.",
    "Parallel lines have so much in common... it's a shame they'll never meet. Unlike our hot leads!",
    "Why was the sales report so calm? It had a lot of figures but no drama."
];
$dailyJoke = $jokes[array_rand($jokes)];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-cockpit.css">

<div class="sales-cockpit container-fluid py-2">
    <!-- Hero Header - following branch-hub-hero pattern -->
    <header class="sales-cockpit-hero">
        <div>
            <h1>
                <i class="fas fa-laugh-beam me-2"></i>
                <?= htmlspecialchars($dailyJoke) ?>
            </h1>
            <p>
                Your personal sales cockpit — everything that matters to <strong>you</strong> today.
            </p>
            <span class="hero-badge">
                <i class="fas fa-bolt"></i>
                Personal • <?= $todayDate ?>
            </span>
        </div>
        <div class="sales-cockpit-actions">
            <a href="<?= BASE_URL ?>sales/today" class="btn btn-light btn-sm">
                <i class="fas fa-receipt me-1"></i> Today's Invoices
            </a>
            <a href="<?= BASE_URL ?>sales/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Sale
            </a>
            <button onclick="refreshCockpit()" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sync me-1"></i> Refresh
            </button>
        </div>
    </header>

    <?php if (AccountingNavHelper::canSeeAccountingNav()): ?>
    <div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 py-2">
        <div>
            <strong><i class="fas fa-calculator me-1 text-teal"></i> Accounting dashboard</strong>
            <span class="text-muted small ms-1">Trial balance, reconciliation traffic lights, and recent journals.</span>
        </div>
        <a href="<?= BASE_URL ?>Accounting/index" class="btn btn-primary btn-sm">
            <i class="fas fa-arrow-right me-1"></i> Open accounting home
        </a>
    </div>
    <?php endif; ?>

    <!-- Personal Stats - branch-stat-card style -->
    <div class="sales-cockpit-stats">
        <div class="sales-stat-card">
            <div class="sales-stat-icon teal">
                <i class="fas fa-wallet"></i>
            </div>
            <div>
                <div class="sales-stat-value">৳ <?= number_format($myMtd) ?></div>
                <div class="sales-stat-label">My Revenue (MTD)</div>
            </div>
        </div>

        <div class="sales-stat-card">
            <div class="sales-stat-icon indigo">
                <i class="fas fa-bullseye"></i>
            </div>
            <div>
                <div class="sales-stat-value"><?= $achievement ?>%</div>
                <div class="sales-stat-label">Target Achieved</div>
                <div style="height:4px; background:#e2e8f0; border-radius:999px; margin-top:4px; overflow:hidden;">
                    <div style="height:100%; width:<?= min(100, $achievement) ?>%; background:linear-gradient(90deg,#10b981,#34d399);"></div>
                </div>
            </div>
        </div>

        <div class="sales-stat-card">
            <div class="sales-stat-icon amber">
                <i class="fas fa-layer-group"></i>
            </div>
            <div>
                <div class="sales-stat-value">৳ <?= number_format($myPipeline) ?></div>
                <div class="sales-stat-label">My Pipeline</div>
            </div>
        </div>

        <div class="sales-stat-card">
            <div class="sales-stat-icon emerald">
                <i class="fas fa-trophy"></i>
            </div>
            <div>
                <div class="sales-stat-value"><?= $myWinRate ?>%</div>
                <div class="sales-stat-label">My Win Rate</div>
            </div>
        </div>

        <div class="sales-stat-card">
            <div class="sales-stat-icon rose">
                <i class="fas fa-tasks"></i>
            </div>
            <div>
                <div class="sales-stat-value"><?= $myDeals ?></div>
                <div class="sales-stat-label">Deals Closed</div>
                <div class="small text-muted" style="font-size:10px;"><?= $myActivities ?> activities</div>
            </div>
        </div>
    </div>

    <div class="sales-cockpit-panel">
        <div class="row g-3">
            
            <!-- My Funnel -->
            <div class="col-lg-5">
                <div class="sales-section">
                    <div class="sales-section-header">
                        <h6><i class="fas fa-filter me-2 text-primary"></i>My Sales Funnel</h6>
                        <span class="badge bg-light text-dark" style="font-size:0.7rem;">Personal</span>
                    </div>
                    
                    <div class="funnel-personal">
                        <div class="funnel-bar" style="background: linear-gradient(90deg, #3b82f6, #60a5fa); width: 100%;">
                            <div class="label">
                                <span>Leads (18)</span>
                                <span>৳ 485k</span>
                            </div>
                        </div>
                        <div class="funnel-bar" style="background: linear-gradient(90deg, #6366f1, #818cf8); width: 78%;">
                            <div class="label">
                                <span>Qualified (14)</span>
                                <span>৳ 385k</span>
                            </div>
                        </div>
                        <div class="funnel-bar" style="background: linear-gradient(90deg, #8b5cf6, #a78bfa); width: 52%;">
                            <div class="label">
                                <span>Proposal (9)</span>
                                <span>৳ 248k</span>
                            </div>
                        </div>
                        <div class="funnel-bar" style="background: linear-gradient(90deg, #10b981, #34d399); width: 32%;">
                            <div class="label">
                                <span>Negotiation (5)</span>
                                <span>৳ 124k</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 small text-muted">Your personal conversion: <strong class="text-success">71%</strong></div>
                </div>
            </div>

            <!-- My Revenue Trend -->
            <div class="col-lg-7">
                <div class="sales-section">
                    <div class="sales-section-header">
                        <h6><i class="fas fa-chart-line me-2"></i>My Revenue Trend (Last 30 days)</h6>
                    </div>
                    <div class="chart-container">
                        <canvas id="myRevenueChart" height="90"></canvas>
                    </div>
                </div>
            </div>

            <!-- My Action Items - creative priority list -->
            <div class="col-lg-6">
                <div class="sales-section">
                    <div class="sales-section-header">
                        <h6><i class="fas fa-list-check me-2 text-warning"></i>My Action Items</h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="viewAllMyActions()">All (6)</button>
                    </div>
                    
                    <div class="action-item" onclick="completeMyAction(this, 'Follow up with Rahim Traders')">
                        <div class="action-priority high">!</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Follow up with Rahim Traders (৳ 48k quote)</div>
                            <div class="small text-muted">Due today • High value lead</div>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-success py-0 px-2" style="font-size:0.7rem;">Done</button>
                        </div>
                    </div>
                    
                    <div class="action-item" onclick="completeMyAction(this, 'Schedule demo for Apex')">
                        <div class="action-priority medium">2</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Schedule demo for Apex Engineering</div>
                            <div class="small text-muted">Tomorrow • Pipeline ৳ 185k</div>
                        </div>
                    </div>
                    
                    <div class="action-item" onclick="completeMyAction(this, 'Send revised quote')">
                        <div class="action-priority low">3</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Send revised quote to Sunrise Pharma</div>
                            <div class="small text-muted">Price approval received</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Hot Opportunities -->
            <div class="col-lg-6">
                <div class="sales-section">
                    <div class="sales-section-header">
                        <h6><i class="fas fa-fire me-2 text-danger"></i>My Hot Opportunities</h6>
                    </div>
                    
                    <div class="small">
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                            <div>
                                <strong>Apex Engineering Phase 2</strong><br>
                                <span class="text-muted" style="font-size:0.7rem;">Expected close: 12 Feb • 80% prob</span>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success">৳ 185k</div>
                                <button class="btn btn-xs btn-outline-primary py-0 px-1 mt-1" style="font-size:0.65rem;" onclick="quickAction(this)">Update</button>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                            <div>
                                <strong>Metro Builders - Expansion</strong><br>
                                <span class="text-muted" style="font-size:0.7rem;">Expected close: 18 Feb • 65% prob</span>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success">৳ 92k</div>
                                <button class="btn btn-xs btn-outline-primary py-0 px-1 mt-1" style="font-size:0.65rem;" onclick="quickAction(this)">Update</button>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center py-1">
                            <div>
                                <strong>City Hospital - Annual Contract</strong><br>
                                <span class="text-muted" style="font-size:0.7rem;">Expected close: 25 Feb • 55% prob</span>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success">৳ 67k</div>
                                <button class="btn btn-xs btn-outline-primary py-0 px-1 mt-1" style="font-size:0.65rem;" onclick="quickAction(this)">Update</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- My Recent Wins + Alerts -->
            <div class="col-12">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="sales-section">
                            <div class="sales-section-header">
                                <h6><i class="fas fa-trophy me-2"></i>Recent Personal Wins</h6>
                            </div>
                            <div class="small">
                                <div class="d-flex justify-content-between py-1">
                                    <span>Closed: <strong>City Mart</strong> (৳ 38k)</span>
                                    <span class="text-success">2 days ago</span>
                                </div>
                                <div class="d-flex justify-content-between py-1">
                                    <span>Closed: <strong>Delta Logistics</strong> (৳ 71k)</span>
                                    <span class="text-success">Last week</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="sales-section">
                            <div class="sales-section-header">
                                <h6><i class="fas fa-exclamation-circle me-2 text-danger"></i>Alerts for You</h6>
                            </div>
                            <div class="small">
                                <div class="alert alert-warning py-1 px-2 mb-1" style="font-size:0.8rem;">
                                    <strong>Overdue:</strong> Follow-up with Rahim Traders (3 days)
                                </div>
                                <div class="alert alert-info py-1 px-2 mb-0" style="font-size:0.8rem;">
                                    <strong>Low stock:</strong> Pump 3000 (your top seller this month)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function() {
    // My personal revenue trend chart
    const ctx = document.getElementById('myRevenueChart');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan 28', 'Feb 4', 'Feb 11', 'Feb 18', 'Feb 25', 'Today'],
                datasets: [{
                    label: 'My Revenue',
                    data: [82000, 105000, 78000, 134000, 96000, 124500],
                    borderColor: '#0f766e',
                    backgroundColor: 'rgba(15, 118, 110, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { ticks: { callback: v => '৳' + (v/1000) + 'k', font: {size: 10} }, grid: { color: '#e2e8f0' } },
                    x: { ticks: { font: {size: 10} }, grid: { color: '#f1f5f9' } }
                }
            }
        });
    }

    // Date range simulation (user personal)
    window.setDateRange = function(el, range) {
        // In real: reload with params or AJAX
        $('.sales-cockpit-hero .btn-group .btn').removeClass('active');
        $(el).addClass('active');
        alert('Personal view updated for ' + range + ' (demo)');
    };

    window.refreshCockpit = function() {
        location.reload();
    };

    window.completeMyAction = function(el, title) {
        $(el).fadeOut(200, function() {
            $(this).remove();
            alert('Great! "' + title + '" marked complete.');
        });
    };

    window.viewAllMyActions = function() {
        alert('Full personal task list would open in a modal or dedicated page.');
    };

    window.quickAction = function(el) {
        $(el).text('Updated!').prop('disabled', true);
        setTimeout(() => {
            $(el).text('Update').prop('disabled', false);
        }, 1400);
    };

    // Premium touch: subtle animation on stat cards
    $('.sales-stat-card').hover(
        function() { $(this).css('transform', 'translateY(-2px)'); },
        function() { $(this).css('transform', ''); }
    );
});
</script>
';
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>