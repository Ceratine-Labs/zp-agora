<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use RuntimeException;

/**
 * The two rows the end-to-end suite needs, and nothing else.
 *
 * Agora's database is the customer's production database, so a browser suite
 * cannot be allowed to create whatever it likes. It gets exactly two fixtures,
 * both deliberately inert:
 *
 *  - **A user**, `TEST-` prefixed, on the read-only auditor role. No suite ever
 *    signs in as a real person, and the account it does use cannot approve,
 *    capture or post anything.
 *  - **A branch**, id 999, marked `IsTrading = false` AND `IsActive = false`.
 *    The scope bar only lists trading, active sites, so it never appears in
 *    the UI — but it gives a future write-path test somewhere safe to write
 *    that is not a real site's data.
 *
 * Three guards, because this seeder creates a working credential:
 *
 *  1. It refuses outright when APP_ENV is production.
 *  2. It does nothing at all unless AGORA_E2E_PASSWORD is set, so a checkout
 *     without the variable simply has no test account.
 *  3. It rejects a short password rather than creating a weak one.
 */
class E2eFixtureSeeder extends Seeder
{
    public const BRANCH_NAME = 'TEST-Playwright';

    /** Last: it is a fixture, not part of the system. */
    public int $seedOrder = 50;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'E2eFixtureSeeder refuses to run with APP_ENV=production. It creates a working sign-in credential.'
            );
        }

        $email = config('agora.e2e.email');
        $password = config('agora.e2e.password');

        if (! $email || ! $password) {
            $this->command?->warn('  E2E fixtures: AGORA_E2E_PASSWORD not set — skipped. The browser specs will skip too.');

            return;
        }

        if (strlen($password) < 16) {
            throw new RuntimeException('AGORA_E2E_PASSWORD is shorter than 16 characters. This account can sign in; give it a real password.');
        }

        $this->branch();
        $this->user($email, $password);
    }

    private function branch(): void
    {
        $branchId = (int) config('agora.e2e.branch_id');

        $branch = Branch::query()->acrossBranches()->firstOrNew(['BranchId' => $branchId]);
        $branch->fill([
            'Name' => self::BRANCH_NAME,
            'IsTrading' => false,
            // Inactive on purpose: the scope bar lists trading + active sites,
            // so this one is reachable by a test and invisible to a person.
            'IsActive' => false,
            'SortOrder' => 9999,
        ]);
        $branch->BranchId = $branchId;
        $branch->save();

        $this->command?->info("  E2E fixtures: branch {$branchId} (".self::BRANCH_NAME.', inactive).');
    }

    private function user(string $email, string $password): void
    {
        $role = Role::query()->acrossBranches()->where('Code', 'auditor')->firstOrFail();

        $user = User::query()->acrossBranches()->firstOrNew([
            'BranchId' => (int) config('agora.group_branch_id'),
            'EmailAddress' => $email,
        ]);

        $user->fill([
            'UserName' => 'TEST Playwright',
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
        ]);
        $user->BranchId = (int) config('agora.group_branch_id');

        // Re-seeding always resets this one's password, unlike a real user's —
        // the whole point is that it matches what is in .env right now.
        $user->PasswordHash = $password;
        $user->save();

        $this->command?->info("  E2E fixtures: user {$email} on the read-only auditor role.");
    }
}
