/* ============================================================================
   agora.usp_Debtors_SaveContact — where a debtor's statement goes

   Called by: Debtors statements → a statement → Email address.
   Writes:    agora.DebtorContact — one row per account per site, INSERT or
              UPDATE. Nothing in PumpIT.

   No address is held for a debtor anywhere in the customer's estate (0 of
   1,406 MIST accounts, 13 Oct 2026), so this list is Agora's.

   @AutoSend puts the account on the monthly run. It needs an address, and
   whether the run actually mails anybody is config('debtors.send_mode'), not
   this switch.

   RULES (a refusal is THROW 51000 'AGORA:{Code}:{message}'):
     BRANCH_NOT_ALLOWED  the site is outside @AllowedBranchIds
     UNKNOWN_ACCOUNT     no posting and no master row for the account there
     BAD_EMAIL           the address is not one
     BAD_CC              one of the copies is not an address
     EMAIL_REQUIRED      the monthly run needs somewhere to send

   Returns the writer status row (Ok, Code, Message, Id = the contact's Id).
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Debtors_SaveContact]
    @BranchId         INT,
    @AccountNo        NVARCHAR(10),
    @Email            NVARCHAR(200) = NULL,
    @CcEmail          NVARCHAR(400) = NULL,
    @AutoSend         BIT           = 0,
    @Note             NVARCHAR(300) = NULL,
    @UserId           INT           = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @AccountNo = NULLIF(LTRIM(RTRIM(@AccountNo)), '');
    SET @Email     = NULLIF(LTRIM(RTRIM(@Email)), '');
    SET @CcEmail   = NULLIF(LTRIM(RTRIM(REPLACE(@CcEmail, ';', ','))), '');
    SET @Note      = NULLIF(LTRIM(RTRIM(@Note)), '');
    SET @AutoSend  = ISNULL(@AutoSend, 0);

    IF NULLIF(LTRIM(RTRIM(ISNULL(@AllowedBranchIds, ''))), '') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') s
                       WHERE TRY_CONVERT(int, LTRIM(RTRIM(s.value))) = @BranchId)
        THROW 51000, 'AGORA:BRANCH_NOT_ALLOWED:That site is not one of yours.', 1;

    IF @AccountNo IS NULL
       OR (NOT EXISTS (SELECT 1 FROM agora.vw_DebtorLedger l WHERE l.BranchId = @BranchId AND l.AccountNo = @AccountNo)
           AND NOT EXISTS (SELECT 1 FROM agora.vw_DebtorAccount m WHERE m.BranchId = @BranchId AND m.AccountNo = @AccountNo))
        THROW 51000, 'AGORA:UNKNOWN_ACCOUNT:There is no such account at that site.', 1;

    /* One address: something@something.something, no spaces, no separators. */
    IF @Email IS NOT NULL
       AND (@Email NOT LIKE '%_@_%._%' OR @Email LIKE '% %' OR @Email LIKE '%,%' OR @Email LIKE '%@%@%')
        THROW 51000, 'AGORA:BAD_EMAIL:That is not an email address. Give one address; copies go in the copy line.', 1;

    IF @CcEmail IS NOT NULL
       AND EXISTS (SELECT 1 FROM STRING_SPLIT(@CcEmail, ',') s
                   WHERE LTRIM(RTRIM(s.value)) <> ''
                     AND (LTRIM(RTRIM(s.value)) NOT LIKE '%_@_%._%' OR LTRIM(RTRIM(s.value)) LIKE '% %'
                          OR LTRIM(RTRIM(s.value)) LIKE '%@%@%'))
        THROW 51000, 'AGORA:BAD_CC:One of the copies is not an email address. Separate them with commas.', 1;

    IF @AutoSend = 1 AND @Email IS NULL
        THROW 51000, 'AGORA:EMAIL_REQUIRED:Give an address before putting the account on the monthly run.', 1;

    /* Tidy the copy line to "a, b, c". */
    IF @CcEmail IS NOT NULL
        SET @CcEmail = NULLIF((SELECT STRING_AGG(LTRIM(RTRIM(s.value)), ', ')
                               FROM STRING_SPLIT(@CcEmail, ',') s WHERE LTRIM(RTRIM(s.value)) <> ''), '');

    DECLARE @Id bigint;

    BEGIN TRAN;

    SELECT @Id = c.Id
    FROM agora.DebtorContact c WITH (UPDLOCK, HOLDLOCK)
    WHERE c.BranchId = @BranchId AND c.AccountNo = @AccountNo;

    IF @Id IS NULL
    BEGIN
        INSERT INTO agora.DebtorContact (BranchId, AccountNo, Email, CcEmail, AutoSend, Note, CreatedAt, CreatedBy)
        VALUES (@BranchId, @AccountNo, @Email, @CcEmail, @AutoSend, @Note, SYSUTCDATETIME(), @UserId);

        SET @Id = SCOPE_IDENTITY();
    END
    ELSE
        UPDATE agora.DebtorContact
           SET Email = @Email, CcEmail = @CcEmail, AutoSend = @AutoSend, Note = @Note,
               UpdatedAt = SYSUTCDATETIME(), UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @Id;

    COMMIT;

    SELECT CONVERT(bit, 1) AS Ok, 'SAVED' AS Code,
           CASE WHEN @AutoSend = 1 THEN 'Saved. The account is on the monthly run.'
                WHEN @Email IS NULL THEN 'Saved. The account has no address, so it is not mailed.'
                ELSE 'Saved. The account is not on the monthly run; its statement goes when somebody sends it.' END AS Message,
           @Id AS Id;
END
