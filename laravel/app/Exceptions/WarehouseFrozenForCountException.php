<?php

namespace App\Exceptions;

/**
 * Warehouse Frozen For Count Exception — Phase 3 (Stock Take plan).
 *
 * Thrown by StockService::applyTransaction() at the very start of an OUTBOUND
 * movement (qty < 0) when the source warehouse is currently frozen by an
 * active stock-take session (warehouses.is_frozen_for_count = true).
 *
 * The exception carries the warehouse identity + the list of active session
 * codes that are freezing it, so the calling controller can render a clear,
 * actionable 422 response naming the session(s) the user must finish/cancel
 * before the outbound movement can proceed.
 *
 * Only OUTBOUND movements are blocked. Inbound movements (purchases received,
 * transfers IN) are allowed during a count — only stock LEAVING the warehouse
 * would corrupt the count. The stock-take's OWN variance application
 * (reference_type='stock_take') and reversals (reference_type='reversal') are
 * explicitly exempt so the count can be posted/cancelled while frozen.
 *
 * This is a soft block by design: the user resolves it by finishing or
 * cancelling the active session, not by escalating privileges.
 */
class WarehouseFrozenForCountException extends \RuntimeException
{
    /** @var int */
    private int $warehouseId;

    /** @var string */
    private string $warehouseName;

    /** @var array<int, array{id: int, session_code: string, status: string}> */
    private array $sessions;

    /**
     * @param int $warehouseId
     * @param string $warehouseName
     * @param array<int, array{id: int, session_code: string, status: string}> $sessions
     *     The active stock-take sessions freezing this warehouse.
     */
    public function __construct(int $warehouseId, string $warehouseName, array $sessions)
    {
        $this->warehouseId   = $warehouseId;
        $this->warehouseName = $warehouseName;
        $this->sessions      = $sessions;

        $codes = array_map(
            static fn(array $s) => $s['session_code'] ?? ('#' . ($s['id'] ?? '?')),
            $sessions
        );
        $codeList = implode(', ', $codes);
        $count    = count($sessions);

        parent::__construct(
            "Warehouse \"{$warehouseName}\" is frozen for an active stock take "
            . "session" . ($count > 1 ? 's' : '') . " ({$codeList}). "
            . 'Outbound movements (sales, transfers out, adjustments out, damages) '
            . 'are blocked until the count is posted or cancelled.'
        );
    }

    public function getWarehouseId(): int
    {
        return $this->warehouseId;
    }

    public function getWarehouseName(): string
    {
        return $this->warehouseName;
    }

    /**
     * @return array<int, array{id: int, session_code: string, status: string}>
     */
    public function getSessions(): array
    {
        return $this->sessions;
    }
}
