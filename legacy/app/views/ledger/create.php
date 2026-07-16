<?php
ob_start();
$title = $title ?? 'New ledger account';
$parentOptions = $parentOptions ?? [];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/ledger-theme.css">

<div class="branch-hub ledger-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-plus me-2"></i>New ledger account</h1>
            <p>Add a GL head for reports and automated journal posting. Use quick scenarios if you are not an accountant.</p>
            <span class="hero-badge"><i class="fas fa-wand-magic-sparkles"></i> Smart suggestions</span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>ledger" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Chart of accounts
            </a>
        </div>
    </header>

    <div class="branch-form-layout has-aside">
        <div class="branch-form-panel">

            <!-- ===================================================== -->
            <!-- CREATIVE HELP FOR NON-ACCOUNTANTS -->
            <!-- ===================================================== -->
            <div id="ledger-scenario-helper" class="card shadow-sm border-0 mb-4 branch-form-section" style="border-left: 4px solid #4f46e5 !important;">
                <div class="card-header bg-primary text-white py-2">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-magic me-2"></i> 
                        Don't know accounting? Just tell us what you want to record
                    </h6>
                </div>
                <div class="card-body py-3">
                    <p class="small text-muted mb-3">Click the option that matches what you want to record. We will fill the correct type and nature for you.</p>
                    
                    <div class="row g-2">
                        <!-- 1 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('customer_receivable', this)">
                                <i class="fas fa-handshake fa-2x text-primary mb-1"></i>
                                <div class="small fw-medium">Customers still have to pay me</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Customer Receivable</div>
                            </div>
                        </div>
                        <!-- 2 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('supplier_payable', this)">
                                <i class="fas fa-truck-loading fa-2x text-warning mb-1"></i>
                                <div class="small fw-medium">I have to pay my suppliers</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Supplier Payable</div>
                            </div>
                        </div>
                        <!-- 3 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('cash_bank', this)">
                                <i class="fas fa-wallet fa-2x text-success mb-1"></i>
                                <div class="small fw-medium">Cash in hand or bank balance</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Cash &amp; Bank</div>
                            </div>
                        </div>
                        <!-- 4 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('inventory', this)">
                                <i class="fas fa-boxes fa-2x text-info mb-1"></i>
                                <div class="small fw-medium">Stock lying in godown</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Inventory / Stock</div>
                            </div>
                        </div>
                        <!-- 5 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('sales_revenue', this)">
                                <i class="fas fa-chart-line fa-2x text-success mb-1"></i>
                                <div class="small fw-medium">Sales I made to customers</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Sales Revenue</div>
                            </div>
                        </div>
                        <!-- 6 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('payroll_expense', this)">
                                <i class="fas fa-users fa-2x text-danger mb-1"></i>
                                <div class="small fw-medium">Salaries of staff &amp; salesmen</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Payroll Expense</div>
                            </div>
                        </div>
                        <!-- 7 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('transport_expense', this)">
                                <i class="fas fa-truck fa-2x text-primary mb-1"></i>
                                <div class="small fw-medium">Transportation &amp; delivery charges</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Operating Expense</div>
                            </div>
                        </div>
                        <!-- 8 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('fixed_asset', this)">
                                <i class="fas fa-car fa-2x text-dark mb-1"></i>
                                <div class="small fw-medium">New delivery vehicle or machine</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Fixed Asset</div>
                            </div>
                        </div>
                        <!-- 9 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('godown_rent', this)">
                                <i class="fas fa-warehouse fa-2x text-secondary mb-1"></i>
                                <div class="small fw-medium">Godown rent, electricity, utilities</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Operating Expense</div>
                            </div>
                        </div>
                        <!-- 10 -->
                        <div class="col-6 col-md-4 col-lg-3">
                            <div class="scenario-card p-2 border rounded text-center h-100" 
                                 onclick="applyScenario('cogs', this)">
                                <i class="fas fa-dolly fa-2x text-warning mb-1"></i>
                                <div class="small fw-medium">Cost of the goods I sold</div>
                                <div class="text-muted" style="font-size: 0.7rem;">Cost of Goods Sold</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            These suggestions are designed for trading &amp; distribution businesses like yours.
                        </small>
                    </div>
                </div>
            </div>
            <!-- End Creative Help Section -->

            <form method="POST" action="<?= BASE_URL ?>ledger/store" id="ledgerCreateForm">
                <!-- CSRF Protection -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                <div class="row g-4">

                    <!-- Basic Information -->
                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-light py-3">
                                <h5 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2"></i> Basic Information</h5>
                            </div>
                            <div class="card-body">

                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label fw-medium">Ledger Name <span class="text-danger">*</span></label>
                                        <input type="text" name="ledger_name" class="form-control" required placeholder="e.g. Office Rent Expense">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium d-flex align-items-center">
                                            Account Type <span class="text-danger">*</span>
                                            <i class="fas fa-question-circle ms-1 text-muted" 
                                               style="cursor: help;" 
                                               title="Asset = Things you own (cash, stock, vehicles)
