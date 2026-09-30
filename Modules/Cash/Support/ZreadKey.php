<?php

namespace Modules\Cash\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One Z-read, named the only way it can be.
 *
 * PumpIT.dbo.DBF_P3TRANS_ZREAD has no surrogate key: its primary key is
 * (SSBranchId, DATE, EOD_CNTR, TERMNUM, LOGFILE, USERID, LOCATION), so that
 * composite IS the row, and every press on one Z-read carries all seven.
 * Trimmed, because four of the source columns are fixed-width CHAR.
 */
final class ZreadKey
{
    public function __construct(
        public readonly int $branchId,
        public readonly string $date,
        public readonly string $location,
        public readonly string $eod,
        public readonly string $terminal,
        public readonly string $logFile,
        public readonly string $posUserId,
    ) {}

    /** Null when any part is missing: a partial key names no Z-read. */
    public static function fromRequest(Request $request, ?int $branchId): ?self
    {
        $parts = [];

        foreach (['date', 'location', 'eod', 'term', 'log', 'user'] as $name) {
            $value = $request->input($name);

            if (! is_scalar($value)) {
                return null;
            }

            $parts[$name] = trim((string) $value);
        }

        if ($branchId === null || $parts['date'] === '' || $parts['eod'] === ''
            || $parts['term'] === '' || $parts['log'] === '' || $parts['user'] === '') {
            return null;
        }

        try {
            $date = Carbon::parse($parts['date'])->toDateString();
        } catch (\Throwable) {
            return null;
        }

        return new self($branchId, $date, $parts['location'], $parts['eod'], $parts['term'], $parts['log'], $parts['user']);
    }

    public static function fromRow(object $row): self
    {
        return new self(
            (int) $row->BranchId,
            Carbon::parse((string) $row->ZReadDate)->toDateString(),
            (string) $row->Location,
            (string) $row->EodCounter,
            (string) $row->TerminalNo,
            (string) $row->LogFile,
            (string) $row->PosUserId,
        );
    }

    /** @return array<string, string> the key as query parameters */
    public function query(): array
    {
        return [
            'date' => $this->date,
            'location' => $this->location,
            'eod' => $this->eod,
            'term' => $this->terminal,
            'log' => $this->logFile,
            'user' => $this->posUserId,
        ];
    }

    /** @return array<string, string|int> the key as the procedures name it */
    public function procedureParameters(): array
    {
        return [
            'BranchId' => $this->branchId,
            'Location' => $this->location,
            'EodCounter' => $this->eod,
            'TerminalNo' => $this->terminal,
            'LogFile' => $this->logFile,
            'PosUserId' => $this->posUserId,
        ];
    }

    public function matches(object $row): bool
    {
        return (string) $row->Location === $this->location
            && (string) $row->EodCounter === $this->eod
            && (string) $row->TerminalNo === $this->terminal
            && (string) $row->LogFile === $this->logFile
            && (string) $row->PosUserId === $this->posUserId;
    }
}
