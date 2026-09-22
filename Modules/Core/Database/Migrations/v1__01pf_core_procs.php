<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Core's stored procedures (slot 01pf).
 *
 * Carries agora.usp_Core_GridUsers without roles. The procedure read
 * agora.UserRole and agora.Role to produce two columns — PrimaryRole and a
 * comma-joined RoleNames — and both stopped meaning anything on 22 Sep 2026
 * when roles were retired. Left as they were they would not have failed: the
 * tables still exist, so the grid would have gone on rendering a "Primary
 * role" column that quietly emptied itself as nothing maintained UserRole any
 * more, which is worse than a column that is simply gone.
 *
 * What replaces them is the question they were actually being read for — how
 * much can this person do — as PermissionCount over agora.UserPermission,
 * which is now the whole of a person's access rather than half of it.
 *
 * The RoleNames header filter goes with the column. A filter whose column is
 * not in the result set narrows nothing and says so to nobody.
 *
 * AND IT CARRIES A SORT FIX FOUND WHILE DOING IT. The ORDER BY opened with a
 * text CASE whose `ELSE f.UserName` caught every column it did not name — so
 * asking for BranchCount or LastSignInAt sorted by NAME first, and the numeric
 * expression underneath only broke ties. Both header arrows have done nothing
 * since the screen shipped. The numeric columns are now named in the text
 * branch and yield NULL there, which steps it aside; PermissionCount would
 * otherwise have shipped with the same dead arrow.
 */
return new class extends ProcedureMigration {};
