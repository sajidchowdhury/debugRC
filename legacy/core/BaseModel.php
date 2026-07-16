<?php
// core/BaseController.php

class BaseController {
    
    public function __construct() {
        // You can add global middleware here later
    }

    protected function isLoggedIn() {
        return Auth::isLoggedIn();
    }

    protected function requireLogin() {
        Auth::requireLogin();
    }
}
?>