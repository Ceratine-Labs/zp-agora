<?php

namespace Modules\Core\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\Export\CsvWriter;
use App\Grid\Export\GridExportTooLarge;
use App\Grid\Export\GridExtract;
use App\Grid\Export\XlsxWriter;
use App\Grid\GridNotFound;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The extract behind every grid.
 *
 * One endpoint for every grid in the system, because the whole view state
 * travels in the URL and is re-applied server-side (feature-rules §3.2): the
 * request that renders the page and the request that downloads it differ only
 * in the page size and the format. That is what makes the file be what the user
 * is looking at rather than something meant to match it.
 *
 * The columns come down in the URL too — `columns=Site,Status,Banked` — because
 * "their visible columns, in their order" is part of what they are looking at,
 * and the server does not otherwise know what the chooser has been doing since
 * the page loaded.
 *
 * A malformed state degrades. An unknown column is dropped, an unparseable date
 * is ignored, a sort the catalogue does not know falls back to the default —
 * the download still happens. The alternative is a user who cannot get their
 * data because of a character in a link somebody sent them.
 */
class GridExtractController extends Controller
{
    public function __construct(
        private GridRegistry $registry,
        private GridService $grids,
    ) {}

    public function __invoke(string $grid, Request $request): Response
    {
        try {
            $definition = $this->registry->findOrFail($grid);
        } catch (GridNotFound $e) {
            abort(404, $e->getMessage());
        }

        $query = $this->grids->query($definition, $request);
        $columns = $this->columns($request);

        try {
            $extract = GridExtract::run($definition, $query, $columns);
        } catch (GridExportTooLarge $e) {
            // 413 rather than a redirect: the browser asked for a file and this
            // is the answer to that question. The grid shows the same sentence
            // before the click, so this is the belt to that pair of braces.
            abort(413, $e->getMessage());
        } catch (AgoraProcException $e) {
            // A procedure that refuses is a message, not a 500 — the same rule
            // the screens follow.
            abort(422, $e->getMessage());
        }

        return $request->query('format') === 'xlsx'
            ? app(XlsxWriter::class)->download($extract)
            : app(CsvWriter::class)->stream($extract);
    }

    /**
     * The visible columns, in the user's order, as the screen sent them.
     *
     * @return array<int, string>|null
     */
    private function columns(Request $request): ?array
    {
        $given = $request->query('columns');

        if (! is_string($given) || trim($given) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $given))));
    }
}
