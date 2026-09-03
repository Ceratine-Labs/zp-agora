<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * Every Agora model extends this.
 *
 * The estate is PascalCase and always has been — `SS_Branch.BranchName`,
 * `BRN_DailyBanking.TransactionDate`. Rather than fight it with column
 * aliases, Agora adopts it: timestamps are `CreatedAt` / `UpdatedAt`, the key
 * is `Id`, and Laravel is told so once, here.
 *
 * Eloquent is for reads and simple master CRUD. Every transactional write and
 * every business rule is a stored procedure called through ProcedureService
 * (plan §3.4) — if a rule exists in both places, the proc is right and the
 * PHP is the bug.
 */
abstract class BaseModel extends Model
{
    use BelongsToBranch;

    public const CREATED_AT = 'CreatedAt';

    public const UPDATED_AT = 'UpdatedAt';

    protected $primaryKey = 'Id';

    protected $keyType = 'int';

    public $incrementing = true;

    /**
     * RowVer is a ROWVERSION: the database owns it and rejects a write that
     * tries to set it. Guarding it here turns a confusing SQL Server error
     * into nothing at all.
     */
    protected $guarded = ['Id', 'RowVer'];

    /** Schema-qualified table name, so no query can resolve against dbo. */
    public function getTable(): string
    {
        if (isset($this->table)) {
            return str_contains($this->table, '.')
                ? $this->table
                : config('agora.schema').'.'.$this->table;
        }

        return config('agora.schema').'.'.class_basename($this);
    }
}
