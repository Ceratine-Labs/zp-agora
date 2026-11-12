/* The next post number at a branch (T023). Read-only.

   A port of PumpIT's dbo.sp_GetNextEmployeePost, rule for rule: posts are
   numbered per branch, and the next one is the branch's highest PostNo plus
   one, or 1 at a branch with none. Returns one row, (BranchId, NextPostNo).

   It reserves nothing — two people asking at once get the same number, as
   they do in PumpIT. Whoever builds the save must take the number inside the
   save's own transaction, not from here.

   Read by Modules/People/Services/EmployeeService (the Employee posts screen,
   with one branch in scope). Deployed by v1__03p. */
CREATE OR ALTER PROCEDURE agora.usp_People_NextPost
    @BranchId INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT @BranchId AS BranchId,
           CONVERT(SMALLINT, ISNULL(MAX(p.PostNo) + 1, 1)) AS NextPostNo
    FROM agora.vw_PeoplePost p
    WHERE p.BranchId = @BranchId;
END
