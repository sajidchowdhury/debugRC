<?php
// Creative fixed footer — click opens drop-up quick tools
require_once __DIR__ . '/../../../core/Auth.php';
require_once __DIR__ . '/../../../core/InvestigationMode.php';

$showInvestigationIcon = Auth::isLoggedIn() && InvestigationMode::isGloballyActive();
?>
<div class="mt-5"></div>
<div class="creative-footer<?= $showInvestigationIcon ? ' has-investigation-icon' : '' ?>" id="creativeFooter">
    <?php if ($showInvestigationIcon): ?>
    <span class="footer-investigation-icon" title="Investigation mode active">
        <i class="fas fa-user-secret" aria-hidden="true"></i>
    </span>
    <?php endif; ?>
    <button
        type="button"
        class="footer-dropup-trigger"
        id="footerDropupTrigger"
        aria-expanded="false"
        aria-controls="footerDropupPanel"
        aria-label="Open quick tools"
    >
        <span class="footer-dropup-trigger-glow" aria-hidden="true"></span>
        <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
        <span class="footer-dropup-trigger-label">Creative Guideline</span>
        <i class="fas fa-chevron-up footer-dropup-chevron" aria-hidden="true"></i>
    </button>

    <div class="footer-dropup-backdrop" id="footerDropupBackdrop" hidden></div>

    <div
        class="footer-dropup-panel"
        id="footerDropupPanel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="footerDropupTitle"
        aria-hidden="true"
    >
        <div class="footer-dropup-handle" aria-hidden="true"></div>
        <div class="footer-dropup-head">
            <div>
                <p class="footer-dropup-eyebrow">MY CREATIVE CODE</p>
                <h2 class="footer-dropup-title" id="footerDropupTitle">user guideline</h2>
            </div>
            <button type="button" class="footer-dropup-close" id="footerDropupClose" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <nav class="footer-dropup-nav">
            
            <a href="<?= BASE_URL ?>sales/guide" class="footer-dropup-link mt-2">
                <span class="footer-dropup-link-icon"><i class="fas fa-clipboard-check"></i></span>
                <span class="footer-dropup-link-text">
                    <strong>Sales Guideline</strong>
                    <small>End-user guide for the sales ecosystem</small>
                </span>
                <i class="fas fa-arrow-right footer-dropup-link-arrow"></i>
            </a>

            <a href="<?= BASE_URL ?>Accounting/guide" class="footer-dropup-link mt-2">
                <span class="footer-dropup-link-icon"><i class="fas fa-clipboard-check"></i></span>
                <span class="footer-dropup-link-text">
                    <strong>Account Guideline</strong>
                    <small>End-user guide for the Account ecosystem</small>
                </span>
                <i class="fas fa-arrow-right footer-dropup-link-arrow"></i>
            </a>


            <a href="<?= BASE_URL ?>sales/go_live_checklist" class="footer-dropup-link">
                <span class="footer-dropup-link-icon"><i class="fas fa-rocket"></i></span>
                <span class="footer-dropup-link-text">
                    <strong>Sales Go-Live Checklist</strong>
                    <small>Stock, GL, cron, reversals — launch sign-off</small>
                </span>
                <i class="fas fa-arrow-right footer-dropup-link-arrow"></i>
            </a>
        </nav>
    </div>
</div>