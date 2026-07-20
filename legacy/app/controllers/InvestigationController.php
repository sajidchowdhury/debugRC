<?php
// app/controllers/InvestigationController.php

require_once '../core/BaseController.php';
require_once '../core/InvestigationMode.php';
require_once '../core/UserAudit.php';
require_once '../app/models/UserModel.php';

class InvestigationController extends BaseController {

    /**
     * QR scan — single entry point (logged-in activator only).
     * OFF → auto-activate. ON → auto-email OTP + show code form.
     */
    public function scan() {
        $token = trim((string)($_GET['t'] ?? ''));

        if (!InvestigationMode::validateQrToken($token)) {
            Flash::set('Invalid or missing investigation QR code.', 'error');
            $this->redirect('dashboard');
            return;
        }

        if (!Auth::isLoggedIn()) {
            $_SESSION['post_login_redirect'] = 'investigation/scan?t=' . urlencode($token);
            Auth::requireLogin();
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (!InvestigationMode::isActivator($userId)) {
            Flash::set('You are not authorized to manage investigation mode.', 'error');
            $this->redirect('dashboard');
            return;
        }

        // POST: enter deactivation OTP only
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleDeactivatePost($token, $userId);
            return;
        }

        if (!InvestigationMode::isGloballyActive()) {
            $result = InvestigationMode::activate($userId, 'QR scan');
            if ($result['status'] === 'success') {
                $audit = new UserAudit();
                $audit->log($userId, 'investigation_mode_activated', null, [
                    'window_id' => $result['window_id'] ?? null,
                    'source'    => 'qr_scan',
                ]);
                InvestigationMode::syncSessionWithDatabase();
                Flash::set($result['message'], 'success');
                $this->view('investigation/scan', [
                    'title'     => 'Investigation mode ON',
                    'mode'      => 'activated',
                    'scanToken' => $token,
                ]);
                return;
            }

            Flash::set($result['message'], 'error');
            $this->view('investigation/scan', [
                'title'       => 'Investigation mode',
                'mode'        => 'error',
                'scanToken'   => $token,
                'errorMessage'=> $result['message'],
            ]);
            return;
        }

        // Already ON — email OTP (rate-limited) and show entry form
        $companyEmail = InvestigationMode::companyEmail();
        $otpError = null;
        $otpSent = false;
        $devOtp = null;
        $emailDelivered = false;

        if ($companyEmail === '') {
            $otpError = 'Company email is not configured (INVESTIGATION_COMPANY_EMAIL in config/local.php).';
        } else {
            $lastSent = (int)($_SESSION['investigation_otp_sent_at'] ?? 0);
            if ($lastSent === 0 || (time() - $lastSent) > 120) {
                $sendResult = InvestigationMode::sendDeactivationOtp($userId);
                if ($sendResult['status'] === 'success') {
                    $_SESSION['investigation_otp_sent_at'] = time();
                    $otpSent = true;
                    $emailDelivered = !empty($sendResult['email_delivered']);
                    if (!empty($sendResult['dev_otp'])) {
                        $_SESSION['investigation_dev_otp'] = $sendResult['dev_otp'];
                    }
                } else {
                    $otpError = $sendResult['message'];
                }
            } else {
                $otpSent = true;
                $emailDelivered = empty($_SESSION['investigation_dev_otp']);
            }
            $devOtp = $_SESSION['investigation_dev_otp'] ?? null;
        }

        $this->view('investigation/scan', [
            'title'          => 'End investigation mode',
            'mode'           => 'deactivate',
            'scanToken'      => $token,
            'companyEmail'   => $companyEmail,
            'otpSent'        => $otpSent,
            'otpError'       => $otpError,
            'devOtp'         => $devOtp,
            'emailDelivered' => $emailDelivered,
            'activeWindow'   => InvestigationMode::getActiveWindow(),
        ]);
    }

    private function handleDeactivatePost(string $token, int $userId): void
    {
        $this->validateCSRF();

        if (!InvestigationMode::validateQrToken($token)) {
            Flash::set('Invalid investigation QR token.', 'error');
            $this->redirect('dashboard');
            return;
        }

        if (!InvestigationMode::isActivator($userId)) {
            Flash::set('You are not authorized to deactivate investigation mode.', 'error');
            $this->redirect('dashboard');
            return;
        }

        $result = InvestigationMode::deactivate((string)($_POST['otp'] ?? ''), $userId);

        if ($result['status'] === 'success') {
            unset($_SESSION['investigation_otp_sent_at'], $_SESSION['investigation_dev_otp']);
            $audit = new UserAudit();
            $audit->log($userId, 'investigation_mode_deactivated', null, ['source' => 'qr_scan']);
            Flash::set($result['message'], 'success');
            $this->redirect('dashboard');
            return;
        }

        Flash::set($result['message'], 'error');
        $this->redirect('investigation/scan?t=' . urlencode($token));
    }

    /**
     * Superadmin setup: activators, QR print sheet, company email.
     */
    public function settings() {
        Auth::requireSuperadmin();

        $userModel = new UserModel();

        $scanUrl = InvestigationMode::scanUrl();
        $qrDataUri = '';
        if ($scanUrl !== '' && InvestigationMode::qrSecret() !== '') {
            require_once __DIR__ . '/../../core/QrRenderer.php';
            try {
                $qrDataUri = QrRenderer::pngDataUri($scanUrl, 240);
            } catch (Throwable $e) {
                error_log('Investigation QR render failed: ' . $e->getMessage());
            }
        }

        $this->view('investigation/settings', [
            'title'        => 'Investigation mode setup',
            'activators'   => InvestigationMode::listActivators(),
            'users'        => $userModel->getAllUsers(),
            'scanUrl'      => $scanUrl,
            'qrDataUri'    => $qrDataUri,
            'qrToken'      => InvestigationMode::qrToken(),
            'companyEmail' => InvestigationMode::companyEmail(),
            'qrConfigured' => InvestigationMode::qrSecret() !== '',
            'isActive'     => InvestigationMode::isGloballyActive(),
            'activeWindow' => InvestigationMode::getActiveWindow(),
            'investigation_period' => InvestigationMode::getReportPeriod(),
        ]);
    }

    public function save_activator() {
        Auth::requireSuperadmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('investigation/settings');
            return;
        }

        $this->validateCSRF();

        $userId = (int)($_POST['user_id'] ?? 0);
        $label = (string)($_POST['label'] ?? '');

        if ($userId <= 0) {
            Flash::set('Select a user.', 'error');
            $this->redirect('investigation/settings');
            return;
        }

        $result = InvestigationMode::addActivator($userId, $label);

        if ($result['status'] === 'success') {
            $audit = new UserAudit();
            $audit->log((int)($_SESSION['user_id'] ?? 0), 'investigation_activator_added', $userId, ['label' => $label]);
        }

        Flash::set($result['message'], $result['status'] === 'success' ? 'success' : 'error');
        $this->redirect('investigation/settings');
    }

    public function remove_activator($id = null) {
        Auth::requireSuperadmin();

        if (!$id) {
            $this->redirect('investigation/settings');
            return;
        }

        $result = InvestigationMode::removeActivator((int)$id);

        if ($result['status'] === 'success') {
            $audit = new UserAudit();
            $audit->log((int)($_SESSION['user_id'] ?? 0), 'investigation_activator_removed', (int)$id);
        }

        Flash::set($result['message'], $result['status'] === 'success' ? 'success' : 'error');
        $this->redirect('investigation/settings');
    }
}
