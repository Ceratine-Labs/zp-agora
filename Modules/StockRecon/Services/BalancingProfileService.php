<?php

namespace Modules\StockRecon\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BalancingProfileService
{
    private function group(): int
    {
        return (int) config('agora.group_branch_id', 2);
    }

    /** @return Collection<int, \stdClass> */
    public function profiles(): Collection
    {
        return DB::table('agora.StockReconBalancingProfile')->where('BranchId', $this->group())
            ->where('IsActive', 1)->orderByDesc('IsDefault')->orderBy('Name')->get();
    }

    /** @return Collection<int, \stdClass> */
    public function mappings(int $branch, int $area): Collection
    {
        return DB::table('agora.StockReconItemProfile as map')
            ->join('agora.StockReconBalancingProfile as profile', function ($join) {
                $join->on('profile.Id', '=', 'map.ProfileId')->where('profile.BranchId', '=', $this->group());
            })->where('map.BranchId', $branch)->where('map.AreaNo', $area)
            ->select('map.*', 'profile.Name as ProfileName')->orderBy('map.StockItemNo')->get();
    }

    /** @param array<string, mixed> $input */
    public function save(array $input, int $actor, ?int $profileId = null): void
    {
        DB::transaction(function () use ($input, $actor, $profileId) {
            $profile = $profileId === null ? null : DB::table('agora.StockReconBalancingProfile')
                ->where('BranchId', $this->group())->where('Id', $profileId)->lockForUpdate()->first();
            if ($profileId !== null && $profile === null) {
                abort(404);
            }
            if ($profile && (int) $profile->Revision !== (int) $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'This profile changed. Reload and review it before saving.']);
            }
            $duplicate = DB::table('agora.StockReconBalancingProfile')->where('BranchId', $this->group())
                ->where('Name', $input['name'])->when($profileId !== null, fn ($q) => $q->where('Id', '<>', $profileId))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['name' => 'A profile with this name already exists.']);
            }
            $values = [
                'Name' => $input['name'], 'MaxAmendment' => $input['MaxAmendment'],
                'MaxAmendmentPct' => $input['MaxAmendmentPct'], 'MinShiftsInChain' => $input['MinShiftsInChain'],
                'KeepBreaksUpTo' => $input['KeepBreaksUpTo'], 'UpdatedAt' => now('UTC'), 'UpdatedBy' => $actor,
            ];
            if ($profile && $profile->IsDefault) {
                $values['HoldBackIncomplete'] = (bool) ($input['HoldBackIncomplete'] ?? false);
            }
            if ($profile) {
                $values['Revision'] = (int) $profile->Revision + 1;
                DB::table('agora.StockReconBalancingProfile')->where('BranchId', $this->group())->where('Id', $profileId)->update($values);
            } else {
                $profileId = DB::table('agora.StockReconBalancingProfile')->insertGetId($values + [
                    'BranchId' => $this->group(), 'IsDefault' => 0, 'IsActive' => 1,
                    'Revision' => 1, 'CreatedAt' => now('UTC'), 'CreatedBy' => $actor,
                ]);
            }
            $this->event($this->group(), 'profile_saved', ['profile' => $profileId, 'before' => $profile, 'after' => $values], $actor);
        });
    }

    public function map(int $branch, int $area, string $item, int $profileId, int $actor): void
    {
        $profile = DB::table('agora.StockReconBalancingProfile')->where('BranchId', $this->group())
            ->where('Id', $profileId)->where('IsActive', 1)->where('IsDefault', 0)->first();
        $itemExists = DB::table('agora.vw_StockReconLine')->where('BranchId', $branch)->where('AreaNo', $area)
            ->where('StockItemNo', $item)->exists();
        if (! $profile || ! $itemExists) {
            throw ValidationException::withMessages(['item' => 'Choose an active custom profile and an item counted in this area.']);
        }
        DB::transaction(function () use ($branch, $area, $item, $profileId, $actor) {
            $key = ['BranchId' => $branch, 'AreaNo' => $area, 'StockItemNo' => $item];
            $old = DB::table('agora.StockReconItemProfile')->where($key)->lockForUpdate()->first();
            DB::table('agora.StockReconItemProfile')->updateOrInsert($key,
                ['ProfileId' => $profileId, 'UpdatedAt' => now('UTC'), 'UpdatedBy' => $actor]);
            $this->event($branch, 'item_mapped', $key + ['before' => $old?->ProfileId, 'after' => $profileId], $actor);
        });
    }

    public function unmap(int $branch, int $area, string $item, int $actor): void
    {
        DB::transaction(function () use ($branch, $area, $item, $actor) {
            $query = DB::table('agora.StockReconItemProfile')->where('BranchId', $branch)->where('AreaNo', $area)->where('StockItemNo', $item);
            $old = $query->lockForUpdate()->first();
            abort_if($old === null, 404);
            $query->delete();
            $this->event($branch, 'item_unmapped', ['area' => $area, 'item' => $item, 'before' => $old->ProfileId], $actor);
        });
    }

    /** @param array<string, mixed> $details */
    private function event(int $branch, string $action, array $details, int $actor): void
    {
        DB::table('agora.StockReconProfileEvent')->insert([
            'BranchId' => $branch, 'Action' => $action, 'Details' => json_encode($details, JSON_THROW_ON_ERROR),
            'CreatedAt' => now('UTC'), 'CreatedBy' => $actor,
        ]);
    }
}
