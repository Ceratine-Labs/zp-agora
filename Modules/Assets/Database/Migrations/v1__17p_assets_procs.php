<?php

use App\Support\Database\ProcedureMigration;

/**
 * Deploys the Assets module's stored procedures (slot 17p, T057).
 *
 * Every .sql file in Modules/Assets/Database/Procedures, each a CREATE OR ALTER
 * in the agora schema. How that is done lives in ProcedureMigration, so this
 * file stays a declaration of intent. A procedure added after this has run on
 * an instance goes out through a lettered follow-on (v1__17pa), because this
 * one will not run again there.
 *
 * None of these procedures writes the customer's databases. The writers —
 * usp_Assets_SaveAsset, usp_Assets_DeleteAsset, usp_Assets_Move,
 * usp_Assets_ReverseMovement, usp_Assets_SaveRepair, usp_Assets_CancelRepair —
 * write agora.Asset, agora.AssetMovement and agora.AssetRepair only (Ryan,
 * 27 Sep 2026, PM decision 01a0e309).
 */
return new class extends ProcedureMigration {};
