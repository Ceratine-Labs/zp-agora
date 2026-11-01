/* Confirm one counting area's prepared-food classification, with reason.
   Owns the confirmation and concurrency rules. Writes only Agora's setting and
   immutable change ledger, atomically; NEVER writes PumpIT. Effective safe
   proposals remain unchanged until Confirm = 1 is explicitly supplied.
   Authorized comes from the authenticated permission resolver, not the form.
   Deployed by v1__14px. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_SaveCountingArea
    @BranchId INT,
    @AreaNo INT,
    @IsPreparedFood BIT,
    @ExpectedVersion NVARCHAR(18),
    @ExpectedFood BIT,
    @Reason NVARCHAR(400),
    @Confirm BIT = 0,
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @Workspace NVARCHAR(10) = N'branch'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    EXEC agora.usp_StockRecon_AuthorizeCountingArea @BranchId, @UserId, @Authorized, @Workspace;
    IF ISNULL(@Confirm, 0) <> 1
        THROW 51000, 'AGORA:AREA_CONFIRM:Explicitly confirm the prepared-food classification before saving.', 1;
    IF @IsPreparedFood IS NULL
        THROW 51000, 'AGORA:AREA_VALUE:Choose whether this is a prepared-food area.', 1;
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');
    IF @Reason IS NULL OR LEN(@Reason) < 10
        THROW 51000, 'AGORA:AREA_REASON:Explain the confirmation in at least 10 characters.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;
        DECLARE @Id BIGINT, @Version varchar(18), @Prior BIT, @Basis nvarchar(60),
                @ConfirmedAt datetime2(0), @ConfirmedBy int;
        SELECT @Id = Id, @Version = CONVERT(varchar(18), CONVERT(binary(8), RowVer), 1)
        FROM agora.StockAreaSetting WITH (UPDLOCK, HOLDLOCK)
        WHERE BranchId = @BranchId AND AreaNo = @AreaNo;

        SELECT @Prior = IsPreparedFood, @Basis = FoodBasis,
               @ConfirmedAt = ConfirmedAt, @ConfirmedBy = ConfirmedBy
        FROM agora.vw_StockAreaFood WHERE BranchId = @BranchId AND AreaNo = @AreaNo;
        IF @Prior IS NULL
            THROW 51000, 'AGORA:AREA_MISSING:That counting area no longer exists at this site.', 1;
        IF ISNULL(@ExpectedVersion, '') <> ISNULL(@Version, 'new')
           OR @ExpectedFood IS NULL OR @ExpectedFood <> @Prior
            THROW 51000, 'AGORA:AREA_STALE:This area changed since you opened it. Reload and review it before confirming.', 1;

        DECLARE @Now datetime2(0) = SYSUTCDATETIME();
        INSERT INTO agora.StockAreaSettingChange
            (BranchId, AreaNo, PriorIsPreparedFood, NewIsPreparedFood, PriorFoodBasis,
             PriorConfirmedAt, PriorConfirmedBy, Reason, CreatedAt, CreatedBy)
        VALUES (@BranchId, @AreaNo, @Prior, @IsPreparedFood, @Basis,
                @ConfirmedAt, @ConfirmedBy, @Reason, @Now, @UserId);

        IF @Id IS NULL
            INSERT INTO agora.StockAreaSetting
                (BranchId, AreaNo, IsPreparedFood, FoodBasis, ConfirmedAt, ConfirmedBy, CreatedAt, CreatedBy)
            VALUES (@BranchId, @AreaNo, @IsPreparedFood, N'Head-office confirmation', @Now, @UserId, @Now, @UserId);
        ELSE
            UPDATE agora.StockAreaSetting
            SET IsPreparedFood = @IsPreparedFood, FoodBasis = N'Head-office confirmation',
                ConfirmedAt = @Now, ConfirmedBy = @UserId, UpdatedAt = @Now, UpdatedBy = @UserId
            WHERE BranchId = @BranchId AND Id = @Id;
        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
    SELECT CONVERT(bit, 1) AS Ok, 'CONFIRMED' AS Code,
           N'Counting area confirmed. The change applies to future stock recon previews.' AS Message,
           CONVERT(bigint, @AreaNo) AS Id;
END
