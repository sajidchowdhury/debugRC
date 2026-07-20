<?php
$title = 'Change Password';
require_once __DIR__ . '/../../../core/PasswordPolicy.php';

$content = '
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-key me-2"></i> Change Your Password</h4>
                </div>
                <div class="card-body">

                    <form method="POST" action="' . BASE_URL . 'user/update_password">
                        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '">

                        <div class="mb-3">
                            <label class="form-label">Current Password <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" class="form-control" required autofocus>
                        </div>

                        <hr>

                        <div class="mb-3">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" class="form-control" required>
                            <div class="form-text">
                                ' . htmlspecialchars(PasswordPolicy::requirementsText(), ENT_QUOTES) . '
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="' . BASE_URL . 'dashboard" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Update Password
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>';

require_once '../app/views/layouts/main.php';
?>