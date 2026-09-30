<?php

namespace Modules\Cash\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Modules\Cash\Support\CashupScope;

/**
 * Daily banking and cashups (T037): the seam between the screens and the
 * procedures.
 *
 * Every figure is T-SQL. What a cashup totals, its variance, whether its
 * Z-read agrees, where it stands — agora.vw_CashupTotals computes each of them
 * once, and nothing here re-derives one. What this class adds is only what a
 * procedure cannot know: where the settings are, who the person is, and
 * whether the literal write switch is on.
 *
 * WHAT REACHES THE CUSTOMER'S DATABASE, and only through a procedure:
 * usp_Cash_SaveCashupLeg writes BRN_DailyBanking (the header), the leg being
 * saved, and — for a leg chosen from a pool — the pool row's banked mark (the
 * list is in the procedure's header), and is only called when writeMode() is
 * 'live'. Confirm, Close and Reopen write Agora's own tables. Everything else
 * reads.
 */
class CashupService
{
    public function __construct(protected ProcedureService $procedures) {}

    /**
     * 'live' when a save may write PumpIT; 'off' otherwise.
     *
     * Read from the LITERAL config('cash.cashup.write_mode') and nothing else,
     * and anything that is not exactly 'live' is 'off', so a typo fails safe.
     */
    public function writeMode(): string
    {
        return config('cash.cashup.write_mode') === 'live' ? 'live' : 'off';
    }

    public function varianceTolerance(): float
    {
        return (float) config('cash.cashup.variance_tolerance', 150.00);
    }

    /**
     * The legs as tabs, in order, without the ones the setting hides.
     *
     * @return array<string, string> channel => label
     */
    public function channels(): array
    {
        $hidden = (array) config('cash.cashup.hidden_channels', []);

        return array_diff_key((array) config('cash.cashup.channels', []), array_flip($hidden));
    }

    /** @return array<int, string> */
    public function savableChannels(): array
    {
        return $this->writeMode() === 'live' ? (array) config('cash.cashup.savable_channels', []) : [];
    }

    /**
     * The legs Agora does not write, with why.
     *
     * @return array<string, string>
     */
    public function heldChannels(): array
    {
        return (array) config('cash.cashup.held_channels', []);
    }

    /** Whether a leg is chosen from a PumpIT pool (and so marks it banked). */
    public function isPool(string $channel): bool
    {
        return array_key_exists($channel, (array) config('cash.cashup.pool_windows', []));
    }

    public function poolName(string $channel): string
    {
        return (string) (config("cash.cashup.pool_names.{$channel}") ?? $channel);
    }

    /**
     * The rows of one leg's pool that nobody has banked, around the cashup's
     * day, for a person to tick.
     *
     * @return array{rows: Collection<int, object>, summary: object|null}
     */
    public function pool(CashupScope $scope, string $channel): array
    {
        [$before, $after] = (array) config("cash.cashup.pool_windows.{$channel}", [3, 3]) + [3, 3];

        $sets = $this->procedures->callSets('usp_Cash_GetCashupPool', $this->key($scope) + [
            'Channel' => $channel,
            'DaysBefore' => (int) $before,
            'DaysAfter' => (int) $after,
        ]);

        return [
            'rows' => $sets[0] ?? collect(),
            'summary' => ($sets[1] ?? collect())->first(),
        ];
    }

    /**
     * Add the ticked pool rows to the cashup's leg — and mark each banked in
     * its pool, in the same transaction. PumpIT is written.
     *
     * @param  array<int, string>  $poolKeys  each the PoolKey JSON the pool list gave the row
     */
    public function savePool(CashupScope $scope, string $channel, array $poolKeys, int $userId, ?string $note = null): object
    {
        $lines = [];

        foreach ($poolKeys as $key) {
            $decoded = json_decode($key, true);

            // Anything that is not a key object is passed as it came, and the
            // procedure refuses it by name — the rule lives in one place.
            $lines[] = is_array($decoded) ? $decoded : $key;
        }

        return $this->save($scope, $channel, $lines, $userId, $note);
    }

    /** A manager reopens a closed cashup, with a reason. Agora's database only. */
    public function reopen(CashupScope $scope, int $userId, ?string $reason): object
    {
        return $this->procedures->write('usp_Cash_ReopenCashup', $this->key($scope) + [
            'Reason' => $reason,
            'UserId' => $userId,
        ]);
    }

    /**
     * The site's cashups for the day — and, when one is named, its lines, the
     * day's Z-reads and what has been done to it.
     *
     * @return array{cashups: Collection<int, object>, lines: Collection<int, object>, zreads: Collection<int, object>, events: Collection<int, object>}
     */
    public function day(CashupScope $scope): array
    {
        $sets = $this->procedures->callSets('usp_Cash_GetCashup', [
            'BranchId' => $scope->branchId,
            'DateFrom' => $scope->date,
            'DateTo' => $scope->date,
            'TillNo' => $scope->tillNo,
            'ShiftNo' => $scope->shiftNo,
        ]);

        return [
            'cashups' => $sets[0] ?? collect(),
            'lines' => $sets[1] ?? collect(),
            'zreads' => $sets[2] ?? collect(),
            'events' => $sets[3] ?? collect(),
        ];
    }