Liability = Things you owe (suppliers, loans)
Equity = Owner's money in the business
Income = Money you earned
Expense = Money you spent"></i>
                                        </label>
                                        <select name="account_type" class="form-select" required>
                                            <option value="Asset">Asset</option>
                                            <option value="Liability">Liability</option>
                                            <option value="Equity">Equity</option>
                                            <option value="Income">Income</option>
                                            <option value="Expense" selected>Expense</option>
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label fw-medium d-flex align-items-center">
                                            Ledger Nature <span class="text-danger">*</span>
                                            <i class="fas fa-question-circle ms-1 text-muted" 
                                               style="cursor: help;" 
                                               title="This is the most important field for the system.
It tells our accounting engine exactly how to use this account in reports and automatic journal entries (Sales, Purchases, etc.)."></i>
                                        </label>
                                        <select name="ledger_nature" class="form-select" required>
                                            <option value="">— Select Nature —</option>
                                            <?php
                                            $selectedNature = '';
                                            require __DIR__ . '/_nature_options.php';
                                            ?>
                                        </select>
                                        <div class="form-text">Tells the accounting engine how this ledger should behave in automatic postings and reports.</div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Accounting Behavior -->
                    <div class="col-lg-5">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-light py-3">
                                <h5 class="mb-0 fw-semibold"><i class="fas fa-balance-scale me-2"></i> Accounting Behavior</h5>
                            </div>
                            <div class="card-body">

                                <div class="mb-3">
                                    <label class="form-label fw-medium">Normal Balance <span class="text-danger">*</span></label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="normal_balance" id="nb_debit" value="debit" checked>
                                        <label class="btn btn-outline-primary" for="nb_debit">Debit</label>

                                        <input type="radio" class="btn-check" name="normal_balance" id="nb_credit" value="credit">
                                        <label class="btn btn-outline-primary" for="nb_credit">Credit</label>
                                    </div>
                                    <div class="form-text">Most assets & expenses are Debit. Most liabilities, equity & income are Credit.</div>
                                </div>

                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_control_account" value="1" id="is_control">
                                    <label class="form-check-label" for="is_control">
                                        This is a Control Account
                                    </label>
                                </div>

                                <div class="mb-3" id="control_type_section" style="display: none;">
                                    <label class="form-label fw-medium">Control Account Type</label>
                                    <select name="control_account_type" class="form-select">
                                        <option value="">— Select Type —</option>
                                        <option value="customer">Customer (Accounts Receivable)</option>
                                        <option value="supplier">Supplier (Accounts Payable)</option>
                                        <option value="employee">Employee</option>
                                        <option value="bank">Bank / Cash</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>

                <!-- Advanced Settings -->
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-header bg-light py-3">
                        <h5 class="mb-0 fw-semibold"><i class="fas fa-cog me-2"></i> Advanced Settings</h5>
                    </div>
                    <div class="card-body">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Parent Ledger</label>
                                <select name="parent_id" class="form-select">
                                    <?php
                                    $selectedParentId = 0;
                                    require __DIR__ . '/_parent_options.php';
                                    ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-medium">Sort Order</label>
                                <input type="number" name="sort_order" class="form-control" value="0">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-medium">Status</label>
                                <input type="hidden" name="is_active" value="0">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="create_is_active" checked>
                                    <label class="form-check-label" for="create_is_active">Active</label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-medium">Description</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Optional description for this ledger..."></textarea>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="branch-form-footer">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-check me-1"></i> Create ledger
                    </button>
                    <a href="<?= BASE_URL ?>ledger" class="btn btn-outline-secondary">Cancel</a>
                </div>

            </form>
        </div>

        <aside class="branch-form-aside">
            <div class="aside-title">Live preview</div>
            <div class="branch-preview-card">
                <div class="branch-avatar" id="previewAvatar">?</div>
                <div class="preview-name" id="previewName">Ledger name</div>
                <div class="preview-code" id="previewType">Expense</div>
                <div class="mt-2 small text-muted" id="previewNature">Select nature</div>
            </div>
            <div class="branch-aside-tip">
                <i class="fas fa-lightbulb me-1"></i>
                <strong>Ledger nature</strong> drives automatic posting (sales, purchases, payments). System assigns the next code (e.g. L-0042).
            </div>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const controlCheckbox = document.getElementById('is_control');
    const controlSection = document.getElementById('control_type_section');

    function toggleControlSection() {
        controlSection.style.display = controlCheckbox.checked ? 'block' : 'none';
    }

    controlCheckbox.addEventListener('change', toggleControlSection);
    toggleControlSection(); // initial state

    // =====================================================
    // SMART SUGGESTION SYSTEM FOR NON-ACCOUNTANTS
    // =====================================================

    // Live smart suggestions while typing ledger name
    const nameInput = document.querySelector('input[name="ledger_name"]');
    const accountTypeSelect = document.querySelector('select[name="account_type"]');
    const natureSelect = document.querySelector('select[name="ledger_nature"]');
    const normalDebit = document.getElementById('nb_debit');
    const normalCredit = document.getElementById('nb_credit');
    const isControlCheckbox = document.getElementById('is_control');

    if (nameInput) {
        let suggestionTimeout;
        nameInput.addEventListener('input', function() {
            clearTimeout(suggestionTimeout);
            suggestionTimeout = setTimeout(() => {
                suggestFromName(this.value);
            }, 350);
        });
    }

    // Keyword-based smart suggestion engine
    function suggestFromName(name) {
        if (!name || name.length < 3) return;

        const lower = name.toLowerCase().trim();
        let suggested = null;

        // Define smart rules (order matters - more specific first)
        const rules = [
            { keywords: ['customer', 'receivable', 'due from customer', 'party receivable'], type: 'Asset', nature: 'customer_receivable', control: true, controlType: 'customer' },
            { keywords: ['supplier', 'payable', 'due to supplier', 'party payable'], type: 'Liability', nature: 'supplier_payable', control: true, controlType: 'supplier' },
            { keywords: ['salary', 'wage', 'staff', 'payroll', 'salesman salary', 'employee salary'], type: 'Expense', nature: 'payroll_expense' },
            { keywords: ['transport', 'delivery', 'freight', 'carriage'], type: 'Expense', nature: 'operating_expense' },
            { keywords: ['godown rent', 'warehouse rent', 'electricity', 'utility', 'internet bill'], type: 'Expense', nature: 'operating_expense' },
            { keywords: ['vehicle', 'car', 'truck', 'rickshaw', 'delivery van', 'machine', 'equipment'], type: 'Asset', nature: 'fixed_asset' },
            { keywords: ['inventory', 'stock', 'godown stock', 'goods in hand'], type: 'Asset', nature: 'inventory' },
            { keywords: ['sales', 'sale of goods', 'trading sale'], type: 'Income', nature: 'sales_revenue' },
            { keywords: ['bank charge', 'bank fee', 'interest paid'], type: 'Expense', nature: 'financial_expense' },
            { keywords: ['cash in hand', 'petty cash', 'cash at branch'], type: 'Asset', nature: 'cash_bank' },
            { keywords: ['bank', 'current account', 'savings account'], type: 'Asset', nature: 'cash_bank', control: true, controlType: 'bank' },
            { keywords: ['cogs', 'cost of goods', 'cost of sale'], type: 'Expense', nature: 'cogs' },
            { keywords: ['marketing', 'advertising', 'promotion'], type: 'Expense', nature: 'operating_expense' },
        ];

        for (const rule of rules) {
            if (rule.keywords.some(kw => lower.includes(kw))) {
                suggested = rule;
                break;
            }
        }

        if (suggested) {
            // Apply suggestion
            if (accountTypeSelect) accountTypeSelect.value = suggested.type;
            if (natureSelect && suggested.nature) natureSelect.value = suggested.nature;

            if (suggested.control && isControlCheckbox) {
                isControlCheckbox.checked = true;
                toggleControlSection();
                const controlTypeSelect = document.querySelector('select[name="control_account_type"]');
                if (controlTypeSelect && suggested.controlType) {
                    controlTypeSelect.value = suggested.controlType;
                }
            }

            // Auto set normal balance (good default)
            if (['Asset', 'Expense'].includes(suggested.type) && normalDebit) {
                normalDebit.checked = true;
            } else if (['Liability', 'Equity', 'Income'].includes(suggested.type) && normalCredit) {
                normalCredit.checked = true;
            }

            // Visual feedback
            showSuggestionFeedback(name, suggested);
        }
    }

    // Show nice feedback when we auto-suggested something
    function showSuggestionFeedback(originalName, suggestion) {
        let feedback = document.getElementById('smart-suggestion-feedback');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.id = 'smart-suggestion-feedback';
            feedback.className = 'alert alert-info py-2 mt-2 small';
            nameInput.parentNode.appendChild(feedback);
        }

        const natureLabel = natureSelect ? natureSelect.options[natureSelect.selectedIndex]?.text : suggestion.nature;

        feedback.innerHTML = `
            <i class="fas fa-magic me-1"></i> 
            <strong>We suggested for you:</strong> 
            <span class="badge bg-primary">${suggestion.type}</span> + 
            <span class="badge bg-success">${natureLabel || suggestion.nature}</span>
            <button type="button" class="btn-close btn-close-sm float-end" onclick="this.parentNode.remove()"></button>
        `;
        
        // Auto remove after 6 seconds
        setTimeout(() => {
            if (feedback && feedback.parentNode) feedback.parentNode.removeChild(feedback);
        }, 6500);
    }

    // =====================================================
    // SCENARIO QUICK-CHOICE SYSTEM (Big friendly buttons)
    // =====================================================
    window.applyScenario = function(scenarioKey, element) {
        // Reset visual state
        document.querySelectorAll('.scenario-card').forEach(el => {
            el.classList.remove('selected');
            el.style.borderColor = '';
            el.style.backgroundColor = '';
        });
        if (element) {
            element.classList.add('selected');
            element.style.borderColor = '#4f46e5';
            element.style.backgroundColor = '#eef2ff';
        }

        const suggestions = {
            'customer_receivable': {
                name: 'Accounts Receivable - Customers',
                type: 'Asset',
                nature: 'customer_receivable',
                normal: 'debit',
                control: true,
                controlType: 'customer',
                explanation: 'This records money your customers still have to pay you. It is an important Control Account.'
            },
            'supplier_payable': {
                name: 'Accounts Payable - Suppliers',
                type: 'Liability',
                nature: 'supplier_payable',
                normal: 'credit',
                control: true,
                controlType: 'supplier',
                explanation: 'This records money you owe to your suppliers. Very useful for managing payments.'
            },
            'cash_bank': {
                name: 'Cash in Hand',
                type: 'Asset',
                nature: 'cash_bank',
                normal: 'debit',
                control: false,
                explanation: 'Use this to track physical cash in hand or your main bank account balance.'
            },
            'inventory': {
                name: 'Inventory / Stock',
                type: 'Asset',
                nature: 'inventory',
                normal: 'debit',
                control: false,
                explanation: 'This shows the value of goods/stock currently lying in your godown ready for sale.'
            },
            'sales_revenue': {
                name: 'Sales Revenue',
                type: 'Income',
                nature: 'sales_revenue',
                normal: 'credit',
                control: false,
                explanation: 'Main income from selling goods to customers. This directly affects your profit.'
            },
            'payroll_expense': {
                name: 'Salaries & Wages',
                type: 'Expense',
                nature: 'payroll_expense',
                normal: 'debit',
                control: false,
                explanation: 'Monthly salaries and wages of your staff, salesmen, and workers.'
            },
            'transport_expense': {
                name: 'Transportation & Delivery',
                type: 'Expense',
                nature: 'operating_expense',
                normal: 'debit',
                control: false,
                explanation: 'Freight, transportation, and delivery charges you paid for moving goods.'
            },
            'fixed_asset': {
                name: 'Delivery Vehicle / Machine',
                type: 'Asset',
                nature: 'fixed_asset',
                normal: 'debit',
                control: false,
                explanation: 'Long term assets like delivery vans, rickshaws, machines, or equipment (not for resale).'
            },
            'godown_rent': {
                name: 'Godown Rent & Utilities',
                type: 'Expense',
                nature: 'operating_expense',
                normal: 'debit',
                control: false,
                explanation: 'Rent of godown/warehouse, electricity bills, and other utility expenses.'
            },
            'cogs': {
                name: 'Cost of Goods Sold',
                type: 'Expense',
                nature: 'cogs',
                normal: 'debit',
                control: false,
                explanation: 'The actual purchase cost of the goods you have sold. Important for gross profit calculation.'
            }
        };

        const config = suggestions[scenarioKey];
        if (!config) return;

        // Apply values
        if (nameInput) nameInput.value = config.name;
        if (accountTypeSelect) accountTypeSelect.value = config.type;
        if (natureSelect) natureSelect.value = config.nature;

        // Normal Balance
        if (config.normal === 'debit' && normalDebit) {
            normalDebit.checked = true;
        } else if (config.normal === 'credit' && normalCredit) {
            normalCredit.checked = true;
        }

        // Control Account
        if (isControlCheckbox) {
            isControlCheckbox.checked = !!config.control;
            toggleControlSection();

            if (config.control) {
                const controlTypeSelect = document.querySelector('select[name="control_account_type"]');
                if (controlTypeSelect && config.controlType) {
                    controlTypeSelect.value = config.controlType;
                }
            }
        }

        showScenarioExplanation(config, element);
        if (nameInput) nameInput.dispatchEvent(new Event('input'));
    };

    function showScenarioExplanation(config, clickedElement) {
        // Remove old explanation if exists
        const old = document.getElementById('scenario-explanation');
        if (old) old.remove();

        const explanationBox = document.createElement('div');
        explanationBox.id = 'scenario-explanation';
        explanationBox.className = 'alert alert-success mt-3 mb-0 py-2 small';
        explanationBox.innerHTML = `
            <div class="d-flex">
                <div class="flex-grow-1">
                    <strong><i class="fas fa-check-circle me-1"></i> Great choice!</strong><br>
                    ${config.explanation}
                    <div class="mt-1">
                        <span class="badge bg-primary">${config.type}</span>
                        <span class="badge bg-success">${config.nature}</span>
                        <span class="badge bg-info text-dark">Normal: ${config.normal}</span>
                    </div>
                </div>
                <div>
                    <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
                </div>
            </div>
        `;

        // Insert after the helper card
        const helperCard = document.getElementById('ledger-scenario-helper');
        if (helperCard && helperCard.parentNode) {
            helperCard.parentNode.insertBefore(explanationBox, helperCard.nextSibling);
        } else {
            document.getElementById('ledgerCreateForm')?.prepend(explanationBox);
        }

        setTimeout(() => {
            document.getElementById('ledgerCreateForm')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 400);
    }

    document.querySelectorAll('.scenario-card').forEach(card => {
        card.addEventListener('mouseenter', () => {
            if (!card.classList.contains('selected')) card.style.borderColor = '#4f46e520';
        });
        card.addEventListener('mouseleave', () => {
            if (!card.classList.contains('selected')) card.style.borderColor = '';
        });
    });

    const previewName = document.getElementById('previewName');
    const previewType = document.getElementById('previewType');
    const previewNature = document.getElementById('previewNature');
    const previewAvatar = document.getElementById('previewAvatar');
    function updateLedgerPreview() {
        const name = (nameInput?.value || '').trim();
        const type = accountTypeSelect?.value || '—';
        const natureOpt = natureSelect?.options[natureSelect.selectedIndex];
        const nature = natureOpt?.text || '—';
        if (previewName) previewName.textContent = name || 'Ledger name';
        if (previewType) previewType.textContent = type;
        if (previewNature) previewNature.textContent = nature;
        if (previewAvatar) previewAvatar.textContent = name ? name.charAt(0).toUpperCase() : '?';
    }
    nameInput?.addEventListener('input', updateLedgerPreview);
    accountTypeSelect?.addEventListener('change', updateLedgerPreview);
    natureSelect?.addEventListener('change', updateLedgerPreview);
    updateLedgerPreview();
});
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
?>