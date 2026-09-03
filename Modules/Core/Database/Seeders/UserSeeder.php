<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

/**
 * The first user, so there is a way in.
 *
 * The password comes from AGORA_ADMIN_PASSWORD if it is set; otherwise one is
 * generated and printed once. It is never written to a file and never
 * defaulted to something guessable — this account is an administrator on a
 * system sitting on the customer's production database.
 *
 * The 88 rows in dbo.SS_Users are NOT imported here. That table stores
 * `Password varchar(50)`, which is not a credential worth carrying forward.
 * Migrating those users (T007) means creating Agora rows and making everyone
 * set a password.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('AGORA_ADMIN_EMAIL', 'ryan@ceratine-labs.co.za');
        $password = env('AGORA_ADMIN_PASSWORD') ?: Str::password(16, symbols: false);
        $generated = ! env('AGORA_ADMIN_PASSWORD');

        $role = Role::query()->acrossBranches()->where('Code', 'admin')->firstOrFail();

        $user = User::query()->acrossBranches()->firstOrNew([
            'BranchId' => (int) config('agora.group_branch_id'),
            'EmailAddress' => $email,
        ]);

        $existed = $user->exists;

        $user->fill([
            'UserName' => env('AGORA_ADMIN_NAME', 'Ryan Cruickshank'),
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
        ]);
        $user->BranchId = (int) config('agora.group_branch_id');

        // An existing user's password is left alone — re-running a seeder must
        // not silently reset someone's credentials.
        if (! $existed) {
            $user->PasswordHash = $password;
        }

        $user->save();

        if ($existed) {
            $this->command?->info("  User: {$email} already exists — password left unchanged.");

            return;
        }

        $this->command?->info("  User: {$email} created as System administrator.");

        if ($generated) {
            $this->command?->newLine();
            $this->command?->warn("  Generated password (shown once): {$password}");
            $this->command?->newLine();
        }
    }
}
