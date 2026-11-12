<?php

namespace Modules\Reports\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Gate;

/**
 * Setup → Governance → User activity (T027).
 *
 * agora.UserActivity — sign-ins, sign-outs, refused sign-ins and password
 * resets and changes — through agora.usp_Reports_GridUserActivity, which
 * filters, sorts and pages, and refuses without the view permission. A row
 * names the person; it opens their account on Users and access for someone
 * who may see it.
 */
class UserActivityGrid extends GridDefinition
{
    public const PERMISSION = 'reports.user-activity.view';

    public const ACTIVITIES = ['Signed in', 'Signed out', 'Sign-in refused', 'Password reset', 'Password changed'];

    public function __construct(protected BranchContext $context) {}

    public function key(): string
    {
        return 'app.setup.user-activity';
    }

    public function title(): string
    {
        return 'User activity';
    }

    public function blurb(): ?string
    {
        return 'Who signed in, out or was refused, and every password reset or change, with where from. '
            .'Times are SAST. The last 30 days unless you choose dates.';
    }

    public function defaultSort(): ?string
    {
        return 'OccurredAt';
    }

    public function defaultDirection(): string
    {
        return 'desc';
    }

    public function dateRange(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new GridColumn('OccurredAt', 'When', format: 'datetime', sort: 'OccurredAt', title: 'SAST'),
            new GridColumn('UserName', 'Who', sort: 'UserName', link: true),
            new GridColumn('EmailAddress', 'Email', sort: 'EmailAddress'),
            new GridColumn('Activity', 'Activity', format: 'chip', sort: 'Activity', options: self::ACTIVITIES),
            new GridColumn('Detail', 'Detail', wide: true),
            new GridColumn('IpAddress', 'From', sort: 'IpAddress', mono: true),
            new GridColumn('BranchName', 'Site', sort: 'BranchName', title: 'The person\'s own branch'),
            new GridColumn('UserAgent', 'Browser', wide: true, visible: false),
            new GridColumn('UserId', 'User id', format: 'number', visible: false),
        ];
    }

    public function source(): GridSource
    {
        $allowed = $this->context->allowed();

        return new ProcedureSource('agora.usp_Reports_GridUserActivity', acceptsFilters: true, extra: [
            'Authorized' => (int) Gate::allows(self::PERMISSION),
            'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
        ]);
    }

    /** The person's account, where the viewer may open it (feature-rules §3.7). */
    public function rowUrl(object $row): ?string
    {
        return isset($row->UserId) && Gate::allows('setup.users.view')
            ? route('app.setup.users.show', ['user' => (int) $row->UserId])
            : null;
    }
}
