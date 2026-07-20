<?php

// app/models/BranchIntercompanyAuditModel.php — inter-branch GL audit checklist (Phase 5D).



require_once __DIR__ . '/../../core/Database.php';

require_once __DIR__ . '/../helpers/Helper.php';



class BranchIntercompanyAuditModel

{

    protected Database $db;

    protected ?int $branchId;



    public function __construct()

    {

        $this->db = new Database();

        $this->branchId = Helper::sessionBranchId();

    }



    public function runHealthChecks(): array

    {

        $sections = [

            $this->sectionGlJournalLinks(),

            $this->sectionLedgerNature(),

            $this->sectionDemandGl(),

            $this->sectionJeBalance(),

        ];



        $pass = $warn = $fail = $info = 0;

        foreach ($sections as $section) {

            foreach ($section['items'] as $item) {

                switch ($item['status']) {

                    case 'pass': $pass++; break;

                    case 'warn': $warn++; break;

                    case 'fail': $fail++; break;

                    default: $info++;

                }

            }

        }



        return [

            'sections'                 => $sections,

            'summary'                  => [

                'pass'  => $pass,

                'warn'  => $warn,

                'fail'  => $fail,

                'info'  => $info,

                'total' => $pass + $warn + $fail + $info,

            ],

            'ran_at'                   => date('Y-m-d H:i:s'),

            'branch_id'                => $this->branchId,

            'missing_demand_journals'  => $this->getDemandsMissingJournalRows(),

        ];

    }



    /**

     * Shared ledger + link sections for warehouse transfer checklist.

     *

     * @return array<int, array<string, mixed>>

     */

    public function sharedInterbranchSections(): array

    {

        return [

            $this->sectionGlJournalLinks(),

            $this->sectionLedgerNature(),

        ];

    }



    private function sectionGlJournalLinks(): array

    {

        return [

            'id'    => 'gl_links',

            'title' => 'GL journal link columns',

            'icon'  => 'fa-link',

            'items' => [

                $this->item(

                    'gl_col_demand_cred',

                    'reference',

                    'branch_demands.journal_entry_id',

                    'Creditor (supplier) branch: Dr Due from / Cr inventory. View on BranchDemand/details/{id}.',

                    'info',

                    null,

                    true,

                    'BranchDemand'

                ),

                $this->item(

                    'gl_col_demand_debt',

                    'reference',

                    'branch_demands.journal_entry_id_debtor',

                    'Debtor (requester) branch: Dr inventory / Cr Due to. Same detail page — second card.',

                    'info',

                    null,

                    true,

                    'BranchDemand'

                ),

                $this->item(

                    'gl_col_wt',

                    'reference',

                    'warehouse_transfers.journal_entry_id / journal_entry_id_debtor',

                    'Standalone cross-branch WT only (demand-linked GL stays on branch_demands). View WarehouseTransfer/details/{id}.',

                    'info',

                    null,

                    true,

                    'WarehouseTransfer'

                ),

            ],

        ];

    }



    private function sectionLedgerNature(): array

