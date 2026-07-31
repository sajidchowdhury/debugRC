<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Store Manual Journal Request — Phase 6 (Accounts Sub-Ledger).
 *
 * Validates a manual journal submission. The request body contains:
 *   - journal_date:     date of the entry
 *   - branch_id:        branch
 *   - description:      free-text description
 *   - status:           'draft' or 'post' (the controller maps 'post' → 'posted')
 *   - lines:            JSON string of [{ledger_id, debit, credit, description}, ...]
 *
 * Dr = Cr enforcement is done in the ManualJournalService (not here) because
 * it requires parsing the lines JSON and comparing floats. The service also
 * enforces a minimum of 2 lines.
 *
 * RBAC: route middleware (role:accountant,manager,admin + branch.isolation).
 */
class StoreManualJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date'],
            'branch_id'    => ['required', 'integer', 'exists:branches,id'],
            'description'  => ['nullable', 'string', 'max:1000'],
            'status'       => ['required', 'in:draft,post'],
            'lines'        => ['required', 'string'], // JSON string — decoded + validated in service
        ];
    }

    public function attributes(): array
    {
        return [
            'journal_date' => 'journal date',
            'branch_id'    => 'branch',
            'description'  => 'description',
            'status'       => 'status',
            'lines'        => 'journal lines',
        ];
    }

    /**
     * Decode the lines JSON and return the payload for ManualJournalService.
     *
     * @return array{
     *     journal_date: string,
     *     branch_id: int,
     *     description: string,
     *     post: bool,
     *     lines: array<int, {ledger_id: int, debit: float, credit: float, description: string}>,
     *     created_by: int|null
     * }
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();

        $lines = json_decode($validated['lines'], true);
        if (!is_array($lines)) {
            $lines = [];
        }

        // Normalize each line: cast types, default description.
        $lines = array_map(function ($line) {
            return [
                'ledger_id'   => (int) ($line['ledger_id'] ?? 0),
                'debit'       => (float) ($line['debit'] ?? 0),
                'credit'      => (float) ($line['credit'] ?? 0),
                'description' => (string) ($line['description'] ?? ''),
            ];
        }, $lines);

        return [
            'journal_date' => $validated['journal_date'],
            'branch_id'    => (int) $validated['branch_id'],
            'description'  => $validated['description'] ?? '',
            'post'         => $validated['status'] === 'post',
            'lines'        => $lines,
            'created_by'   => auth()->id(),
        ];
    }
}
