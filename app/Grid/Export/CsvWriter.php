<?php

namespace App\Grid\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The extract as CSV, streamed.
 *
 * Streamed rather than built: a hundred thousand rows assembled into a string
 * before the first byte leaves is a memory ceiling and a request timeout, in
 * that order. `fputcsv` to `php://output` with a flush per thousand rows keeps
 * the process flat whatever the size of the answer.
 *
 * The byte-order mark is not decoration. Excel on Windows opens a UTF-8 CSV as
 * Windows-1252 unless it sees one, and the site names in this estate carry
 * characters that then arrive as mojibake — which reads as our bug, in a file
 * the customer is about to send to somebody else.
 */
final class CsvWriter
{
    public function stream(GridExtract $extract): StreamedResponse
    {
        $filename = $extract->filename('csv');

        return response()->stream(function () use ($extract) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, array_map(fn ($c) => $c->label, $extract->columns), ',', '"', '\\');

            $written = 0;

            foreach ($extract->rows as $row) {
                fputcsv(
                    $out,
                    array_map(fn ($c) => $extract->value($row, $c), $extract->columns),
                    ',',
                    '"',
                    '\\',
                );

                if (++$written % 1000 === 0) {
                    flush();
                }
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            // Nothing between here and the browser may buffer this, or the
            // stream is pointless.
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store',
        ]);
    }
}
