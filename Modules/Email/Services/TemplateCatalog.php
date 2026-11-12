<?php

namespace Modules\Email\Services;

use App\Support\Modules\ModuleManager;
use LogicException;
use Modules\Email\Contracts\VariableRegistry;
use Modules\Email\Support\TemplateDefinition;

final class TemplateCatalog
{
    /** @return array<string, TemplateDefinition> */
    public function all(): array
    {
        $templates = [];
        foreach (app(ModuleManager::class)->enabled() as $module) {
            $class = 'Modules\\'.$module->name.'\\Email\\VariableRegistry';
            if (! class_exists($class)) {
                continue;
            }
            $registry = app($class);
            if (! $registry instanceof VariableRegistry) {
                throw new LogicException($class.' must implement the email VariableRegistry contract.');
            }
            foreach ($registry->templates() as $definition) {
                if ($definition->module !== $module->alias || ! str_starts_with($definition->code, $module->alias.'.')
                    || isset($templates[$definition->code])) {
                    throw new LogicException('Email template codes must be unique and owned by their module.');
                }
                $templates[$definition->code] = $definition;
            }
        }
        ksort($templates);

        return $templates;
    }

    public function get(string $code): TemplateDefinition
    {
        return $this->all()[$code] ?? abort(404, 'Unknown email template code.');
    }
}
