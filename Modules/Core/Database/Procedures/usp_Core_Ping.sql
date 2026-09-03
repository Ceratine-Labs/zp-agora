-- Proves the procedure layer end to end: named parameters arrive intact, and
-- a proc returning two result sets has both of them read.
--
-- CREATE OR ALTER so the deploying migration is idempotent and `git diff`
-- shows the change to the body rather than a drop-and-recreate.
CREATE OR ALTER PROCEDURE agora.usp_Core_Ping
    @BranchId INT,
    @Note NVARCHAR(200) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        CAST(1 AS BIT)      AS Ok,
        'CORE_PING'         AS Code,
        'Procedure layer is reachable.' AS Message,
        @BranchId           AS BranchId,
        @Note               AS Note;

    -- A second set, so callSets() has something to prove: PDO returns only
    -- the first unless nextRowset() is walked.
    SELECT b.BranchId, b.Name
    FROM agora.Branch b
    WHERE b.BranchId = @BranchId;
END
