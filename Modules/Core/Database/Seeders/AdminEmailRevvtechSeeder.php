<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\User;

/**
 * Move the administrator to ryan@revvtech.co.za (Ryan, 6 September 2026).
 *
 * The address is the sign-in identity, so changing the default in
 * `config/agora.php` alone would have created a SECOND administrator on every
 * instance the old one already exists on: UserSeeder matches on email and
 * `firstOrNew` would simply not find the old row. Worse, the old row keeps
 * working, so nothing would look broken until somebody wondered why there were
 * two of him.
 *
 * A seeder rather than a migration because it changes a ROW, not a shape, and
 * the seed ledger gives one-shot semantics for free: `agora.SeedMaster` records
 * the class after a successful run and skips it forever after. There is no
 * version — changing what was seeded means writing another seeder, exactly as
 * it does for a migration.
 *
 * Deliberately narrow. It renames ONE known address and does nothing else — no
 * pattern match, no "any ceratine-labs address", because a rename that guesses
 * at its own scope is a rename nobody can predict the result of.
 */
class AdminEmailRevvtechSeeder extends Seeder
{
    /** After UserSeeder (40), which creates the row this renames. */
    public int $seedOrder = 41;

    private const WAS = 'ryan@ceratine-labs.co.za';

    private const NOW = 'ryan@revvtech.co.za';

    public function run(): void
    {
        $person = User::query()->acrossBranches()->where('EmailAddress', self::WAS)->first();

        if (! $person) {
            $this->command?->info('  Admin email: nothing at '.self::WAS.' — nothing to move.');

            return;
        }

        // (BranchId, EmailAddress) is a unique key. If the new address is
        // already taken the rename would throw, and a seeder that throws leaves
        // no ledger row and is retried forever — so this says what it found and
        // stops, which is a state a person can act on.
        $taken = User::query()->acrossBranches()
            ->where('EmailAddress', self::NOW)
            ->where('Id', '!=', $person->Id)
            ->first();

        if ($taken) {
            $this->command?->warn(
                '  Admin email: '.self::NOW." is already user #{$taken->Id} ({$taken->UserName}). "
                .'Left '.self::WAS.' alone — merge them by hand.'
            );

            return;
        }

        // saveQuietly: this is a data correction, not somebody editing their
        // profile, and it must not fire whatever a profile change comes to fire.
        $person->forceFill(['EmailAddress' => self::NOW])->saveQuietly();

        $this->command?->info('  Admin email: user #'.$person->Id.' moved from '.self::WAS.' to '.self::NOW.'.');
    }
}
