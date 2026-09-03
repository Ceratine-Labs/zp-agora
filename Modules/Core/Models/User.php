<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;

/**
 * An Agora user.
 *
 * The estate is PascalCase, so the auth contract is pointed at the real column
 * names rather than the table being bent to Laravel's defaults: the key is
 * `Id`, the password is `PasswordHash`, the remember token is `RememberToken`.
 *
 * `LegacyUserId` links back to dbo.SS_Users. The 88 legacy rows carry
 * `Password varchar(50)` — plaintext or a legacy hash, either way not
 * something to import as a credential. Migrating those users (T007) means
 * creating Agora rows and making everyone set a password, not copying that
 * column across.
 */
class User extends BaseModel implements AuthenticatableContract
{
    use Authorizable;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'User';

    public const DELETED_AT = 'DeletedAt';

    protected $hidden = ['PasswordHash', 'RememberToken'];

    protected $casts = [
        'IsActive' => 'boolean',
        'IsLocked' => 'boolean',
        'LastSignInAt' => 'datetime',
        'PasswordHash' => 'hashed',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'RoleId', 'Id');
    }

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
