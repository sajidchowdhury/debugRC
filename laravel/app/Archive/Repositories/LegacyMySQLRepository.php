<?php

namespace App\Archive\Repositories;

use App\Archive\DTOs\InvoiceArchiveDTO;
use App\Archive\DTOs\CustomerArchiveDTO;
use App\Archive\DTOs\LedgerArchiveDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Legacy MySQL Repository — Phase 12.
 *
 * The Anti-Corruption Layer implementation. Connects to the legacy MySQL
 * database (READ-ONLY), queries legacy tables, and translates results
 * into clean Laravel DTOs.
 *
 * Laravel controllers NEVER see legacy table names, column names, or
 * raw MySQL records. They only see DTOs.
 *
 * The legacy connection is configured in config/archive.php and uses
 * a READ-ONLY MySQL user. No writes are possible.
 *
 * Future: This class can be replaced with SqlDumpRepository,
 * DataWarehouseRepository, etc. — the interface stays the same.
 */
class LegacyMySQLRepository implements ArchiveRepositoryInterface
{
    private ?\PDO $connection = null;

    /**
     * Get the legacy MySQL PDO connection (lazy, cached).
     */
    private function getConnection(): ?\PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (!config('archive.enabled', false)) {
            return null;
        }

        try {
            $config = config('archive.connection');
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
            $this->connection = new \PDO($dsn, $config['username'], $config['password'], $config['options'] ?? []);
            return $this->connection;
        } catch (\PDOException $e) {
            Log::warning('Archive MySQL connection failed: ' . $e->getMessage());
            return null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->getConnection() !== null;
    }

    public function searchInvoices(string $search, int $limit = 50): Collection
    {
        $conn = $this->getConnection();
        if (!$conn) return collect();

        try {
            $stmt = $conn->prepare("
                SELECT si.id, si.invoice_code, si.invoice_date, si.total_amount,
                       si.paid_amount, si.due_amount, si.status,
                       c.customer_name, c.shop_name, c.customer_code,
                       b.branch_name
                FROM sales_invoices si
                LEFT JOIN customers c ON c.id = si.customer_id
                LEFT JOIN branches b ON b.id = si.branch_id
                WHERE si.invoice_code LIKE :search
                   OR c.customer_name LIKE :search2
                   OR c.shop_name LIKE :search3
                ORDER BY si.invoice_date DESC
                LIMIT :limit
            ");
            $searchTerm = "%{$search}%";
            $stmt->bindValue(':search', $searchTerm);
            $stmt->bindValue(':search2', $searchTerm);
            $stmt->bindValue(':search3', $searchTerm);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return collect($stmt->fetchAll())->map(fn($row) => InvoiceArchiveDTO::fromLegacy($row));
        } catch (\PDOException $e) {
            Log::warning('Archive invoice search failed: ' . $e->getMessage());
            return collect();
        }
    }

    public function findInvoice(string $invoiceCode): ?InvoiceArchiveDTO
    {
        $conn = $this->getConnection();
        if (!$conn) return null;

        try {
            $stmt = $conn->prepare("
                SELECT si.*, c.customer_name, c.shop_name, c.customer_code, b.branch_name
                FROM sales_invoices si
                LEFT JOIN customers c ON c.id = si.customer_id
                LEFT JOIN branches b ON b.id = si.branch_id
                WHERE si.invoice_code = :code
                LIMIT 1
            ");
            $stmt->bindValue(':code', $invoiceCode);
            $stmt->execute();
            $row = $stmt->fetch();

            return $row ? InvoiceArchiveDTO::fromLegacy($row) : null;
        } catch (\PDOException $e) {
            Log::warning('Archive invoice lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    public function searchCustomers(string $search, int $limit = 50): Collection
    {
        $conn = $this->getConnection();
        if (!$conn) return collect();

        try {
            $stmt = $conn->prepare("
                SELECT id, customer_code, customer_name, shop_name, mobile, address, opening_balance
                FROM customers
                WHERE customer_name LIKE :search
                   OR shop_name LIKE :search2
                   OR customer_code LIKE :search3
                   OR mobile LIKE :search4
                ORDER BY customer_name
                LIMIT :limit
            ");
            $searchTerm = "%{$search}%";
            $stmt->bindValue(':search', $searchTerm);
            $stmt->bindValue(':search2', $searchTerm);
            $stmt->bindValue(':search3', $searchTerm);
            $stmt->bindValue(':search4', $searchTerm);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return collect($stmt->fetchAll())->map(fn($row) => CustomerArchiveDTO::fromLegacy($row));
        } catch (\PDOException $e) {
            Log::warning('Archive customer search failed: ' . $e->getMessage());
            return collect();
        }
    }

    public function getCustomerLedger(int $customerId, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $conn = $this->getConnection();
        if (!$conn) return collect();

        try {
            $sql = "
                SELECT id, transaction_date, transaction_type, reference_type, reference_id,
                       debit, credit, running_balance AS balance, remarks AS description
                FROM customer_ledger
                WHERE customer_id = :cid
            ";
            $params = [':cid' => $customerId];

            if ($fromDate) {
                $sql .= " AND transaction_date >= :from";
                $params[':from'] = $fromDate;
            }
            if ($toDate) {
                $sql .= " AND transaction_date <= :to";
                $params[':to'] = $toDate;
            }
            $sql .= " ORDER BY transaction_date, id";

            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();

            return collect($stmt->fetchAll())->map(fn($row) => LedgerArchiveDTO::fromLegacy($row));
        } catch (\PDOException $e) {
            Log::warning('Archive customer ledger failed: ' . $e->getMessage());
            return collect();
        }
    }

    public function getSupplierLedger(int $supplierId, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $conn = $this->getConnection();
        if (!$conn) return collect();

        try {
            $sql = "
                SELECT id, transaction_date, transaction_type, reference_type, reference_id,
                       debit, credit, running_balance AS balance, remarks AS description
                FROM supplier_ledger
                WHERE supplier_id = :sid
            ";
            $params = [':sid' => $supplierId];

            if ($fromDate) {
                $sql .= " AND transaction_date >= :from";
                $params[':from'] = $fromDate;
            }
            if ($toDate) {
                $sql .= " AND transaction_date <= :to";
                $params[':to'] = $toDate;
            }
            $sql .= " ORDER BY transaction_date, id";

            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();

            return collect($stmt->fetchAll())->map(fn($row) => LedgerArchiveDTO::fromLegacy($row));
        } catch (\PDOException $e) {
            Log::warning('Archive supplier ledger failed: ' . $e->getMessage());
            return collect();
        }
    }
}
