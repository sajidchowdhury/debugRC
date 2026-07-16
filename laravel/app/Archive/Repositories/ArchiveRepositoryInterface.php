<?php

namespace App\Archive\Repositories;

use App\Archive\DTOs\InvoiceArchiveDTO;
use App\Archive\DTOs\CustomerArchiveDTO;
use App\Archive\DTOs\LedgerArchiveDTO;
use Illuminate\Support\Collection;

/**
 * Archive Repository Interface — Phase 12.
 *
 * Defines the contract for accessing historical archive data.
 * The implementation (LegacyMySQLRepository) connects to the legacy
 * MySQL database and translates records into DTOs.
 *
 * Future implementations could use:
 *   - SQL dump reader
 *   - Data warehouse
 *   - Object storage (S3/MinIO)
 *   - Reporting database
 *
 * Laravel controllers depend on this interface, never on the implementation.
 * Replacing the data source requires changing only the service provider binding.
 */
interface ArchiveRepositoryInterface
{
    /**
     * Search historical invoices by code or customer name.
     *
     * @param string $search
     * @param int $limit
     * @return Collection<int, InvoiceArchiveDTO>
     */
    public function searchInvoices(string $search, int $limit = 50): Collection;

    /**
     * Get a historical invoice by code.
     */
    public function findInvoice(string $invoiceCode): ?InvoiceArchiveDTO;

    /**
     * Search historical customers.
     *
     * @return Collection<int, CustomerArchiveDTO>
     */
    public function searchCustomers(string $search, int $limit = 50): Collection;

    /**
     * Get historical customer ledger entries.
     *
     * @return Collection<int, LedgerArchiveDTO>
     */
    public function getCustomerLedger(int $customerId, ?string $fromDate = null, ?string $toDate = null): Collection;

    /**
     * Get historical supplier ledger entries.
     *
     * @return Collection<int, LedgerArchiveDTO>
     */
    public function getSupplierLedger(int $supplierId, ?string $fromDate = null, ?string $toDate = null): Collection;

    /**
     * Check if the archive is available (legacy MySQL reachable).
     */
    public function isAvailable(): bool;
}
