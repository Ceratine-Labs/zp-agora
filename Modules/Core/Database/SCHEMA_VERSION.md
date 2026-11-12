# Module-qualified schema stamps

The ledger key is `(BranchId, Module, Version)`. Each module can use its own
version numbers. Repeating a stamp updates only that branch/module/version row.
MigrationHelper derives Module from the executing file under
`Modules/<Module>/Database/Migrations`; callers outside that directory must pass
an explicit third argument. Missing or malformed attribution fails before SQL.

The forward migration `v1__01i_core_schema_version_modules.php` retains every
surviving pre-upgrade row and all its original values, adding only the reserved
Module value `LegacyUnattributed`. It does not infer attribution from notes or
numeric versions. Earlier collisions already overwrote some notes: those notes
cannot be recovered from this ledger. A trusted historical backup would be
required for any separate recovery. Normal stamps cannot use the reserved name.

The helper also performs the upgrade lazily before every stamp. This protects
the unchanged Core baseline's first stamp on a fresh install, before the forward
migration runs. Column addition, unique-index replacement and the stamp share a
transaction-owned exclusive SQL application lock; failure rolls the transition
back and never falls back to the old Version-only key. Existing timestamps and
marker notes used by one-time migrations are retained unchanged. Only the
superseded `(BranchId, Version)` unique key is removed.

A failed stamp aborts the enclosing migration transaction as well. SQL Server
DDL errors can discard nested savepoints; resetting the whole transaction keeps
the original error visible and prevents unstamped migration changes surviving.
Previously committed ledger rows remain intact.

Decision: `01a1bbca-e2ba-70ab-98f1-6b4bf474a694`.
Task: `01a0e340-6e4d-72d3-94df-1a246cee6c77`.
day_list_id: `01a1ba62-f2e9-7149-aeed-f326e5d4ed98`.
