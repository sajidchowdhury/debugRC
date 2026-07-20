<?php
/**
 * One-shot flash toasts for form redirects (SweetAlert2).
 * Controllers use $_SESSION['success'], $_SESSION['error'], or Flash::set().
 */
require_once __DIR__ . '/../../../core/Flash.php';

$__flashSuccess = $_SESSION['success'] ?? null;
$__flashError = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$__flash = Flash::get();
if ($__flashSuccess === null && $__flashError === null && $__flash === null) {
    return;
}
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($__flashSuccess !== null && $__flashSuccess !== ''): ?>
    Swal.fire({
        title: 'Success',
        text: <?= json_encode((string)$__flashSuccess, JSON_UNESCAPED_UNICODE) ?>,
        icon: 'success',
        confirmButtonColor: '#0f766e'
    });
    <?php endif; ?>

    <?php if ($__flashError !== null && $__flashError !== ''): ?>
    Swal.fire({
        title: 'Could not save',
        text: <?= json_encode((string)$__flashError, JSON_UNESCAPED_UNICODE) ?>,
        icon: 'error',
        confirmButtonColor: '#dc2626'
    });
    <?php endif; ?>

    <?php if ($__flash !== null): ?>
    Swal.fire({
        title: <?= json_encode(match ($__flash['type'] ?? 'info') {
            'success' => 'Success',
            'error' => 'Error',
            'warning' => 'Warning',
            default => 'Notice',
        }, JSON_UNESCAPED_UNICODE) ?>,
        text: <?= json_encode((string)($__flash['message'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        icon: <?= json_encode(match ($__flash['type'] ?? 'info') {
            'success' => 'success',
            'error' => 'error',
            'warning' => 'warning',
            default => 'info',
        }, JSON_UNESCAPED_UNICODE) ?>,
        confirmButtonColor: '#0f766e'
    });
    <?php endif; ?>
});
</script>
