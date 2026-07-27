<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 11 — Bulk upsert counts via CSV import through the API.
 *
 * The CSV file is uploaded as multipart form-data (field name: csv_file).
 * The controller parses it (product_code, physical_qty columns) and calls
 * StockTakeService::bulkUpsertCounts — same path as the web import.
 *
 * Mirrors the web controller's importCounts() validation.
 */
class ImportCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'csv_file' => [
                'description' => 'CSV file (multipart). Columns: product_code, physical_qty. BOM is stripped. Max 2MB.',
            ],
        ];
    }
}
