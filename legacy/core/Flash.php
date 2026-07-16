<?php
// core/Flash.php

class Flash {

    public static function set($message, $type = 'error') {
        $_SESSION['flash'] = [
            'message' => $message,
            'type'    => $type   // error, success, info, warning
        ];
    }

    public static function get() {
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);   // Flash once only
            return $flash;
        }
        return null;
    }

    public static function has() {
        return isset($_SESSION['flash']);
    }
}
?>