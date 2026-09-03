# ZP-NQL as Agora's reference project

**Reference repo:** `~/Development/ZP/Zulu Petroleum` (project "Zulu Petroleum",
app name *DB Intelligence*, Laravel + Postgres app DB `dbintel`).

Agora and ZP-NQL point at the **same customer SQL Server instance**. ZP-NQL got
there first, so its connection layer, its measured join maps and its schema
instruction blocks are the cheapest source of truth Agora has. This file is a
**pointer list, not a copy** — read the originals; copies drift and a stale join
map is worse than none because it looks authoritative.

---

## 1. The customer instance (verified live 2026-09-03)

| | |
|---|---|
| Host | `105.247.172.179` · TCP `1433` · reachable from Ryan's machine |
| Engine | Microsoft SQL Server **2017** (RTM-GDR, KB5021127, 14.0.2047) |
| Login | `Revteck` — **`sysadmin` + `dbcreator` at server level, `db_owner` in PumpIT** |
| Server collation | `Latin1_General_CI_AS` on all three databases |
| Driver locally | `pdo_sqlsrv` + `sqlsrv` present in PHP; `isql` available |

| Database | Compat | Recovery | Schemas | Base tables | Declared FKs | Procs | Size | Role for Agora |
|---|---|---|---|---|---|---|---|---|
| `PumpIT` | 130 | **SIMPLE** | `dbo` 592, `cashup` 17, `bankrecon` 4 | 613 | 69 | 491 | **249 GB** | Primary — the ERP database, evolved in place |
| `MIST_Import` | 120 | **SIMPLE** | `dbo` 148 | 148 | 0 | — | — | Secondary — POS landing zone, read-only reporting |
| `Alteryx` | 140 | FULL | `dbo` | 100 | — | — | — | ZP-NQL's reporting extracts; not an Agora connector |

There is **no `agora` schema yet** — `PumpIT` currently holds `dbo`, `cashup`,
`bankrecon` only.

Both PumpIT and MIST_Import are **partitioned** (multiple `sys.partitions` rows
per table on `DBF_INVHISTF_CUSTOMER`, `ARCH_vw_dwh_stock_movement`, …). The
partition scripts live in `~/Development/ZP/`: `PumpIT_Partitioning.sql`,
`PumpIT_MaintenanceJob.sql`, `Mist_Import Partition Creation.sql`,
`MIST_Import_Partitioning_Remaining.sql`.

Credentials are **not** in ZP's `.env`. They live encrypted (`Crypt`, APP_KEY) in
the `database_connections` table of the ZP app DB. Recover with ZP tinker:
`DatabaseConnection::where('name','PumpIT')->first()->getDecryptedPassword()`.

---

## 2. What to read in the ZP repo, and why

### Connection + guard layer (the pattern Agora reads with)
| File | Why |
|---|---|
| `app/Models/DatabaseConnection.php` | `toConnectionConfig()` — the exact sqlsrv config that works against this instance (`encrypt=yes`, `trust_server_certificate=true`; the internal certs are self-signed). Password mutator + `encrypted:array` schema cache. |
| `app/Services/DatabaseManager.php` | `executeReadOnly()` — SELECT-only guard, auto row cap (1000), `dynamic_{id}` runtime connection registry, per-driver introspection. Every refusal in `refuseNonRead()` is a scar: MERGE-after-CTE, `SELECT … INTO`, comment-led SQL, keyword-in-a-literal. Read the docblocks before writing Agora's equivalent. |
| `app/Services/Sql/ReservedWordBracketer.php` | Legacy `Rev_DBF_*` / FoxPro columns named `DESC`, `KEY`, `USER`. `alias.DESC` is always a syntax error on sqlsrv. |
| `docs/setup/sql-server.md` | Local mssql container (`zp-mssql`, host port 14330), sqlsrv `.env` profile, and the no-DB-FK portability decisions. |

### Measured schema knowledge (the expensive part)
| File | Why |
|---|---|
| `docs/relationships/pumpit-joins.json` | **The one that matters most.** Measured 2026-08-11 against live: 590 base tables, 2 schemas, 68 FK constraints, 338 tables with a PK. Per-table join paths with `join_keys`, `right_query` (THREE-part names on purpose) and **rejected paths**. Agora's build plan §3.4 already calls its rejected-paths list *law*. |
| `docs/relationships/joins.json` (172 KB) · `alteryx-joins.json` | The other two curated maps. `config/join_map.php` lists exactly these three explicitly — `mist-import-joins.json` and `seed-relationships.json` are **superseded drafts**, close enough in shape to load and wrong enough to mislead. Don't glob. |
| `docs/relationships/HANDOVER.md` · `SESSION-HANDOVER-2026-08-04.md` · `UNRESOLVED.md` | How the maps were built, and what is still unknown. "Unmapped means UNKNOWN, never forbidden." |
| `app/Services/JoinMap.php` | The reader for the above. |

### AI instruction sets
These are **not files** — they are rows in ZP's app DB (`app_settings`), surfaced
at `/admin/settings` and assembled by `app/Services/Ai/AiInstructions.php`.

