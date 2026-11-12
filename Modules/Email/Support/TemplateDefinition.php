<?php

namespace Modules\Email\Support;

final readonly class TemplateDefinition
{
    /** @param array<string, array{sample: string, type?: string}> $variables */
    public function __construct(
        public string $module,
        public string $code,
        public string $subject,
        public string $html,
        public string $text,
        public array $variables,
    ) {}

    /** @return array<string, string> */
    public function samples(): array
    {
        return array_map(fn (array $variable): string => $variable['sample'], $this->variables);
    }
}