    {

        $recvLedgers = $this->scalarCount("

            SELECT COUNT(*) AS c FROM ledgers

            WHERE ledger_nature = 'interbranch_receivable' AND is_active = 1

        ");

        $payLedgers = $this->scalarCount("

            SELECT COUNT(*) AS c FROM ledgers

            WHERE ledger_nature = 'interbranch_payable' AND is_active = 1

        ");

        $invLedgers = $this->scalarCount("

            SELECT COUNT(*) AS c FROM ledgers

            WHERE ledger_nature = 'inventory' AND is_active = 1

        ");



        return [

            'id'    => 'ledger_nature',

            'title' => 'Inter-branch CoA heads (migration 021)',

            'icon'  => 'fa-book',

            'items' => [

                $this->item(

                    'nat_recv',

                    'auto',

                    'interbranch_receivable ledger exists',

                    'Due from Branches — debited on supplier branch when stock ships out.',

                    $recvLedgers > 0 ? 'pass' : 'fail',

                    $recvLedgers > 0 ? "{$recvLedgers} active ledger(s)" : 'Missing — run migration 021'

                ),

                $this->item(

                    'nat_pay',

                    'auto',

                    'interbranch_payable ledger exists',

                    'Due to Branches — credited on requester branch when stock is received.',

                    $payLedgers > 0 ? 'pass' : 'fail',

                    $payLedgers > 0 ? "{$payLedgers} active ledger(s)" : 'Missing — run migration 021'

                ),

                $this->item(

                    'nat_inv',

                    'auto',

                    'inventory ledger exists',

                    'Required on both legs of inter-branch stock movement.',

                    $invLedgers > 0 ? 'pass' : 'fail',

                    $invLedgers > 0 ? "{$invLedgers} active ledger(s)" : 'Missing — GRN/stock posting will fail'

                ),

            ],

        ];

    }



    private function sectionDemandGl(): array

    {

        $missingJe = $this->scalarCount("

            SELECT COUNT(*) AS c FROM branch_demands bd

            WHERE bd.status = 'received'

              AND COALESCE(bd.is_reversed, 0) = 0

              AND COALESCE(bd.total_value, 0) >= 0.01

              AND (

                  COALESCE(bd.journal_entry_id, 0) = 0

                  OR COALESCE(bd.journal_entry_id_debtor, 0) = 0

              )

              {$this->demandBranchFilter()}

        ");



        $reversedActiveJe = $this->scalarCount("

            SELECT COUNT(*) AS c FROM branch_demands bd

            WHERE COALESCE(bd.is_reversed, 0) = 1

              AND (

                  (COALESCE(bd.journal_entry_id, 0) > 0 AND EXISTS (

                      SELECT 1 FROM journal_entries je

                      WHERE je.id = bd.journal_entry_id AND COALESCE(je.is_reversed, 0) = 0

                  ))

                  OR (COALESCE(bd.journal_entry_id_debtor, 0) > 0 AND EXISTS (

                      SELECT 1 FROM journal_entries je

                      WHERE je.id = bd.journal_entry_id_debtor AND COALESCE(je.is_reversed, 0) = 0

                  ))

              )

              {$this->demandBranchFilter()}

        ");



        return [

            'id'    => 'demand_gl',

            'title' => 'Branch demand GL alignment',

            'icon'  => 'fa-balance-scale',

            'items' => [

                $this->item(

                    'demand_je',

                    'auto',

                    'Received demands have both branch journals',

                    'When total_value > 0, creditor and debtor journal_entry_id columns must be set.',

                    $missingJe === 0 ? 'pass' : 'warn',

                    $missingJe === 0 ? 'OK' : "{$missingJe} demand(s) missing journal"

                ),

                $this->item(

                    'demand_rev',

                    'auto',

                    'Reversed demands reverse GL',

                    'Both linked journals should be reversed when demand is reversed.',

                    $reversedActiveJe === 0 ? 'pass' : 'warn',

                    $reversedActiveJe === 0 ? 'OK' : "{$reversedActiveJe} reversed demand(s) with active journal"

                ),

            ],

        ];

    }



    private function sectionJeBalance(): array

    {

        $unbalanced = $this->scalarCount("
            SELECT COUNT(*) AS c FROM journal_entries je
            WHERE je.reference_type = 'branch_demand'
              AND EXISTS (
                  SELECT 1 FROM branch_demands bd
                  WHERE bd.id = je.reference_id
                    AND bd.status = 'received'
                    AND COALESCE(bd.is_reversed, 0) = 0
                    AND COALESCE(bd.total_value, 0) >= 0.01
                    {$this->demandBranchFilter('bd')}
              )
              AND ABS(
                  (SELECT COALESCE(SUM(jel.debit), 0) FROM journal_entry_lines jel WHERE jel.journal_entry_id = je.id)
                  - (SELECT COALESCE(SUM(jel.credit), 0) FROM journal_entry_lines jel WHERE jel.journal_entry_id = je.id)
              ) >= 0.02
        ");



        return [

            'id'    => 'je_balance',

            'title' => 'Journal balance (TB integrity)',

            'icon'  => 'fa-scale-balanced',

            'items' => [

                $this->item(

                    'je_balanced',

                    'auto',

                    'Demand fulfillment journals balance',

                    'Each linked journal should have total Dr = total Cr (within 0.02).',

                    $unbalanced === 0 ? 'pass' : 'fail',

                    $unbalanced === 0 ? 'OK' : "{$unbalanced} unbalanced journal(s)"

                ),

                $this->item(

                    'tb_ref',

                    'reference',

                    'Trial balance check',

                    'After period activity, run Report/TrialBalance — inter-branch Due from/to should net across branches.',

                    'info',

                    null,

                    true,

                    'Report/TrialBalance'

                ),

            ],

        ];

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public function getDemandsMissingJournalRows(int $limit = 15): array

    {

        try {

            $this->db->query("

                SELECT bd.id, bd.demand_code, bd.demand_date, bd.total_value,

                       fb.branch_name AS from_branch, tb.branch_name AS to_branch

                FROM branch_demands bd

                JOIN branches fb ON fb.id = bd.from_branch_id

                JOIN branches tb ON tb.id = bd.to_branch_id

                WHERE bd.status = 'received'

                  AND COALESCE(bd.is_reversed, 0) = 0

                  AND COALESCE(bd.total_value, 0) >= 0.01

                  AND (

                      COALESCE(bd.journal_entry_id, 0) = 0

                      OR COALESCE(bd.journal_entry_id_debtor, 0) = 0

                  )

                  {$this->demandBranchFilter('bd')}

                ORDER BY bd.demand_date DESC

                LIMIT " . (int)$limit

            );



            return $this->db->resultSet() ?: [];

        } catch (Exception $e) {

            error_log('BranchIntercompanyAuditModel::getDemandsMissingJournalRows: ' . $e->getMessage());



            return [];

        }

    }



    private function item(

        string $id,

        string $type,

        string $title,

        string $expected,

        string $status,

        ?string $detail = null,

        bool $reference = false,

        ?string $route = null

    ): array {

        return [

            'id'        => $id,

            'type'      => $type,

            'title'     => $title,

            'expected'  => $expected,

            'status'    => $status,

            'detail'    => $detail ?? '',

            'reference' => $reference,

            'url'       => $route ? (defined('BASE_URL') ? BASE_URL : '') . $route : null,

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

            error_log('BranchIntercompanyAuditModel: ' . $e->getMessage());



            return -1;

        }

    }



    private function demandBranchFilter(string $alias = 'bd'): string

    {

        if (!$this->branchId) {

            return '';

        }



        return ' AND (' . $alias . '.from_branch_id = ' . (int)$this->branchId

            . ' OR ' . $alias . '.to_branch_id = ' . (int)$this->branchId . ')';

    }

}

