<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Issue a challan (stock OUT + COGS GL).
 *
 * This is step 2 of the challan workflow. It creates the sales_challan,
 * moves stock OUT at avg_cost, and posts the COGS journal entry.
 * The invoice must already be godown-prepared (status=confirmed).
 */
class IssueChallanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_invoice_id' => 'required|integer|exists:sales_invoices,id',
            'challan_date'     => 'nullable|date',
            'transport_name'   => 'nullable|string|max:100',
            'transport_phone'  => 'nullable|string|max:20',
            'vehicle_number'   => 'nullable|string|max:50',
            'driver_name'      => 'nullable|string|max:100',
            'transport_cost'   => 'nullable|numeric|min:0',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'sales_invoice_id' => ['description' => 'Invoice that is ready for challan issue (must be godown-prepared)', 'example' => 42],
            'challan_date'     => ['description' => 'Challan date (defaults to today)', 'example' => '2025-01-21'],
            'transport_name'   => ['description' => 'Transport company name', 'example' => 'Fast Cargo Ltd'],
            'vehicle_number'   => ['description' => 'Vehicle registration number', 'example' => 'Dhaka-GA-1234'],
        ];
    }
}
