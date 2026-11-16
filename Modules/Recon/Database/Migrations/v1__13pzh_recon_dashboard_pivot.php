<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/**
 * The bank recon dashboard's Pivot (16 Nov 2026). Both read only:
 * agora.usp_Recon_DashboardOutstanding keeps its first result set's columns and
 * appends transit and credit figures, then returns the sites in scope, the
 * statement coverage per area and, with @Grain, the outstanding money by day or
 * month; agora.usp_Recon_DashboardFeeds (new) says which feeds each site has
 * ever had and how many automatic rules it has.
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            parent::up();
        });
    }

    /** @return array<int, string> */
    protected function files(): array
    {
        return array_map(fn (string $name): string => __DIR__.'/../Procedures/'.$name.'.sql', [
            'usp_Recon_DashboardOutstanding',
            'usp_Recon_DashboardFeeds',
        ]);
    }
};
