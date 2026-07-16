/**
 * Manual journal create + details (Phase 6A)
 */
(function () {
    'use strict';

    const base = (document.getElementById('base_url') || {}).value || '/';
    const csrf = document.querySelector('[name="csrf_token"]')?.value || window.CSRF_TOKEN || '';

    function money(n) {
        return (Math.round(n * 100) / 100).toFixed(2);
    }

    function parseAmount(el) {
        const v = parseFloat(el?.value || '0');
        return Number.isFinite(v) && v > 0 ? v : 0;
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ---------- Create form ---------- */
    const createRoot = document.getElementById('manualJournalCreate');
    if (createRoot) {
        const form = document.getElementById('manualJournalForm');
        const linesBody = document.getElementById('linesBody');
        const template = document.getElementById('lineRowTemplate');
        const btnAdd = document.getElementById('btnAddLine');
        const btnSubmit = document.getElementById('btnSubmit');
        const totalDebitEl = document.getElementById('totalDebit');
        const totalCreditEl = document.getElementById('totalCredit');
        const balanceStatus = document.getElementById('balanceStatus');
        const balancePreview = document.getElementById('balancePreview');

        function addLine() {
            if (!template || !linesBody) return;
            linesBody.appendChild(template.content.cloneNode(true));
            bindLineEvents(linesBody.lastElementChild);
            recalc();
        }

        function bindLineEvents(row) {
            if (!row) return;
            row.querySelector('.mj-remove')?.addEventListener('click', () => {
                if (linesBody.querySelectorAll('.mj-line-row').length <= 2) {
                    alert('At least two lines are required.');
                    return;
                }
                row.remove();
                recalc();
            });
            row.querySelectorAll('.mj-debit, .mj-credit, .mj-ledger, .mj-line-desc').forEach((el) => {
                el.addEventListener('input', recalc);
                el.addEventListener('change', recalc);
            });
            row.querySelector('.mj-debit')?.addEventListener('input', function () {
                if (parseAmount(this) > 0) {
                    const cr = row.querySelector('.mj-credit');
                    if (cr) cr.value = '';
                }
            });
            row.querySelector('.mj-credit')?.addEventListener('input', function () {
                if (parseAmount(this) > 0) {
                    const dr = row.querySelector('.mj-debit');
                    if (dr) dr.value = '';
                }
            });
        }

        function collectLines() {
            const lines = [];
            linesBody.querySelectorAll('.mj-line-row').forEach((row) => {
                const ledgerId = parseInt(row.querySelector('.mj-ledger')?.value || '0', 10);
                const debit = parseAmount(row.querySelector('.mj-debit'));
                const credit = parseAmount(row.querySelector('.mj-credit'));
                const desc = row.querySelector('.mj-line-desc')?.value?.trim() || '';
                if (ledgerId > 0 && (debit > 0 || credit > 0)) {
                    lines.push({ ledger_id: ledgerId, debit, credit, description: desc });
                }
            });
            return lines;
        }

        function recalc() {
            let totalDr = 0;
            let totalCr = 0;
            let activeLines = 0;

            linesBody.querySelectorAll('.mj-line-row').forEach((row) => {
                const dr = parseAmount(row.querySelector('.mj-debit'));
                const cr = parseAmount(row.querySelector('.mj-credit'));
                const ledgerId = parseInt(row.querySelector('.mj-ledger')?.value || '0', 10);
                totalDr += dr;
                totalCr += cr;
                if (ledgerId > 0 && (dr > 0 || cr > 0)) activeLines++;
                row.classList.toggle('mj-line-error', dr > 0 && cr > 0);
            });

            totalDebitEl.textContent = money(totalDr);
            totalCreditEl.textContent = money(totalCr);

            const balanced = activeLines >= 2 && Math.abs(totalDr - totalCr) < 0.005;
            balanceStatus.textContent = balanced ? 'Balanced ✓' : 'Out of balance';
            balanceStatus.className = 'mj-balance-badge ' + (balanced ? 'balanced' : 'unbalanced');
            btnSubmit.disabled = !balanced;

            renderPreview(totalDr, totalCr, balanced);
        }

        function renderPreview(totalDr, totalCr, balanced) {
            if (!balancePreview) return;
            if (!balanced) {
                const diff = Math.abs(totalDr - totalCr);
                balancePreview.innerHTML = '<p class="small text-danger mb-0">Difference: <strong>' + money(diff) + '</strong></p>';
                return;
            }
            let html = '<table class="table table-sm mb-0 acct-gl-preview-table"><thead><tr><th>Side</th><th class="text-end">Amount</th></tr></thead><tbody>';
            html += '<tr><td>Total debits</td><td class="text-end fw-bold">' + money(totalDr) + '</td></tr>';
            html += '<tr><td>Total credits</td><td class="text-end fw-bold">' + money(totalCr) + '</td></tr></tbody></table>';
            balancePreview.innerHTML = html;
        }

        btnAdd?.addEventListener('click', addLine);
        addLine();
        addLine();

        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (btnSubmit.disabled) return;

            const lines = collectLines();
            const fd = new FormData(form);
            fd.set('lines', JSON.stringify(lines));

            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Posting…';

            try {
                const res = await fetch(base + 'ManualJournal/store', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (data.status === 'success' && data.redirect_url) {
                    window.location.href = data.redirect_url;
                    return;
                }
                alert(data.message || 'Could not post journal.');
            } catch (err) {
                alert('Network error. Please try again.');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '<i class="fas fa-check me-1"></i> Post journal';
                recalc();
            }
        });
    }

    /* ---------- Details reverse ---------- */
    const detailsRoot = document.getElementById('manualJournalDetails');
    if (detailsRoot) {
        detailsRoot.querySelector('.js-mj-reverse')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const id = btn.getAttribute('data-id');
            const entryNo = btn.getAttribute('data-entry-no') || '';
            const reason = prompt('Reason for reversing ' + entryNo + ':');
            if (!reason || !reason.trim()) return;

            const fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('id', id);
            fd.append('reason', reason.trim());

            btn.disabled = true;
            try {
                const res = await fetch(base + 'ManualJournal/reverse', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (data.status === 'success') {
                    window.location.reload();
                    return;
                }
                alert(data.message || 'Could not reverse journal.');
            } catch (err) {
                alert('Network error.');
            } finally {
                btn.disabled = false;
            }
        });
    }
})();
