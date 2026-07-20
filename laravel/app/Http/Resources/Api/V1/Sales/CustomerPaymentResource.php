<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer Payment API Resource — includes allocations.
 */
class CustomerPaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                         => $this->id,
            'payment_code'               => $this->payment_code,
            'payment_date'               => $this->payment_date?->format('Y-m-d'),
            'customer'                   => $this->whenLoaded('customer', fn() => [
                'id'   => $this->customer?->id,
                'name' => $this->customer?->customer_name,
            ]),
            'branch_id'                  => $this->branch_id,
            'bank_id'                    => $this->bank_id,
            'payment_mode'               => $this->payment_mode,
            'transaction_type'           => $this->transaction_type ?? 'receive',
            'transaction_type_label'     => $this->when(
                method_exists($this->resource, 'getTransactionTypeLabel'),
                fn() => $this->getTransactionTypeLabel()
            ),
            'amount'                     => (float) $this->amount,
            'discount_amount'            => (float) $this->discount_amount,
            'reference_no'               => $this->reference_no,
            'is_reversed'                => (bool) $this->is_reversed,
            'notes'                      => $this->notes,
            'allocations'                => PaymentAllocationResource::collection(
                $this->whenLoaded('allocations')
            ),
            'journal_entry_id'           => $this->journal_entry_id,
            'intercompany_journal_entry_id' => $this->intercompany_journal_entry_id,
            'created_at'                 => $this->created_at?->toIso8601String(),
        ];
    }
}
