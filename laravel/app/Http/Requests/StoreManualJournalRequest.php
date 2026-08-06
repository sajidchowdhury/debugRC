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
            // G-321 (MEDIUM-WAVE-3): dimension tag per line. The `lines` field is
            // sent as a JSON string (see manual-journal.js collectLines()), so
            // Laravel's `lines.*.dimension_value_id` rule does NOT fire on the
            // wire-level request — the rule is declared here for documentation +
            // future-proofing (if the form is ever changed to submit lines as an
            // array, the rule would activate). The actual per-line cast +
            // existence check happens in toServicePayload() (int cast) and the
            // FK constraint on manual_journal_lines.dimension_value_id (DB-level).
            'lines.*.dimension_value_id' => ['nullable', 'integer', 'exists:dimension_values,id'],
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
     *     lines: array<int, {ledger_id: int, debit: float, credit: float, description: string, dimension_value_id: int|null}>,
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
        // G-321: pass dimension_value_id through — nullable int, 0/empty → null.
        $lines = array_map(function ($line) {
            $dimValueId = (int) ($line['dimension_value_id'] ?? 0);
            return [
                'ledger_id'          => (int) ($line['ledger_id'] ?? 0),
                'debit'              => (float) ($line['debit'] ?? 0),
                'credit'             => (float) ($line['credit'] ?? 0),
                'description'        => (string) ($line['description'] ?? ''),
                'dimension_value_id' => $dimValueId > 0 ? $dimValueId : null,
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
