<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;

/**
 * One thing a person may do, as {module}.{resource}.{action}.
 *
 * @property int $Id
 * @property string $Code
 * @property string $Module
 * @property string $Resource
 * @property string $Action
 * @property string $Name
 * @property string|null $Description
 * @property int $SortOrder
 */
class Permission extends BaseModel
{
    protected $table = 'Permission';

    /** The three parts, joined the one way the whole system spells them. */
    public static function code(string $module, string $resource, string $action): string
    {
        return strtolower("{$module}.{$resource}.{$action}");
    }
}
