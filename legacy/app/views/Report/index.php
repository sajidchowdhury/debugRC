<?php
require_once __DIR__ . '/../../helpers/ReportsCatalog.php';
ob_start();
$title = $title ?? 'Reports Command Center';
$categories = $categories ?? [];
$featured = $featured ?? [];
$branch_name = $branch_name ?? 'Branch';

$storyAccent = ['sales' => 'sales', 'finance' => 'finance', 'inventory' => 'inventory'];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/reports-premium.css">

<div class="reports-hub" id="reportsHub">
    <header class="reports-hub-hero">
        <div class="row align-items-end g-3">
            <div class="col-lg-7">
                <p class="mb-1 small text-uppercase" style="letter-spacing:0.14em;opacity:0.8">Insights command center</p>
                <h1><i class="fas fa-table-cells-large me-2"></i>Reports</h1>
                <p class="hero-lead">Creative lenses on sales, stock, payables, and GL — pick a story, set the period, export or print.</p>
                <span class="badge bg-light text-dark mt-2"><i class="fas fa-building me-1"></i> <?= htmlspecialchars($branch_name, ENT_QUOTES) ?></span>
            </div>
            <div class="col-lg-5">
                <div class="reports-hub-search position-relative">
                    <i class="fas fa-search search-icon"></i>
                    <input type="search" id="reportsSearch" class="form-control" placeholder="Search reports…" autocomplete="off">
                </div>
            </div>
        </div>
    </header>

    <?php if (!empty($investigation_period)): ?>
    <div class="alert alert-warning rpt-no-print mb-3 py-2 small">
        <i class="fas fa-user-secret me-1"></i>
        Investigation mode — reports limited to <?= htmlspecialchars($investigation_period['label'], ENT_QUOTES) ?>.
    </div>
    <?php endif; ?>

    <?php if (!empty($featured)): ?>
    <section class="reports-featured rpt-no-print">
        <?php foreach (array_slice($featured, 0, 3) as $f):
            $accent = $storyAccent[$f['category_accent'] ?? ''] ?? 'finance';
            $runUrl = ReportsCatalog::buildRunUrl($f, 'mtd');
        ?>
        <a href="<?= htmlspecialchars($runUrl, ENT_QUOTES) ?>" class="reports-story-card <?= htmlspecialchars($accent, ENT_QUOTES) ?>">
            <div class="story-label">Featured insight</div>
            <h3><?= htmlspecialchars($f['title'], ENT_QUOTES) ?></h3>
            <p><?= htmlspecialchars($f['tagline'], ENT_QUOTES) ?></p>
            <div class="story-cta">Run MTD <i class="fas fa-arrow-right ms-1"></i></div>
            <i class="fas <?= htmlspecialchars($f['icon'], ENT_QUOTES) ?> story-icon"></i>
        </a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <div class="reports-lenses rpt-no-print">
        <span class="align-self-center small text-muted me-1">Quick period for <strong>Run</strong> links:</span>
        <button type="button" class="reports-lens-btn" data-lens="today">Today</button>
        <button type="button" class="reports-lens-btn active" data-lens="mtd">Month to date</button>
        <button type="button" class="reports-lens-btn" data-lens="last7">Last 7 days</button>
        <button type="button" class="reports-lens-btn" data-lens="preset">Default range</button>
    </div>

    <div class="reports-category-tabs rpt-no-print">
        <button type="button" class="reports-cat-tab active" data-category="all"><i class="fas fa-border-all"></i> All</button>
        <?php foreach ($categories as $cat): ?>
        <button type="button" class="reports-cat-tab" data-category="<?= htmlspecialchars($cat['id'], ENT_QUOTES) ?>">
            <i class="fas <?= htmlspecialchars($cat['icon'], ENT_QUOTES) ?>"></i>
            <?= htmlspecialchars($cat['label'], ENT_QUOTES) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <section id="reportsPinnedSection" class="rpt-no-print" style="display:none">
        <div class="reports-section-title"><i class="fas fa-star text-warning me-1"></i> Pinned</div>
        <div class="reports-bento" id="reportsPinnedGrid"></div>
    </section>

    <div class="reports-section-title">Report library</div>
    <div class="reports-bento" id="reportsBento">
        <?php foreach ($categories as $cat): ?>
        <div id="cat-<?= htmlspecialchars($cat['id'], ENT_QUOTES) ?>" class="reports-category-block w-100" data-category-anchor="<?= htmlspecialchars($cat['id'], ENT_QUOTES) ?>"></div>
            <?php foreach ($cat['reports'] as $r):
                $searchBlob = strtolower($r['title'] . ' ' . $r['tagline'] . ' ' . implode(' ', $r['tags'] ?? []));
                $configureUrl = BASE_URL . ($r['route'] ?? '');
                $runUrl = ReportsCatalog::buildRunUrl($r, 'mtd');
            ?>
            <article class="reports-card"
                     data-report-id="<?= htmlspecialchars($r['id'], ENT_QUOTES) ?>"
                     data-category="<?= htmlspecialchars($cat['id'], ENT_QUOTES) ?>"
                     data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>">
                <div class="reports-card-head">
                    <div class="reports-card-icon <?= htmlspecialchars($cat['accent'], ENT_QUOTES) ?>">
                        <i class="fas <?= htmlspecialchars($r['icon'], ENT_QUOTES) ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h4><?= htmlspecialchars($r['title'], ENT_QUOTES) ?></h4>
                        <p class="card-tagline"><?= htmlspecialchars($r['tagline'], ENT_QUOTES) ?></p>
                    </div>
                    <button type="button" class="reports-pin" data-report-id="<?= htmlspecialchars($r['id'], ENT_QUOTES) ?>" aria-pressed="false" title="Pin">
                        <i class="fas fa-star"></i>
                    </button>
                </div>
                <div class="reports-card-tags">
                    <?php foreach ($r['tags'] ?? [] as $tag): ?>
                    <span><?= htmlspecialchars($tag, ENT_QUOTES) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="reports-card-actions">
                    <a href="<?= htmlspecialchars($runUrl, ENT_QUOTES) ?>"
                       class="btn btn-primary btn-sm"
                       data-lens-run="1"
                       data-filter-type="<?= htmlspecialchars($r['filter_type'] ?? 'range', ENT_QUOTES) ?>"
                       data-preset-days="<?= (int)($r['preset_days'] ?? 30) ?>">
                        <i class="fas fa-play me-1"></i> Run
                    </a>
                    <a href="<?= htmlspecialchars($configureUrl, ENT_QUOTES) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-sliders me-1"></i> Configure
                    </a>
                </div>
            </article>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <div id="reportsEmptySearch" class="reports-empty-search">
        <i class="fas fa-magnifying-glass-chart fa-2x mb-2 d-block"></i>
        <p>No reports match your search. Try another keyword or category.</p>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/ReportsHub.js"></script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';