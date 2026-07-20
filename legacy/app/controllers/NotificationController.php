<?php
require_once '../core/BaseController.php';
require_once '../app/models/NotificationModel.php';

class NotificationController extends BaseController {

    private $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new NotificationModel();
    }

    public function unread() {
        $user_id = $_SESSION['user_id'] ?? 0;
        
        if ($user_id === 0) {
            $this->sendJson(['status' => 'error', 'message' => 'Not logged in']);
            return;
        }

        $notifications = $this->model->getUnreadNotifications($user_id);
        
        $this->sendJson([
            'status' => 'success',
            'notifications' => $notifications
        ]);
    }

    public function mark_all_read() {
        $this->validateCSRF();
        $user_id = $_SESSION['user_id'] ?? 0;
        
        // Optional: Mark all as read
        $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
        $this->model->db->query($sql);
        $this->model->db->bind(1, $user_id);
        $this->model->db->execute();

        $this->sendJson(['status' => 'success']);
    }
}