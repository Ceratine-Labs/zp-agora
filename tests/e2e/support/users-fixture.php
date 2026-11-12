<?php

/**
 * TEST people for users.spec.js: one user administrator and three signed-in
 * subjects, all head office with no site granted. Local container only.
 *
 *   php tests/e2e/support/users-fixture.php prepare <12 hex>
 *   php tests/e2e/support/users-fixture.php cleanup <12 hex>
 *
 * The spec deactivates people, which is a write, so this refuses to run unless
 * AGORA_USERS_E2E=1, the configuration is not production and the app
 * connection (Agora's own database, the only one it touches) is loopback.
 */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\PermissionService;

if (PHP_SAPI !== 'cli' || getenv('AGORA_USERS_E2E') !== '1') {
    throw new RuntimeException('User fixtures require an explicit local CLI opt-in (AGORA_USERS_E2E=1).');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Users fixture refused: '.($error::class === RuntimeException::class ? $error->getMessage() : $error::class.': '.$error->getMessage())."\n");
    exit(1);
});
if (app()->isProduction() || app()->configurationIsCached()) {
    throw new RuntimeException('User fixtures refuse production or cached configuration.');
}
// Only the app connection: every row this writes or deletes is in Agora's own
// database. The legacy connections are only ever read, here and by the pages
// the spec opens.
$connection = config('agora.connections.app');
if (! is_string($connection) || ! in_array(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('The app connection must point to loopback.');
}
$token = $argv[2] ?? '';
if (! preg_match('/^[a-f0-9]{12}$/', $token)) {
    throw new RuntimeException('Expected a twelve-character fixture token.');
}

$db = DB::connection(config('agora.connections.app'));
$prefix = 'TEST-users-'.$token;
$people = ['admin' => ['setup.users.view', 'setup.users.edit'], 'one' => ['setup.users.view'],
    'two' => ['setup.users.view'], 'ho' => ['setup.users.view']];
$email = fn (string $who): string => $prefix.'-'.$who.'@agora.invalid';
$action = $argv[1] ?? '';

if ($action === 'prepare') {
    $out = ['prefix' => $prefix];
    $db->transaction(function () use ($db, $people, $email, $prefix, &$out): void {
        foreach ($people as $who => $codes) {
            if ($db->table('agora.User')->where('EmailAddress', $email($who))->exists()) {
                throw new RuntimeException('Fixture users already exist; refusing to overwrite them.');
            }
            $permissions = $db->table('agora.Permission')->whereIn('Code', $codes)->pluck('Id');
            if ($permissions->count() !== count($codes)) {
                throw new RuntimeException('User permissions must already be registered.');
            }
            $password = bin2hex(random_bytes(24));
            $user = User::query()->acrossBranches()->create([
                'BranchId' => 2, 'UserName' => $prefix.' '.$who, 'EmailAddress' => $email($who), 'PasswordHash' => $password,
                'IsActive' => true, 'IsLocked' => false, 'MustChangePassword' => false, 'Workspace' => 'ho',
                'HomeBranchId' => null, 'LandingRoute' => 'app.dashboard',
            ]);
            foreach ($permissions as $permission) {
                $db->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
            }
            // Deliberately no agora.UserBranch row: none granted means every site.
            $out[$who] = ['id' => (int) $user->Id, 'email' => $email($who), 'password' => $password];
        }
    });
    // What ShellComposer offers somebody with no grant: the trading estate.
    $out['estate'] = Branch::query()->acrossBranches()->visibleTo([])->ordered()->pluck('BranchId')->map(fn ($id) => (int) $id)->all();
    echo json_encode($out, JSON_THROW_ON_ERROR)."\n";
} elseif ($action === 'cleanup') {
    $removed = $db->transaction(function () use ($db, $people, $email, $prefix): array {
        $ids = User::query()->acrossBranches()->withTrashed()
            ->whereIn('EmailAddress', array_map($email, array_keys($people)))->pluck('Id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return ['users' => 0];
        }
        if (User::query()->acrossBranches()->withTrashed()->whereIn('Id', $ids)->where('UserName', 'not like', $prefix.'%')->exists()) {
            throw new RuntimeException('A fixture user was renamed; refusing cleanup.');
        }
        $removed = [];
        // Every agora table keyed by a person, so nothing they touched outlives them.
        $tables = array_column($db->select(
            "SELECT c.TABLE_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS c
             JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = 'agora' AND c.COLUMN_NAME = 'UserId' AND t.TABLE_TYPE = 'BASE TABLE'"
        ), 'name');
        foreach ($tables as $table) {
            if ($table === 'User') {
                continue;
            }
            $count = $db->table('agora.'.$table)->whereIn('UserId', $ids)->delete();
            if ($count > 0) {
                $removed[$table] = $count;
            }
        }
        foreach ($ids as $id) {
            app(PermissionService::class)->forget($id);
            app(MenuAccessService::class)->forget($id);
        }
        $removed['users'] = User::query()->acrossBranches()->withTrashed()->whereIn('Id', $ids)->forceDelete();
        $removed['users_remaining'] = $db->table('agora.User')->where('EmailAddress', 'like', $prefix.'%')->count();

        return $removed;
    });
    echo json_encode($removed, JSON_THROW_ON_ERROR)."\n";
} else {
    throw new RuntimeException('Expected prepare or cleanup.');
}