| Key | Size | Content |
|---|---|---|
| `ai_schema_relationships` | 7.2 KB | The cross-cutting block auto-prepended to every AI surface: core Alteryx tables (`Rev_MIST_Branch`, `Rev_Mist_Trans_Sales`, `Rev_ReportingCategoryLinks`, `Rev_BRN_Pump`, `Rev_BRN_FuelType`, `Rev_P3TRANS_EOD`), canonical join patterns, fan-out warnings, the `${branches}` / `${window.*}` variable convention, and the **COALESCE exclusion trap**. |
| `ai_dashboard_builder_instructions` | 594 B | |
| `ai_dashboard_insights_instructions` | 646 B | |
| `ai_widget_instructions` | 646 B | |

Read them with:
```bash
cd ~/Development/ZP/Zulu\ Petroleum
psql -h 127.0.0.1 -U cyrix_test -d dbintel -At \
  -c "select value from app_settings where key='ai_schema_relationships';"
```
Caveat for Agora: that block is written **Alteryx-first** (`Rev_*` tables in the
Alteryx DB). Agora reads PumpIT `dbo` directly, so the join patterns transfer as
*technique* (dedupe lookups in a CTE, TOP not LIMIT, string-keyed category joins
are not FKs) but the table names mostly do not.

### Operational context worth reading once
| File | Why |
|---|---|
| `CLAUDE.md` §"TWO RULES ABOVE ALL", §"The 14 non-negotiables", §"Three databases" | The read-only invariant, the workflow-write carve-out, and 14 rules that each cost a real bug. |
| `docs/pumpit-auto-recon-findings.md` / `.html` · `docs/exco-cash-banking-scope.md` | The 2026 auto-recon pilot in the `cashup` schema — mid-rebuild, known-wrong, and the reason Agora's plan says do not read `cashup`. |
| `docs/sql/sp_AUTOReconcile_*.sql`, `sp_RPT_AUTORecon*.sql` | The customer's own recon procs, corrected. Documentation of the intended rule. |
| `~/Development/ZP/PumpIT.html`, `PUMPIT_DATA.txt` (4.9 MB), `MIST_Audit.html`, `MIST_Import.html`, `PilotPOS.html` | The March 2026 audit reports and raw dumps, generated by `build_pumpit_report.py` / `build_mist_audit_report.py`. Column-level detail per table. |

---

## 3. Agora's `.env` connectors — decided 2026-09-03

Three customer connections ship on day one. `fuelsheet`, named in T001 of the
build plan, is dropped until someone can say what it points at. All three use
the existing **`Revteck`** login — Ryan's call, made knowing it is sysadmin on
the instance; tightening to a scoped login is a cutover-time task, not a v1
blocker.

```env
# Primary — the ERP database. Agora migrations create schema `agora` in here.
PUMPIT_DB_HOST=105.247.172.179
PUMPIT_DB_PORT=1433
PUMPIT_DB_DATABASE=PumpIT
PUMPIT_DB_USERNAME=Revteck
PUMPIT_DB_PASSWORD=            # recover once from ZP: DatabaseConnection 'PumpIT'
PUMPIT_DB_ENCRYPT=yes
PUMPIT_DB_TRUST_SERVER_CERTIFICATE=true

# Secondary — POS landing zone. Read-only for Agora in v1 (load monitoring).
MIST_DB_HOST=105.247.172.179
MIST_DB_PORT=1433
MIST_DB_DATABASE=MIST_Import
MIST_DB_USERNAME=Revteck
MIST_DB_PASSWORD=
MIST_DB_ENCRYPT=yes
MIST_DB_TRUST_SERVER_CERTIFICATE=true

# Reporting extracts — ZP-NQL's primary source, read-only for Agora.
ALTERYX_DB_HOST=105.247.172.179
ALTERYX_DB_PORT=1433
ALTERYX_DB_DATABASE=Alteryx
ALTERYX_DB_USERNAME=Revteck
ALTERYX_DB_PASSWORD=
ALTERYX_DB_ENCRYPT=yes
ALTERYX_DB_TRUST_SERVER_CERTIFICATE=true
```

`encrypt=yes` with `trust_server_certificate=true` is not optional and not an
oversight: ODBC Driver 18 validates the server certificate by default and this
instance's certs are self-signed. The traffic is encrypted; the identity is not
verified.

---

## 4. Standing consequences of the 3 Sep decisions

**Dev runs against production PumpIT.** There is no local restore and there is
no staging copy. Agora's migrations create objects in the `agora` schema of the
live 249 GB database. Three things follow and none of them are optional:

- **`migrate:fresh` is forbidden outright.** Forward-only, always.
- **Every migration is `agora`-scoped.** Nothing in `dbo` is altered. Legacy
  tables are reached through `agora.vw_*` views; writes go through procs.
- **PumpIT is in SIMPLE recovery**, so there is no transaction-log chain and no
  point-in-time restore. A bad migration cannot be rewound to the minute — only
  to whenever the last full backup was taken. Raise the recovery model with the
  customer before cutover.

**No read guard in v1.** Agora does not port `DatabaseManager::executeReadOnly()`.
ZP-NQL needs it because an LLM writes its SQL; Agora v1 has no natural-language
query surface, and the guard would refuse `EXEC` — which is Agora's normal mode
of operation. Revisit when the AI tool layer lands: that is the moment untrusted
SQL first exists, and the moment the guard earns its place.

The decisions themselves are recorded in the project manager under Agora →
Decisions, with the rationale as Ryan gave it.
