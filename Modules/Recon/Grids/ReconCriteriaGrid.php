<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Gate;

/**
 * The extraction configuration — the Configuration tab of the area workbench.
 *
 * EVERY ROW CARRIES BOTH VALUES, and that is not decoration. Ryan's decision of
 * 8 September 2026 gives Agora an override table that shadows the customer's
 * `BRN_AutoReconCriteria`, which means the configuration now has two sources of
 * truth and ZP can still edit theirs in SSMS without telling us. A screen that
 * showed only what is in force would make that divergence invisible — so the
 * customer's own values are columns of their own, visible by default on any
 * row where they differ.
 *
 * `Source` is the column to read first: Customer · Overridden · Added by Agora
 * · Parked. "Added by Agora" is the one that matters most — twenty-four of
 * twenty-six branches have no rule at all for at least one area (finding 1),
 * and giving a site its first rule is a different act from correcting a wrong
 * one.
 */
class ReconCriteriaGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.recon.config';
    }

    public function title(): string
    {
        return 'Extraction configuration';
    }

    public function blurb(): ?string
    {
        return 'What every preview in this area resolves against, per site — and what the customer\'s own '
            .'BRN_AutoReconCriteria row says, beside it. Agora never writes to that table: a change here '
            .'is an override this application owns, and reverting it is switching it off.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName', link: true),
            new GridColumn(key: 'BankReconArea', label: 'Area', sort: 'BankReconArea'),
            new GridColumn(key: 'ProcessOrder', label: 'Order', format: 'number', sort: 'ProcessOrder'),
            new GridColumn(key: 'Source', label: 'In force', format: 'chip', sort: 'Source'),

            new GridColumn(key: 'BANK_StartPosition', label: 'Bank from', format: 'number', sort: 'BANK_StartPosition'),
            new GridColumn(key: 'BANK_EndPosition', label: 'Bank to/len', format: 'number'),
            // The number the previews actually use, spelled out — the column
            // itself does not say whether it is a length or an end position
            // (finding 9), and that ambiguity has cost real reconciliations.
            new GridColumn(key: 'ResolvedBankLen', label: 'Resolves to', format: 'number'),

            new GridColumn(key: 'FILTER_Value', label: 'Filter', sort: 'FILTER_Value'),
            new GridColumn(key: 'Health', label: 'Against real narratives', sort: 'Health', wide: true),

            // The customer's own values. Hidden by default because most rows
            // agree; a person checking a divergence turns them on and the
            // choice is remembered for them (§3.6).
            new GridColumn(key: 'LegacyBankStart', label: 'Theirs: bank from', format: 'number', visible: false),
            new GridColumn(key: 'LegacyBankEnd', label: 'Theirs: bank to/len', format: 'number', visible: false),
            new GridColumn(key: 'LegacyFilterValue', label: 'Theirs: filter', visible: false),
            new GridColumn(key: 'LegacyAutoReconId', label: 'Theirs: rule id', format: 'number', visible: false),

            new GridColumn(key: 'MOPS_StartPosition', label: 'Deposit from', format: 'number', visible: false),
            new GridColumn(key: 'MOPS_EndPosition', label: 'Deposit to/len', format: 'number', visible: false),
            new GridColumn(key: 'BANK_StartPosition2', label: 'Second from', format: 'number', visible: false),
            new GridColumn(key: 'BANK_EndPosition2', label: 'Second to/len', format: 'number', visible: false),

            new GridColumn(key: 'NarrativeMaxLen', label: 'Longest narrative', format: 'number', visible: false),
            new GridColumn(key: 'NarrativeLines', label: 'Lines checked', format: 'number', visible: false),

            new GridColumn(key: 'Reason', label: 'Why', wide: true, sort: 'Reason'),
            new GridColumn(key: 'ChangedBy', label: 'Changed by', sort: 'ChangedBy'),
            new GridColumn(key: 'ChangedAt', label: 'Changed', format: 'datetime', sort: 'ChangedAt'),
        ];
    }

    /**
     * Keyed by COLUMN — the contract the base class states, and what the header
     * row looks each column up by.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'BranchName' => new GridFilter(column: 'BranchName', type: 'text'),
            'BankReconArea' => new GridFilter(column: 'BankReconArea', type: 'set',
                options: array_keys((array) config('recon.areas'))),
            'Source' => new GridFilter(column: 'Source', type: 'set',
                options: ['Customer', 'Overridden', 'Added by Agora', 'Parked']),
            'FILTER_Value' => new GridFilter(column: 'FILTER_Value', type: 'text'),
            'Reason' => new GridFilter(column: 'Reason', type: 'text'),
            'ChangedBy' => new GridFilter(column: 'ChangedBy', type: 'text'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Recon_GridCriteria',
            acceptsFilters: true,
            extra: [
                'ReconArea' => $this->area(),
                'AllowedBranchIds' => self::allowedBranchIds(),
                // OFF by default: asking the customer's 249 GB statement table
                // how long its narratives are is the most useful thing this
                // screen can say and the most expensive. The screen asks.
                'WithNarrativeCheck' => (int) self::wantsNarrativeCheck(),
                'NarrativeDays' => 90,
            ],
        );
    }

    /** Site, then area, then the order the rules are tried in. */
    public function defaultSort(): ?string
    {
        return 'BranchName';
    }

    /** A rule belongs to a site, so head office picks which sites it is reading. */
    public function branchSelector(): bool
    {
        return true;
    }

    /**
     * A row opens its edit form.
     *
     * A modal rather than in-cell editing — Ryan, 8 September 2026: "showing a
     * modal instead is also fine". It keeps the grid framework read-and-export
     * only, and it is the only shape that can show the customer's row beside
     * the override while the person types.
     */
    public function rowUrl(object $row): ?string
    {
        return route('app.recon.config.edit', [
            'area' => $row->BankReconArea,
            'branch' => $row->BranchId,
            'order' => $row->ProcessOrder,
        ]);
    }

    /**
     * The row's action opens the editor in a dialog.
     *
     * `rowUrl()` above returns a real address and the site name is an anchor to
     * it, so a rule is a place you can send someone and the screen works with
     * no JavaScript at all. That address renders a PAGE — the controller gives
     * the bare fragment only to an XHR, which is what the dialog makes. Until
     * 8 September 2026 it gave the fragment to everyone, so following the link
     * landed on unstyled text with no way back.
     *
     * The action is what makes it a dialog when scripting is there.
     *
     * @return array<int, array{label: string, url: string, primary?: bool, attributes?: array<string, string>}>
     */
    public function rowActions(object $row): array
    {
        return [[
            'label' => Gate::allows('recon.criteria.edit') ? 'Change' : 'Open',
            'url' => $this->rowUrl($row) ?? '#',
            'primary' => ($row->Source ?? '') !== 'Customer',
            'attributes' => [
                'data-modal-open' => 'rule-editor',
                'data-modal-url' => $this->rowUrl($row) ?? '',
            ],
        ]];
    }

    /** Whether the caller asked for the narrative check on this request. */
    public static function wantsNarrativeCheck(): bool
    {
        return request()->query('check') === '1';
    }

    private function area(): ?string
    {
        $area = request()->query('area');

        return is_string($area) && array_key_exists($area, (array) config('recon.areas'))
            ? $area
            : null;
    }

    /** The branch grant as a CSV, or null where the grant is the whole estate. */
    private static function allowedBranchIds(): ?string
    {
        $allowed = app(BranchContext::class)->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