    /**
     * What the card terminal's batch file says one cashup took by card, and
     * what saving it would do to the FNB leg as it stands.
     *
     * @return array{lines: Collection<int, object>, summary: object|null}
     */
    public function cardLoad(CashupScope $scope): array
    {
        $sets = $this->procedures->callSets('usp_Cash_LoadCardAmounts', $this->key($scope));

        return [
            'lines' => $sets[0] ?? collect(),
            'summary' => ($sets[1] ?? collect())->first(),
        ];
    }

    /**
     * Save the FNB leg as the batch file has it — only the lines that would
     * add or correct something are sent, so a re-save writes nothing.
     */
    public function saveCardLoad(CashupScope $scope, int $userId, ?string $note = null): object
    {
        $lines = $this->cardLoad($scope)['lines']
            ->filter(fn (object $l) => $l->Amount !== null)
            ->map(fn (object $l) => [
                'BatchNo' => (string) $l->BatchNo,
                'MerchantNo' => (string) $l->MerchantNo,
                'Amount' => (string) $l->Amount,
            ])
            ->values()
            ->all();

        return $this->save($scope, 'FNB', $lines, $userId, $note);
    }

    /**
     * One leg of one cashup, and the header with it — PumpIT is written.
     *
     * @param  array<int, mixed>  $lines
     */
    public function save(CashupScope $scope, ?string $channel, array $lines, int $userId, ?string $note = null): object
    {
        return $this->procedures->write('usp_Cash_SaveCashupLeg', $this->key($scope) + [
            'Channel' => $channel,
            'LinesJson' => $channel === null ? null : json_encode(array_values($lines), JSON_THROW_ON_ERROR),
            'Note' => $note,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);
    }

    /** A person agrees to the cashup's figures. Agora's database only. */
    public function confirm(CashupScope $scope, int $userId, ?string $note = null): object
    {
        return $this->procedures->write('usp_Cash_ConfirmCashup', $this->key($scope) + [
            'Note' => $note,
            'VarianceTolerance' => $this->varianceTolerance(),
            'UserId' => $userId,
        ]);
    }

    /** A manager signs a confirmed cashup off. Agora's database only. */
    public function close(CashupScope $scope, int $userId, ?string $note = null): object
    {
        return $this->procedures->write('usp_Cash_CloseCashup', $this->key($scope) + [
            'Note' => $note,
            'UserId' => $userId,
        ]);
    }

    /**
     * The figures above the day's list, counted from the procedure's own rows
     * so the strip and the list cannot disagree.
     *
     * @param  Collection<int, object>  $cashups
     * @param  Collection<int, object>  $zreads
     * @return array<string, int|float>
     */
    public function summary(Collection $cashups, Collection $zreads): array
    {
        $tolerance = $this->varianceTolerance();

        return [
            'cashups' => $cashups->count(),
            'variance' => (float) $cashups->sum(fn (object $c) => (float) $c->Variance),
            'beyond' => $cashups->filter(fn (object $c) => abs((float) $c->Variance) > $tolerance)->count(),
            'toConfirm' => $cashups->where('Status', 'Open')->count(),
            'toClose' => $cashups->where('Status', 'Confirmed')->count(),
            'disagree' => $cashups->where('ZreadCheck', '!=', 'Agrees')->count(),
            'notCaptured' => $zreads->filter(fn (object $z) => (bool) $z->IsAllocated && ! (bool) $z->HasCashup
                && (float) $z->Sales !== 0.0)->count(),
            'shorts' => (float) $cashups->sum(fn (object $c) => (float) $c->StaffShorts),
        ];
    }

    /**
     * The tone of a variance chip: good inside a rand, warn inside the
     * tolerance, crit beyond it.
     */
    public function varianceTone(float|string|null $variance): string
    {
        $abs = abs((float) $variance);

        return match (true) {
            $variance === null => 'neutral',
            $abs < 1.0 => 'good',
            $abs <= $this->varianceTolerance() => 'warn',
            default => 'crit',
        };
    }

    public static function statusTone(?string $status): string
    {
        return match ($status) {
            'Closed' => 'good',
            'Day balanced' => 'good',
            'Confirmed' => 'neutral',
            'Open' => 'warn',
            default => 'neutral',
        };
    }

    public static function zreadTone(?string $check): string
    {
        return match ($check) {
            'Agrees' => 'good',
            'No Z-read', 'Z-read differs' => 'serious',
            'Other cashier' => 'warn',
            default => 'neutral',
        };
    }

    /**
     * @return array{BranchId: int|null, TransactionDate: string, TillNo: int|null, ShiftNo: int|null}
     */
    private function key(CashupScope $scope): array
    {
        return $scope->key();
    }
}
