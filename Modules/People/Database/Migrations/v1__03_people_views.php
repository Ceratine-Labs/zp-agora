<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * People — employees, posts and employee areas, as Agora reads them (T023,
 * slot 03).
 *
 * ADOPTED, NOT MIGRATED (plan §4). The employees live in PumpIT's
 * BRN_Employee and PumpIT keeps trading on them — every till sign-on, cashup
 * and stock count names an EmployeeCode from that table. So this module owns
 * no tables yet: it reads the three legacy tables through views, and the
 * screens are procedures over the views. Whether an edit made in Agora is
 * Agora's own row or a write back into BRN_Employee is Ryan's call and is not
 * built (CLAUDE.md rule 1: a write to PumpIT is a named write or nothing).
 *
 *   agora.vw_PeopleEmployee      BRN_Employee, one row per (BranchId,
 *                                EmployeeCode) — its primary key on the
 *                                customer's instance. The name parts kept as
 *                                they are, and EmployeeName COMPOSED here,
 *                                "Surname FirstName", because PumpIT has no
 *                                such column and that is how its own
 *                                sp_GetBranchEmployees and agora.vw_Employee
 *                                write it. The post's description joined on
 *                                (BranchId, PostNo), which is BRN_EmployeePost's
 *                                primary key, so the join cannot fan out.
 *   agora.vw_PeoplePost          BRN_EmployeePost — posts are PER BRANCH
 *                                (311 rows, 55 numbers, 24 branches on
 *                                12 Nov 2026); the same description carries a
 *                                different number at different sites.
 *   agora.vw_PeopleEmployeeArea  BRN_EmployeeArea — which counting areas a
 *                                person may be signed on to, with the area's
 *                                description from STK_Area.
 *
 * NOTHING IS FILTERED. An inactive (terminated) employee stays in
 * vw_PeopleEmployee with Status = 'Inactive', because the cashups, stock
 * counts and staff shorts they worked still name them; a view that dropped
 * them would turn a historic shift's cashier into a bare code. Which rows a
 * screen shows is the procedure's choice, in the open.
 *
 * WHAT IS LEFT OUT, deliberately: IDNo, Password (plaintext varchar(50)), the
 * bank, tax, medical-aid and pension numbers, addresses, birth date and photo.
 * None of them is needed to run a site, and a column a view does not name
 * cannot leak through a grid, an extract or a log.
 *
 * Two legacy dates are cleaned and nothing else is: DateJoined and DateDepart
 * carry 1899-12-30 (FoxPro's zero date) where nothing was entered, which is
 * returned as NULL. 2016-03-22 is left as stored — it is the date most rows
 * were imported (1,017 joined, 1,055 departed on it), but it cannot be told
 * apart from a real date, so it is not ours to blank.
 *
 * agora.vw_Employee (Reports) and agora.vw_EmployeePost (Cash) predate this
 * module and keep their narrower shape for the procedures already reading
 * them; nothing here changes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');

        foreach ($this->views($erp) as $name => $body) {
            DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[{$name}] AS".$body.';');
        }

        MigrationHelper::recordVersion(
            '1.0',
            'People (T023): vw_PeopleEmployee, vw_PeoplePost and vw_PeopleEmployeeArea over BRN_Employee, '
            .'BRN_EmployeePost and BRN_EmployeeArea — read-only, inactive staff kept, no personal numbers.'
        );
    }

    /** @return array<string, string> */
    protected function views(string $erp): array
    {
        return [
            'vw_PeopleEmployee' => "
                SELECT e.SSBranchId                                         AS BranchId,
                       CONVERT(nvarchar(11), LTRIM(RTRIM(e.EmployeeCode)))  AS EmployeeCode,
                       NULLIF(LTRIM(RTRIM(e.Title)), N'')                   AS Title,
                       NULLIF(LTRIM(RTRIM(e.Initials)), N'')                AS Initials,
                       NULLIF(LTRIM(RTRIM(e.FirstName)), N'')               AS FirstName,
                       LTRIM(RTRIM(e.Surname))                              AS Surname,
                       NULLIF(LTRIM(RTRIM(e.NickName)), N'')                AS NickName,
                       /* Falls back to the code where both parts are blank
                          (BRN_Employee TEST at branch 1), so a name is never
                          an empty link. */
                       CONVERT(nvarchar(60), ISNULL(NULLIF(LTRIM(RTRIM(
                           ISNULL(LTRIM(RTRIM(e.Surname)), N'') + N' ' + ISNULL(LTRIM(RTRIM(e.FirstName)), N''))), N''),
                           LTRIM(RTRIM(e.EmployeeCode))))                   AS EmployeeName,
                       e.PostNo,
                       NULLIF(LTRIM(RTRIM(p.PostDescription)), N'')         AS PostDescription,
                       CONVERT(bit, ISNULL(p.StockControl, 0))              AS PostStockControl,
                       CONVERT(bit, e.IsActive)                             AS IsActive,
                       CONVERT(varchar(10), CASE WHEN e.IsActive = 1 THEN 'Active' ELSE 'Inactive' END) AS Status,
                       CONVERT(bit, e.IsPumpAttendant)                      AS IsPumpAttendant,
                       /* PumpIT's own sign-on lists leave these out
                          (sp_CaptureAreaEmployee): the PUMPIT and ADMIN rows
                          are system accounts, not people. */
                       CONVERT(bit, CASE WHEN UPPER(LTRIM(RTRIM(e.EmployeeCode))) = N'PUMPIT'
                                          OR UPPER(LTRIM(RTRIM(e.Surname))) = N'ADMIN'
                                          OR UPPER(LTRIM(RTRIM(e.FirstName))) = N'PUMPIT' THEN 1 ELSE 0 END)
                                                                            AS IsSystemAccount,
                       CASE WHEN e.DateJoined >= '19000102' THEN CONVERT(date, e.DateJoined) END AS DateJoined,
                       CASE WHEN e.DateDepart >= '19000102' THEN CONVERT(date, e.DateDepart) END AS DateDepart,
                       NULLIF(LTRIM(RTRIM(e.CashDeviceCode)), N'')          AS CashDeviceCode,
                       NULLIF(LTRIM(RTRIM(e.DepositorName)), N'')           AS DepositorName,
                       CONVERT(bit, ISNULL(e.CanSignIn, 0))                 AS CanSignIn,
                       CONVERT(bit, ISNULL(e.CanAccessManager, 0))          AS CanAccessManager,
                       CONVERT(bit, ISNULL(e.CanAccessSetup, 0))            AS CanAccessSetup,
                       e.CreateDateTime
                FROM [{$erp}].dbo.BRN_Employee e
                LEFT JOIN [{$erp}].dbo.BRN_EmployeePost p
                       ON p.SSBranchId = e.SSBranchId AND p.PostNo = e.PostNo",

            'vw_PeoplePost' => "
                SELECT p.SSBranchId                                         AS BranchId,
                       p.PostNo,
                       NULLIF(LTRIM(RTRIM(p.PostDescription)), N'')         AS PostDescription,
                       CONVERT(bit, p.StockControl)                         AS StockControl,
                       p.CreateDateTime
                FROM [{$erp}].dbo.BRN_EmployeePost p",

            'vw_PeopleEmployeeArea' => "
                SELECT a.BranchId,
                       a.Autoidx                                            AS EmployeeAreaId,
                       CONVERT(nvarchar(11), LTRIM(RTRIM(a.EmployeeCode)))  AS EmployeeCode,
                       a.AreaNo,
                       s.AreaDescription,
                       s.AreaGroup
                FROM [{$erp}].dbo.BRN_EmployeeArea a
                LEFT JOIN [{$erp}].dbo.STK_Area s
                       ON s.SSBranchId = a.BranchId AND s.AreaNo = a.AreaNo",
        ];
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Nothing reads these views but People's own procedures.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (array_keys($this->views((string) config('agora.source_databases.erp'))) as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }
    }
};
