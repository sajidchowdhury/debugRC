<?php

namespace App\Archive\DTOs;

/**
 * Invoice Archive DTO — Phase 12.
 *
 * Represents a sales invoice from EITHER PostgreSQL (operational) or
 * legacy MySQL (archive). The UI uses this DTO and never knows which
 * database produced it.
 *
 * This is the Anti-Corruption Layer's output — raw MySQL records are
 * translated into this clean DTO before reaching Laravel controllers.
 */
class InvoiceArchiveDTO
{
    public function __construct(
        public ?int $id,
        public string $invoiceCode,
        public string $invoiceDate,
        public ?string $customerName,
        public ?string $customerCode,
        public ?string $branchName,
        public float $totalAmount,
        public float $paidAmount,
        public float $dueAmount,
        public string $status,
        public ?string $source = null, // 'postgresql' or 'archive_mysql'
        public ?array $items = null,
    ) {}

    /**
     * Create from a PostgreSQL (Eloquent) record.
     */
    public static function fromEloquent(object $invoice): self
    {
        return new self(
            id: $invoice->id,
            invoiceCode: $invoice->invoice_code,
            invoiceDate: $invoice->invoice_date,
            customerName: $invoice->customer?->customer_name,
            customerCode: $invoice->customer?->customer_code,
            branchName: $invoice->branch?->branch_name,
            totalAmount: (float) $invoice->total_amount,
            paidAmount: (float) $invoice->paid_amount,
            dueAmount: (float) $invoice->due_amount,
            status: $invoice->status,
            source: 'postgresql',
        );
    }

    /**
     * Create from a legacy MySQL record (translated).
     */
    public static function fromLegacy(array $row): self
    {
        return new self(
            id: $row['id'] ?? null,
            invoiceCode: $row['invoice_code'] ?? 'UNKNOWN',
            invoiceDate: $row['invoice_date'] ?? '',
            customerName: $row['customer_name'] ?? $row['shop_name'] ?? null,
            customerCode: $row['customer_code'] ?? null,
            branchName: $row['branch_name'] ?? null,
            totalAmount: (float) ($row['total_amount'] ?? 0),
            paidAmount: (float) ($row['paid_amount'] ?? 0),
            dueAmount: (float) ($row['due_amount'] ?? 0),
            status: $row['status'] ?? 'unknown',
            source: 'archive_mysql',
        );
    }

    /**
     * Convert to array for JSON response / view.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invoice_code' => $this->invoiceCode,
            'invoice_date' => $this->invoiceDate,
            'customer_name' => $this->customerName,
            'customer_code' => $this->customerCode,
            'branch_name' => $this->branchName,
            'total_amount' => $this->totalAmount,
            'paid_amount' => $this->paidAmount,
            'due_amount' => $this->dueAmount,
            'status' => $this->status,
            'source' => $this->source,
            'is_archived' => $this->source === 'archive_mysql',
        ];
    }
}
