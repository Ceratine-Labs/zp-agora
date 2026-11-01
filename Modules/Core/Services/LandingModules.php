<?php

namespace Modules\Core\Services;

use Illuminate\Support\Str;
use Modules\Core\Models\User;

/** Module hubs and direct daily tools, drawn from the same permitted catalogue. */
class LandingModules
{
    public function __construct(protected ModuleMenu $menus) {}

    /** @return list<array{key: string, label: string, description: string, art: string, href: string, entryLabel: string}> */
    public function forUser(User $user, string $workspace): array
    {
        $hubs = $this->menus->forUser($user, $workspace);
        $tools = [];
        foreach ($hubs as $hub) {
            foreach ($hub['groups'] as $group) {
                array_push($tools, ...$group['tools']);
            }
        }
        $tiles = [];
        foreach (config('agora.landing_modules', []) as $key => $module) {
            if (in_array($key, ['my-queue', 'operations'], true)) {
                $chosen = null;
                $best = PHP_INT_MAX;
                foreach ($tools as $tool) {
                    if (! in_array($tool['route'], $module['route_names'] ?? [], true) && ! Str::startsWith($tool['route'], $module['route_prefixes'] ?? [])) {
                        continue;
                    }
                    foreach ($module['route_parameters'] ?? [] as $parameter => $value) {
                        if (($tool['parameters'][$parameter] ?? null) !== $value) {
                            continue 2;
                        }
                    }
                    $rank = array_search($tool['route'], $module['preferred_routes'] ?? [], true);
                    $rank = $rank === false ? PHP_INT_MAX : $rank;
                    if ($chosen === null || $rank < $best) {
                        $chosen = $tool;
                        $best = $rank;
                    }
                }
                if ($chosen === null) {
                    continue;
                }
                $href = $chosen['href'];
                $entryLabel = $chosen['label'];
            } elseif (isset($hubs[$key])) {
                $href = $hubs[$key]['href'];
                $entryLabel = $module['label'];
            } else {
                continue;
            }
            $tiles[] = [
                'key' => $key, 'label' => $module['label'], 'description' => $module['description'], 'art' => $module['art'],
                'href' => $href, 'entryLabel' => $entryLabel,
            ];
        }

        return $tiles;
    }
}
