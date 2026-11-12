<?php

/** Dedicated local TEST user and branch-999 override; never sends mail. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;

if (PHP_SAPI !== 'cli' || getenv('AGORA_EMAIL_TEMPLATE_E2E') !== '1') {
    throw new RuntimeException('Email fixtures require an explicit local CLI opt-in.');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'Email fixture refused: '.($error::class === RuntimeException::class ? $error->getMessage() : $error::class)."\n");
    exit(1);
});
if (app()->isProduction() || app()->configurationIsCached()) {
    throw new RuntimeException('Email fixtures refuse production or cached configuration.');
}
foreach (['app', 'erp', 'pos_landing', 'reporting'] as $role) {
    $connection = config('agora.connections.'.$role);
    if (! is_string($connection) || ! in_array(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Every fixture connection must point to loopback.');
    }
}
$token = $argv[2] ?? '';
if (! preg_match('/^[a-f0-9]{12}$/', $token)) {
    throw new RuntimeException('Expected a twelve-character fixture token.');
}
$db = DB::connection(config('agora.connections.app'));
$email = 'TEST-email-template-'.$token.'@agora.invalid';
$prefix = 'TEST-'.$token;
$action = $argv[1] ?? '';
if ($action === 'prepare') {
    $branch = $db->table('agora.Branch')->where('BranchId', 999)->first();
    if (! $branch || ! str_starts_with($branch->Name, 'TEST-') || $branch->IsActive || $branch->IsTrading) {
        throw new RuntimeException('Expected inactive local TEST branch 999.');
    }
    $password = bin2hex(random_bytes(24));
    $db->transaction(function () use ($db, $email, $password, $prefix): void {
        if ($db->table('agora.EmailTemplate')->where('BranchId', 999)->where('Code', 'core.welcome')->exists()
            || $db->table('agora.User')->where('EmailAddress', $email)->exists()) {
            throw new RuntimeException('Fixture keys already exist; refusing to overwrite them.');
        }
        $permissions = $db->table('agora.Permission')->whereIn('Code', ['email.templates.view', 'email.templates.edit'])->pluck('Id');
        if ($permissions->count() !== 2) {
            throw new RuntimeException('Email permissions must already be registered.');
        }
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'UserName' => $prefix.' email editor', 'EmailAddress' => $email, 'PasswordHash' => $password,
            'IsActive' => true, 'IsLocked' => false, 'MustChangePassword' => false, 'Workspace' => 'branch',
            'HomeBranchId' => 999, 'LandingRoute' => 'app.setup.email-templates.index',
        ]);
        foreach ($permissions as $permission) {
            $db->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        $db->table('agora.UserBranch')->insert(['BranchId' => 999, 'UserId' => $user->Id]);
    });
    echo json_encode(['email' => $email, 'password' => $password, 'prefix' => $prefix,
        'path' => '/app/setup/email-templates/999/core.welcome'], JSON_THROW_ON_ERROR)."\n";
} elseif ($action === 'cleanup') {
    $removed = $db->transaction(function () use ($db, $email, $prefix): array {
        $user = User::query()->acrossBranches()->where('EmailAddress', $email)->firstOrFail();
        $template = $db->table('agora.EmailTemplate')->where('BranchId', 999)->where('Code', 'core.welcome')->first();
        if ($template && (! str_starts_with($template->Subject, $prefix) || (int) $template->CreatedBy !== (int) $user->Id)) {
            throw new RuntimeException('Template ownership changed; refusing cleanup.');
        }
        $removed = [];
        if ($template) {
            $removed['versions'] = $db->table('agora.EmailTemplateVersion')->where('BranchId', 999)->where('TemplateId', $template->Id)->delete();
            $removed['templates'] = $db->table('agora.EmailTemplate')->where('BranchId', 999)->where('Id', $template->Id)->delete();
        }
        foreach (['UserActivity', 'UserPreference', 'UserPermission', 'UserBranch'] as $table) {
            $removed[$table] = $db->table('agora.'.$table)->where('UserId', $user->Id)->delete();
        }
        app(PermissionService::class)->forget($user);
        $user->forceDelete();
        $removed['users_remaining'] = $db->table('agora.User')->where('EmailAddress', $email)->count();
        $removed['overrides_remaining'] = $db->table('agora.EmailTemplate')->where('BranchId', 999)->where('Code', 'core.welcome')->count();

        return $removed;
    });
    echo json_encode($removed, JSON_THROW_ON_ERROR)."\n";
} else {
    throw new RuntimeException('Expected prepare or cleanup.');
}
