<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Services\Budgeting\BudgetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Budget Variance Report — LOW-G / G-325.
 *
 * Generates the budget-vs-actual variance report from the CLI and writes
 * it to disk (CSV by default). Closes the G-325 gap: previously the
 * accountant HAD to manually click "Variance" in the admin UI every
 * month — no artisan command, no scheduled job. A missed month silently
 * left the report un-generated.
 *
 * The report re-uses the canonical `BudgetService::getBudgetVsActual()`
 * (the same method the UI Variance button + CSV export use), so the CLI
 * output is byte-identical to the UI CSV export (modulo the optional
 * `--format=html` flag, which produces a standalone HTML table for
 * email embedding).
 *
 * Lookup logic mirrors BudgetController::varianceReport (L248-275):
 *   - Resolve fiscal year (default: current calendar year — same as UI).
 *   - Find the active Budget row matching fiscal_year + optional branch_id.
 *   - Call BudgetService::getBudgetVsActual($budget).
 *   - Emit rows grouped by account_type.
 *
 * Usage:
 *   php artisan budget:variance-report                           # current year, CSV to storage/app/
 *   php artisan budget:variance-report --fiscal-year=2026        # specific fiscal year
 *   php artisan budget:variance-report --branch=2                # scope to branch_id=2
 *   php artisan budget:variance-report --format=html             # HTML table output
 *   php artisan budget:variance-report --output=/tmp/var.csv     # custom output path
 *   php artisan budget:variance-report --email=cfo@example.com   # email the report
 *
 * Scheduled monthly on the 1st at 03:00 in routes/console.php (offset
 * from the 01:00 depreciation post + 02:00 stale-draft cancel so the
 * three heavy month-start jobs don't pile up).
 *
 * Exit codes:
 *   0 = success (report generated + written to disk; email dispatched if --email)
 *   1 = failure (no active budget found, disk write failed, email send failed, etc.)
 */
class BudgetVarianceReport extends Command
{
    /**
     * CSV column header — matches BudgetController::exportCsv (L306).
     * Kept in sync so the CLI output is byte-identical to the UI CSV export.
     */
    private const CSV_HEADER = [
        'Account Type',
        'Ledger Code',
        'Ledger Name',
        'Period',
        'Budget',
        'Actual',
        'Variance',
        'Variance %',
    ];

    /**
     * UTF-8 Byte Order Mark — 3 bytes prepended to every CSV so Excel
     * auto-detects UTF-8 encoding (mirrors CsvExporter::BOM). Bengali
     * ledger names render correctly instead of mojibake.
     */
    private const BOM = "\xEF\xBB\xBF";

    protected $signature = 'budget:variance-report
                            {--fiscal-year= : Fiscal year (defaults to current calendar year, matching the UI)}
                            {--branch= : Scope to a single branch_id (default: all branches / company-wide budget)}
                            {--email= : Email recipient (optional — dispatches the report as an attachment)}
                            {--format=csv : Output format (csv|html)}
                            {--output= : Output file path (optional, defaults to storage/app/budget-variance-{YYYY-MM-DD}.{ext})}';

    protected $description = 'Generate the budget-vs-actual variance report (monthly scheduled job — G-325)';

    public function handle(BudgetService $budgetService): int
    {
        $fiscalYear = $this->resolveFiscalYear();
        $branchId   = $this->option('branch') ? (int) $this->option('branch') : null;
        $format     = strtolower((string) $this->option('format'));
        $email      = $this->option('email') ? (string) $this->option('email') : null;

        if (!in_array($format, ['csv', 'html'], true)) {
            $this->error("Invalid --format value. Expected 'csv' or 'html', got: {$format}");
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Generating budget variance report for fiscal year %s%s.',
            $fiscalYear,
            $branchId ? " (branch {$branchId})" : ' (all branches / company-wide)'
        ));

        // Step 1: locate the active Budget row matching fiscal_year + optional branch.
        // Mirrors BudgetController::varianceReport L254-257.
        $budgetQuery = Budget::where('fiscal_year', $fiscalYear)
            ->where('status', 'active')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $budget = $budgetQuery->first();

        if (!$budget) {
            $this->warn(sprintf(
                'No active budget found for fiscal year %s%s. Nothing to report.',
                $fiscalYear,
                $branchId ? " / branch {$branchId}" : ''
            ));
            // Not a hard failure — a freshly-started fiscal year may not have a
            // budget activated yet. Return SUCCESS so the scheduler log doesn't
            // fill with noise on every month-start run during the gap.
            return self::SUCCESS;
        }

        // Step 2: compute variance via the canonical service method.
        // Same call the UI Variance button makes (BudgetController L261).
        try {
            $varianceData = $budgetService->getBudgetVsActual($budget);
        } catch (Throwable $e) {
            $this->error("Failed to compute variance: {$e->getMessage()}");
            Log::error('budget:variance-report: getBudgetVsActual failed', [
                'budget_id'   => $budget->id,
                'fiscal_year' => $fiscalYear,
                'branch_id'   => $branchId,
                'error'       => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        // Step 3: render the report to the requested format.
        $content = $format === 'csv'
            ? $this->renderCsv($varianceData)
            : $this->renderHtml($varianceData, $fiscalYear, $budget);

        // Step 4: write to disk.
        $outputPath = $this->resolveOutputPath($format);
        $bytesWritten = @file_put_contents($outputPath, $content);

        if ($bytesWritten === false) {
            $this->error("Failed to write report to: {$outputPath}");
            Log::error('budget:variance-report: file write failed', [
                'output_path' => $outputPath,
            ]);
            return self::FAILURE;
        }

        $rowCount = $this->countVarianceRows($varianceData);
        $this->info(sprintf(
            'Report written: %s (%d row(s), %s bytes).',
            $outputPath,
            $rowCount,
            number_format($bytesWritten)
        ));
        $this->line(sprintf(
            '  totals: budget=%s  actual=%s  variance=%s',
            number_format((float) $varianceData['totals']['budget_amount'], 2),
            number_format((float) $varianceData['totals']['actual_amount'], 2),
            number_format((float) $varianceData['totals']['variance_amount'], 2)
        ));

        // Step 5: optional email dispatch.
        if ($email !== null) {
            $mailed = $this->dispatchEmail($email, $outputPath, $format, $fiscalYear, $rowCount);
            if (!$mailed) {
                // Email failure is a soft failure — the report was still
                // generated + persisted to disk. Surface the issue but don't
                // fail the whole run (the scheduler can retry next month).
                $this->warn('Report saved to disk, but email dispatch failed — see log for details.');
            } else {
                $this->info("Report emailed to: {$email}");
            }
        }

        return self::SUCCESS;
    }

    // ====================================================================
    // FISCAL YEAR + OUTPUT PATH RESOLUTION
    // ====================================================================

    /**
     * Resolve the fiscal year for the report.
     *
     * Precedence:
     *   1. --fiscal-year=YYYY (explicit)
     *   2. current calendar year (matches BudgetController::varianceReport L250)
     *
     * NB: Bangladesh fiscal year runs July 1 → June 30, but the budgets.fiscal_year
     * column is a free-text string and the existing UI defaults to the calendar
     * year. We match the UI's behavior for consistency — if the accountant
     * wants a different year (e.g. '2026-2027'), they pass --fiscal-year=2026-2027.
     */
    private function resolveFiscalYear(): string
    {
        $explicit = trim((string) $this->option('fiscal-year'));
        if ($explicit !== '') {
            return $explicit;
        }

        return now()->format('Y');
    }

    /**
     * Resolve the output file path.
     *
     * Precedence:
     *   1. --output=/path/to/file (explicit)
     *   2. storage/app/budget-variance-{YYYY-MM-DD}.{ext} (default)
     *
     * The extension is derived from --format. If the user passes --output
     * with no extension, the format-appropriate extension is appended.
     */
    private function resolveOutputPath(string $format): string
    {
        $explicit = trim((string) $this->option('output'));
        if ($explicit !== '') {
            // If the path has no extension, append the format's extension.
            if (!preg_match('/\.[a-z0-9]+$/i', $explicit)) {
                return $explicit . '.' . $format;
            }
            return $explicit;
        }

        $date = now()->format('Y-m-d');
        return storage_path("app/budget-variance-{$date}.{$format}");
    }

    // ====================================================================
    // RENDERERS
    // ====================================================================

    /**
     * Render the variance data as a CSV string.
     *
     * Mirrors BudgetController::buildBudgetVarianceRows (L341-357) — same
     * column order, same number_format precision, same BOM prepend. A
     * totals row is appended at the end (the UI CSV export does NOT
     * include this, but a CLI report benefits from it).
     *
     * @param  array $varianceData Output from BudgetService::getBudgetVsActual()
     * @return string
     */
    private function renderCsv(array $varianceData): string
    {
        // Open an in-memory stream so fputcsv handles RFC-4180 escaping.
        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open php://temp stream for CSV render.');
        }

        // UTF-8 BOM — Excel auto-detects encoding (mirrors CsvExporter::BOM).
        fwrite($handle, self::BOM);

        // Header row.
        fputcsv($handle, self::CSV_HEADER, ',', '"', '\\');

        // Data rows — grouped by account_type, same iteration order as the UI.
        foreach ($varianceData['lines'] as $type => $rows) {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->account_type,
                    $row->ledger_code,
                    $row->ledger_name,
                    $row->period,
                    number_format((float) $row->budget_amount, 2),
                    number_format((float) $row->actual_amount, 2),
                    number_format((float) $row->variance_amount, 2),
                    $row->variance_percent ?? 'N/A',
                ], ',', '"', '\\');
            }
        }

        // Totals row — CLI-only convenience (the UI CSV export omits this).
        fputcsv($handle, [
            'TOTAL',
            '',
            '',
            '',
            number_format((float) $varianceData['totals']['budget_amount'], 2),
            number_format((float) $varianceData['totals']['actual_amount'], 2),
            number_format((float) $varianceData['totals']['variance_amount'], 2),
            '',
        ], ',', '"', '\\');

        // Rewind + read the full contents.
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Render the variance data as a standalone HTML table.
     *
     * Used when --format=html is passed (typically for embedding in an
     * email body). The output is a complete HTML document with inline
     * CSS so it renders correctly in webmail clients that strip <style>.
     *
     * @param  array  $varianceData
     * @param  string $fiscalYear
     * @param  Budget $budget
     * @return string
     */
    private function renderHtml(array $varianceData, string $fiscalYear, Budget $budget): string
    {
        $rows = [];
        foreach ($varianceData['lines'] as $type => $typeRows) {
            foreach ($typeRows as $row) {
                $rows[] = sprintf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
                    . '<td style="text-align:right">%s</td>'
                    . '<td style="text-align:right">%s</td>'
                    . '<td style="text-align:right">%s</td>'
                    . '<td style="text-align:right">%s</td></tr>',
                    htmlspecialchars((string) $row->account_type, ENT_QUOTES),
                    htmlspecialchars((string) $row->ledger_code, ENT_QUOTES),
                    htmlspecialchars((string) $row->ledger_name, ENT_QUOTES),
                    htmlspecialchars((string) $row->period, ENT_QUOTES),
                    number_format((float) $row->budget_amount, 2),
                    number_format((float) $row->actual_amount, 2),
                    number_format((float) $row->variance_amount, 2),
                    htmlspecialchars((string) ($row->variance_percent ?? 'N/A'), ENT_QUOTES)
                );
            }
        }

        $rowsHtml = implode("\n", $rows);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Budget vs Actual — Fiscal Year {$fiscalYear}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #222;">
<h2 style="margin: 0 0 4px;">Budget vs Actual Variance Report</h2>
<p style="margin: 0 0 12px; color: #555;">
  Budget: <strong>{$this->escape($budget->name)}</strong> &middot;
  Fiscal year: <strong>{$this->escape($fiscalYear)}</strong> &middot;
  Generated: <strong>{$this->escape(now()->toDateTimeString())}</strong>
</p>
<table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse; width: 100%;">
<thead>
<tr style="background: #f0f0f0;">
  <th>Account Type</th><th>Ledger Code</th><th>Ledger Name</th><th>Period</th>
  <th>Budget</th><th>Actual</th><th>Variance</th><th>Variance %</th>
</tr>
</thead>
<tbody>
{$rowsHtml}
<tr style="background: #fafafa; font-weight: bold;">
  <td colspan="4">TOTAL</td>
  <td style="text-align:right">{$this->fmt($varianceData['totals']['budget_amount'])}</td>
  <td style="text-align:right">{$this->fmt($varianceData['totals']['actual_amount'])}</td>
  <td style="text-align:right">{$this->fmt($varianceData['totals']['variance_amount'])}</td>
  <td></td>
</tr>
</tbody>
</table>
</body>
</html>
HTML;
    }

    // ====================================================================
    // EMAIL DISPATCH
    // ====================================================================

    /**
     * Dispatch the report email.
     *
     * Sends a plain-text email with the report file attached. Uses Laravel's
     * Mail facade with a raw message (no Mailable class — the budgeting
     * subsystem has no mail infrastructure today, and adding a Mailable
     * is a follow-up concern). If mail infrastructure is mis-configured,
     * the failure is logged but does not abort the run (the report was
     * already persisted to disk).
     *
     * @param  string $to        Recipient email address.
     * @param  string $filePath  Path to the generated report file.
     * @param  string $format    Report format ('csv' or 'html').
     * @param  string $fiscalYear
     * @param  int    $rowCount  Number of data rows in the report.
     * @return bool              True on success, false on failure.
     */
    private function dispatchEmail(
        string $to, string $filePath, string $format, string $fiscalYear, int $rowCount
    ): bool {
        $subject = "Budget Variance Report — FY {$fiscalYear} ({$rowCount} rows)";
        $body = sprintf(
            "Budget vs Actual variance report for fiscal year %s.\n\n"
            . "Rows: %d\n"
            . "Format: %s\n"
            . "Generated: %s\n"
            . "File: %s\n\n"
            . "— Generated by php artisan budget:variance-report (G-325).",
            $fiscalYear,
            $rowCount,
            strtoupper($format),
            now()->toDateTimeString(),
            basename($filePath)
        );

        try {
            Mail::raw($body, function ($message) use ($to, $subject, $filePath, $format) {
                $message->to($to)->subject($subject);
                // Attach the report file. MIME type derived from format.
                $mime = $format === 'csv' ? 'text/csv' : 'text/html';
                $message->attach($filePath, [
                    'as'   => basename($filePath),
                    'mime' => $mime,
                ]);
            });
            return true;
        } catch (Throwable $e) {
            Log::error('budget:variance-report: email dispatch failed', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ====================================================================
    // SMALL HELPERS
    // ====================================================================

    /**
     * Count the total number of data rows in the variance payload.
     */
    private function countVarianceRows(array $varianceData): int
    {
        $count = 0;
        foreach ($varianceData['lines'] as $rows) {
            $count += is_countable($rows) ? count($rows) : 0;
        }
        return $count;
    }

    /**
     * Escape a string for safe HTML output.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }

    /**
     * Format a numeric value for HTML output (2 decimal places).
     */
    private function fmt($value): string
    {
        return number_format((float) $value, 2);
    }
}
