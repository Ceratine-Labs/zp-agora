/*
    Agora — daily transaction log backup (SQL Agent job on ZP-MIST-SVR)
    =====================================================================

    Why: the live Agora database is in FULL recovery, but no one ever backed up
    its log (16 Nov 2026: log_reuse_wait LOG_BACKUP, 2.6 GB log 99% used). Each
    growth step is 8 GB. ZP's maintenance plan "SQL Logs Backup" (22:00) backs up
    the logs of a fixed list of databases, and Agora is not on that list.
    Ryan, 16 Nov 2026: "we can set up a backup once a day till i confirm things further".

    What it does, every day at 22:15:
      1. BACKUP LOG [Agora] to E:\SQL\Backups\MIST\Agora\Agora_backup_<yyyy_MM_dd_HHmmss>.trn
         (compressed, with checksum). Same folder layout as ZP's plan.
         Skipped while Agora is in SIMPLE recovery, so a later switch to SIMPLE
         makes the job a harmless no-op.
      2. Deletes .trn files in that folder older than 14 days (xp_delete_file only
         removes SQL Server backup files).

    The full backups are not touched. ZP takes a full backup of Agora daily at about
    03:58, written to C:\Windows\TEMP.

    Run: as a sysadmin, in one batch (SSMS or the app connection). Re-running
    replaces the job.
    Remove: EXEC msdb.dbo.sp_delete_job @job_name = N'Agora - Daily log backup', @delete_unused_schedule = 1;
*/
SET NOCOUNT ON;

DECLARE @job sysname = N'Agora - Daily log backup';

DECLARE @backupStep nvarchar(max) = N'-- Agora daily log backup (Revvtech, 16 Nov 2026). Skipped while Agora is in SIMPLE recovery.
IF (SELECT recovery_model_desc FROM sys.databases WHERE name = N''Agora'') = N''SIMPLE''
BEGIN
    PRINT N''Agora is in SIMPLE recovery: no log backup needed.'';
END
ELSE
BEGIN
    DECLARE @file nvarchar(260) = N''E:\SQL\Backups\MIST\Agora\Agora_backup_'' + FORMAT(SYSDATETIME(), N''yyyy_MM_dd_HHmmss'') + N''.trn'';
    BACKUP LOG [Agora] TO DISK = @file WITH COMPRESSION, CHECKSUM, NAME = N''Agora daily log backup'';
END';

DECLARE @cleanupStep nvarchar(max) = N'-- Keep 14 days of Agora log backups. xp_delete_file only deletes SQL Server backup files.
DECLARE @cutoff nvarchar(19) = CONVERT(nvarchar(19), DATEADD(day, -14, GETDATE()), 126);
EXEC master.dbo.xp_delete_file 0, N''E:\SQL\Backups\MIST\Agora\'', N''trn'', @cutoff, 0;';

-- The folder must exist before the first backup; this is a no-op when it does.
EXEC master.dbo.xp_create_subdir N'E:\SQL\Backups\MIST\Agora';

BEGIN TRY
    BEGIN TRANSACTION;

    IF EXISTS (SELECT 1 FROM msdb.dbo.sysjobs WHERE name = @job)
        EXEC msdb.dbo.sp_delete_job @job_name = @job, @delete_unused_schedule = 1;

    EXEC msdb.dbo.sp_add_job
        @job_name = @job,
        @enabled = 1,
        @description = N'Revvtech, 16 Nov 2026. Backs up the Agora transaction log once a day to E:\SQL\Backups\MIST\Agora and keeps 14 days of .trn files. Agora stays in FULL recovery; the job skips itself in SIMPLE. Script: Agora repo scripts/sql/agora-log-backup-job.sql',
        @category_name = N'Database Maintenance',
        @owner_login_name = N'sa';

    EXEC msdb.dbo.sp_add_jobstep
        @job_name = @job,
        @step_id = 1,
        @step_name = N'Back up the Agora log',
        @subsystem = N'TSQL',
        @database_name = N'master',
        @command = @backupStep,
        @retry_attempts = 1,
        @retry_interval = 5,
        @on_success_action = 3,  -- next step
        @on_fail_action = 2;     -- quit, report failure

    EXEC msdb.dbo.sp_add_jobstep
        @job_name = @job,
        @step_id = 2,
        @step_name = N'Delete Agora log backups older than 14 days',
        @subsystem = N'TSQL',
        @database_name = N'master',
        @command = @cleanupStep,
        @on_success_action = 1,  -- quit, report success
        @on_fail_action = 2;

    EXEC msdb.dbo.sp_add_jobschedule
        @job_name = @job,
        @name = N'Agora - Daily 22:15',
        @enabled = 1,
        @freq_type = 4,             -- daily
        @freq_interval = 1,         -- every day
        @freq_subday_type = 1,      -- once, at the start time
        @active_start_time = 221500;

    EXEC msdb.dbo.sp_add_jobserver @job_name = @job, @server_name = N'(local)';

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
