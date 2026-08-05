<?php

namespace App\Exceptions;

/**
 * System Policy Write Blocked Exception — AUDIT-TRAIL-3 (G-172 + G-175).
 *
 * Thrown when a destructive (write) operation is attempted while the system
 * is in INVESTIGATION mode. INVESTIGATION mode is the forensic posture that
 * "freezes the books" — all financial mutations are blocked so the
 * investigator can examine a stable, uncontaminated data set.
 *
 * Two layers throw this exception:
 *
 *   1. BlockWritesDuringInvestigation middleware (HTTP layer) — blocks all
 *      non-GET requests during INVESTIGATION, with a narrow allowlist
 *      (auth, compliance admin so the superadmin can deactivate, API docs,
 *      health check). Catches HTTP-driven writes from web browsers + mobile
 *      API clients.
 *
 *   2. SystemPolicyService::assertWriteAllowed() (service layer) — called
 *      from JournalPostingService::createJournalEntry() (the single GL
 *      chokepoint; reverseJournalEntry calls it internally). Catches writes
 *      that bypass HTTP middleware: console commands, scheduled jobs, queue
 *      workers, and any code path that posts a GL entry directly.
 *
 * The exception carries the active mode + the operation/context string so
 * the rendered response can name what was blocked. The bootstrap/app.php
 * exception handler renders it as JSON for API/AJAX callers + a
 * redirect-back-with-error for web (mirrors the
 * WarehouseFrozenForCountException pattern).
 *
 * Resolution path for the user: a superadmin deactivates INVESTIGATION mode
 * via /admin/compliance/deactivate (allowlisted, so the toggle itself is
 * never blocked). This is a soft block — the user escalates by toggling the
 * policy off, not by escalating privileges.
 */
class SystemPolicyWriteBlockedException extends \RuntimeException
{
    /** @var string */
    private string $mode;

    /** @var string */
    private string $operation;

    /** @var string|null */
    private ?string $context;

    /**
     * @param string      $mode      The active system policy mode (always 'INVESTIGATION' when thrown).
     * @param string      $operation A short label for the blocked operation
     *                               (e.g. 'http_request', 'journal_entry_create').
     * @param string|null $context   Optional context (e.g. the request URI, or
     *                               the reference_type of the journal entry).
     */
    public function __construct(string $mode, string $operation, ?string $context = null)
    {
        $this->mode      = $mode;
        $this->operation = $operation;
        $this->context   = $context;

        $message = "System is in {$mode} mode — destructive operations are blocked."
            . " Operation: {$operation}"
            . ($context !== null ? " ({$context})" : '')
            . ' A superadmin can deactivate INVESTIGATION mode via /admin/compliance/deactivate.';

        parent::__construct($message);
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }
}
