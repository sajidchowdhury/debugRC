<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * CsvExporter Facade — REPORTS-AUDIT-1 (G-126 / csv-export.md G3).
 *
 * Provides a static-callable interface to the CsvExporter singleton
 * registered in AppServiceProvider. Allows the existing 9 master-data
 * controllers to keep their call-site syntax (`CsvExporter::export(...)`)
 * unchanged while the underlying service is now an instance class.
 *
 * Usage:
 *
 *   use App\Facades\CsvExporter;
 *
 *   return CsvExporter::export('branches', $columns, $query);
 *
 * Resolution: `CsvExporter::export(...)` →
 *   `app(\App\Services\Export\CsvExporter::class)->export(...)`
 *
 * The singleton is stateless within a request (config values are read
 * once at construction), so the Facade is safe to call from any context.
 */
class CsvExporter extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * Returns the concrete service class binding (resolved via the
     * singleton registered in AppServiceProvider::register()). This
     * keeps the Facade + service binding in lockstep — renaming the
     * service class only requires touching 2 files (the service +
     * this accessor).
     */
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\Export\CsvExporter::class;
    }
}
