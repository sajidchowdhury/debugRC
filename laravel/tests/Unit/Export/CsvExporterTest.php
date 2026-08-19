<?php

namespace Tests\Unit\Export;

use App\Models\Branch;
use App\Models\Company;
use App\Services\Export\CsvExporter;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * G-219 / csv-export.md §14 G10 — unit tests for the CsvExporter service.
 *
 * MEDIUM-WAVE-3 (subagent 3-c).
 *
 * The existing `tests/Feature/Export/CsvExportTest.php` (464L) covers the 9
 * master-data HTTP endpoints that consume CsvExporter, but ONLY asserts the
 * response shape (200 + text/csv + BOM + header row contains expected labels).
 * It does NOT assert the per-cell value formatting that `extractValue()`
 * applies (dotted relations, bool→Yes/No, DateTime formatting, null→'').
 *
 * This unit test file fills that gap by exercising CsvExporter directly:
 *
 *   - `extractValue()` — 7 tests covering direct attribute, dotted relation,
 *     null, bool true/false, DateTimeInterface, and the (string) cast
 *     fallback. extractValue is PROTECTED — we expose it via a test subclass
 *     `TestableCsvExporter` declared at the bottom of this file (per the gap
 *     spec's recommended approach).
 *
 *   - `filename()` — 5 tests covering simple labels, multi-part filenames,
 *     slugification of spaces/special chars, skipping of empty parts, and
 *     the `.csv` extension invariant. filename() is PUBLIC — tested directly.
 *
 *   - `export()` — 5 end-to-end tests using a real EloquentBuilder over
 *     factory-seeded Branch rows. Captures the StreamedResponse body via
 *     Laravel's `streamedContent()` testing helper (which wraps
 *     `$response->sendContent()` in ob_start/ob_get_clean internally —
 *     equivalent to the ob_start pattern recommended in the gap spec, but
 *     reusing the project's existing helper from CsvExportTest).
 *
 *   - `fputcsv()` — 2 tests covering RFC 4180 row writing + escaping of
 *     embedded commas/quotes. fputcsv is PROTECTED — also exposed via the
 *     TestableCsvExporter subclass.
 *
 * Total: 19 test methods (spec minimum was 15+).
 *
 * Test isolation: extends `Tests\TestCase` which uses `DatabaseTransactions`
 * — the Branch/Company rows seeded for the `export()` tests are rolled back
 * after each test, so the dev DB stays pristine. No `RefreshDatabase`
 * needed (the project's baseline migration is too slow to replay per test).
 */
class CsvExporterTest extends TestCase
{
    /**
     * The CsvExporter instance under test (used for PUBLIC methods:
     * filename() + export()).
     */
    private CsvExporter $exporter;

    /**
     * Test subclass exposing the PROTECTED methods (extractValue, fputcsv)
     * as PUBLIC wrappers so they can be invoked from tests.
     */
    private TestableCsvExporter $testable;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the service container so the singleton-registered instance is
        // resolved (same path as production controllers that go through the
        // CsvExporter Facade). The TestableCsvExporter subclass is instantiated
        // directly with `new` since it isn't registered in AppServiceProvider.
        $this->exporter = app(CsvExporter::class);
        $this->testable = new TestableCsvExporter();
    }

    // ====================================================================
    // 1. extractValue() — PROTECTED, exposed via TestableCsvExporter
    // ====================================================================

    /**
     * Direct attribute access: 'branch_code' → $record->branch_code.
     */
    public function test_extract_value_direct_attribute(): void
    {
        $branch = Branch::factory()->create([
            'branch_code' => 'B001',
            'branch_name' => 'Direct Attr Branch',
        ]);

        $this->assertSame('B001', $this->testable->exposedExtractValue($branch, 'branch_code'));
    }

    /**
     * Dotted relation access: 'company.company_name' traverses the BelongsTo
     * relation via Eloquent's magic __get, then reads the company_name
     * attribute on the related Company model.
     */
    public function test_extract_value_dotted_relation(): void
    {
        $company = Company::create([
            'company_code' => 'CC-' . strtoupper(substr(uniqid(), -6)),
            'company_name' => 'Dhaka Trading Co.',
            'currency'     => 'BDT',
            'status'       => 'active',
        ]);

        $branch = Branch::factory()->create([
            'branch_code' => 'B002',
            'branch_name' => 'Dotted Relation Branch',
            'company_id'  => $company->id,
        ]);

        // Load the relation so extractValue's property traversal sees the
        // Company instance (not a lazy-loaded null in test contexts).
        $branch->load('company');

        $this->assertSame('Dhaka Trading Co.', $this->testable->exposedExtractValue($branch, 'company.company_name'));
    }

    /**
     * Null handling: a dotted relation whose chain hits null (e.g. the
     * BelongsTo foreign key is null) must return an empty string, not the
     * literal "null" or a thrown error.
     */
    public function test_extract_value_null_returns_empty_string(): void
    {
        // Branch with no company — company_id is null.
        $branch = Branch::factory()->create([
            'branch_code' => 'B003',
            'branch_name' => 'No Company Branch',
            'company_id'  => null,
        ]);

        $this->assertSame('', $this->testable->exposedExtractValue($branch, 'company.company_name'));
    }

    /**
     * Boolean true → 'Yes'. The is_active column is cast to boolean in the
     * Branch model ($casts['is_active'] = 'boolean'); extractValue must
     * emit the human-readable 'Yes' so spreadsheet consumers see a clean
     * label instead of '1' / 'true'.
     */
    public function test_extract_value_bool_true_returns_yes(): void
    {
        $branch = Branch::factory()->create([
            'branch_code' => 'B004',
            'branch_name' => 'Active Branch',
            'is_active'   => true,
        ]);

        $this->assertSame('Yes', $this->testable->exposedExtractValue($branch, 'is_active'));
    }

    /**
     * Boolean false → 'No'. Symmetric counterpart to the true→Yes test.
     */
    public function test_extract_value_bool_false_returns_no(): void
    {
        $branch = Branch::factory()->create([
            'branch_code' => 'B005',
            'branch_name' => 'Inactive Branch',
            'is_active'   => false,
        ]);

        $this->assertSame('No', $this->testable->exposedExtractValue($branch, 'is_active'));
    }

    /**
     * DateTimeInterface formatting: Eloquent auto-casts the `created_at`
     * timestamp column to a Carbon (which implements DateTimeInterface).
     * extractValue must format it as 'Y-m-d H:i:s' (ISO-friendly, sorts
     * lexicographically, no timezone drift).
     */
    public function test_extract_value_datetime_interface(): void
    {
        $branch = Branch::factory()->create([
            'branch_code' => 'B006',
            'branch_name' => 'Date Branch',
        ]);

        // Pin the created_at to a known timestamp for a deterministic assertion.
        $branch->forceFill([
            'created_at' => '2025-03-15 14:30:45',
            'updated_at' => '2025-03-15 14:30:45',
        ])->save();

        $branch->refresh();

        $value = $this->testable->exposedExtractValue($branch, 'created_at');

        // The 'Y-m-d H:i:s' format on a Carbon at 2025-03-15 14:30:45.
        $this->assertSame('2025-03-15 14:30:45', $value);
    }

    /**
     * (string) cast fallback: any value that is not null, not bool, and not
     * a DateTimeInterface falls through to `(string) $value`. Test with an
     * integer (the Branch primary key) — proves the cast runs and produces
     * a digit string, not the literal integer.
     */
    public function test_extract_value_string_cast(): void
    {
        $branch = Branch::factory()->create([
            'branch_code' => 'B007',
            'branch_name' => 'Cast Branch',
        ]);

        $id = (int) $branch->id;
        $this->assertGreaterThan(0, $id, 'Branch ID should be a positive integer.');

        $extracted = $this->testable->exposedExtractValue($branch, 'id');
        $this->assertSame((string) $id, $extracted);
        $this->assertIsString($extracted);
    }

    // ====================================================================
    // 2. filename() — PUBLIC
    // ====================================================================

    /**
     * Simple single-label filename: 'branches' → 'branches_{ts}.csv'.
     */
    public function test_filename_simple_label(): void
    {
        $filename = $this->exporter->filename('branches');

        $this->assertStringStartsWith('branches_', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }

    /**
     * Multi-part filename: each part is slugified + joined with '_'.
     * The integer part (1) becomes '1'; the 'to' keyword stays 'to'.
     *
     * NB on date slugification: `Str::slug('2025-01-01', '_')` converts
     * dashes to underscores (since the separator is '_', the opposite
     * character '-' is treated as a separator too) → '2025_01_01'. The
     * trailing timestamp segment, however, is added AFTER slugification
     * via `now()->format('Y-m-d_His')`, so its dashes are preserved as-is.
     * This test asserts the slugified date form ('2025_01_01') for the
     * user-supplied parts and the dash-preserving form for the trailing
     * timestamp (via the `.csv` extension invariant only — exact timestamp
     * is non-deterministic).
     */
    public function test_filename_with_parts(): void
    {
        $filename = $this->exporter->filename('branch_demand_weekly', [1, '2025-01-01', 'to', '2025-01-31']);

        $this->assertStringStartsWith('branch_demand_weekly_', $filename);
        $this->assertStringContainsString('_1_', $filename);
        $this->assertStringContainsString('2025_01_01', $filename);
        $this->assertStringContainsString('_to_', $filename);
        $this->assertStringContainsString('2025_01_31', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }

    /**
     * Slugification of spaces + special chars: 'Trial Balance' (space)
     * + 'S/E Dept' (slash) → 'trial_balance_se_dept_...csv'. Both
     * spaces and slashes are treated as separators by Str::slug;
     * single-letter segments merge without extra underscores.
     */
    public function test_filename_slugifies_spaces_and_special_chars(): void
    {
        $filename = $this->exporter->filename('Trial Balance', ['S/E Dept']);

        $this->assertStringContainsString('trial_balance_se_dept_', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }

    /**
     * Empty parts are skipped: filename('report', ['', 'valid']) must NOT
     * produce 'report__valid' (double underscore) — the empty part slugs
     * to '' and is filtered out by the `if ($slug !== '')` guard.
     */
    public function test_filename_skips_empty_parts(): void
    {
        $filename = $this->exporter->filename('report', ['', 'valid']);

        $this->assertStringContainsString('report_valid_', $filename);
        $this->assertStringNotContainsString('__', $filename, 'Empty part must not produce double underscore.');
        $this->assertStringEndsWith('.csv', $filename);
    }

    /**
     * The filename always ends with `.csv` regardless of input shape.
     */
    public function test_filename_ends_with_csv_extension(): void
    {
        $this->assertStringEndsWith('.csv', $this->exporter->filename('a'));
        $this->assertStringEndsWith('.csv', $this->exporter->filename('Long Label With Spaces', ['part1', 'part2']));
        $this->assertStringEndsWith('.csv', $this->exporter->filename('unicode_ΔΦΩ', []));
    }

    // ====================================================================
    // 3. export() — PUBLIC, end-to-end with a real EloquentBuilder
    // ====================================================================

    /**
     * Helper for the export() tests: seed two Branch rows with known
     * codes + is_active values, return the EloquentBuilder pre-scoped to
     * those two rows. Used by every export() test so they all assert
     * against the same dataset.
     */
    private function makeExportQuery(): EloquentBuilder
    {
        Branch::factory()->create([
            'branch_code' => 'CSVUNIT-1',
            'branch_name' => 'Csv Unit One',
            'is_active'   => true,
        ]);
        Branch::factory()->create([
            'branch_code' => 'CSVUNIT-2',
            'branch_name' => 'Csv Unit Two',
            'is_active'   => false,
        ]);

        return Branch::query()
            ->whereIn('branch_code', ['CSVUNIT-1', 'CSVUNIT-2'])
            ->orderBy('branch_code');
    }

    /**
     * Helper: invoke export() and capture the streamed body by executing
     * the response callback inside ob_start/ob_get_clean.
     *
     * Laravel's StreamedResponse does not expose streamedContent() in all
     * versions, so we capture the output buffer manually.
     */
    private function captureExport(string $filename, array $columns, EloquentBuilder $query): string
    {
        $response = $this->exporter->export($filename, $columns, $query);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        return $content !== false ? $content : '';
    }

    /**
     * The first 3 bytes of the streamed body must be the UTF-8 BOM
     * (\xEF\xBB\xBF) so Excel auto-detects encoding and renders UTF-8
     * content (e.g. Bengali names) without mojibake.
     */
    public function test_export_produces_bom_header(): void
    {
        $content = $this->captureExport(
            'branches_export.csv',
            ['branch_code' => 'Code', 'branch_name' => 'Branch Name'],
            $this->makeExportQuery(),
        );

        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3), 'CSV body must start with UTF-8 BOM.');
    }

    /**
     * The first CSV row (immediately after the BOM) is the column header
     * built from the array_values of the $columns map (column labels, not
     * keys). Must contain each label.
     */
    public function test_export_writes_header_row(): void
    {
        $content = $this->captureExport(
            'branches_export.csv',
            ['branch_code' => 'Code', 'branch_name' => 'Branch Name', 'is_active' => 'Active'],
            $this->makeExportQuery(),
        );

        $rows = $this->parseCsv($content);
        $this->assertNotEmpty($rows, 'CSV must produce at least the header row.');
        $this->assertContains('Code', $rows[0]);
        $this->assertContains('Branch Name', $rows[0]);
        $this->assertContains('Active', $rows[0]);
    }

    /**
     * Each seeded record must appear as a data row with its branch_code +
     * branch_name values intact. The is_active boolean must render as
     * 'Yes'/'No' via extractValue's bool→Yes/No conversion.
     */
    public function test_export_writes_data_rows(): void
    {
        $content = $this->captureExport(
            'branches_export.csv',
            ['branch_code' => 'Code', 'branch_name' => 'Branch Name', 'is_active' => 'Active'],
            $this->makeExportQuery(),
        );

        $rows = $this->parseCsv($content);

        // Row 0 = header; rows 1 + 2 = the two seeded Branch records
        // (query is ordered by branch_code ascending → CSVUNIT-1 first).
        $this->assertCount(3, $rows, 'CSV must contain exactly 1 header row + 2 data rows.');

        $this->assertSame('CSVUNIT-1', $rows[1][0]);
        $this->assertSame('Csv Unit One', $rows[1][1]);
        $this->assertSame('Yes', $rows[1][2], 'is_active=true must render as "Yes".');

        $this->assertSame('CSVUNIT-2', $rows[2][0]);
        $this->assertSame('Csv Unit Two', $rows[2][1]);
        $this->assertSame('No', $rows[2][2], 'is_active=false must render as "No".');
    }

    /**
     * The Content-Type header is read from config('reports.csv.content_type')
     * at CsvExporter construction time. The default value (set in
     * config/reports.php) is 'text/csv; charset=UTF-8' (capital UTF-8 —
     * per the G18 cosmetic-consistency resolution).
     */
    public function test_export_sets_content_type_header(): void
    {
        $response = $this->exporter->export(
            'branches_export.csv',
            ['branch_code' => 'Code'],
            $this->makeExportQuery(),
        );

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    /**
     * The Content-Disposition header signals an attachment download with
     * the exact filename passed to export(). Format: 'attachment; filename="..."'
     * (RFC 6266). The filename is consumed AS-IS by export() — the caller
     * is responsible for building it via CsvExporter::filename() upstream.
     */
    public function test_export_sets_content_disposition(): void
    {
        $filename = 'branches_export_2025-01-15_143022.csv';

        $response = $this->exporter->export(
            $filename,
            ['branch_code' => 'Code'],
            $this->makeExportQuery(),
        );

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename="', $disposition);
        $this->assertStringEndsWith('"', $disposition);
        $this->assertStringContainsString($filename, $disposition);
        $this->assertSame('attachment; filename="' . $filename . '"', $disposition);
    }

    // ====================================================================
    // 4. fputcsv() — PROTECTED, exposed via TestableCsvExporter
    // ====================================================================

    /**
     * fputcsv writes a single row terminated by a newline, fields joined
     * by the forced ',' delimiter. Simple non-special fields are written
     * as-is without enclosure.
     */
    public function test_fputcsv_writes_rfc4180_row(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);

        $this->testable->exposedFputcsv($stream, ['hello', 'world']);

        rewind($stream);
        $written = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame("hello,world\n", $written);
    }

    /**
     * Fields containing a comma, double-quote, or newline are wrapped in
     * double-quotes per RFC 4180. Internal double-quotes are escaped by
     * doubling them ('""'). PHP's fputcsv with the forced ',' / '"' / '\\'
     * arguments produces the canonical RFC 4180 output.
     */
    public function test_fputcsv_escapes_commas_and_quotes(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);

        $this->testable->exposedFputcsv($stream, ['has,comma', 'has"quote', 'plain']);

        rewind($stream);
        $written = stream_get_contents($stream);
        fclose($stream);

        // Expected per RFC 4180: comma-field wrapped + quoted; quote-field
        // wrapped + internal quote doubled; plain field unwrapped.
        $this->assertSame("\"has,comma\",\"has\"\"quote\",plain\n", $written);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Parse a CSV string (after the BOM) into an array of rows, each row
     * an array of cell strings. Mirrors the parseCsv helper in
     * tests/Feature/Export/CsvExportTest.php — kept duplicated here so
     * this unit test has zero dependencies on the feature-test suite.
     *
     * @return list<list<string|null>>
     */
    private function parseCsv(string $content): array
    {
        // Strip the BOM.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open memory stream for CSV parsing.');
        }
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === ['']) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}

/**
 * Test subclass of CsvExporter that exposes the PROTECTED methods
 * (extractValue, fputcsv) as PUBLIC wrappers so they can be invoked
 * directly from the test class.
 *
 * Declared in the same file (per the gap spec's recommendation) so PSR-4
 * autoloading is not required for this helper — PHPUnit includes the
 * whole file when loading CsvExporterTest, so the subclass is available
 * to the test class above.
 *
 * An alternative pattern (used by tests/Unit/StockTake/StockTakeServiceTest
 * + tests/Unit/Realtime/ListenNotifyServiceTest) is ReflectionMethod — but
 * the subclass approach is preferred here because:
 *   1. The gap spec explicitly recommends it.
 *   2. extractValue + fputcsv are called from MANY call sites inside
 *      CsvExporter (export, exportFromRows) — exposing them via a subclass
 *      lets us exercise them in isolation without re-implementing the
 *      caller-side logic, AND lets us reuse the same instance for end-to-end
 *      export() tests if needed.
 */
class TestableCsvExporter extends CsvExporter
{
    /**
     * Expose the PROTECTED extractValue() for direct unit testing.
     *
     * @param  Model  $record  The Eloquent record to extract a value from.
     * @param  string $key     Either a direct attribute ('branch_code') or
     *                         a dotted relation path ('company.company_name').
     * @return string          The formatted value ('' for null, 'Yes'/'No'
     *                         for bool, 'Y-m-d H:i:s' for DateTimeInterface,
     *                         (string) cast otherwise).
     */
    public function exposedExtractValue(Model $record, string $key): string
    {
        return $this->extractValue($record, $key);
    }

    /**
     * Expose the PROTECTED fputcsv() for direct unit testing.
     *
     * @param resource $handle A writable stream handle (e.g. from fopen('php://memory')).
     * @param array    $fields The row fields to write.
     */
    public function exposedFputcsv($handle, array $fields): void
    {
        $this->fputcsv($handle, $fields);
    }
}
