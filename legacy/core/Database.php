<?php
// core/Database.php

class Database {
    // BUGFIX (Phase 0): previously hard-coded to localhost/root/''/osudlagb_remotecenter,
    // ignoring the DB_HOST/DB_USER/DB_PASS/DB_NAME constants defined in config/config.php
    // (and any config/local.php or environment overrides). Now reads from those constants.
    private $host;
    private $user;
    private $pass;
    private $dbname;

    private $dbh;
    private $stmt;
    private $error;

    public function __construct() {
        $this->host   = defined('DB_HOST') ? DB_HOST : 'localhost';
        $this->user   = defined('DB_USER') ? DB_USER : 'root';
        $this->pass   = defined('DB_PASS') ? DB_PASS : '';
        $this->dbname = defined('DB_NAME') ? DB_NAME : 'osudlagb_remotecenter';

        $dsn = "mysql:host=$this->host;dbname=$this->dbname;charset=utf8mb4";
        $options = [
            // Persistent connections reuse the same handle and can silently end
            // an open transaction started on another Database instance.
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];

        try {
            $this->dbh = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log('Database connection failed: ' . $this->error);
            $message = (defined('APP_DEBUG') && APP_DEBUG)
                ? 'Database Connection Failed: ' . $this->error
                : 'Database connection failed. Please contact the administrator.';
            die($message);
        }
    }

    public function query($sql) {
        $this->stmt = $this->dbh->prepare($sql);
    }

    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
    }

    public function execute() {
        return $this->stmt->execute();
    }

    public function resultSet() {
        $this->execute();
        return $this->stmt->fetchAll();
    }

    public function single() {
        $this->execute();
        return $this->stmt->fetch();
    }

    public function rowCount() {
        return $this->stmt->rowCount();
    }

    public function lastInsertId() {
        return $this->dbh->lastInsertId();
    }

    // ================= TRANSACTION METHODS =================
    public function beginTransaction() {
        return $this->dbh->beginTransaction();
    }

    public function inTransaction(): bool
    {
        return $this->dbh->inTransaction();
    }

    public function commit(): bool {
        if (!$this->dbh->inTransaction()) {
            return false;
        }
        return $this->dbh->commit();
    }

    /** @throws RuntimeException when commit did not run (transaction already closed). */
    public function commitOrFail(): void {
        if (!$this->commit()) {
            throw new RuntimeException('Database transaction commit failed (no active transaction).');
        }
    }

    public function rollback() {
        if (!$this->dbh->inTransaction()) {
            return false;
        }
        return $this->dbh->rollBack();
    }

    public function getLastError() {
    return $this->stmt ? $this->stmt->errorInfo()[2] : 'No error info';
}
}
?>