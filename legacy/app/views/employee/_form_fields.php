<?php
/**
 * @var array $employee
 * @var array $branches
 * @var array $roles
 * @var bool $isEdit
 */
$employee = $employee ?? [];
$branches = $branches ?? [];
$roles = $roles ?? ['salesman', 'warehouse_manager', 'dispatcher', 'accountant', 'manager', 'hr', 'other'];
$isEdit = !empty($isEdit);
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$selectedBranch = (int)($employee['branch_id'] ?? 0);
$selectedRole = (string)($employee['role'] ?? 'salesman');
?>
<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap indigo"><i class="fas fa-user"></i></span>
        Personal
    </div>
    <div class="row g-3">
        <?php if ($isEdit): ?>
        <div class="col-12 col-md-4">
            <label class="form-label">Employee code</label>
            <input type="text" class="form-control bg-light" readonly
                   value="<?= htmlspecialchars($employee['employee_code'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-8">
        <?php else: ?>
        <div class="col-12">
            <p class="small text-muted mb-2"><i class="fas fa-barcode me-1"></i> Code assigned automatically on save.</p>
        </div>
        <div class="col-12">
        <?php endif; ?>
            <label class="form-label" for="emp_name">Full name <span class="text-danger">*</span></label>
            <input type="text" id="emp_name" name="name" class="form-control" required
                   value="<?= htmlspecialchars($employee['name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="father_name">Father's name</label>
            <input type="text" id="father_name" name="father_name" class="form-control"
                   value="<?= htmlspecialchars($employee['father_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label" for="mother_name">Mother's name</label>
            <input type="text" id="mother_name" name="mother_name" class="form-control"
                   value="<?= htmlspecialchars($employee['mother_name'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="date_of_birth">Date of birth</label>
            <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                   value="<?= htmlspecialchars($employee['date_of_birth'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="nid">NID</label>
            <input type="text" id="nid" name="nid" class="form-control"
                   value="<?= htmlspecialchars($employee['nid'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="blood_group">Blood group</label>
            <input type="text" id="blood_group" name="blood_group" class="form-control" placeholder="e.g. A+"
                   value="<?= htmlspecialchars($employee['blood_group'] ?? '', ENT_QUOTES) ?>">
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-phone"></i></span>
        Contact
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label" for="mobile">Mobile <span class="text-danger">*</span></label>
            <input type="tel" id="mobile" name="mobile" class="form-control" required inputmode="tel"
                   value="<?= htmlspecialchars($employee['mobile'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control" inputmode="email"
                   value="<?= htmlspecialchars($employee['email'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="bank_account">Bank account</label>
            <input type="text" id="bank_account" name="bank_account" class="form-control"
                   value="<?= htmlspecialchars($employee['bank_account'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12">
            <label class="form-label" for="address">Address</label>
            <textarea id="address" name="address" class="form-control" rows="2"><?= htmlspecialchars($employee['address'] ?? '', ENT_QUOTES) ?></textarea>
        </div>
    </div>
</div>

<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap amber"><i class="fas fa-briefcase"></i></span>
        Employment
    </div>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label" for="branch_id">Branch <span class="text-danger">*</span></label>
            <select id="branch_id" name="branch_id" class="form-select" required>
                <option value="">Select branch</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>"
                        <?= (int)$branch['id'] === $selectedBranch ? 'selected' : '' ?>>
                        <?= htmlspecialchars($branch['branch_name'] ?? '', ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="designation">Designation</label>
            <input type="text" id="designation" name="designation" class="form-control"
                   value="<?= htmlspecialchars($employee['designation'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="department">Department</label>
            <input type="text" id="department" name="department" class="form-control"
                   value="<?= htmlspecialchars($employee['department'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="role">Role <span class="text-danger">*</span></label>
            <select id="role" name="role" class="form-select" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= htmlspecialchars($role, ENT_QUOTES) ?>"
                        <?= $role === $selectedRole ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $role)), ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="joining_date">Joining date</label>
            <input type="date" id="joining_date" name="joining_date" class="form-control"
                   value="<?= htmlspecialchars($employee['joining_date'] ?? '', ENT_QUOTES) ?>">
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="salary">Salary (Tk)</label>
            <input type="number" step="0.01" id="salary" name="salary" class="form-control"
                   value="<?= htmlspecialchars((string)($employee['salary'] ?? '0'), ENT_QUOTES) ?>">
        </div>
    </div>
</div>

<?php if ($isEdit): ?>
<div class="branch-form-section">
    <div class="branch-form-section-head">
        <span class="icon-wrap teal"><i class="fas fa-power-off"></i></span>
        Status
    </div>
    <p class="small text-muted mb-2">Cannot deactivate while a linked user account is still active.</p>
    <div class="branch-status-toggle">
        <div class="status-option">
            <input type="radio" name="is_active" id="empActive" value="1"
                   <?= !empty($employee['is_active']) ? 'checked' : '' ?>>
            <label for="empActive" class="active-opt"><i class="fas fa-circle-check"></i> Active</label>
        </div>
        <div class="status-option">
            <input type="radio" name="is_active" id="empInactive" value="0"
                   <?= empty($employee['is_active']) ? 'checked' : '' ?>>
            <label for="empInactive" class="inactive-opt"><i class="fas fa-circle-xmark"></i> Inactive</label>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_photo_field.php'; ?>