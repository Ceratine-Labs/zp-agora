<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * One person's layout for one grid.
 *
 * feature-rules §3.6: column order, column widths and text size are the user's,
 * per grid, and survive a reload and a new session. `GridKey` is the ROUTE NAME
 * (`app.cash.dropsafe`), qualified where a screen carries two grids
 * (`app.cash.dropsafe:bags`) — not the controller class, because one controller
 * serves several screens and a class rename would silently orphan everyone's
 * saved layout with nothing anywhere reporting it.
 *
 * Read and written ACROSS branches, like UserPreference: a layout belongs to
 * the person, not to the site they happen to be looking at, so it must survive
 * a change of scope. The row still carries the group entity's BranchId rather
 * than NULL — never NULL, which is how legacy rows became unreportable — and
 * the natural key `(BranchId, UserId, GridKey)` includes it, as every unique
 * key here does.
 *
 * `ColumnsJson` is user-controlled and is whitelisted on the way in by
 * App\Grid\GridColumnState; nothing else may write this table. What reads it is
 * a blade template, so an unfiltered blob here is a payload, not a preference.
 *
 * @property int $BranchId
 * @property int $Id
 * @property int $UserId
 * @property string $GridKey
 * @property string $ColumnsJson
 */
class UserGridColumn extends BaseModel
{
    protected $table = 'UserGridColumn';

    /**
     * The saved layout, or an empty array.
     *
     * Anything unreadable is treated as absent. A layout is a convenience: a
     * corrupt one must give the user the default grid, never an exception on a
     * page they were only trying to look at.
     *
     * @return array<string, mixed>
     */
    public static function layout(int $userId, string $gridKey): array
    {
        $json = static::query()
            ->acrossBranches()
            ->where('UserId', $userId)
            ->where('GridKey', $gridKey)
            ->value('ColumnsJson');

        if (! is_string($json) || $json === '') {
            return [];
        }

        try {
            $state = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($state) ? $state : [];
    }

    /**
     * Replace a user's layout for one grid.
     *
     * An empty state DELETES the row rather than storing `{}` — "reset my
     * columns" has to fall back to the catalogue, and a stored empty object is
     * a state that keeps being applied. Same reasoning as the widths map in
     * GridColumnState, one level up.
     *
     * @param  array<string, mixed>  $state  already whitelisted by GridColumnState::sanitise()
     */
    public static function put(int $userId, string $gridKey, array $state): void
    {
        $branchId = (int) config('agora.group_branch_id');

        if ($state === []) {
            static::forget($userId, $gridKey);

            return;
        }

        $row = static::query()->acrossBranches()->firstOrNew([
            'BranchId' => $branchId,
            'UserId' => $userId,
            'GridKey' => $gridKey,
        ]);

        $row->BranchId = $branchId;
        $row->UserId = $userId;
        $row->GridKey = $gridKey;
        $row->ColumnsJson = json_encode($state, JSON_THROW_ON_ERROR);
        $row->save();
    }

    /** Back to the catalogue's own layout. */
    public static function forget(int $userId, string $gridKey): void
    {
        static::query()
            ->acrossBranches()
            ->where('UserId', $userId)
            ->where('GridKey', $gridKey)
            ->delete();
    }
}
