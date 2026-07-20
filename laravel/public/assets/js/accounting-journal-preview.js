/**
 * Shared double-entry GL preview for entity transaction create forms.
 */
(function (global) {
    'use strict';

    const DEFAULT_EMPTY = 'Select party, type, and amount to preview double-entry.';

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function moneyCell(amount) {
        return amount > 0 ? amount.toFixed(2) : '—';
    }

    function resolveCashBank(mode, bankName, glLabels) {
        const labels = glLabels || {};
        return mode === 'bank'
            ? escapeHtml(bankName || labels.bank || 'Bank')
            : escapeHtml(labels.cash || 'Cash');
    }

    function showEmpty(container, message) {
        if (!container) {
            return;
        }
        container.innerHTML = '<p class="text-muted small mb-0">' + escapeHtml(message || DEFAULT_EMPTY) + '</p>';
    }

    function renderTable(container, drAccount, crAccount, amount) {
        if (!container) {
            return;
        }
        container.innerHTML =
            '<table class="table table-sm mb-0 acct-gl-preview-table">' +
            '<thead><tr><th>Account</th><th class="text-end">Dr</th><th class="text-end">Cr</th></tr></thead><tbody>' +
            '<tr><td>' + drAccount + '</td><td class="text-end fw-bold">' + moneyCell(amount) + '</td><td class="text-end">—</td></tr>' +
            '<tr><td>' + crAccount + '</td><td class="text-end">—</td><td class="text-end fw-bold">' + moneyCell(amount) + '</td></tr>' +
            '</tbody></table>';
    }

    function renderCustomerPreview(container, options) {
        const opts = options || {};
        const type = opts.type || '';
        const amount = parseFloat(opts.amount || 0);
        const mode = opts.mode || 'cash';
        const glLabels = opts.glLabels || {};
        const partySelected = opts.partySelected !== false;

        if (!type || amount <= 0 || !partySelected) {
            showEmpty(container, opts.emptyMessage || 'Select customer, type, and amount to preview double-entry.');
            return;
        }

        const ar = escapeHtml(glLabels.ar || 'Accounts Receivable');
        const cashBank = resolveCashBank(mode, opts.bankName, glLabels);
        const discount = escapeHtml(glLabels.discount || 'Sales discount');
        const writeOff = escapeHtml(glLabels.write_off || 'Bad debt expense');
        let drAccount = '';
        let crAccount = '';

        if (type === 'receive') {
            drAccount = cashBank;
            crAccount = ar;
        } else if (type === 'payment') {
            drAccount = ar;
            crAccount = cashBank;
        } else if (type === 'discount') {
            drAccount = discount;
            crAccount = ar;
        } else if (type === 'write_off') {
            drAccount = writeOff;
            crAccount = ar;
        } else {
            showEmpty(container, 'Select a transaction type.');
            return;
        }

        renderTable(container, drAccount, crAccount, amount);
    }

    function renderSupplierPreview(container, options) {
        const opts = options || {};
        const type = opts.type || '';
        const amount = parseFloat(opts.amount || 0);
        const mode = opts.mode || 'cash';
        const glLabels = opts.glLabels || {};
        const partySelected = opts.partySelected !== false;

        if (!type || amount <= 0 || !partySelected) {
            showEmpty(container, opts.emptyMessage || 'Select supplier, type, and amount to preview double-entry.');
            return;
        }

        const ap = escapeHtml(glLabels.ap || 'Supplier Payable');
        const cashBank = resolveCashBank(mode, opts.bankName, glLabels);
        let drAccount = '';
        let crAccount = '';

        if (type === 'payment' || type === 'advance') {
            drAccount = ap;
            crAccount = cashBank;
        } else if (type === 'receive') {
            drAccount = cashBank;
            crAccount = ap;
        } else {
            showEmpty(container, 'Select a transaction type.');
            return;
        }

        renderTable(container, drAccount, crAccount, amount);
    }

    function renderEmployeePreview(container, options) {
        const opts = options || {};
        const type = opts.type || '';
        const amount = parseFloat(opts.amount || 0);
        const mode = opts.mode || 'cash';
        const glLabels = opts.glLabels || {};
        const partySelected = opts.partySelected !== false;
        const outflow = ['advance', 'loan', 'salary', 'adjustment'];
        const inflow = ['repayment', 'deduction'];

        if (!type || amount <= 0 || !partySelected) {
            showEmpty(container, opts.emptyMessage || 'Select employee, type, and amount to preview double-entry.');
            return;
        }

        const empPayable = escapeHtml(glLabels.employee_payable || 'Employee Payable');
        const cashBank = resolveCashBank(mode, opts.bankName, glLabels);
        let drAccount = '';
        let crAccount = '';

        if (outflow.includes(type)) {
            drAccount = empPayable;
            crAccount = cashBank;
        } else if (inflow.includes(type)) {
            drAccount = cashBank;
            crAccount = empPayable;
        } else {
            showEmpty(container, 'Select a transaction type.');
            return;
        }

        renderTable(container, drAccount, crAccount, amount);
    }

    global.AccountingJournalPreview = {
        escapeHtml,
        moneyCell,
        renderTable,
        showEmpty,
        renderCustomerPreview,
        renderSupplierPreview,
        renderEmployeePreview,
    };
})(window);
