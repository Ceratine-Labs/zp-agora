<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The two landing pages roles point at before their epics exist.
 *
 * `agora.Role.LandingRoute` is data — the business decides where Finance
 * lands, not a developer — but a named route that resolves to nothing is a 404
 * on the first screen somebody sees after signing in. So the branch console
 * (T030) and the Exco pack (T077) get a route each now, saying plainly what is
 * coming and where they are meanwhile.
 *
 * Both are replaced wholesale by their own controllers when those tasks land.
 * Neither reads anything, so there is nothing here to keep correct in the
 * meantime — which is the property that makes a stub safe to leave standing.
 */
class LandingStubController extends Controller
{
    public function console(): View
    {
        return view('core::landing-stub', [
            'eyebrow' => 'Branch',
            'title' => 'Branch console',
            'blurb' => 'Where a branch manager starts the day.',
            'owner' => 'T030',
            'what' => 'The day-close checklist, the site\'s Z-reads, its cashups and its exceptions — '
                .'one screen that says what still has to happen before today can be closed.',
        ]);
    }

    public function exco(): View
    {
        return view('core::landing-stub', [
            'eyebrow' => 'Head office',
            'title' => 'Exco pack',
            'blurb' => 'The group position, for the people who answer for it.',
            'owner' => 'T077',
            'what' => 'Trading against budget and last year across all 31 sites, by profit centre, '
                .'with the exceptions that explain the gaps.',
        ]);
    }
}
