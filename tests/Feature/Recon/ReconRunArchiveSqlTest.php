<?php

namespace Tests\Feature\Recon;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** Shipping T-SQL against synthetic local-only tables, including two-session races. */
class ReconRunArchiveSqlTest extends TestCase
{
    private Connection $connection;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('This test creates an isolated SQL fixture and runs archive/commit against it.');
        $this->connection = DB::connection(config('agora.connections.app'));
        if ($this->connection->getDatabaseName() !== 'tempdb') {
            $this->markTestSkipped('Explicit local tempdb configuration is required; never use the application database.');
        }
        $this->schema = 'TEST_ReconArchive_'.bin2hex(random_bytes(5));
        $this->sql("CREATE SCHEMA [{$this->schema}]");
        $tables = [
            'ReconRun' => "Id bigint PRIMARY KEY,BranchId int,ReconArea nvarchar(20),FromDate date,ToDate date,Status nvarchar(20),ParamsJson nvarchar(max),Note nvarchar(400),StampMode nvarchar(20) DEFAULT 'journal',ProcedureName nvarchar(128) DEFAULT 'fixture',TotalRows int DEFAULT 1,MatchedRows int DEFAULT 1,MismatchRows int DEFAULT 0,BankOnlyRows int DEFAULT 0,DepositOnlyRows int DEFAULT 0,OtherRows int DEFAULT 0,MatchedTotal money DEFAULT 100,CommittedRows int DEFAULT 0,CommittedTotal money DEFAULT 0,PreviewMs int DEFAULT 1,CreatedAt datetime2 DEFAULT SYSUTCDATETIME(),CreatedBy int,UpdatedAt datetime2,UpdatedBy int,CommittedAt datetime2,CommittedBy int,FailureCode nvarchar(40)",
            'ReconRunLine' => 'Id bigint PRIMARY KEY,BranchId int,RunId bigint,WouldReconcile bit,Selected bit,CommitState nvarchar(20),BankLines int,BankTotal money,BankCC money,BankDD money,MopsTotal money,KeyRef nvarchar(50),KeyRef2 nvarchar(50),MopsKeyRef nvarchar(50),BankLineId bigint,WindowFrom datetime,WindowTo datetime,UsedProcessOrder int,UsedBankStart int,UsedBankLen int,RulesInGroup int,ReconBatchNo int,BlockReason nvarchar(400),UpdatedAt datetime2,UpdatedBy int',
            'ReconBatch' => 'Id bigint IDENTITY PRIMARY KEY,BranchId int,RunId bigint,BatchNo int,ReconArea nvarchar(20),KeyRef nvarchar(50),KeyRef2 nvarchar(50),BankLineCount int,MopsRowCount int,BankTotal money,MopsTotal money,State nvarchar(20),CreatedAt datetime2,CreatedBy int',
            'ReconMatch' => 'Id bigint IDENTITY PRIMARY KEY,BranchId int,RunId bigint,RunLineId bigint,BatchId bigint,BatchNo int,Side nvarchar(10),SourceTable nvarchar(128),SourceId bigint,SourceKeyJson nvarchar(max),SourceDate date,Amount money,PriorReconState int,PriorBatchNo int,CreatedAt datetime2,CreatedBy int',
            'ReconStamp' => 'Id bigint IDENTITY PRIMARY KEY,BranchId int,RunId bigint,MatchId bigint,BatchNo int,TargetDatabase nvarchar(128),TargetTable nvarchar(128),TargetKeyJson nvarchar(max),SetColumns nvarchar(200),State nvarchar(20),AppliedAt datetime2,AppliedBy int,CreatedAt datetime2,CreatedBy int',
            'ReconAllocation' => 'Id bigint IDENTITY PRIMARY KEY,BranchId int,RunId bigint',
            'Branch' => 'BranchId int PRIMARY KEY,Name nvarchar(100),DeletedAt datetime2',
            'User' => 'Id int PRIMARY KEY,UserName nvarchar(100)',
            'vw_AutoReconCriteria' => 'BranchId int,BankReconArea nvarchar(20),ProcessOrder int,AutoReconId int,FILTER_StartPosition int,FILTER_EndPosition int,FILTER_Value nvarchar(50),BANK_StartPosition int,BANK_EndPosition int,BANK_StartPosition2 int,BANK_EndPosition2 int,MOPS_StartPosition int,MOPS_EndPosition int',
            'vw_BankStatementLine' => 'BankStatementLineID bigint PRIMARY KEY,BranchId int,Type nvarchar(50),IDState int,LineDate datetime,Description nvarchar(400),Amount money,ReconState int,ReconBatchNo int',
            'TestGate' => 'Id int PRIMARY KEY,Entered bit,Delay bit',
            'SS_UniqueNumber' => 'SectionId int,NextUniqueNumber int',
            'RCN_BankStatementLinesPumpIT' => 'SSBranchId int,BankStatementLineID bigint,ReconBatchNo int,ReconState int',
            'BRN_DailyBankingABSA' => 'SSBranchId int,ReconBatchNoPumpIT int,TransactionDate datetime,BatchNumber nvarchar(50),MerchantNumber nvarchar(50)',
            'BRN_DailyBankingFNB' => 'SSBranchId int,ReconBatchNoPumpIT int,TransactionDate datetime,BatchNo nvarchar(50),MerchantNo nvarchar(50)',
            'BRN_DailyBankingDeposita' => 'SSBranchId int,ReconBatchNoPumpIT int,TransactionDate datetime,SlipNo nvarchar(50)',
            'BRN_DailyBankingCashBags' => 'SSBranchId int,ReconBatchNoPumpIT int,DailyBankingCashBagID bigint',
            'BRN_DailyBankingSmartATM' => 'SSBranchId int,ReconBatchNoPumpIT int,DailyBankingSmartATMID bigint',
        ];
        foreach ($tables as $name => $columns) {
            $this->sql("CREATE TABLE [{$this->schema}].[{$name}] ({$columns})");
        }
        $this->sql("INSERT INTO [{$this->schema}].Branch VALUES(13,'Alpha',NULL),(18,'Beta',NULL),(99,'Outside',NULL); INSERT INTO [{$this->schema}].[User] VALUES(7,'Reviewer'),(8,'Other'); INSERT INTO [{$this->schema}].TestGate VALUES(1,0,0)");
        foreach ([13, 18, 99] as $branch) {
            $this->sql("INSERT INTO [{$this->schema}].vw_AutoReconCriteria VALUES($branch,'ABSA',1,1,1,1,NULL,2,6,8,10,1,5)");
        }
        $this->install('Functions/fn_Recon_DrillBank.sql');
        foreach (['DrillBank', 'RunScope', 'RunValidity', 'ArchiveInvalidRuns', 'GridRuns'] as $name) {
            $this->install('Procedures/usp_Recon_'.$name.'.sql');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            while ($this->connection->transactionLevel() > 0) {
                $this->connection->rollBack();
            }
            foreach (['P' => 'PROCEDURE', 'IF' => 'FUNCTION', 'U' => 'TABLE'] as $type => $kind) {
                $objects = $this->connection->select('SELECT o.name FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE s.name=? AND o.type=?', [$this->schema, $type]);
                foreach ($objects as $object) {
                    $this->sql("DROP $kind [{$this->schema}].[{$object->name}]");
                }
            }
            $this->sql("DROP SCHEMA [{$this->schema}]");
        }
        parent::tearDown();
    }

    public function test_only_complete_original_proofs_are_invalid_and_selection_does_not_hide_work(): void
    {
        $this->runFixture(1, selected: false);
        $this->runFixture(2, state: 1);
        $this->runFixture(3);
        $this->proposal(31, 3, '00301', 1);
        $this->sql("UPDATE [{$this->schema}].ReconRun SET TotalRows=2 WHERE Id=3");
        $this->runFixture(4);
        $this->sql("DELETE FROM [{$this->schema}].vw_BankStatementLine WHERE BankStatementLineID=40");
        $this->runFixture(5);
        $this->sql("UPDATE [{$this->schema}].vw_BankStatementLine SET Amount=101 WHERE BankStatementLineID=50");
        $this->runFixture(6);
        $this->sql("INSERT INTO [{$this->schema}].vw_BankStatementLine SELECT 61,BranchId,Type,IDState,LineDate,Description,Amount,1,0 FROM [{$this->schema}].vw_BankStatementLine WHERE BankStatementLineID=60");
        $this->runFixture(7);
        $this->sql("UPDATE [{$this->schema}].ReconRunLine SET UsedBankStart=NULL WHERE RunId=7");
        $this->runFixture(8);
        $this->sql("UPDATE [{$this->schema}].ReconRunLine SET WouldReconcile=0 WHERE RunId=8");
        $this->runFixture(9);
        $this->sql("UPDATE [{$this->schema}].ReconRun SET TotalRows=2 WHERE Id=9");
        foreach (['ReconBatch', 'ReconMatch', 'ReconStamp', 'ReconAllocation'] as $index => $table) {
            $id = 10 + $index;
            $this->runFixture($id);
            $this->sql("INSERT INTO [{$this->schema}].[$table](BranchId,RunId) VALUES(13,$id)");
        }
        $this->runFixture(14, status: 'closed');
        $this->runFixture(15);
        $this->sql("DELETE FROM [{$this->schema}].ReconRunLine WHERE RunId=15; UPDATE [{$this->schema}].ReconRun SET TotalRows=0 WHERE Id=15");
        $rows = $this->validity(range(1, 15));
        $this->assertSame(
            array_filter($rows, static fn (array $row): bool => (bool) $row['IsInvalid']),
            $this->validity(range(1, 15), invalidOnly: true),
            'The fast mode must return exactly the proven invalid rows, with unchanged proof counts.'
        );
        $this->assertSame(1, (int) $rows[1]['IsInvalid'], 'Unticking cannot hide an original unusable proposal.');
        foreach (range(2, 15) as $id) {
            $this->assertSame(0, (int) $rows[$id]['IsInvalid'], 'Never archive uncertain, partial, empty or processed run '.$id);
        }
        $this->assertSame(1, (int) $rows[3]['InvalidRows']);
        $this->assertSame(1, (int) $rows[3]['RemainingRows']);
        $this->assertSame('review', $rows[4]['Validity']);
        $this->assertSame('review', $rows[5]['Validity']);
        $before = $this->connection->select("SELECT * FROM [{$this->schema}].ReconRunLine ORDER BY Id");
        $result = $this->archive(['RunIdsJson' => json_encode(range(1, 15))]);
        $this->assertSame(1, (int) $result['Archived']);
        $this->assertSame(14, (int) $result['Skipped']);
        $this->assertSame('closed', $this->runStatus(1));
        $this->assertEquals($before, $this->connection->select("SELECT * FROM [{$this->schema}].ReconRunLine ORDER BY Id"), 'Archiving preserves every original proposal.');
        $this->assertSame(7, (int) $this->connection->scalar("SELECT UpdatedBy FROM [{$this->schema}].ReconRun WHERE Id=1"));
    }

    public function test_all_filtered_scope_is_not_a_page_and_keeps_permissions_mine_and_filters(): void
    {
        foreach (range(100, 159) as $id) {
            $this->runFixture($id);
        }
        $this->runFixture(200, branch: 18);
        $this->runFixture(201, branch: 99);
        $this->runFixture(202, user: 8);
        $this->runFixture(203, note: 'different');
        $this->runFixture(204);
        $this->sql("UPDATE [{$this->schema}].ReconRun SET FromDate='2025-01-01',ToDate='2025-01-31' WHERE Id=204");
        $this->runFixture(205, state: 1);
        $filters = json_encode([['column' => 'Note', 'type' => 'text', 'op' => 'eq', 'value' => 'Archive fixture']]);
        $params = ['BranchIds' => '13,18,99', 'AllowedBranchIds' => '13', 'DateFrom' => '2026-10-01', 'DateTo' => '2026-10-31', 'Search' => 'fixture', 'FiltersJson' => $filters];
        $grid = $this->execute('GridRuns', [...$params, 'MineOnly' => 1, 'UserId' => 7, 'Page' => 1, 'PageSize' => 1]);
        $this->assertCount(1, $grid[0]);
        $this->assertSame(61, (int) $grid[1][0]['TotalRows']);
        $result = $this->archive($params);
        $this->assertSame(60, (int) $result['Archived']);
        $this->assertSame(0, (int) $result['Skipped'], 'All-invalid excludes initially valid rows from skipped.');
        foreach (range(200, 205) as $id) {
            $this->assertSame('previewed', $this->runStatus($id));
        }
        $this->assertSame(60, (int) $this->connection->scalar("SELECT COUNT(*) FROM [{$this->schema}].ReconRun WHERE Status='closed'"));
        $isolation = $this->connection->scalar('SELECT transaction_isolation_level FROM sys.dm_exec_sessions WHERE session_id=@@SPID');
        $this->assertSame(2, (int) $isolation, 'Serializable isolation must not leak out of the procedure.');
    }

    public function test_selected_ids_fail_closed_and_empty_selection_is_not_all(): void
    {
        $this->runFixture(1);
        $this->assertSame(0, (int) $this->archive(['RunIdsJson' => '[]'])['Archived']);
        foreach (['{}', '[0]', '[-1]', '[null]', '[true]', '[1.5]', '["1.5"]', '[{}]', 'not json'] as $ids) {
            try {
                $this->archive(['RunIdsJson' => $ids]);
                $this->fail('Malformed selected IDs were accepted: '.$ids);
            } catch (\PDOException $e) {
                $this->assertStringContainsString('AGORA:BAD_RUN_IDS', $e->getMessage());
            }
            $this->assertSame('previewed', $this->runStatus(1));
        }
        $this->runFixture(2, branch: 99);
        $result = $this->archive(['RunIdsJson' => '[1,2,999]', 'AllowedBranchIds' => '13']);
        $this->assertSame(1, (int) $result['Archived']);
        $this->assertSame(0, (int) $result['Skipped'], 'Unauthorized or absent IDs must not enter counts.');
        $this->assertSame('previewed', $this->runStatus(2));
    }

    public function test_invalid_current_extraction_configuration_requires_review(): void
    {
        $this->runFixture(1);
        foreach (['BANK_EndPosition=-1', 'BANK_EndPosition2=-1', "FILTER_EndPosition=-1,FILTER_Value='B'"] as $changes) {
            $this->sql("UPDATE [{$this->schema}].vw_AutoReconCriteria SET BANK_EndPosition=6,BANK_EndPosition2=10,FILTER_EndPosition=1,FILTER_Value=NULL WHERE BranchId=13");
            $this->sql("UPDATE [{$this->schema}].vw_AutoReconCriteria SET $changes WHERE BranchId=13");
            $row = $this->validity([1])[1];
            $this->assertSame('review', $row['Validity']);
            $this->assertSame(0, (int) $row['IsInvalid']);
            $this->assertSame([], $this->validity([1], invalidOnly: true));
        }
    }

    public function test_commit_rechecks_status_after_drill_before_any_business_write(): void
    {
        $this->runFixture(1);
        $this->install('Procedures/usp_Recon_Commit.sql');
        $this->sql("CREATE PROCEDURE [{$this->schema}].usp_Recon_DrillMops
            @ReconArea nvarchar(20),@BranchId int,@FromDate datetime,@ToDate datetime,@KeyRef nvarchar(50)=NULL,@KeyRef2 nvarchar(50)=NULL,@BankLineId bigint=NULL,@WindowFrom datetime=NULL,@WindowTo datetime=NULL,@RuleOrder varchar(12)='specific',@BatchKey varchar(10)='numeric',@MatchMode varchar(10)='contains',@MopsConvention varchar(10)='length',@BankStartOverride int=NULL,@BankLenOverride int=NULL,@MopsKeyRef nvarchar(50)=NULL,@IncludeReconciled bit=0,@WithCashier bit=0
            AS BEGIN SET NOCOUNT ON;
                UPDATE [{$this->schema}].TestGate SET Entered=1 WHERE Id=1;
                WAITFOR DELAY '00:00:02';
                SELECT CAST('00001' AS nvarchar(50)) SourceRef,CAST('2026-10-10' AS datetime) SourceDate,CAST(100 AS money) Amount,CAST('fixture' AS nvarchar(200)) Detail,CAST(NULL AS bigint) SourceId,CAST('fixture' AS nvarchar(200)) SourceKey,CAST(0 AS int) ReconBatchNoPumpIT;
            END");
        [$process, $pipes] = $this->startSql("EXEC [{$this->schema}].usp_Recon_Commit @RunId=1,@BranchId=13,@StampMode='journal',@UserId=7");
        try {
            $entered = false;
            for ($i = 0; $i < 300; $i++) {
                $entered = (bool) $this->connection->scalar("SELECT Entered FROM [{$this->schema}].TestGate WITH (NOLOCK) WHERE Id=1");
                if ($entered) {
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($entered, 'Concurrent commit reached its drill after passing the first status check.');
            $this->assertSame(1, (int) $this->archive(['RunIdsJson' => '[1]'])['Archived']);
        } finally {
            $out = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        }
        $this->assertSame(1, $exit, $out.$error);
        $this->assertStringContainsString('AGORA:RUN_NOT_PREVIEWED', $out);
        $this->assertSame('closed', $this->runStatus(1));
        $this->assertSame('pending', $this->connection->scalar("SELECT CommitState FROM [{$this->schema}].ReconRunLine WHERE RunId=1"));
        foreach (['ReconBatch', 'ReconMatch', 'ReconStamp', 'ReconAllocation'] as $table) {
            $this->assertSame(0, (int) $this->connection->scalar("SELECT COUNT(*) FROM [{$this->schema}].[$table]"));
        }

        $this->runFixture(2, state: 1);
        $committed = $this->execute('Commit', ['RunId' => 2, 'BranchId' => 13, 'StampMode' => 'journal', 'UserId' => 7]);
        $this->assertSame('JOURNALLED', $committed[0][0]['Code']);
        $this->assertSame(1, (int) $committed[0][0]['Id']);
        $this->assertSame('committed', $this->runStatus(2));
        $result = $this->archive(['RunIdsJson' => '[2]']);
        $this->assertSame(0, (int) $result['Archived']);
        $this->assertSame(1, (int) $result['Skipped']);
        $this->assertSame('committed', $this->runStatus(2));
    }

    public function test_source_state_locks_last_until_archive_finishes(): void
    {
        $this->runFixture(1);
        $this->sql("CREATE TRIGGER [{$this->schema}].TestArchivePause ON [{$this->schema}].ReconRun AFTER UPDATE AS
            BEGIN SET NOCOUNT ON;
                IF EXISTS(SELECT 1 FROM inserted WHERE Status='closed')
                BEGIN UPDATE [{$this->schema}].TestGate SET Entered=1 WHERE Id=1; WAITFOR DELAY '00:00:02'; END;
            END");
        [$archive, $archivePipes] = $this->startSql("EXEC [{$this->schema}].usp_Recon_ArchiveInvalidRuns @RunIdsJson=N'[1]',@UserId=7");
        $restore = null;
        $restorePipes = [];
        $restoreExit = -1;
        $restoreOut = 'Source restoration was not started.';
        try {
            $entered = false;
            for ($i = 0; $i < 300; $i++) {
                $entered = (bool) $this->connection->scalar("SELECT Entered FROM [{$this->schema}].TestGate WITH(NOLOCK) WHERE Id=1");
                if ($entered) {
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($entered, 'Archive reached its close while the transaction is held open.');
            [$restore, $restorePipes] = $this->startSql("UPDATE [{$this->schema}].vw_BankStatementLine SET ReconState=1,ReconBatchNo=0 WHERE BankStatementLineID=10");
            usleep(200000);
            $this->assertTrue(proc_get_status($restore)['running'], 'Source restoration must wait until the archive transaction ends.');
            $this->assertSame(2, (int) $this->connection->scalar("SELECT ReconState FROM [{$this->schema}].vw_BankStatementLine WITH(NOLOCK) WHERE BankStatementLineID=10"));
        } finally {
            $out = stream_get_contents($archivePipes[1]).stream_get_contents($archivePipes[2]);
            fclose($archivePipes[1]);
            fclose($archivePipes[2]);
            $archiveExit = proc_close($archive);
            if (is_resource($restore) && isset($restorePipes[1], $restorePipes[2])) {
                $restoreOut = stream_get_contents($restorePipes[1]).stream_get_contents($restorePipes[2]);
                fclose($restorePipes[1]);
                fclose($restorePipes[2]);
                $restoreExit = proc_close($restore);
            }
        }
        $this->assertSame(0, $archiveExit, $out);
        $this->assertSame(0, $restoreExit, $restoreOut);
        $this->assertSame('closed', $this->runStatus(1));
        $this->assertSame(1, (int) $this->connection->scalar("SELECT ReconState FROM [{$this->schema}].vw_BankStatementLine WHERE BankStatementLineID=10"));
    }

    public function test_sql_server_restores_function_and_procedure_ddl_when_transaction_fails(): void
    {
        $name = $this->schema.'.fn_Recon_DrillBank';
        $before = $this->connection->scalar('SELECT OBJECT_DEFINITION(OBJECT_ID(?))', [$name]);
        try {
            $this->connection->transaction(function () use ($before): void {
                $alter = preg_replace('/CREATE\s+(?:OR\s+ALTER\s+)?FUNCTION\b/i', 'ALTER FUNCTION', $before, 1);
                $this->sql(str_replace('RETURN (', "RETURN (\n/* rollback probe */", $alter));
                $this->sql("CREATE PROCEDURE [{$this->schema}].TestRollbackProc AS SELECT 1 AS Value");
                $this->sql("CREATE PROCEDURE [{$this->schema}].TestBroken AS THIS IS NOT SQL");
            });
            $this->fail('Invalid DDL did not fail.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Incorrect syntax', $e->getMessage());
        }
        $this->assertSame($before, $this->connection->scalar('SELECT OBJECT_DEFINITION(OBJECT_ID(?))', [$name]));
        $this->assertNull($this->connection->scalar('SELECT OBJECT_ID(?)', [$this->schema.'.TestRollbackProc']));
        $this->assertSame(0, $this->connection->transactionLevel());
    }

    private function runFixture(int $id, int $state = 2, int $branch = 13, int $user = 7, bool $selected = true, string $status = 'previewed', string $note = 'Archive fixture'): void
    {
        $this->connection->insert("INSERT INTO [{$this->schema}].ReconRun(Id,BranchId,ReconArea,FromDate,ToDate,Status,ParamsJson,Note,CreatedBy) VALUES(?,?,'ABSA','2026-10-01','2026-10-31',?,'{\"RuleOrder\":\"specific\"}',?,?)", [$id, $branch, $status, $note, $user]);
        $this->proposal($id * 10, $id, str_pad((string) $id, 5, '0', STR_PAD_LEFT), $state, $branch, $selected);
    }

    private function proposal(int $id, int $run, string $key, int $state, int $branch = 13, bool $selected = true): void
    {
        $this->connection->insert("INSERT INTO [{$this->schema}].ReconRunLine(Id,BranchId,RunId,WouldReconcile,Selected,CommitState,BankLines,BankTotal,BankCC,BankDD,MopsTotal,KeyRef,KeyRef2,UsedProcessOrder,UsedBankStart,UsedBankLen,RulesInGroup) VALUES(?,?,?,1,?,'pending',1,100,100,0,100,?,'1',1,2,5,1)", [$id, $branch, $run, (int) $selected, $key]);
        $this->connection->insert("INSERT INTO [{$this->schema}].vw_BankStatementLine VALUES(?,?,'ABSA',2,'2026-10-10',?,100,?,?)", [$id, $branch, 'B'.$key.'M001 CC', $state, $state === 1 ? 0 : 1000]);
    }

    /**
     * @param  array<int>  $ids
     * @return array<int,array<string,mixed>>
     */
    private function validity(array $ids, bool $invalidOnly = false): array
    {
        $out = [];
        foreach ($this->execute('RunValidity', ['RunIdsJson' => json_encode($ids), 'InvalidOnly' => (int) $invalidOnly])[0] as $row) {
            $out[(int) $row['RunId']] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function archive(array $params = []): array
    {
        return $this->execute('ArchiveInvalidRuns', [...['UserId' => 7, 'MineOnly' => 1], ...$params])[0][0];
    }

    private function runStatus(int $id): string
    {
        return (string) $this->connection->scalar("SELECT Status FROM [{$this->schema}].ReconRun WHERE Id=?", [$id]);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function execute(string $name, array $params): array
    {
        $sql = "EXEC [{$this->schema}].usp_Recon_$name ".implode(',', array_map(static fn ($key) => '@'.$key.'=?', array_keys($params)));
        $statement = $this->connection->getPdo()->prepare($sql);
        $statement->execute(array_values($params));
        $sets = [];
        do {
            if ($statement->columnCount() > 0) {
                $sets[] = $statement->fetchAll(PDO::FETCH_ASSOC);
            }
        } while ($statement->nextRowset());
        $statement->closeCursor();

        return $sets;
    }

    private function install(string $path): void
    {
        $sql = file_get_contents(base_path('Modules/Recon/Database/'.$path));
        $this->sql(str_replace(['[agora].', 'agora.', '[PumpIT].dbo.'], ["[{$this->schema}].", "{$this->schema}.", "[{$this->schema}]."], $sql));
    }

    private function sql(string $sql): void
    {
        $this->connection->unprepared($sql);
    }

    /** @return array{resource,array<int,resource>} */
    private function startSql(string $sql): array
    {
        $settings = $this->connection->getConfig();
        $dsn = 'sqlsrv:Server='.$settings['host'].','.$settings['port'].';Database=tempdb;Encrypt=yes;TrustServerCertificate=yes';
        $env = array_merge(getenv(), ['RECON_TEST_DSN' => $dsn, 'RECON_TEST_USER' => $settings['username'], 'RECON_TEST_PASSWORD' => $settings['password'], 'RECON_TEST_SQL' => $sql]);
        $code = 'try{$p=new PDO(getenv("RECON_TEST_DSN"),getenv("RECON_TEST_USER"),getenv("RECON_TEST_PASSWORD"));$s=$p->query(getenv("RECON_TEST_SQL"));do{if($s->columnCount())$s->fetchAll();}while($s->nextRowset());echo "ok";}catch(Throwable $e){echo $e->getMessage();exit(1);}';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        fclose($pipes[0]);

        return [$process, $pipes];
    }
}
