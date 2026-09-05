<?php

namespace Modules\Recon\Services;

use RuntimeException;

/**
 * A preview procedure declined to run and said why in its result set.
 *
 * The five AUTO RECON previews answer a missing or unusable
 * BRN_AutoReconCriteria row with a single row carrying an `Error` column
 * rather than with a THROW — so it does not arrive as an AgoraProcException
 * and would otherwise render as an empty grid. An empty grid reads as "this
 * branch is fully reconciled", which is the opposite of what happened.
 *
 * The detail row is kept because it carries the resolved positions when the
 * refusal is "a resolved extraction length is not positive" — which is the
 * message that tells the customer exactly which configuration row to fix.
 */
class ReconPreviewRefused extends RuntimeException
{
    /** @param array<string, mixed> $detail */
    public function __construct(
        string $message,
        protected string $procedure,
        protected array $detail = [],
    ) {
        parent::__construct($message);
    }

    public function procedure(): string
    {
        return $this->procedure;
    }

    /** @return array<string, mixed> */
    public function detail(): array
    {
        return array_diff_key($this->detail, ['Error' => null]);
    }
}
