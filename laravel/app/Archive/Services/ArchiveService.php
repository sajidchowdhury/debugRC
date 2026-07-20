<?php

namespace App\Archive\Services;

use App\Archive\Repositories\ArchiveRepositoryInterface;
use App\Archive\DTOs\InvoiceArchiveDTO;
use App\Archive\DTOs\CustomerArchiveDTO;
use App\Archive\DTOs\LedgerArchiveDTO;
use App\Models\SalesInvoice;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Archive Service — Phase 12.
 *
 * The unified search service. Determines whether to query PostgreSQL
 * (operational) or the legacy MySQL (archive) and returns results
 * as DTOs. The UI never knows which database produced the result.
 *
 * Flow:
 *   1. Search PostgreSQL first (operational layer)
 *   2. If results found, return them (source = 'postgresql')
 *   3. If no results, search legacy MySQL (archive layer)
 *   4. Return archive results (source = 'archive_mysql')
 *   5. Cache archive lookups (immutable data — safe to cache)
 *
 * Performance: archive queries NEVER slow operational PostgreSQL.
 * The legacy MySQL is a separate database on a separate connection.
 */
class ArchiveService
{
    public function __construct(
        private ArchiveRepositoryInterface $archiveRepository
    ) {}

    /**
     * Search invoices across both PostgreSQL and legacy archive.
     * Returns a unified collection of InvoiceArchiveDTO.
     *
     * @return Collection<int, InvoiceArchiveDTO>
     */
    public function searchInvoices(string $search, int $limit = 50): Collection
    {
        // 1. Search PostgreSQL (operational).
        $pgResults = SalesInvoice::with(['customer', 'branch'])
            ->where('invoice_code', 'ILIKE', "%{$search}%")
            ->orWhereHas('customer', fn($q) => $q->where('customer_name', 'ILIKE', "%{$search}%"))
            ->orderBy('invoice_date', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($inv) => InvoiceArchiveDTO::fromEloquent($inv));

        if ($pgResults->isNotEmpty()) {
            return $pgResults;
        }

        // 2. Fallback to legacy archive (cached).
        return Cache::remember(
            "archive:invoices:{$search}:{$limit}",
            config('archive.cache_ttl', 3600),
            fn() => $this->archiveRepository->searchInvoices($search, $limit)
        );
    }

    /**
     * Find a specific invoice by code (check PG first, then archive).
     */
    public function findInvoice(string $invoiceCode): ?InvoiceArchiveDTO
    {
        // 1. PostgreSQL.
        $pgInvoice = SalesInvoice::with(['customer', 'branch', 'items.product'])
            ->where('invoice_code', $invoiceCode)
            ->first();

        if ($pgInvoice) {
            return InvoiceArchiveDTO::fromEloquent($pgInvoice);
        }

        // 2. Legacy archive (cached).
        return Cache::remember(
            "archive:invoice:{$invoiceCode}",
            config('archive.cache_ttl', 3600),
            fn() => $this->archiveRepository->findInvoice($invoiceCode)
        );
    }

    /**
     * Search customers across both databases.
     *
     * @return Collection<int, CustomerArchiveDTO>
     */
    public function searchCustomers(string $search, int $limit = 50): Collection
    {
        // 1. PostgreSQL — use full-text search (tsvector + GIN) when available,
        //    falls back to ILIKE via the Customer::scopeSearch() method.
        $pgResults = Customer::search($search, ranked: true)
            ->orderBy('customer_name')
            ->limit($limit)
            ->get()
            ->map(fn($c) => CustomerArchiveDTO::fromEloquent($c));

        if ($pgResults->isNotEmpty()) {
            return $pgResults;
        }

        // 2. Legacy archive (cached).
        return Cache::remember(
            "archive:customers:{$search}:{$limit}",
            config('archive.cache_ttl', 3600),
            fn() => $this->archiveRepository->searchCustomers($search, $limit)
        );
    }

    /**
     * Get customer ledger history (PG first, then archive for older entries).
     *
     * @return Collection<int, LedgerArchiveDTO>
     */
    public function getCustomerLedger(int $customerId, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        // 1. PostgreSQL (current entries).
        $pgResults = \Illuminate\Support\Facades\DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->when($fromDate, fn($q, $d) => $q->where('transaction_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('transaction_date', '<=', $d))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn($r) => LedgerArchiveDTO::fromEloquent($r));

        // 2. If PG has results, return them.
        // If the user is looking for older data not in PG, also check archive.
        if ($pgResults->isNotEmpty()) {
            return $pgResults;
        }

        // 3. Legacy archive (cached).
        return Cache::remember(
            "archive:customer_ledger:{$customerId}:{$fromDate}:{$toDate}",
            config('archive.cache_ttl', 3600),
            fn() => $this->archiveRepository->getCustomerLedger($customerId, $fromDate, $toDate)
        );
    }

    /**
     * Get supplier ledger history.
     *
     * @return Collection<int, LedgerArchiveDTO>
     */
    public function getSupplierLedger(int $supplierId, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $pgResults = \Illuminate\Support\Facades\DB::table('supplier_ledger')
            ->where('supplier_id', $supplierId)
            ->where('is_reversed', false)
            ->when($fromDate, fn($q, $d) => $q->where('transaction_date', '>=', $d))
            ->when($toDate, fn($q, $d) => $q->where('transaction_date', '<=', $d))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn($r) => LedgerArchiveDTO::fromEloquent($r));

        if ($pgResults->isNotEmpty()) {
            return $pgResults;
        }

        return Cache::remember(
            "archive:supplier_ledger:{$supplierId}:{$fromDate}:{$toDate}",
            config('archive.cache_ttl', 3600),
            fn() => $this->archiveRepository->getSupplierLedger($supplierId, $fromDate, $toDate)
        );
    }

    /**
     * Check if the archive is available.
     */
    public function isArchiveAvailable(): bool
    {
        return $this->archiveRepository->isAvailable();
    }
}
