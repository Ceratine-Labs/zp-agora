<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A stored procedure refused the work and said why.
 *
 * Agora's procedures signal business failures with a structured THROW:
 *
 *     THROW 51000, 'AGORA:BANKING_CLOSED:The banking day is already closed.', 1;
 *
 * ProcedureService parses that into ->code() and ->getMessage(), so a
 * controller can map a refusal onto a validation error without matching on
 * English prose. Anything that does NOT carry the AGORA: prefix is a genuine
 * database fault and is left to bubble as a QueryException — a deadlock is not
 * a business rule and must not be presented to the user as one.
 */
class AgoraProcException extends RuntimeException
{
    public function __construct(
        protected string $agoraCode,
        string $message,
        protected string $procedure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The {Code} segment — stable, matchable, safe to branch on. */
    public function code(): string
    {
        return $this->agoraCode;
    }

    /** The procedure that raised it, for the log line. */
    public function procedure(): string
    {
        return $this->procedure;
    }

    /**
     * Did this exception come from a proc's deliberate THROW rather than a
     * database fault? Always true for this class; kept as a named check so
     * call sites read as intent.
     */
    public function isBusinessRule(): bool
    {
        return true;
    }
}
