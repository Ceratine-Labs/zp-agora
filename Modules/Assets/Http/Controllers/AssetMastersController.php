<?php

namespace Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Assets\Services\AssetsService;

/**
 * The asset masters, read-only (T057): the customer's groups, types, owners
 * and movement types, and the provisional depreciation period beside each
 * group.
 *
 * There is no edit here, deliberately. The four lists are the customer's
 * PumpIT tables and Agora never writes them (Ryan, 27 Sep 2026, PM decision
 * 01a0e309); a new type is added in PumpIT and appears here on the next load.
 * The periods live in Modules/Assets/Config/config.php until ZP confirm.
 */
class AssetMastersController extends Controller
{
    public const TABS = ['groups' => 'Groups and depreciation', 'types' => 'Types', 'owners' => 'Owners', 'movements' => 'Movement types'];

    public function __construct(protected AssetsService $service) {}

    public function index(Request $request): View
    {
        $tab = (string) $request->query('tab', 'groups');

        return view('assets::masters.index', [
            'tab' => array_key_exists($tab, self::TABS) ? $tab : 'groups',
            'tabs' => self::TABS,
            'masters' => $this->service->masters(),
            'provisional' => $this->service->ratesProvisional(),
            'basis' => $this->service->ratesBasis(),
        ]);
    }
}
