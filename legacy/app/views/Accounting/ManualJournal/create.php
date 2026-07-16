<?php
ob_start();
$title = $title ?? 'Post Manual Journal';
$ledgers = $ledgers ?? [];
$branches = $branches ?? [];
$canOverride = !empty($can_override);
$today = $today ?? date('Y-m-d');
$branch_name = $branch_name ?? 'Branch';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/manual-journal-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">

<div class="branch-hub manual-journal-theme acct-money-app container-fluid py-2" id="manualJournalCreate">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i>Post manual journal</h1>
            <p>Multi-line double entry · debits must equal credits before posting</p>
            <span class="hero-badge"><?= htmlspecialchars($branch_name, ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>ManualJournal" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">
            <form id="manualJournalForm" enctype="multipart/form-data" novalidate aria-label="Post manual journal">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

                <div class="branch-form-section">
                    <div class="branch-form-section-head"><span class="icon-wrap indigo"><i class="fas fa-file-lines"></i></span> Header</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="entry_date">Entry date <span class="text-danger">*</span></label>
                            <?php
                            $minPosting = $min_posting_date ?? null;
                            $defaultDate = $today;
                            if ($minPosting && $defaultDate < $minPosting) {
                                $defaultDate = $minPosting;
                            }
                            ?>
                            <input type="date" name="entry_date" id="entry_date" class="form-control"
                                   value="<?= htmlspecialchars($defaultDate, ENT_QUOTES) ?>"
                                   <?= $minPosting ? 'min="' . htmlspecialchars($minPosting, ENT_QUOTES) . '"' : '' ?>
                                   required>
                        </div>
                        <?php if ($canOverride): ?>
                        <div class="col-md-4">
                            <label class="form-label" for="mj_branch_id">Branch <span class="text-danger">*</span></label>
                            <select name="branch_id" id="mj_branch_id" class="form-select" required aria-required="true">
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= (int)($branch_id ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label" for="description">Description / narration <span class="text-danger">*</span></label>
                            <textarea name="description" id="description" class="form-control" rows="2" required aria-required="true" placeholder="Purpose of this journal entry…"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="internal_note">Internal note <span class="text-muted">(optional)</span></label>
                            <textarea name="internal_note" id="internal_note" class="form-control" rows="2" placeholder="Visible on manual journal detail only…"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="attachment">Attachment <span class="text-muted">(optional, max 5 MB)</span></label>
                            <input type="file" name="attachment" id="attachment" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt">
                        </div>
                    </div>
                </div>

                <div class="branch-form-section">
                    <div class="branch-form-section-head d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><span class="icon-wrap teal"><i class="fas fa-table"></i></span> Journal lines</span>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddLine"><i class="fas fa-plus me-1"></i> Add line</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mj-lines-table mb-2" id="linesTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:14rem">Ledger account</th>
                                    <th class="text-end" style="width:8rem">Debit</th>
                                    <th class="text-end" style="width:8rem">Credit</th>
                                    <th style="min-width:10rem">Line note</th>
                                    <th style="width:2.5rem"></th>
                                </tr>
                            </thead>
                            <tbody id="linesBody"></tbody>
                            <tfoot>
                                <tr class="mj-totals-row">
                                    <td class="text-end fw-semibold">Totals</td>
                                    <td class="text-end fw-bold" id="totalDebit">0.00</td>
                                    <td class="text-end fw-bold" id="totalCredit">0.00</td>
                                    <td colspan="2">
                                        <span id="balanceStatus" class="mj-balance-badge unbalanced" role="status" aria-live="polite">Out of balance</span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="branch-form-footer">
                    <a href="<?= BASE_URL ?>ManualJournal" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" id="btnSubmit" disabled><i class="fas fa-check me-1"></i> Post journal</button>
                </div>
            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="branch-form-aside-card">
                <h3><i class="fas fa-scale-balanced me-1"></i> Balance check</h3>
                <p class="small text-muted mb-2">Add at least two lines. Each line is debit <em>or</em> credit. Totals must match within 0.01 Tk.</p>
                <div id="balancePreview" class="acct-gl-preview-panel">
                    <p class="text-muted small mb-0">Enter lines to preview double-entry.</p>
                </div>
            </div>
        </aside>
    </div>
</div>

<template id="lineRowTemplate">
    <tr class="mj-line-row">
        <td>
            <select class="form-select form-select-sm mj-ledger" required>
                <option value="">— Select ledger —</option>
                <?php foreach ($ledgers as $l): ?>
                <option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars(($l['ledger_code'] ?? '') . ' — ' . ($l['ledger_name'] ?? ''), ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="number" class="form-control form-control-sm text-end mj-debit" step="0.01" min="0" placeholder="0.00"></td>
        <td><input type="number" class="form-control form-control-sm text-end mj-credit" step="0.01" min="0" placeholder="0.00"></td>
        <td><input type="text" class="form-control form-control-sm mj-line-desc" placeholder="Optional"></td>
        <td><button type="button" class="btn btn-link btn-sm text-danger p-0 mj-remove" title="Remove line">&times;</button></td>
    </tr>
</template>

<script src="<?= BASE_URL ?>assets/js/manual-journal.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
