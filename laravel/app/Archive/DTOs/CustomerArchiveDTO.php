<?php

namespace App\Archive\DTOs;

/**
 * Customer Archive DTO — Phase 12.
 *
 * Represents a customer from either PostgreSQL or legacy MySQL.
 */
class CustomerArchiveDTO
{
    public function __construct(
        public ?int $id,
        public string $customerCode,
        public string $customerName,
        public ?string $mobile,
        public ?string $address,
        public float $balance,
        public ?string $source = null,
    ) {}

    public static function fromEloquent(object $customer): self
    {
        return new self(
            id: $customer->id,
            customerCode: $customer->customer_code,
            customerName: $customer->customer_name,
            mobile: $customer->mobile,
            address: $customer->address,
            balance: (float) ($customer->opening_balance ?? 0),
            source: 'postgresql',
        );
    }

    public static function fromLegacy(array $row): self
    {
        return new self(
            id: $row['id'] ?? null,
            customerCode: $row['customer_code'] ?? '',
            customerName: $row['customer_name'] ?? $row['shop_name'] ?? '',
            mobile: $row['mobile'] ?? null,
            address: $row['address'] ?? null,
            balance: (float) ($row['opening_balance'] ?? 0),
            source: 'archive_mysql',
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customerCode,
            'customer_name' => $this->customerName,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'balance' => $this->balance,
            'source' => $this->source,
            'is_archived' => $this->source === 'archive_mysql',
        ];
    }
}
