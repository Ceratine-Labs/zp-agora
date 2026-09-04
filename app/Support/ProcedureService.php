<?php

namespace App\Support;

use App\Exceptions\AgoraProcException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * The only way Agora calls a stored procedure.
 *
 * The business rules live in `agora.usp_*`, not in PHP (plan §3.4), so this
 * class is the seam between the two. It exists to make three things
 * impossible to get wrong:
 *
 *  - **Named parameters, always.** Positional `?` binding against a proc means
 *    a reordered signature silently sends the wrong value to the wrong
 *    argument, and nothing errors. Every call names its arguments.
 *  - **Every result set, not just the first.** A reader proc commonly returns
 *    a header row set and a lines row set. `PDOStatement::fetchAll()` returns
 *    only the first, so the second silently vanishes; `callSets()` walks
 *    `nextRowset()` to the end.
 *  - **A refusal is not a crash.** A proc that THROWs `AGORA:{Code}:{message}`
 *    surfaces as AgoraProcException with the code intact. Anything else — a
 *    deadlock, a missing procedure, a type mismatch — is a fault, and comes out
 *    as a QueryException like every other database error in the application.
 *    Calls here go through raw PDO to reach nextRowset(), so the PDOException
 *    that produces is wrapped rather than left to escape as its own type: a
 *    caller should not have to catch two exception classes depending on which
 *    layer issued the statement.
 *
 * `write()` wraps the call in a transaction the proc joins, so a proc that
 * throws half way leaves nothing behind — SET XACT_ABORT ON inside the proc
 * and the transaction here are belt and braces on the same failure. When the
 * CALLER already has one open, write() joins that instead of nesting: a
 * doomed transaction cannot be rolled back to a savepoint, and nesting one
 * there loses the refusal and strands the connection. See write() itself.
 */
class ProcedureService
{
    /** Matches the structured THROW contract: AGORA:{CODE}:{message}. */
    private const REFUSAL = '/AGORA:([A-Z0-9_]+):(.*)$/s';

    public function __construct(protected ?string $connection = null) {}

    /**
     * Read: run a proc and return its FIRST result set as a collection.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, object>
     */
    public function call(string $procedure, array $params = []): Collection
    {
        $sets = $this->callSets($procedure, $params);

        return $sets[0] ?? collect();
    }

    /**
     * Read: run a proc and return EVERY result set, in order.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, Collection<int, object>>
     */
    public function callSets(string $procedure, array $params = []): array
    {
        return $this->execute($procedure, $params);
    }

    /**
     * Write: run a proc inside a transaction and return its single status row.
     *
     * Writers return one row `(Ok BIT, Code NVARCHAR(40), Message NVARCHAR(400),
     * Id BIGINT)` by contract. A writer that returns `Ok = 0` has declined the
     * work in a way it expects the caller to handle, so it is raised as an
     * AgoraProcException exactly like a THROW would be — the caller should not
     * have to remember which of two refusal shapes a given proc uses.
     *
     * @param  array<string, mixed>  $params
     */
    public function write(string $procedure, array $params = []): object
    {
        $connection = DB::connection($this->connectionName());

        /*
         * A caller that already has a transaction open JOINS it rather than
         * getting a nested one, and that is not an optimisation.
         *
         * Every writer sets XACT_ABORT ON, so a THROW inside one dooms the
         * WHOLE transaction — SQL Server will not roll a doomed transaction
         * back to a savepoint. Laravel's nested transaction() creates exactly
         * that savepoint, so the rollback fails with "Cannot roll back trans2",
         * the AgoraProcException the proc raised is REPLACED by that
         * PDOException, and the connection is left at a transaction level
         * nothing can unwind — the caller's own rollBack() throws too. One
         * refused write then poisons the connection for the rest of the
         * request.
         *
         * Joining is also the semantics a caller composing two writes wanted:
         * XACT_ABORT already makes the pair all-or-nothing, and the refusal
         * now reaches them intact so they can act on its code.
         */
        if ($connection->transactionLevel() > 0) {
            return $this->statusRow($procedure, $params);
        }

        return $connection->transaction(fn () => $this->statusRow($procedure, $params));
    }

