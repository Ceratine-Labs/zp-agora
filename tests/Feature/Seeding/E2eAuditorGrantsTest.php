<?php

namespace Tests\Feature\Seeding;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The browser suite's auditor can open every screen it is meant to.
 *
 * E2eFixtureSeeder grants `*.*.view` once, and the seed ledger never runs it
 * again — so a module added later brings view permissions the auditor does not
 * hold, and its browser specs fail with "This screen is not on your menu"
 * before they test anything. On 4 Oct 2026 that was 15 of 23 failures (Assets,
 * Utilities, Cash deductions). Ryan, 5 Oct: the auditor holds view on every
 * module, no create or edit.
 *
 * Read-only: it names the gap and the one command that closes it, rather than
 * re-seeding behind the reader's back.
 */
class E2eAuditorGrantsTest extends TestCase
{
    public function test_the_auditor_holds_every_view_permission(): void
    {
        $db = DB::connection(config('agora.connections.app'));

        $user = $db->selectOne('SELECT Id FROM agora.[User] WHERE EmailAddress = ?', [config('agora.e2e.email')]);

        if ($user === null) {
            $this->markTestSkipped('No E2E fixture user here — run the E2eFixtureSeeder.');
        }

        $missing = collect($db->select("
            SELECT p.Code FROM agora.Permission p
            WHERE (p.Code LIKE '%.view' OR p.Code LIKE '%.view.%')
              AND NOT EXISTS (SELECT 1 FROM agora.UserPermission up
                              WHERE up.UserId = ? AND up.PermissionId = p.Id)
            ORDER BY p.Code", [$user->Id]))->pluck('Code');

        $this->assertTrue($missing->isEmpty(),
            'The browser auditor lacks '.$missing->implode(', ').'. A module was added after the fixture ran. '
            .'Run: php artisan seed:master --forget=E2eFixtureSeeder && php artisan seed:master');

        $writes = collect($db->select("
            SELECT p.Code FROM agora.UserPermission up
            JOIN agora.Permission p ON p.Id = up.PermissionId
            WHERE up.UserId = ?
              AND p.Code NOT LIKE '%.view' AND p.Code NOT LIKE '%.view.%' AND p.Code NOT LIKE 'audit.%'", [$user->Id]))
            ->pluck('Code');

        $this->assertTrue($writes->isEmpty(), 'The browser auditor must not hold create or edit: '.$writes->implode(', '));
    }
}
