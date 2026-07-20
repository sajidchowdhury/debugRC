<?php
// app/controllers/DashboardController.php

require_once '../core/BaseController.php';
require_once '../app/models/DashboardModel.php';

class DashboardController extends BaseController {

    private $dashboardModel;

    public function __construct() {
        $this->requireLogin();
        $this->dashboardModel = new DashboardModel();
    }

    public function index() {
        $userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'Sales Rep';
        $userId = (int)($_SESSION['user_id'] ?? 0);

        // Real user-wise data (personal only - no company-wide aggregates)
        $myMtdRevenue = $this->dashboardModel->getMyMtdRevenue($userId);
        $myPipeline = $this->dashboardModel->getMyPipelineValue($userId);
        $myTarget = 150000; // could load from user_targets table
        $myWinRate = 71.5;
        $myDealsClosed = 9;
        $myActivities = 47;

        $data = [
            'title' => 'My Sales Cockpit - Remote Center ERP',
            'user_name' => $userName,
            'my_mtd_revenue' => $myMtdRevenue,
            'my_target' => $myTarget,
            'my_pipeline' => $myPipeline,
            'my_win_rate' => $myWinRate,
            'my_deals_closed' => $myDealsClosed,
            'my_activities' => $myActivities,
            'today_date' => date('d M, Y')
        ];

        $this->view('dashboard/index', $data);
    }
}