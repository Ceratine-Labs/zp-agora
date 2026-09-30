<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The issue multiples head office sent with the weekly stock recon pack.
 *
 * Source: "ZRP Stock Recon — Item Issue Multiples", sheet "Proposed Multiples",
 * bundled in zrp-stock-recon-weekly.zip (23 Sep 2026) as
 * reference/issue_multiples.xlsx. 3,111 lines across 19 sites, one per
 * (branch, area, item), exported to data/issue_multiples_2026-09-11.csv with
 * the columns the recon reads and nothing reinterpreted: the multiple as
 * written, IsWeighed where the Issue Unit is "Kilogram / litre".
 *
 * Why it is in Agora at all: see v1__14f. PumpIT's IssueMultiple column is
 * empty on these sites, and rule 5 of the pack cannot be applied without it.
 *
 * A NEW WORKBOOK IS A NEW SEEDER, with its own CSV and its own Workbook value —
 * this one is in the ledger once it has run and will not run again. Upserts on
 * the natural key, so a line the new workbook leaves out keeps its old value
 * rather than silently becoming "no pack".
 */
class IssueMultipleSeeder extends Seeder
{
    public int $seedOrder = 70;

    protected string $csv = __DIR__.'/data/issue_multiples_2026-09-11.csv';

    protected string $workbook = 'Issue Multiples 2026-09-11 (weekly pack 23 Sep 2026)';

    public function run(): void
    {
        $rows = $this->rows();
        $schema = config('agora.schema');
        $db = DB::connection(config('agora.connections.app'));

        $db->transaction(function () use ($db, $schema, $rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                $values = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'));
                $bindings = [];

                foreach ($chunk as $r) {
                    array_push($bindings, $r['BranchId'], $r['AreaNo'], $r['StockItemNo'], $r['POSCode'],
                        $r['IssueMultiple'], $r['IsWeighed'], $r['Enforcement'], $r['SourceOfValue']);
                }

                $db->statement("
                    MERGE [{$schema}].[StockIssueMultiple] AS t
                    USING (VALUES {$values}) AS s (BranchId, AreaNo, StockItemNo, POSCode, IssueMultiple, IsWeighed, Enforcement, SourceOfValue)
                       ON t.BranchId = s.BranchId AND t.AreaNo = s.AreaNo AND t.StockItemNo = s.StockItemNo
                    WHEN MATCHED THEN UPDATE SET
                        POSCode = s.POSCode, IssueMultiple = s.IssueMultiple, IsWeighed = s.IsWeighed,
                        Enforcement = s.Enforcement, SourceOfValue = s.SourceOfValue,
                        Workbook = ?, UpdatedAt = SYSDATETIME()
                    WHEN NOT MATCHED THEN INSERT
                        (BranchId, AreaNo, StockItemNo, POSCode, IssueMultiple, IsWeighed, Enforcement, SourceOfValue, Workbook, CreatedAt)
                        VALUES (s.BranchId, s.AreaNo, s.StockItemNo, s.POSCode, s.IssueMultiple, s.IsWeighed,
                                s.Enforcement, s.SourceOfValue, ?, SYSDATETIME());
                ", [...$bindings, $this->workbook, $this->workbook]);
            }
        });
    }

    /** @return list<array<string, int|string|null>> */
    private function rows(): array
    {
        $handle = fopen($this->csv, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$this->csv}.");
        }

        $header = fgetcsv($handle, escape: '');
        $rows = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $r = array_combine((array) $header, $line);
            $rows[] = [
                'BranchId' => (int) $r['BranchId'],
                'AreaNo' => (int) $r['AreaNo'],
                'StockItemNo' => trim((string) $r['StockItemNo']),
                'POSCode' => trim((string) $r['POSCode']) ?: null,
                'IssueMultiple' => (string) $r['IssueMultiple'],
                'IsWeighed' => (int) $r['IsWeighed'],
                'Enforcement' => trim((string) $r['Enforcement']) ?: null,
                'SourceOfValue' => trim((string) $r['SourceOfValue']) ?: null,
            ];
        }

        fclose($handle);

        return $rows;
    }
}
