<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GlobalOverviewExport
{
    /** @param Collection<int, object> $rows */
    public function download(Collection $rows, string $kind, int $runId, string $basis): StreamedResponse
    {
        abort_if($rows->count() > 100000, 422, 'The export exceeds 100,000 rows. Use a smaller run.');
        $columns = match ($kind) {
            'chains' => ['PreProductionTypeNo', 'AreaNo', 'StockItemNo', 'ItemDescription', 'UOMCode', 'Ratio', 'OriginalVariance', 'BalancedVariance', 'Residual', 'Held', 'CandidateCount'],
            'pairs' => ['OverArea', 'OverItemNo', 'OverItem', 'OverQuantity', 'ShortArea', 'ShortItemNo', 'ShortItem', 'ShortQuantity', 'UOMCode', 'LinkReason', 'Difference'],
            'production' => ['TransactionDate', 'ShiftNo', 'StockItemNo', 'PosSystem', 'BaseCode', 'TrackingNumber', 'TotalQuantity'],
            default => throw new \InvalidArgumentException('Unknown global export kind.'),
        };

        return response()->streamDownload(function () use ($rows, $columns, $basis) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Basis', $basis, 'Raw quantities; ratios not applied. Candidates are not confirmed corrections.'], ',', '"', '');
            fputcsv($out, $columns, ',', '"', '');
            foreach ($rows as $row) {
                $values = array_map(function (string $column) use ($row): string {
                    $value = (string) ($row->{$column} ?? '');

                    // Keep labels inert when opened by a spreadsheet program.
                    return preg_match('/^[\s]*[=+@-]/u', $value) && ! is_numeric($value) ? "'".$value : $value;
                }, $columns);
                fputcsv($out, $values, ',', '"', '');
            }
            fclose($out);
        }, 'stock-recon-'.$runId.'-'.$basis.'-'.$kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
