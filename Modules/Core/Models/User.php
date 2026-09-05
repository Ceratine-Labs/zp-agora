<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * An Agora user.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $UserName
 * @property string $EmailAddress
 * @property string $PasswordHash
 * @property int|null $RoleId
 * @property int|null $HomeBranchId
 * @property bool $IsActive
 * @property bool $IsLocked
 * @property Carbon|null $LastSignInAt
 * @property string|null $RememberToken
 * @property int|null $LegacyUserId
 * @property string|null $UserCode
 * @property string|null $UserType
 * @property string|null $LegacyUserType
 * @property bool $MustChangePassword
 * @property Carbon|null $PasswordChangedAt
 * @property-read Role|null $role
 * @property-read Branch|null $homeBranch
 *
 * The estate is PascalCase, so the auth contract is pointed at the real column
 * names rather than the table being bent to Laravel's defaults: the key is
 * `Id`, the password is `PasswordHash`, the remember token is `RememberToken`.
 *
 * `LegacyUserId` links back to dbo.SS_Users. Those 85 legacy rows carry
 * `Password varchar(50)` — plaintext or a legacy hash, either way not
 * something to import as a credential. `agora.usp_Core_MigrateUsers` therefore
 * creates Agora rows and makes everyone set a password: it never selects that
 * column, and every user it writes arrives with PasswordHash =
 * self::UNUSABLE_PASSWORD and MustChangePassword set.
 */
class User extends BaseModel implements AuthenticatableContract
{
    use Authorizable;
    use Notifiable;
    use SoftDeletes;

    /**
     * What a migrated user's PasswordHash is set to.
     *
     * Not a hash of anything. No bcrypt digest can equal it, so
     * `password_verify()` refuses every attempt — which is what makes
     * "passwords reset on first login" a property of the data rather than a
     * rule somebody has to remember to apply.
     */
    public const UNUSABLE_PASSWORD = '!reset-required';

    protected $table = 'User';

    public const DELETED_AT = 'DeletedAt';

    protected $hidden = ['PasswordHash', 'RememberToken'];

    protected $casts = [
        'IsActive' => 'boolean',
        'IsLocked' => 'boolean',
        'LastSignInAt' => 'datetime',
        'PasswordChangedAt' => 'datetime',
        'MustChangePassword' => 'boolean',
        'PasswordHash' => 'hashed',
    ];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'RoleId', 'Id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'HomeBranchId', 'BranchId');
    }

    /**
     * Branch ids this user may see. An empty result means every branch —
     * head office users are granted nothing individually rather than being
     * granted all 31 rows, which would have to be maintained as sites open.
     *
     * @return array<int, int>
     */
    public function allowedBranchIds(): array
    {
        return UserBranch::query()
            ->acrossBranches()
            ->where('UserId', $this->Id)
            ->pluck('BranchId')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canSignIn(): bool
    {
        return $this->IsActive && ! $this->IsLocked;
    }

    /**
     * True when this account has no password anyone could use.
     *
     * Read off the stored value rather than off MustChangePassword: the flag
     * is a policy and the sentinel is a fact, and it is the fact that decides
     * whether "Forgot your password?" is the only way in.
     */
    public function hasUnusablePassword(): bool
    {
        return ($this->attributes['PasswordHash'] ?? null) === self::UNUSABLE_PASSWORD;
    }

    /**
     * Put this account beyond signing in until somebody resets it.
     *
     * Written straight into the attribute bag, PAST the `hashed` cast, and
     * that is the whole reason this method exists. The cast hashes anything
     * that is not already a hash — so `$user->PasswordHash =
     * User::UNUSABLE_PASSWORD` would store bcrypt('!reset-required') and the
     * literal string '!reset-required' would then BE the password. A sentinel
     * that can be typed is not a sentinel.
     *
     * usp_Core_MigrateUsers writes the same value in T-SQL, where no cast can
     * reach it; this is the PHP-side equivalent for T008's user editor.
     */
    public function makePasswordUnusable(): self
    {
        $this->attributes['PasswordHash'] = self::UNUSABLE_PASSWORD;
        $this->attributes['MustChangePassword'] = true;

        return $this;
    }

    /**
     * Where this person lands after signing in.
     *
     * The route is on the ROLE row, so "where does Finance land" is data the
     * business changes without a deploy. A route that does not exist yet — and
     * most of them do not, since the epics behind them are unbuilt — falls back
     * to the dashboard rather than 500ing on the one page everybody uses.
     */
    public function landingRoute(): string
    {
        $route = $this->role?->LandingRoute;

        return $route && Route::has($route) ? $route : 'app.dashboard';
    }

    // ---- Authenticatable, pointed at the estate's column names -------------

    public function getAuthIdentifierName(): string
    {
        return 'Id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getAttribute('Id');
    }

    public function getAuthPasswordName(): string
    {
        return 'PasswordHash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('PasswordHash');
    }

    public function getRememberToken(): ?string
    {
        return $this->getAttribute('RememberToken');
    }

    public function setRememberToken($value): void
    {
        $this->setAttribute('RememberToken', $value);
    }

    public function getRememberTokenName(): string
    {
        return 'RememberToken';
    }
}
