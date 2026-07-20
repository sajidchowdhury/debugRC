<?php
// app/models/DamageAuditModel.php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/DamageModel.php';

class DamageAuditModel
{
    protected Database $db;
    protected ?int $branchId;

    public function __construct()
    {
        $this->db = new Database();
        $this->branchId = Helper::sessionBranchId();
    }

    public function runDamageChecks(int $damageId): array
    {
        $d = (new DamageModel())->getDamageById($damageId);
        if (!$d) {
            return ['items' => [], 'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0]];
        }

        $items      = [];
        $isReversed = !empty($d['is_reversed']);
        $total      = (float)($d['total_value'] ?? 0);

        $items[] = $this->item(
            'branch_wh',
            'auto',
            'Branch warehouse',
            'Damage must be from a warehouse in the user branch.',
            'pass',
            ($d['warehouse_name'] ?? '') . ' · ' . ($d['branch_name'] ?? '')
        );

        $movements = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions
            WHERE reference_type = 'damage' AND reference_id = :id
              AND COALESCE(is_reversed, 0) = 0
        ", [':id' => $damageId]);

        $items[] = $this->item(
            'stock',
            'auto',
            'Stock movements',
            'Negative qty movements for each damaged line.',
            $movements > 0 ? 'pass' : ($isReversed ? 'info' : 'fail'),
            $movements > 0 ? "{$movements} active row(s)" : 'Missing'
        );

        $lineSum = $this->scalarSum("
            SELECT COALESCE(SUM(qty * rate), 0) AS s FROM damage_invoice_items WHERE damage_invoice_id = :id
        ", [':id' => $damageId]);

        $delta = abs($lineSum - $total);
        $items[] = $this->item(
            'total_value',
            'auto',
            'Damage amount',
            'Header total_value must equal sum of line qty × rate.',
            $delta < 0.02 ? 'pass' : 'fail',
            'Header ' . number_format($total, 2) . ' · Lines ' . number_format($lineSum, 2)
        );

        if ($isReversed) {
            $items[] = $this->item(
                'reversed',
                'auto',
                'Reversed',
                'Stock and GL (if any) reversed.',
                !empty($d['reverse_reason']) ? 'pass' : 'warn',
                trim(($d['reverse_reason'] ?? '') . ' ' . ($d['reversed_by_name'] ?? ''))
            );
        } elseif ($total >= 0.01) {
            $hasGl = !empty($d['journal_entry_id']);
            $items[] = $this->item(
                'gl',
                'auto',
                'Damage GL',
                'Dr shrinkage / Cr inventory when total &gt; 0.',
                $hasGl ? 'pass' : 'warn',
                $hasGl ? 'Journal #' . $d['journal_entry_id'] : 'Missing (run migration 027?)'
            );
        }

        $pass = $warn = $fail = 0;
        foreach ($items as $it) {
            match ($it['status']) {
                'pass' => $pass++,
                'warn' => $warn++,
                'fail' => $fail++,
                default => null,
            };
        }

        return ['items' => $items, 'summary' => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail]];
    }

    private function item(string $id, string $type, string $title, string $expected, string $status, ?string $detail = null): array
    {
        return [
            'id'       => $id,
            'type'     => $type,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
        ];
    }

    private function scalarCount(string $sql, array $bind = []): int
    {
        try {
            $this->db->query($sql);
            foreach ($bind as $k => $v) {
                $this->db->bind($k, $v);
            }
            return (int)($this->db->single()['c'] ?? 0);
        } catch (Exception $e) {
            return -1;
        }
    }

    private function scalarSum(string $sql, array $bind = []): float
    {
        try {
            $this->db->query($sql);
            foreach ($bind as $k => $v) {
                $this->db->bind($k, $v);
            }
            return (float)($this->db->single()['s'] ?? 0);
        } catch (Exception $e) {
            return 0.0;
        }
    }
}