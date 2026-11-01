<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

return new class extends ProcedureMigration
{
    public function up(): void
    {
        // Only known routine outcomes are review-only. Unknown outcomes and
        // every saved count or audit code remain in the actionable list.
        DB::unprepared("CREATE OR ALTER VIEW agora.vw_StockReconExceptionGroups AS
            SELECT rl.BranchId, rl.Id, rl.RunId,
                CASE WHEN rl.ExceptionCode IS NULL AND rl.AuditCode IS NULL
                          AND (rl.Outcome IN ('Balanced to zero', 'No change needed',
                               'Dormant: opening and closing held equal, no stock moved')
                               OR rl.Outcome = 'Opening only: follows the amended closing before it')
                     THEN 'review' ELSE 'exceptions' END AS RowGroup
            FROM agora.StockReconRunLine rl
            WHERE rl.ExceptionCode IS NOT NULL OR rl.AuditCode IS NOT NULL OR rl.CountDriftReason IS NOT NULL");
        parent::up();
    }

    protected function files(): array
    {
        return array_map(fn (string $name): string => $this->path().'/'.$name.'.sql', [
            'usp_StockRecon_GridExceptions', 'usp_StockRecon_ExceptionTypes',
        ]);
    }
};
