<?php

namespace Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Support\PasswordPolicy;

/**
 * Setting your own password, while signed in.
 *
 * Reached two ways: on purpose, and because `RequirePasswordChange` will not
 * let you anywhere else while `MustChangePassword` is set. The second is what
 * makes "passwords reset on first login" true of a credential somebody else
 * chose — an administrator setting a temporary one, or T008's user editor
 * doing the same.
 *
 * The current password is required even here. Being signed in is not proof of
 * being the account's owner: an unlocked laptop is the commonest way an ERP
 * account changes hands, and the whole value of this screen is that afterwards
 * only one person knows the credential.
 */
class ChangePasswordController extends Controller
{
    public function __construct(protected ActivityLogger $activity) {}

    public function edit(Request $request): View
    {
        return view('core::auth.change-password', [
            'forced' => (bool) $request->user()?->MustChangePassword,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => PasswordPolicy::rules(),
        ]);

        if (! Hash::check($data['current_password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        if (Hash::check($data['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => 'That is the password you already have.',
            ]);
        }

        $user->forceFill([
            'PasswordHash' => $data['password'],
            'MustChangePassword' => false,
            'PasswordChangedAt' => now(),
        ])->save();

        $this->activity->record($user, 'password-changed', 'Changed by the account holder.', $request);

        return redirect()->route($user->landingRoute())->with(
            'status',
            'Your password is changed.'
        );
    }
}
