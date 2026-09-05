<?php

namespace Modules\Core\Services;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Models\UserActivity;

/**
 * Writes `agora.UserActivity`, through the procedure and never around it.
 *
 * The rule that a sign-in also moves `User.LastSignInAt` lives in
 * `agora.usp_Core_LogActivity`, so this class holds no rule at all — it turns
 * a Request into the seven arguments the procedure takes and gets out of the
 * way. Plan §3.4: if the rule existed here as well, one of the two would be
 * the bug.
 *
 * Two entry points, and the difference is deliberate:
 *
 *  - `record()` throws. A sign-in that cannot be recorded on an ERP is a
 *    defect, and a silent one is worse than a loud one.
 *  - `recordQuietly()` does not. Sign-OUT uses it: a person asking to leave
 *    must leave, even if their user row has been deleted underneath their
 *    session and the procedure quite correctly refuses to log against it.
 */
class ActivityLogger
{
    public function __construct(protected ProcedureService $procedures) {}

    public function signIn(User $user, Request $request): void
    {
        $this->record($user, UserActivity::SIGN_IN, null, $request);
    }

    public function signOut(User $user, Request $request): void
    {
        $this->recordQuietly($user, UserActivity::SIGN_OUT, null, $request);
    }

    public function passwordReset(User $user, Request $request): void
    {
        $this->record($user, UserActivity::PASSWORD_RESET, 'Password set through the forgot-password link.', $request);
    }

    /**
     * A refused attempt against a KNOWN account. An unknown address writes
     * nothing at all — there is no user to attribute it to, and a table of
     * addresses somebody tried is a list worth stealing rather than an audit
     * trail.
     */
    public function signInRefused(User $user, string $why, Request $request): void
    {
        $this->recordQuietly($user, UserActivity::SIGN_IN_REFUSED, $why, $request);
    }

    public function record(User $user, string $activity, ?string $detail, ?Request $request = null): void
    {
        $this->procedures->write('usp_Core_LogActivity', [
            // The user's OWN branch, not the branch they happen to be looking
            // at: an activity row is about a person, and a head-office user's
            // row carries the group entity id.
            'BranchId' => (int) $user->BranchId,
            'UserId' => (int) $user->Id,
            'Activity' => $activity,
            'Detail' => $detail,
            'IpAddress' => $request?->ip(),
            'UserAgent' => $request ? substr((string) $request->userAgent(), 0, 300) : null,
            'OccurredAt' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function recordQuietly(User $user, string $activity, ?string $detail, ?Request $request = null): void
    {
        try {
            $this->record($user, $activity, $detail, $request);
        } catch (AgoraProcException|QueryException $e) {
            Log::warning('agora.activity.failed', [
                'activity' => $activity,
                'user' => $user->Id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
