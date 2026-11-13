<?php

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The screens render, refuse an anonymous caller, and put on the page the two
 * things feature-rules insists a grid carries: which procedure produced the
 * rows (§3.4) and how much of the answer is on screen (§3.2, §C).
 *
 * Read-only — it signs in as the seeded administrator rather than creating one,
 * because the app connection can be the customer's instance and a test that
 * seeds users leaves rows behind when it fails.
 */
class ReportsScreenTest extends TestCase
{
    private function admin(): User
    {
        $user = User::query()->acrossBranches()
            ->where('EmailAddress', config('agora.admin.email'))
            ->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run php artisan seed:master first.');
        }

        // These tests are about the screens, not who may see them: since managed
        // roles (23 Oct 2026) a seeder creates each report's permission and
        // grants it to nobody, so a fresh database's administrator sees no
        // reports. Allow every ability in memory rather than granting rows
        // (this test is read-only).
        Gate::before(fn () => true);

        return $user;
    }

    public function test_an_anonymous_caller_cannot_read_a_report(): void
    {
        $this->get('/app/reports')->assertRedirect('/login');
        $this->get('/app/reports/staff-shorts')->assertRedirect('/login');
    }

    public function test_the_catalogue_lists_every_report_in_the_registry(): void
    {
        $response = $this->actingAs($this->admin())->get('/app/reports')->assertOk();

        foreach (config('reports.reports') as $report) {
            $response->assertSee($report['label']);
            // Rule 3.4: the customer reads the procedure name and goes straight
            // to the object they are allowed to change.
            $response->assertSee('agora.'.$report['procedure']);
        }
    }

    public function test_a_report_renders_its_columns_and_names_its_procedure(): void
    {
        $report = config('reports.reports.day-close');

        $response = $this->actingAs($this->admin())
            ->get('/app/reports/day-close?from=2026-09-03&to=2026-09-03')
            ->assertOk()
            ->assertSee($report['label'])
            ->assertSee('agora.'.$report['procedure']);

        foreach ($report['columns'] as $column) {
            $response->assertSee($column['label'], false);
        }
    }

    public function test_a_report_that_does_not_exist_is_a_404_not_a_500(): void
    {
        $this->actingAs($this->admin())->get('/app/reports/no-such-report')->assertNotFound();
    }

    public function test_a_procedures_refusal_is_rendered_as_a_message_not_a_crash(): void
    {
        /*
         * Overnight loads is a grid of branches by days by feeds and refuses
         * anything wider than 92 days — a THROW of AGORA:RANGE_TOO_WIDE. The
         * user should get the sentence, not a 500 and a stack trace.
         */
        $this->actingAs($this->admin())
            ->get('/app/reports/overnight-loads?from=2020-01-01&to=2026-09-03')
            ->assertOk()
            ->assertSee('92 days at a time');
    }

    public function test_the_caveat_on_waste_is_on_the_page_not_only_in_the_procedure(): void
    {
        // What waste counts against a shortage is a rule the clerk reads on the
        // screen, not one buried in the procedure. The wording is the config's
        // (it changed when waste authorization was built), so the test reads it
        // from there rather than pinning a sentence.
        $this->actingAs($this->admin())
            ->get('/app/reports/waste')
            ->assertOk()
            ->assertSee((string) config('reports.reports.waste.caveat'));
    }
}
