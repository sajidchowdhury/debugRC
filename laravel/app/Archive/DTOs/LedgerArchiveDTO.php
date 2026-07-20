<?php

namespace App\Archive\DTOs;

/**
 * Ledger Archive DTO — Phase 12.
 *
 * Represents a ledger entry from either PostgreSQL or legacy MySQL.
 */
class LedgerArchiveDTO
{
    public function __construct(
        public ?int $id,
        public string $transactionDate,
        public string $transactionType,
        public ?string $referenceType,
        public ?int $referenceId,
        public float $debit,
        public float $credit,
        public float $balance,
        public ?string $description,
        public ?string $source = null,
    ) {}

    public static function fromEloquent(object $entry): self
    {
        return new self(
            id: $entry->id,
            transactionDate: $entry->transaction_date,
            transactionType: $entry->transaction_type,
            referenceType: $entry->reference_type,
            referenceId: $entry->reference_id,
            debit: (float) $entry->debit,
            credit: (float) $entry->credit,
            balance: (float) $entry->balance,
            description: $entry->description,
            source: 'postgresql',
        );
    }

    public static function fromLegacy(array $row): self
    {
        return new self(
            id: $row['id'] ?? null,
            transactionDate: $row['transaction_date'] ?? '',
            transactionType: $row['transaction_type'] ?? '',
            referenceType: $row['reference_type'] ?? null,
            referenceId: $row['reference_id'] ?? null,
            debit: (float) ($row['debit'] ?? 0),
            credit: (float) ($row['credit'] ?? 0),
            balance: (float) ($row['balance'] ?? $row['running_balance'] ?? 0),
            description: $row['description'] ?? $row['remarks'] ?? null,
            source: 'archive_mysql',
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'transaction_date' => $this->transactionDate,
            'transaction_type' => $this->transactionType,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'balance' => $this->balance,
            'description' => $this->description,
            'source' => $this->source,
            'is_archived' => $this->source === 'archive_mysql',
        ];
    }
}
