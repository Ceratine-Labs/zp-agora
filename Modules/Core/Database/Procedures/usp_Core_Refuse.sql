-- The refusal contract, in the smallest possible form.
--
-- Business failures are signalled with a structured THROW that carries a
-- matchable code: AGORA:{Code}:{human message}. ProcedureService turns that
-- into an AgoraProcException; anything without the prefix stays a database
-- error, because a deadlock is not a business rule.
CREATE OR ALTER PROCEDURE agora.usp_Core_Refuse
    @Reason NVARCHAR(200) = 'Nothing to do.'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @message NVARCHAR(400) = 'AGORA:CORE_REFUSED:' + @Reason;
    THROW 51000, @message, 1;
END
