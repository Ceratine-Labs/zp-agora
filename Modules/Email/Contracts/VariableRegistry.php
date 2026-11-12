<?php

namespace Modules\Email\Contracts;

use Modules\Email\Support\TemplateDefinition;

/** Each module owns its mail codes, allowed variables, sample values and initial copy. */
interface VariableRegistry
{
    /** @return list<TemplateDefinition> */
    public function templates(): array;
}
