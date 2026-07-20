<?php
// app/models/BaseModel.php

require_once '../core/Database.php';

abstract class BaseModel {

    protected $db;

    public function __construct() {
        $this->db = new Database();
    }


} // END OF FILE 