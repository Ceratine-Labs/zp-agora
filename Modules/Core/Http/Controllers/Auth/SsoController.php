<?php

namespace Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\User;
use Modules\Core\Support\Sso;
use Modules\Core\Support\SsoTicket;

/**
 * The Agora end of the cross-app handshake with ZP-NQL.
 *
 * The mirror of App\Http\Controllers\Auth\SsoController in the ZP repo, and
 * deliberately the same shape: both apps issue tickets and both redeem them,
 * so whichever one a person opens first signs them into the other and neither
 * is "the login app".
 *
 * Agora's half is the simpler of the two, because the ticket's subject IS an
 * Agora user id. There is no mapping to do here — emit names this user's `Id`
 * and accept looks one up by primary key. The single link column lives on the
 * ZP side; see the note on SsoTicket.
 *
 * `directory` is the one endpoint with no counterpart over there: it exists so
 * `php artisan sso:link` on ZP can propose matches instead of somebody typing
 * 85 integers.
 */
class SsoController extends Controller
{
    /**
     * ZP is asking whether this browser has a session here.
     *
     * A signed-out visitor is answered, not redirected to a login page: the
     * person is sitting in front of ZP, and bouncing them here to sign in is
     * not what they asked for. The peer needs the "no" so it can stop asking.
     */
    public function emit(Request $request): RedirectResponse
    {
        $return = $this->verifiedReturn($request);

        if ($return === null) {
            return redirect('/');
        }

        $user = Auth::user();

        // canSignIn() covers IsActive and IsLocked — the same two flags the
        // password form enforces. A locked account must not slip in through a
        // side door that only checks for a session.
        if (! $user instanceof User || ! $user->canSignIn()) {
            return redirect()->away($this->answer($return, ['sso' => 'none']));
        }

        return redirect()->away($this->answer($return, [
            'ticket' => SsoTicket::mint((int) $user->Id, (string) config('sso.peer.audience')),
        ]));
    }

    /** ZP's answer, coming back with the visitor. */
    public function accept(Request $request): RedirectResponse
    {
        $next = Sso::safeNext($request->query('next'));

        if (Auth::check()) {
            // A session appeared while the handshake was in flight — a second
            // tab. Redeeming now would sign the person in as whoever the
            // ticket names, who need not be who is already here.
            return redirect()->to($next);
        }

        $agoraUserId = SsoTicket::redeem($request->query('ticket'));

        if ($agoraUserId === null) {
            return redirect()->to($next)->withCookie(Sso::guardCookie());
        }

        $user = User::query()->acrossBranches()->find($agoraUserId);

        if (! $user instanceof User || ! $user->canSignIn()) {
            return redirect()->to($next)->withCookie(Sso::guardCookie());
        }

        // MustChangePassword is deliberately NOT a refusal here. It is a
        // policy the app already enforces on its own — the middleware
        // redirects to password/change — and refusing at the door instead
        // would leave the person unable to reach the page that fixes it.
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->to($next)->withCookie(Sso::forgetGuardCookie());
    }

    /** ZP signed out, so this app does too. */
    public function logout(Request $request): RedirectResponse
    {
        $return = $this->verifiedReturn($request);

        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $response = $return === null
            ? redirect()->route('login')
            : redirect()->away($return);

        return $response->withCookie(Sso::guardCookie());
    }

    /**
     * Every account that could hold a link, for ZP's `sso:link` command.
     *
     * Signed with the shared secret and nothing else — there is no session on
     * a CLI call. That makes the secret the only thing standing between this
     * URL and a list of the customer's staff addresses, which is why the
     * response carries exactly three fields: an id to link, an address to
     * match on, and a name to show in the proposal. No role, no branch
     * grants, no flags beyond "could this person sign in at all", and
     * emphatically no password state.
     */
    public function directory(Request $request): JsonResponse
    {
        if (! SsoTicket::checkParams($request->query())) {
            // 404 rather than 403: an endpoint that says "wrong signature" is
            // an endpoint that has confirmed it exists and is worth attacking.
            abort(404);
        }

        $users = User::query()
            ->acrossBranches()
            ->where('IsActive', true)
            ->where('IsLocked', false)
            ->orderBy('EmailAddress')
            ->get(['Id', 'UserName', 'EmailAddress'])
            ->map(fn (User $u) => [
                'id' => (int) $u->Id,
                'name' => (string) $u->UserName,
                'email' => (string) $u->EmailAddress,
            ]);

        return response()->json(['users' => $users]);
    }

    // ------------------------------------------------------------- internals

    /**
     * The caller's `return` URL, if it is signed AND points at the peer.
     *
     * Both checks matter and neither is sufficient on its own. The signature
     * proves the request came from something holding the shared secret; the
     * host check means that even a leaked secret cannot aim this app's
     * tickets at an arbitrary collector.
     */
    private function verifiedReturn(Request $request): ?string
    {
        $params = $request->query();

        if (! SsoTicket::checkParams($params)) {
            return null;
        }

        $return = (string) ($params['return'] ?? '');
        $peer = (string) config('sso.peer.url');

        if ($peer === '' || ! str_starts_with($return, $peer.'/')) {
            return null;
        }

        return $return;
    }

    /** @param  array<string, string>  $extra */
    private function answer(string $return, array $extra): string
    {
        $glue = str_contains($return, '?') ? '&' : '?';

        return $return.$glue.http_build_query($extra);
    }
}
