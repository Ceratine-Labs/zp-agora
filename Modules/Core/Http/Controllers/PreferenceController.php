<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\UserPreference;

/**
 * Stores a person's own choices.
 *
 * The theme is written to a cookie by the browser as well, and that cookie is
 * what the server reads to stamp the page before it paints — a stored
 * preference alone would mean an unstyled flash on every load while the
 * request round-trips. The row is what carries the choice to their next
 * device; the cookie is what makes it instant on this one.
 */
class PreferenceController extends Controller
{
    public function theme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark'],
        ]);

        UserPreference::put($request->user()->Id, UserPreference::THEME, $validated['theme']);

        return response()->json(['theme' => $validated['theme']]);
    }
}
