<?php

namespace Tests\Feature\Auth;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * `agora.usp_Core_MigrateUsers`, against the local PumpIT stub.
 *
 * DRY RUN ONLY — every call here passes Apply = 0, so nothing is written on
 * either side and there is nothing for tearDown to clean up. That is not
 * squeamishness: this procedure's whole job is to be run against the
 * customer's 85 live users by somebody who has read its report first, and a
 * test suite that gets into the habit of applying it is the wrong habit.
 *
 * The eleven fixtures in database/stubs/pumpit-users.sql exist for exactly
 * these assertions: a duplicate address, a missing one, a malformed one, a
 * locked user and an unmapped user type. If they are absent — a checkout
 * pointed at the customer's instance, where SS_Users holds real people — the
 * class skips rather than asserting things about somebody's staff.
 */
class MigrateUsersTest extends TestCase
{
    private ProcedureService $procedures;

    /** @var Collection<int, object> */
    private Collection $report;

    private object $summary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->procedures = app(ProcedureService::class);

        $sets = $this->procedures->callSets('usp_Core_MigrateUsers', [
            'BranchId' => (int) config('agora.group_branch_id'),
            'Apply' => false,
        ]);

        $this->report = $sets[0] ?? collect();
        $summary = ($sets[1] ?? collect())->first();

        if (! $summary) {
            $this->fail('The procedure returned no summary row.');
        }

        $this->summary = $summary;

        if ($this->report->firstWhere('LegacyUserId', 905) === null) {
            $this->markTestSkipped(
                'The TEST- fixtures are not in [PumpIT].dbo.SS_Users — run scripts/local-sql.sh up.'
            );
        }
    }

    public function test_a_row_with_no_email_is_reported_rather_than_invented(): void
    {
        // Two of them: one NULL and one that is whitespace. The view trims, so
        // both must arrive as the same case rather than as two.
        foreach ([907, 908] as $legacyId) {
            $row = $this->report->firstWhere('LegacyUserId', $legacyId);

            $this->assertSame('skip', $row->Action);
            $this->assertSame('no-email', $row->ReasonCode);
            $this->assertNull($row->EmailAddress);
        }
    }

    public function test_an_address_with_no_at_sign_is_a_separate_reason(): void
    {
        $row = $this->report->firstWhere('LegacyUserId', 909);

        $this->assertSame('skip', $row->Action);
        $this->assertSame('bad-email', $row->ReasonCode);
    }

    public function test_a_duplicate_address_keeps_the_lowest_autoidx_and_reports_the_other(): void
    {
        $kept = $this->report->firstWhere('LegacyUserId', 905);
        $dropped = $this->report->firstWhere('LegacyUserId', 906);

        $this->assertNotSame('skip', $kept->Action, 'The lower Autoidx is the one that comes across.');

        $this->assertSame('skip', $dropped->Action);
        $this->assertSame('duplicate-email', $dropped->ReasonCode);
        $this->assertSame(2, (int) $dropped->DuplicateCount);

        // The pair differ only in case. agora.User is unique on
        // (BranchId, EmailAddress) under a case-insensitive collation, so they
        // ARE one address and the report has to treat them as one.
        $this->assertSame($kept->EmailAddress, $dropped->EmailAddress);
    }

    public function test_a_locked_legacy_user_arrives_inactive(): void
    {
        $row = $this->report->firstWhere('LegacyUserId', 910);

        $this->assertSame(0, (int) $row->IsActive);
        $this->assertSame(1, (int) $row->IsLocked);
    }

    public function test_head_office_and_branch_come_from_grant_count_not_from_the_type_text(): void
    {
        // 903 is a "Branch Manager" with one grant and 910 is a "Branch
        // Manager" with four. Under the old rule both matched '%branch%' and
        // both were called branch users. Only the first one is.
        $site = $this->report->firstWhere('LegacyUserId', 903);
        $office = $this->report->firstWhere('LegacyUserId', 910);

        $this->assertSame('Branch Manager', $site->LegacyUserType);
        $this->assertSame('Branch Manager', $office->LegacyUserType);

        $this->assertSame('branch', $site->UserType, 'One grant is a site.');
        $this->assertSame('ho', $office->UserType, 'Four grants is head office, whatever the type is called.');
    }

    public function test_an_unmapped_user_type_is_kept_and_flagged_rather_than_dropped(): void
    {
        // The LEFT JOIN is the point: an INNER JOIN would silently lose this
        // person, which is the exact failure the report exists to prevent.
        $row = $this->report->firstWhere('LegacyUserId', 911);

        $this->assertNotNull($row, 'A user whose UserTypeId matches nothing must still appear.');
        $this->assertNull($row->LegacyUserType);
        $this->assertSame(1, (int) $this->summary->UnmappedUserType);

        // UserType is NOT derived from the legacy type text any more, so an
        // unmapped type says nothing about where a person works. 911 holds
        // exactly one branch grant, and one grant is a site — see
        // v1__01c_core_legacy_user_type for why the old rule could never have
        // worked: SS_UserType is a privilege level and contains neither the
        // word "branch" nor "site", so every user came out as head office.
        $this->assertSame('branch', $row->UserType, 'One branch grant is a site, whatever the legacy type says.');
        $this->assertSame(1, (int) $row->BranchGrantCount);
    }

    public function test_the_summary_counts_agree_with_the_report(): void
    {
        $this->assertSame($this->report->count(), (int) $this->summary->Considered);
        $this->assertSame(3, (int) $this->summary->Unmatched, 'Two with no address, one with no @.');
        $this->assertSame(1, (int) $this->summary->Duplicate);
        $this->assertSame(0, (int) $this->summary->Applied);
    }

    public function test_the_legacy_password_column_is_never_selected(): void
    {
        // The report is what a person reads and what the command prints. If
        // dbo.SS_Users.Password ever leaked into it, it would leak onto a
        // terminal and into a screenshot.
        $columns = array_keys((array) $this->report->first());

        $this->assertNotContains('Password', $columns);
        $this->assertNotContains('PasswordHash', $columns);
    }

    public function test_an_unknown_branch_is_refused_with_a_code(): void
    {
        try {
            $this->procedures->callSets('usp_Core_MigrateUsers', [
                'BranchId' => 999999,
                'Apply' => false,
            ]);
            $this->fail('A branch that does not exist must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CORE_UNKNOWN_BRANCH', $e->code());
        }
    }
}
