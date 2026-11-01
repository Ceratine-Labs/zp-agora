<?php

namespace Tests\Feature\Reports;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Actual T-SQL with synthetic dependencies, isolated and rolled back locally. */
class MyQueueTotalsSqlTest extends TestCase
{
    private Connection $connection;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('This SQL test creates synthetic tables and a procedure in a rolled-back schema.');
        $this->connection = DB::connection(config('agora.connections.app'));
        $this->schema = 'TEST_QueueTotals_'.bin2hex(random_bytes(4));
        $this->connection->beginTransaction();
        $this->connection->unprepared("CREATE SCHEMA [{$this->schema}]");

        $tables = [
            'vw_Branch' => 'BranchId int, Name nvarchar(100)',
            'vw_ReconImports' => 'BranchId int, ReconDate date, DayBalance bit',
            'vw_CashZread' => 'BranchId int, ZReadAt datetime2, IsAllocated bit, IsPartlyAllocated bit, Sales money',
            'vw_CashupTotals' => 'BranchId int, TransactionAt datetime2, IsOpen bit, ZRead money',
            'vw_PurchaseRequest' => 'BranchId int, TransactionNo int, PurchaseRequestDate datetime2, Approved bit, IsDeclined bit, Posted bit',
            'vw_PurchaseRequestLine' => 'BranchId int, TransactionNo int, PurchaseAmount money',
            'vw_StaffShort' => 'BranchId int, TransactionDate datetime2, AmountShort money, Approved bit, IsDeclined bit',
            'vw_DropSafeBag' => 'BranchId int, DropDate datetime2, Amount money, CollectionId int',
        ];
        foreach ($tables as $name => $columns) {
            $this->connection->unprepared("CREATE TABLE [{$this->schema}].[{$name}] ({$columns})");
        }

