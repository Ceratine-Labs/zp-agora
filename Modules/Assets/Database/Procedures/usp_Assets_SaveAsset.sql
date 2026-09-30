/* ============================================================================
 * agora.usp_Assets_SaveAsset — register an asset, or change one.
 *
 * Called by: Asset register -> Register an asset / Edit (T057).
 * Writes:    agora.Asset — one row, INSERT or UPDATE. Nothing in PumpIT: the
 *            register is Agora's own (Ryan, 27 Sep 2026, PM decision 01a0e309).
 *
 * @AssetId NULL registers a new asset and numbers it; otherwise the asset
 * (@BranchId, @AssetId) is changed.
 *
 * THE NUMBER. `{group}{seq}-{branch}`: BE0007-8 is the seventh Bakery
 * Equipment asset on Caltex Ulundi's register. The sequence is per (site,
 * group), counts soft-deleted assets so a number is never reused, and is taken
 * under UPDLOCK, HOLDLOCK so two people registering at once cannot draw the
 * same one. It is fixed at registration — a later change of group does not
 * renumber, because by then the number is on a sticker on the machine.
 *
 * WHAT A SAVE MAY NOT DO:
 *   · move the asset. LocatedBranchId is set to the owning site on
 *     registration (or to @LocatedBranchId, for an asset registered where it
 *     already stands), and after that only agora.usp_Assets_Move changes it —
 *     a location changed by editing leaves no trail.
 *   · change its status. Disposal, write-off and repair are movements.
 *
 * RULES (a refusal is THROW 51000 'AGORA:{Code}:{message}'):
 *   BRANCH_REQUIRED · UNKNOWN_BRANCH       the owning site must be a real site
 *   UNKNOWN_LOCATION                       where it stands, likewise
 *   UNKNOWN_TYPE                           (group, type) must be on the
 *                                          customer's type list
 *   MAKE_REQUIRED                          the legacy form required it too
 *   PURCHASE_DATE_REQUIRED · FUTURE_DATE   an asset not yet bought is not one
 *   BAD_COST                               cost is required, and not negative
 *   BAD_CONDITION                          one of agora.vw_AssetCondition
 *   UNKNOWN_OWNER                          one of the customer's owner names
 *   UNKNOWN_SUPPLIER                       a supplier at the owning site
 *   DUPLICATE_SERIAL                       the same make and serial number is
 *                                          already on a live register — the
 *                                          legacy pilot put one printer on two
 *                                          sites' registers, and this is what
 *                                          stops it
 *   EDIT_NOT_ALLOWED                       changing an asset needs
 *                                          assets.register.edit (@AllowEdit)
 *   NOT_FOUND · DELETED
 *   REASON_REQUIRED                        every change says why
 *
 * Returns the writer status row (Ok, Code, Message, Id = the asset's Id) plus
 * the AssetNo.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_SaveAsset]
    @BranchId        INT,
    @AssetId         BIGINT         = NULL,
    @AssetGroupCode  NVARCHAR(10),
    @AssetTypeCode   NVARCHAR(10),
    @Make            NVARCHAR(100),
    @Model           NVARCHAR(100)  = NULL,
    @SerialNo        NVARCHAR(100)  = NULL,
    @Description     NVARCHAR(200)  = NULL,
    @LocatedBranchId INT            = NULL,
    @AssetOwner      NVARCHAR(100)  = NULL,
    @SupplierCode    NVARCHAR(16)   = NULL,
    @DatePurchased   DATE           = NULL,
    @DateAllocated   DATE           = NULL,
    @Cost            DECIMAL(18,2)  = NULL,
    @Condition       NVARCHAR(20)   = 'GOOD',
    @Reason          NVARCHAR(300)  = NULL,
    @AllowEdit       BIT            = 0,
    @UserId          INT            = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    SET @AssetGroupCode = NULLIF(LTRIM(RTRIM(@AssetGroupCode)), '');
    SET @AssetTypeCode  = NULLIF(LTRIM(RTRIM(@AssetTypeCode)), '');
    SET @Make           = NULLIF(LTRIM(RTRIM(@Make)), '');
    SET @Model          = NULLIF(LTRIM(RTRIM(@Model)), '');
    SET @SerialNo       = NULLIF(LTRIM(RTRIM(@SerialNo)), '');
    SET @Description    = NULLIF(LTRIM(RTRIM(@Description)), '');
    SET @AssetOwner     = NULLIF(LTRIM(RTRIM(@AssetOwner)), '');
    SET @SupplierCode   = NULLIF(LTRIM(RTRIM(@SupplierCode)), '');
    SET @Condition      = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@Condition)), ''), 'GOOD'));
    SET @Reason         = NULLIF(LTRIM(RTRIM(@Reason)), '');

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:BRANCH_REQUIRED:Say which site''s register the asset belongs to.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:UNKNOWN_BRANCH:That site does not exist.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.vw_AssetType t
                    WHERE t.AssetGroupCode = @AssetGroupCode AND t.AssetTypeCode = @AssetTypeCode)
        THROW 51000, 'AGORA:UNKNOWN_TYPE:That asset type is not in that group on the customer''s list.', 1;

    IF @Make IS NULL
        THROW 51000, 'AGORA:MAKE_REQUIRED:Give the make — the maker''s name is how the asset is found again.', 1;

    IF @DatePurchased IS NULL
        THROW 51000, 'AGORA:PURCHASE_DATE_REQUIRED:Give the date it was bought. Depreciation starts in that month.', 1;

    IF @DatePurchased > @Today OR @DateAllocated > @Today
        THROW 51000, 'AGORA:FUTURE_DATE:An asset cannot be bought or allocated on a day that has not happened.', 1;

    IF @Cost IS NULL OR @Cost < 0
        THROW 51000, 'AGORA:BAD_COST:Give what it cost, excluding VAT — zero if it was given to the site, never negative.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.vw_AssetCondition c WHERE c.Condition = @Condition)
        THROW 51000, 'AGORA:BAD_CONDITION:Condition must be New, Good, Fair, Poor or Unserviceable.', 1;

    IF @AssetOwner IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM agora.vw_AssetOwner o WHERE o.AssetOwner = @AssetOwner)
        THROW 51000, 'AGORA:UNKNOWN_OWNER:That owner is not on the customer''s owner list.', 1;

    IF @SupplierCode IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM agora.vw_Supplier s WHERE s.BranchId = @BranchId AND s.SupplierCode = @SupplierCode)
        THROW 51000, 'AGORA:UNKNOWN_SUPPLIER:That supplier is not set up at this site.', 1;

    /* The same machine on two registers is the one mistake the legacy pilot
       made in eight rows. Make plus serial, because two makers can share a
       serial number; blank serials are not compared. */
    IF @SerialNo IS NOT NULL
       AND EXISTS (SELECT 1 FROM agora.Asset a
                    WHERE a.Make = @Make AND a.SerialNo = @SerialNo
                      AND a.DeletedAt IS NULL
                      AND NOT (a.BranchId = @BranchId AND a.Id = ISNULL(@AssetId, -1)))
    BEGIN
        DECLARE @Twin nvarchar(60) = (SELECT TOP 1 a.AssetNo FROM agora.Asset a
                                       WHERE a.Make = @Make AND a.SerialNo = @SerialNo AND a.DeletedAt IS NULL
                                         AND NOT (a.BranchId = @BranchId AND a.Id = ISNULL(@AssetId, -1)));
        DECLARE @TwinMsg nvarchar(400) = CONCAT('AGORA:DUPLICATE_SERIAL:', @Make, ' serial ', @SerialNo,
                                                ' is already registered as ', @Twin, '. Move that asset rather than registering it again.');
        THROW 51000, @TwinMsg, 1;
    END

    BEGIN TRANSACTION;

    IF @AssetId IS NULL
    BEGIN
        SET @LocatedBranchId = ISNULL(@LocatedBranchId, @BranchId);

        IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @LocatedBranchId)
            THROW 51000, 'AGORA:UNKNOWN_LOCATION:The site it stands at does not exist.', 1;

        DECLARE @Seq int = 1 + ISNULL((
            SELECT MAX(a.AssetSeq) FROM agora.Asset a WITH (UPDLOCK, HOLDLOCK)
             WHERE a.BranchId = @BranchId AND a.AssetGroupCode = @AssetGroupCode), 0);

        DECLARE @AssetNo nvarchar(30) = CONCAT(
            @AssetGroupCode,
            CASE WHEN @Seq < 10000 THEN RIGHT(CONCAT('000', @Seq), 4) ELSE CONVERT(varchar(12), @Seq) END,
            '-', @BranchId);

        INSERT INTO agora.Asset (
            BranchId, AssetNo, AssetSeq, AssetGroupCode, AssetTypeCode, Make, Model, SerialNo, [Description],
            LocatedBranchId, AssetOwner, SupplierCode, DatePurchased, DateAllocated, Cost, Condition,
            [Status], Reason, CreatedAt, CreatedBy)
        VALUES (
            @BranchId, @AssetNo, @Seq, @AssetGroupCode, @AssetTypeCode, @Make, @Model, @SerialNo, @Description,
            @LocatedBranchId, @AssetOwner, @SupplierCode, @DatePurchased, @DateAllocated, @Cost, @Condition,
            'IN_SERVICE', @Reason, SYSUTCDATETIME(), @UserId);

        SET @AssetId = SCOPE_IDENTITY();

        COMMIT TRANSACTION;

        SELECT Ok = CONVERT(bit, 1), Code = 'REGISTERED',
               [Message] = CONCAT('Registered as ', @AssetNo, '.'),
               Id = @AssetId, AssetNo = @AssetNo;
        RETURN;
    END

    /* ---- a change to an asset already on the register ------------------ */

    IF @AllowEdit = 0
        THROW 51000, 'AGORA:EDIT_NOT_ALLOWED:Changing a registered asset needs the assets.register.edit permission.', 1;

    DECLARE @Current TABLE (AssetNo nvarchar(30), DeletedAt datetime2(0));
    INSERT INTO @Current (AssetNo, DeletedAt)
    SELECT a.AssetNo, a.DeletedAt
    FROM agora.Asset a WITH (UPDLOCK)
    WHERE a.BranchId = @BranchId AND a.Id = @AssetId;

    IF NOT EXISTS (SELECT 1 FROM @Current)
        THROW 51000, 'AGORA:NOT_FOUND:That asset is not on this site''s register.', 1;

    IF EXISTS (SELECT 1 FROM @Current WHERE DeletedAt IS NOT NULL)
        THROW 51000, 'AGORA:DELETED:That asset was deleted from the register and cannot be changed.', 1;

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the asset is being changed. It is kept against the change.', 1;

    UPDATE agora.Asset
       SET AssetGroupCode = @AssetGroupCode,
           AssetTypeCode  = @AssetTypeCode,
           Make           = @Make,
           Model          = @Model,
           SerialNo       = @SerialNo,
           [Description]  = @Description,
           AssetOwner     = @AssetOwner,
           SupplierCode   = @SupplierCode,
           DatePurchased  = @DatePurchased,
           DateAllocated  = @DateAllocated,
           Cost           = @Cost,
           Condition      = @Condition,
           Reason         = @Reason,
           UpdatedAt      = SYSUTCDATETIME(),
           UpdatedBy      = @UserId
     WHERE BranchId = @BranchId AND Id = @AssetId;

    COMMIT TRANSACTION;

    SELECT Ok = CONVERT(bit, 1), Code = 'SAVED',
           [Message] = CONCAT((SELECT AssetNo FROM @Current), ' saved.'),
           Id = @AssetId, AssetNo = (SELECT AssetNo FROM @Current);
END