    /**
     * Run a writer and return its status row, raising a declined one as a
     * refusal. The transaction, if there is to be one, belongs to the caller.
     *
     * @param  array<string, mixed>  $params
     */
    private function statusRow(string $procedure, array $params): object
    {
        $rows = $this->execute($procedure, $params)[0] ?? collect();
        $row = $rows->first();

        if ($row === null) {
            throw new AgoraProcException(
                'NO_STATUS_ROW',
                "{$procedure} returned no status row. A writer must return (Ok, Code, Message, Id).",
                $procedure,
            );
        }

        if (isset($row->Ok) && ! (bool) $row->Ok) {
            throw new AgoraProcException(
                $row->Code ?? 'REFUSED',
                $row->Message ?? "{$procedure} declined the work without saying why.",
                $procedure,
            );
        }

        return $row;
    }

    /** Same service bound to a different connection (mist_import, alteryx). */
    public function on(string $connection): self
    {
        return new self($connection);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, Collection<int, object>>
     */
    protected function execute(string $procedure, array $params): array
    {
        $sql = $this->statement($procedure, $params);
        $started = microtime(true);

        try {
            $pdo = DB::connection($this->connectionName())->getPdo();
            $statement = $pdo->prepare($sql);

            foreach ($params as $name => $value) {
                $statement->bindValue(':'.ltrim($name, '@:'), $value, $this->pdoType($value));
            }

            $statement->execute();

            $sets = [];
            do {
                // columnCount() is 0 for a rowset-less step (a proc that does
                // work between SELECTs). Skipping those keeps the returned
                // array's indexes aligned with the SELECTs the proc author
                // actually wrote.
                if ($statement->columnCount() > 0) {
                    $sets[] = collect($statement->fetchAll(PDO::FETCH_OBJ));
                }
            } while ($statement->nextRowset());

            Log::debug('agora.proc', [
                'procedure' => $procedure,
                'sets' => count($sets),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $sets;
        } catch (QueryException|\PDOException $e) {
            throw $this->translate($e, $procedure, $sql, $params);
        }
    }

    /**
     * Turn a proc's deliberate THROW into an AgoraProcException, and anything
     * else into the QueryException the rest of the application already catches.
     */
    /** @param  array<string, mixed>  $bindings */
    protected function translate(\Throwable $e, string $procedure, string $sql = '', array $bindings = []): \Throwable
    {
        if (preg_match(self::REFUSAL, $e->getMessage(), $m) === 1) {
            return new AgoraProcException($m[1], trim($m[2]), $procedure, $e);
        }

        if ($e instanceof QueryException) {
            return $e;
        }

        return new QueryException($this->connectionName(), $sql, $bindings, $e);
    }

    /** `EXEC agora.usp_X @A = :A, @B = :B` — schema-qualified, named, no interpolation. */
    /** @param  array<string, mixed>  $params */
    protected function statement(string $procedure, array $params): string
    {
        $arguments = collect(array_keys($params))
            ->map(fn (string $name) => '@'.ltrim($name, '@:').' = :'.ltrim($name, '@:'))
            ->implode(', ');

        return trim('EXEC '.$this->qualify($procedure).' '.$arguments);
    }

    /**
     * A bare proc name is assumed to be Agora's own. Anything already carrying
     * a schema (a legacy `dbo.sp_*` read during a port) is left untouched.
     */
    protected function qualify(string $procedure): string
    {
        return str_contains($procedure, '.')
            ? $procedure
            : config('agora.schema').'.'.$procedure;
    }

    protected function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    protected function connectionName(): string
    {
        return $this->connection ?? config('agora.connections.app');
    }
}
