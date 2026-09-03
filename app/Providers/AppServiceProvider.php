<?php

namespace App\Providers;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request: the scope bar sets it, the global scope reads it.
        $this->app->singleton(BranchContext::class);

        $this->app->bind(ProcedureService::class, fn () => new ProcedureService);
    }

    public function boot(): void
    {
        // Accessing a relation that was not eager-loaded is a bug, not a
        // convenience — on a 613-table production database an N+1 in a grid is
        // measured in seconds, not milliseconds.
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        $this->guardAgainstLegacyWrites();
    }

    /**
     * Agora develops against the customer's production database. `dbo` is
     * theirs; `agora` is ours. This listener watches every statement that goes
     * out and shouts if a write names a dbo object.
     *
     * It is a smoke alarm, not a lock — it runs after the statement, and a
     * determined caller can still write. The real guarantee is that writes go
     * through procedures in the agora schema. What this catches is the
     * accident: a model missing its schema prefix, a raw statement copied out
     * of a legacy proc. Local and staging only, because the cost of running a
     * regex over every query in production is not worth paying for a check
     * that should already have fired in development.
     */
    protected function guardAgainstLegacyWrites(): void
    {
        if (app()->isProduction()) {
            return;
        }

        DB::listen(function ($query) {
            $sql = ltrim($query->sql);

            if (! preg_match('/^\s*(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|ALTER|DROP)\b/i', $sql)) {
                return;
            }

            if (preg_match('/\b(?:\[dbo\]|dbo)\s*\.\s*\[?(\w+)/i', $sql, $m)) {
                Log::warning('agora.dbo_write_attempt', [
                    'table' => $m[1],
                    'sql' => substr($sql, 0, 300),
                    'connection' => $query->connectionName,
                ]);
            }
        });
    }
}
