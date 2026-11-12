<?php

namespace Modules\Email\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $BranchId
 * @property int $Id
 * @property string $ModuleTag
 * @property string $Code
 * @property string $Subject
 * @property string $BodyHtml
 * @property string $BodyText
 * @property list<string> $VariablesJson
 * @property bool $IsActive
 * @property int $Version
 */
class EmailTemplate extends BaseModel
{
    use SoftDeletes;

    public const DELETED_AT = 'DeletedAt';

    protected $casts = ['VariablesJson' => 'array', 'IsActive' => 'boolean', 'Version' => 'integer'];
}
