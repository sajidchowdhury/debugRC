<?php
// app/controllers/PaymentController.php

require_once '../core/BaseController.php';
require_once '../app/models/SalesModel.php';

class PaymentController extends BaseController {

    private $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new SalesModel();
    }

    // Show receive payment form
    public function receive() {
        $data = [
            'title' => 'Receive Payment from Customer'
        ];
        $this->view('sales/receive_payment', $data);   // we'll create this view next
    }

    // Process payment
    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $result = $this->model->recordCustomerPayment($_POST);
            
            if ($result['status'] === 'success') {
                $_SESSION['success'] = $result['message'];
                $this->redirect('sales/today');   // or wherever you want
            } else {
                $_SESSION['error'] = $result['message'];
                $this->redirect('payment/receive');
            }
        }
    }
}