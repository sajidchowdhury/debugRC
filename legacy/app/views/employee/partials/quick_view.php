<?php
/**
 * Quick View Modal Content - Phase 2.1
 * Employee Management
 */

$photoPath = !empty($employee['photo']) 
    ? BASE_URL . htmlspecialchars($employee['photo']) 
    : null;

$statusBadge = $employee['is_active'] 
    ? '<span class="badge bg-success px-3 py-2">Active</span>' 
    : '<span class="badge bg-danger px-3 py-2">Inactive</span>';

$roleLabel = ucwords(str_replace('_', ' ', $employee['role'] ?? ''));
?>

<div class="row g-4">
    <!-- Photo Section -->
    <div class="col-md-4 text-center">
        <div class="d-inline-block position-relative">
            <?php if ($photoPath): ?>
                <img src="<?= $photoPath ?>" 
                     class="rounded-circle shadow-sm border" 
                     style="width: 160px; height: 160px; object-fit: cover; border: 4px solid #f8f9fa;"
                     alt="Employee Photo">
            <?php else: ?>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center shadow-sm border"
                     style="width: 160px; height: 160px; border: 4px solid #f8f9fa;">
                    <i class="fas fa-user fa-5x text-secondary"></i>
                </div>
            <?php endif; ?>
        </div>
        <div class="mt-3">
            <?= $statusBadge ?>
        </div>
    </div>

    <!-- Details Section -->
    <div class="col-md-8">
        <div class="mb-3">
            <h4 class="mb-1 fw-semibold"><?= htmlspecialchars($employee['name']) ?></h4>
            <div class="text-muted">
                <strong>Code:</strong> <?= htmlspecialchars($employee['employee_code']) ?>
            </div>
        </div>

        <div class="row g-3">
            <!-- Personal Info -->
            <div class="col-sm-6">
                <div class="small text-muted mb-1">Mobile</div>
                <div class="fw-medium"><?= htmlspecialchars($employee['mobile'] ?? '—') ?></div>
            </div>
            <div class="col-sm-6">
                <div class="small text-muted mb-1">Email</div>
                <div class="fw-medium"><?= htmlspecialchars($employee['email'] ?? '—') ?></div>
            </div>

            <div class="col-sm-6">
                <div class="small text-muted mb-1">Branch</div>
                <div class="fw-medium"><?= htmlspecialchars($employee['branch_name'] ?? '—') ?></div>
            </div>
            <div class="col-sm-6">
                <div class="small text-muted mb-1">Role</div>
                <div class="fw-medium"><?= htmlspecialchars($roleLabel ?: '—') ?></div>
            </div>

            <div class="col-sm-6">
                <div class="small text-muted mb-1">Designation</div>
                <div class="fw-medium"><?= htmlspecialchars($employee['designation'] ?? '—') ?></div>
            </div>
            <div class="col-sm-6">
                <div class="small text-muted mb-1">Department</div>
                <div class="fw-medium"><?= htmlspecialchars($employee['department'] ?? '—') ?></div>
            </div>

            <div class="col-sm-6">
                <div class="small text-muted mb-1">Joining Date</div>
                <div class="fw-medium">
                    <?= !empty($employee['joining_date']) ? date('d M Y', strtotime($employee['joining_date'])) : '—' ?>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="small text-muted mb-1">Blood Group</div>
                <div class="fw-medium"><?= htmlspecialchars($employee['blood_group'] ?? '—') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="border-top pt-3 mt-4">
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>employee/edit/<?= $employee['id'] ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit me-1"></i> Edit Employee
        </a>

        <?php 
            // Check if employee has a user account (we'll enhance this later if needed)
            $hasUser = !empty($employee['has_user_account']) || !empty($employee['has_active_user']);
        ?>
        <?php if ($hasUser && !empty($employee['user_id'])): ?>
            <a href="<?= BASE_URL ?>employee/account/<?= (int)$employee['id'] ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-id-card-clip me-1"></i> Employee &amp; account
            </a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>user/create?employee_id=<?= $employee['id'] ?>" class="btn btn-outline-info btn-sm">
                <i class="fas fa-user-plus me-1"></i> Create User Account
            </a>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>employee" class="btn btn-outline-secondary btn-sm ms-auto">
            <i class="fas fa-list me-1"></i> View All Employees
        </a>
    </div>
</div>