<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Core's stored procedures (slot 01pe).
 *
 * Carries the fix for agora.usp_Core_GridUsers, which had read @FiltersJson as
 * `OPENJSON(@FiltersJson) f` keyed on `f.[key]` looking for `$.q`. The payload
 * is a JSON ARRAY, so `[key]` is the index and `$.q` exists nowhere: every
 * filter variable stayed NULL, every predicate short-circuited to true, and
 * the header filters on Setup → Users and access narrowed nothing from the day
 * the screen shipped.
 *
 * The same migration corrects two things found beside it: UserType is a SET
 * filter and has no `$.value` to read even once the parse is right, and the
 * second result set repeated the predicates by hand and had drifted — RoleNames
 * and Status were never counted, so filtering on either gave a footer that
 * disagreed with its own rows.
 */
return new class extends ProcedureMigration {};
