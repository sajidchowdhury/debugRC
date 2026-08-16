<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * YearEndCloseException — thrown when year-end close cannot proceed.
 *
 * Created in Session 3. Used by AccountingPeriodService::yearEndClose()
 * to abort the close when a pre-flight gate fails — most commonly the
 * backup-on-file gate (no fresh, verified backup exists for the FY
 * being closed).
 *
 * Rendered by the global exception handler in bootstrap/app.php as a
 * redirect-back-with-error for web requests. The controller
 * (AccountingPeriodController::yearEndClose) already wraps the service
 * call in a try/catch that does this — but the exception class exists
 * so callers can distinguish "year-end close failed" from other
 * RuntimeExceptions (e.g., a missing retained-earnings ledger is a
 * different class of error that should be reported differently).
 *
 * @see \App\Services\Accounting\AccountingPeriodService::yearEndClose()
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 3
 */
class YearEndCloseException extends RuntimeException
{
    /**
     * The fiscal year id that failed to close, if known.
     */
    private ?int $fiscalYearId;

    public function __construct(string $message, ?int $fiscalYearId = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->fiscalYearId = $fiscalYearId;
    }

    public function getFiscalYearId(): ?int
    {
        return $this->fiscalYearId;
    }
}
