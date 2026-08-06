/**
 * Manual journal create + details (Phase 6)
 *
 * Rewritten to use Laravel named routes (via MJ_BOOT config) instead of the
 * legacy CodeIgniter-style URLs (ManualJournal/store, ManualJournal/reverse).
 * Matches the element IDs in resources/views/admin/manual-journals/create.blade.php
 * and show.blade.php.
 */
(function () {
    'use strict';

    var csrf = window.MJ_BOOT?.csrf_token || document.querySelector('meta[name="csrf-token"]')?.content || '';

    function money(n) {
        return (Math.round(n * 100) / 100).toFixed(2);
    }

    function parseAmount(el) {
        var v = parseFloat(el?.value || '0');
        return Number.isFinite(v) && v > 0 ? v : 0;
    }

    /* ---------- Create form ---------- */
    var createRoot = document.getElementById('manualJournalCreate');
    if (createRoot) {
        var form = document.getElementById('manualJournalForm');
        var linesBody = document.getElementById('linesBody');
        var template = document.getElementById('lineRowTemplate');
        var btnAdd = document.getElementById('btnAddLine');
        var btnSubmit = document.getElementById('btnSubmit');
        var btnSaveDraft = document.getElementById('btnSaveDraft');
        var totalDebitCell = document.getElementById('totalDebitCell');
        var totalCreditCell = document.getElementById('totalCreditCell');
        var balanceBadge = document.getElementById('balanceBadge');
        var linesInput = document.getElementById('linesInput');
        var statusInput = document.getElementById('statusInput');

        function addLine() {
            if (!template || !linesBody) return;
            linesBody.appendChild(template.content.cloneNode(true));
            bindLineEvents(linesBody.lastElementChild);
            recalc();
        }

        function bindLineEvents(row) {
            if (!row) return;
            row.querySelector('.mj-remove')?.addEventListener('click', function () {
                if (linesBody.querySelectorAll('.mj-line-row').length <= 2) {
                    alert('At least two lines are required.');
                    return;
                }
                row.remove();
                recalc();
            });
            row.querySelectorAll('.mj-debit, .mj-credit, .mj-ledger, .mj-line-desc, .mj-dim-value').forEach(function (el) {
                el.addEventListener('input', recalc);
                el.addEventListener('change', recalc);
            });
            row.querySelector('.mj-debit')?.addEventListener('input', function () {
                if (parseAmount(this) > 0) {
                    var cr = row.querySelector('.mj-credit');
                    if (cr) cr.value = '';
                }
            });
            row.querySelector('.mj-credit')?.addEventListener('input', function () {
                if (parseAmount(this) > 0) {
                    var dr = row.querySelector('.mj-debit');
                    if (dr) dr.value = '';
                }
            });
        }

        function collectLines() {
            var lines = [];
            linesBody.querySelectorAll('.mj-line-row').forEach(function (row) {
                var ledgerId = parseInt(row.querySelector('.mj-ledger')?.value || '0', 10);
                var debit = parseAmount(row.querySelector('.mj-debit'));
                var credit = parseAmount(row.querySelector('.mj-credit'));
                var desc = row.querySelector('.mj-line-desc')?.value?.trim() || '';
                // G-321 (MEDIUM-WAVE-3): per-line dimension tag (optional).
                // Empty string → 0 → server treats as null (no tag).
                var dimValueId = parseInt(row.querySelector('.mj-dim-value')?.value || '0', 10);
                if (ledgerId > 0 && (debit > 0 || credit > 0)) {
                    lines.push({
                        ledger_id: ledgerId,
                        debit: debit,
                        credit: credit,
                        description: desc,
                        dimension_value_id: dimValueId || null
                    });
                }
            });
            return lines;
        }

        function recalc() {
            var totalDr = 0;
            var totalCr = 0;
            var activeLines = 0;

            linesBody.querySelectorAll('.mj-line-row').forEach(function (row) {
                var dr = parseAmount(row.querySelector('.mj-debit'));
                var cr = parseAmount(row.querySelector('.mj-credit'));
                var ledgerId = parseInt(row.querySelector('.mj-ledger')?.value || '0', 10);
                totalDr += dr;
                totalCr += cr;
                if (ledgerId > 0 && (dr > 0 || cr > 0)) activeLines++;
            });

            if (totalDebitCell) totalDebitCell.textContent = money(totalDr);
            if (totalCreditCell) totalCreditCell.textContent = money(totalCr);

            var balanced = activeLines >= 2 && Math.abs(totalDr - totalCr) < 0.005;
            if (balanceBadge) {
                balanceBadge.textContent = balanced ? 'Balanced ✓' : 'Out of balance';
                balanceBadge.className = 'badge ' + (balanced ? 'bg-success' : 'bg-danger');
            }
            if (btnSubmit) btnSubmit.disabled = !balanced;

            // Update the hidden lines input for form submission.
            if (linesInput) {
                linesInput.value = JSON.stringify(collectLines());
            }
        }

        btnAdd?.addEventListener('click', addLine);

        // Start with 2 empty lines.
        addLine();
        addLine();

        // "Save as draft" button — sets status to draft then submits.
        btnSaveDraft?.addEventListener('click', function (e) {
            e.preventDefault();
            if (statusInput) statusInput.value = 'draft';
            // Drafts don't need to be balanced — enable submit temporarily.
            if (btnSubmit) btnSubmit.disabled = false;
            // Recompute lines input before submit.
            if (linesInput) linesInput.value = JSON.stringify(collectLines());
            if (form) form.requestSubmit(btnSubmit);
        });

        // "Post journal" button — ensures status is post.
        btnSubmit?.addEventListener('click', function () {
            if (statusInput) statusInput.value = 'post';
            if (linesInput) linesInput.value = JSON.stringify(collectLines());
        });

        // Intercept form submit for AJAX.
        form?.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (btnSubmit && btnSubmit.disabled && statusInput.value !== 'draft') return;

            var lines = collectLines();
            if (linesInput) linesInput.value = JSON.stringify(lines);

            var fd = new FormData(form);
            fd.set('lines', JSON.stringify(lines));

            var orig = btnSubmit?.innerHTML;
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
            }

            try {
                var res = await fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                var data = await res.json();
                if (data.status === 'success') {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        window.location.href = window.MJ_BOOT?.routes?.index || '/admin/manual-journals';
                    }
                    return;
                }
                alert(data.message || 'Could not save journal.');
            } catch (err) {
                alert('Network error. Please try again.');
            } finally {
                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = orig;
                }
                recalc();
            }
        });
    }
})();
