<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * One system default or threshold, for the estate or for one site (T026).
 *
 * The group entity's row (BranchId = config('agora.group_branch_id'), never
 * NULL) is the estate's value; a site's row with the same SettingKey
 * overrides it there. Read through Modules\Core\Facades\Settings, never
 * queried for a value directly: Settings::for() is where the fallback lives.
 * Written only by agora.usp_Core_SaveSetting, which audits every change in
 * agora.MasterChange.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $SettingKey
 * @property string|null $Value
 * @property string $Type
 * @property string|null $Description
 * @property bool $AllowsBranch
 * @property string|null $UsedBy
 * @property string $Source
 */
class Setting extends BaseModel
{
    public const TYPES = ['int', 'decimal', 'money', 'litres', 'share', 'bool', 'string', 'choice'];

    protected $table = 'Setting';

    protected $casts = [
        'IsNullable' => 'boolean',
        'AllowsBranch' => 'boolean',
    ];
}