        $sql = file_get_contents(base_path('Modules/Reports/Database/Procedures/usp_Reports_GridMyQueue.sql'));
        $sql = str_replace(['[agora].', 'agora.'], ["[{$this->schema}].", "{$this->schema}."], $sql);
        $this->connection->unprepared($sql);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            while ($this->connection->transactionLevel() > 0) {
                $this->connection->rollBack();
            }
        }
        parent::tearDown();
    }

    public function test_totals_cover_the_filtered_scope_not_the_page_and_preserve_queue_meaning(): void
    {
        $this->seedRows();
        $first = $this->runQueue(page: 1);
        $second = $this->runQueue(page: 2);
        $this->assertCount(3, $first);
        $this->assertCount(1, $first[0]);
        $this->assertCount(1, $second[0]);
        $this->assertSame(12, (int) $first[1][0]['TotalRows']);
        $this->assertSame($first[1], $second[1]);
        $this->assertNotSame($first[0], $second[0]);
        $this->assertSame($this->totals($first), $this->totals($second));
        $this->assertSame([
            'day-close' => ['OpenItems' => 3, 'Value' => null, 'SiteQueues' => 2],
            'drop-safe' => ['OpenItems' => 2, 'Value' => '15.0000', 'SiteQueues' => 2],
            'open-cashups' => ['OpenItems' => 2, 'Value' => '300.0000', 'SiteQueues' => 2],
            'purchase-approvals' => ['OpenItems' => 3, 'Value' => '100.0000', 'SiteQueues' => 2],
            'staff-shorts' => ['OpenItems' => 2, 'Value' => '10.0000', 'SiteQueues' => 2],
            'unallocated-zreads' => ['OpenItems' => 3, 'Value' => '35.0000', 'SiteQueues' => 2],
        ], $this->totals($first));

        $branch = $this->runQueue(branches: '13');
        $this->assertSame(6, (int) $branch[1][0]['TotalRows']);
        $this->assertSame(['OpenItems' => 2, 'Value' => null, 'SiteQueues' => 1], $this->totals($branch)['day-close']);
        $this->assertSame(['OpenItems' => 2, 'Value' => '40.0000', 'SiteQueues' => 1], $this->totals($branch)['purchase-approvals']);

        $searched = $this->runQueue(search: 'Open cashups');
        $this->assertSame(2, (int) $searched[1][0]['TotalRows']);
        $this->assertSame(['open-cashups' => ['OpenItems' => 2, 'Value' => '300.0000', 'SiteQueues' => 2]], $this->totals($searched));

        foreach ([$this->runQueue(branches: '-1'), $this->runQueue(search: 'No matching queue')] as $empty) {
            $this->assertSame([], $empty[0]);
            $this->assertSame(0, (int) $empty[1][0]['TotalRows']);
            $this->assertSame([], $empty[2], 'Absent queue types must not acquire a fabricated value.');
        }
    }

    public function test_totals_widen_counts_and_money_before_aggregation(): void
    {
        $sql = file_get_contents(base_path('Modules/Reports/Database/Procedures/usp_Reports_GridMyQueue.sql'));
        preg_match('/SELECT QueueKey,.*?GROUP BY QueueKey;/s', $sql, $match);
        $this->assertNotEmpty($match, 'Exercise the aggregate that actually ships.');
        $batch = "SET NOCOUNT ON; CREATE TABLE #Rows (QueueKey varchar(30), OpenItems int, Value money);
            INSERT INTO #Rows VALUES ('open-cashups',2147483647,900000000000000.1234),
                                    ('open-cashups',2147483647,900000000000000.1234),
                                    ('day-close',1,NULL),('day-close',2,NULL); ".$match[0];
        $statement = $this->connection->getPdo()->query($batch);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        $totals = $this->totals([[], [], $rows]);
        $this->assertSame(['OpenItems' => 4294967294, 'Value' => '1800000000000000.2468', 'SiteQueues' => 2], $totals['open-cashups']);
        $this->assertSame(['OpenItems' => 3, 'Value' => null, 'SiteQueues' => 2], $totals['day-close']);
    }

    /** @return array<int, array<int, array<string, mixed>>> */
    private function runQueue(int $page = 1, string $branches = '13,18', ?string $search = null): array
    {
        $statement = $this->connection->getPdo()->prepare(
            "EXEC [{$this->schema}].[usp_Reports_GridMyQueue] @BranchIds=?, @DateFrom='2026-10-01', @DateTo='2026-10-31', @Search=?, @Page=?, @PageSize=1"
        );
        $statement->execute([$branches, $search, $page]);
        $sets = [];
        do {
            if ($statement->columnCount() > 0) {
                $sets[] = $statement->fetchAll(PDO::FETCH_ASSOC);
            }
        } while ($statement->nextRowset());
        $statement->closeCursor();

        return $sets;
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $sets
     * @return array<string, array{OpenItems: int, Value: mixed, SiteQueues: int}>
     */
    private function totals(array $sets): array
    {
        $totals = [];
        foreach ($sets[2] as $row) {
            $totals[$row['QueueKey']] = ['OpenItems' => (int) $row['OpenItems'], 'Value' => $row['Value'], 'SiteQueues' => (int) $row['SiteQueues']];
        }
        ksort($totals);

        return $totals;
    }

    private function seedRows(): void
    {
        $values = [
            'vw_Branch' => "(13,'TEST-Baobab'),(18,'TEST-Riverside'),(99,'TEST-Outside')",
            'vw_ReconImports' => "(13,'2026-10-20',0),(13,'2026-10-21',0),(18,'2026-10-20',0),(99,'2026-10-20',0),(13,'2026-10-22',1),(13,'2020-01-01',0)",
            'vw_CashZread' => "(13,'2026-10-20',0,0,10),(13,'2026-10-21',0,0,20),(18,'2026-10-20',0,0,5),(13,'2026-10-20',1,0,999)",
            'vw_CashupTotals' => "(13,'2026-10-20',1,100),(18,'2026-10-20',1,200),(13,'2026-10-20',0,999)",
            'vw_PurchaseRequest' => "(13,101,'2026-10-20',0,0,0),(13,102,'2026-10-20',0,0,0),(18,201,'2026-10-20',0,0,0)",
            'vw_PurchaseRequestLine' => '(13,101,12),(13,101,8),(13,102,20),(18,201,60)',
            'vw_StaffShort' => "(13,'2026-10-20',4,0,0),(18,'2026-10-20',6,0,0)",
            'vw_DropSafeBag' => "(13,'2026-10-20',7,0),(18,'2026-10-20',8,0)",
        ];
        foreach ($values as $table => $rows) {
            $this->connection->unprepared("INSERT INTO [{$this->schema}].[{$table}] VALUES {$rows}");
        }
    }
}
