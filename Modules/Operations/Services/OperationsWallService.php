<?php

namespace Modules\Operations\Services;

use App\Support\BusinessDate;
use App\Support\Format;
use App\Support\ProcedureService;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use LogicException;

/** Presentation over the procedure's rules. Cached by explicit sites, never by an unscoped URL. */
class OperationsWallService
{
    public function __construct(private ProcedureService $procedures) {}

    /** @param array<int, int> $sites
     * @param  array<int, string>  $allowedSources
     * @return array<string, mixed>
     */
    public function snapshot(array $sites, array $allowedSources): array
    {
        sort($sites);
        $date = BusinessDate::today()->subDay()->toDateString();
        $payload = ['generatedAt' => now()->toIso8601String(), 'businessDate' => $date,
            'scope' => ['siteCount' => count($sites), 'label' => count($sites).' selected sites'], 'screens' => []];
        if ($sites === [] || $allowedSources === []) {
            return $payload;
        }

        $key = 'operations.wall.v2.'.$date.'.'.hash('sha256', implode(',', $sites));
        $store = Cache::store('file');
        if (! $store instanceof Repository || ! $store->getStore() instanceof LockProvider) {
            throw new LogicException('The Operations Wall file cache must support query locks.');
        }
        $load = function () use ($sites, $date): array {
            $rows = $this->procedures->call('agora.usp_Operations_Snapshot', [
                'BranchIds' => implode(',', $sites), 'AllowedBranchIds' => implode(',', $sites), 'TradeDate' => $date,
            ]);

            $extra = $this->procedures->call('agora.usp_Operations_TradingChecks', [
                'BranchIds' => implode(',', $sites), 'AllowedBranchIds' => implode(',', $sites), 'TradeDate' => $date,
            ]);
            $rows = $rows->concat($extra);

            // File-cache deserialization deliberately disallows stdClass.
            // Persist scalars only; construct presentation objects after read.
            return ['generatedAt' => now()->toIso8601String(),
                'rows' => $rows->map(fn (object $row): array => $this->scalarRow(get_object_vars($row)))->all()];
        };
        $raw = $store->get($key);
        if ($raw === null) {
            $raw = $store->getStore()->lock($key.'.lock', 45)->block(15,
                fn () => $store->remember($key, (int) config('operations.cache_seconds', 60), $load));
        }
        if (! is_array($raw) || ! is_array($raw['rows'] ?? null) || ! is_string($raw['generatedAt'] ?? null)) {
            throw new LogicException('The Operations Wall cached snapshot is invalid.');
        }
        $payload['generatedAt'] = $raw['generatedAt'];
        $cachedRows = collect($raw['rows'])->map(function (mixed $row): object {
            if (! is_array($row)) {
                throw new LogicException('The Operations Wall cached row is invalid.');
            }

            return (object) $this->scalarRow($row);
        });
        /** @var array<string, array{title:string,subtitle:string,sources:array<int,string>,reports:array<int,string>,gaps:array<int,string>}> $definitions */
        $definitions = config('operations.screens');
        foreach ($definitions as $id => $definition) {
            $sources = array_intersect($definition['sources'], $allowedSources);
            if ($sources === []) {
                continue;
            }
            $rows = $cachedRows->filter(fn (object $row): bool => in_array($row->SourceKey, $sources, true));
            $items = $rows->map(fn (object $row): array => $this->item($row, $date))->values()->all();
            $critical = $rows->where('Severity', 'crit')->count();
            $warnings = $rows->where('Severity', 'warn')->count();
            $missing = $rows->filter(fn (object $row): bool => $row->Value === null)->count();
            $latest = $rows->pluck('SourceUpdatedAt')->filter()->sort()->last();
            $observed = $rows->contains(fn (object $row): bool => ! str_starts_with($row->SourceKey, 'budget-') && $row->Value !== null);
            $payload['screens'][] = [
                'id' => $id, 'title' => $definition['title'], 'subtitle' => $definition['subtitle'],
                'updatedAt' => $latest ? Carbon::parse((string) $latest, BusinessDate::ZONE)->toIso8601String() : null,
                'sourceLabel' => 'PumpIT / POS / budget sources via Agora',
                'status' => $observed ? 'live' : 'unavailable',
                'message' => 'Trading date '.$date.'. Critical-line stock is the current recorded POS snapshot with no refresh timestamp. Missing sources are not an all-clear.',
                'metrics' => [
                    ['label' => 'Critical signals', 'value' => (string) $critical, 'tone' => $critical ? 'crit' : 'neutral'],
                    ['label' => 'Watch signals', 'value' => (string) $warnings, 'tone' => $warnings ? 'warn' : 'neutral'],
                    ['label' => 'Missing readings', 'value' => (string) $missing, 'tone' => 'neutral'],
                    ['label' => 'Sites in scope', 'value' => (string) count($sites), 'tone' => 'neutral'],
                ],
                'items' => $items, 'gaps' => $definition['gaps'],
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, bool|float|int|string|null>
     */
    private function scalarRow(array $row): array
    {
        $values = [];
        foreach ($row as $key => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new LogicException('The Operations Wall row contains a non-scalar value.');
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function item(object $row, string $date): array
    {
        $source = (string) $row->SourceKey;
        $metric = (string) $row->MetricKey;
        $slug = match ($source) {
            'fuel' => 'fuel-input', 'meters' => 'pump-readings',
            'loads' => 'stock-count', default => 'day-close',
        };
        $query = ['branches' => [(int) $row->BranchId], 'from' => $date, 'to' => $date];
        if ($source === 'stock') {
            $query['f'] = ['RemainingVariance' => ['q' => '0', 'op' => 'lt']];
            if ($row->ItemCode) {
                $query['f']['StockItemNo'] = ['q' => (string) $row->ItemCode, 'eq' => '1'];
                if (isset($row->StockLocation) && $row->StockLocation !== '') {
                    $query['f']['StockLocation'] = ['q' => (string) $row->StockLocation, 'eq' => '1'];
                }
            }
            $url = route('app.stockrecon.variance', $query);
        } elseif ($source === 'cash') {
            $url = route('app.cash.banking', ['branch_id' => (int) $row->BranchId, 'date' => $date]);
        } elseif ($source === 'critical') {
            $url = route('app.master.critical.index', ['branches' => [(int) $row->BranchId], 'f' => [
                'PosSystem' => ['in' => [(string) $row->Area]],
                'PosCode' => ['q' => (string) $row->ItemCode, 'eq' => '1'],
            ]]);
        } elseif (str_starts_with($source, 'budget-')) {
            $url = null;
        } else {
            $url = route('app.reports.show', ['report' => $slug] + $query);
        }
        $facts = [
            ['label' => $source === 'critical' ? 'Observation period' : 'Trading date',
                'value' => $source === 'critical' ? 'Current recorded POS snapshot; source date unknown' : $date],
            ['label' => 'Source', 'value' => $source === 'critical' || str_starts_with($source, 'budget-')
                ? 'agora.usp_Operations_TradingChecks' : 'agora.usp_Operations_Snapshot'],
            ['label' => 'Source captured (SAST)', 'value' => $row->SourceUpdatedAt
                ? Carbon::parse((string) $row->SourceUpdatedAt)->format('d M H:i') : 'Not available'],
            ['label' => 'Source records', 'value' => (string) $row->SourceCount],
        ];
        if ($row->SecondaryValue !== null) {
            [$label, $unit] = match ($metric) {
                'tank-variance' => ['Closing dip', 'litres'],
                'tank-cover' => ['Seven-day average draw (per day)', 'litres'],
                'critical-pricing' => ['POS sell price (excl VAT)', 'rand'],
                'critical-stock' => ['Low-stock threshold (one pack)', 'quantity'],
                'stock-loss-item' => ['Loss rank at this site', 'count'],
                'stock-summary' => ['Overs (separate from losses)', 'rand'],
                'turnover' => ['Previous four matching weekdays · average', 'rand'],
                'cashups-open' => ['Captured cash-ups', 'count'],
                'cashup-variance' => ['Overs (separate from shorts)', 'rand'],
                default => ['Supporting measure', (string) $row->Unit],
            };
            $facts[] = ['label' => $label, 'value' => $this->number($row->SecondaryValue, $unit)];
        }
        if ($row->ItemCode) {
            $facts[] = ['label' => 'Item / source code', 'value' => (string) $row->ItemCode];
        }
        if ($row->Area) {
            $facts[] = ['label' => 'Area', 'value' => (string) $row->Area];
        }

        return [
            'id' => $source.'-'.$metric.'-'.$row->BranchId.'-'.($row->ItemCode ?? '').'-'.($row->Area ?? '').'-'.md5((string) $row->Title),
            'title' => (string) $row->Title, 'site' => (string) $row->BranchName,
            'severity' => (string) $row->Severity, 'value' => $this->number($row->Value, (string) $row->Unit),
            'isZero' => is_numeric($row->Value) && (float) $row->Value === 0.0,
            'detail' => (string) $row->Detail, 'href' => $url, 'facts' => $facts,
        ];
    }

    private function number(mixed $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($unit) {
            'rand' => Format::r($value),
            'litres' => Format::n($value, 1).' L',
            'days' => Format::n($value, 1).' days',
            'percent' => Format::n($value, 1).'%',
            'quantity' => Format::n($value, 3),
            default => Format::n($value, 0),
        };
    }
}
