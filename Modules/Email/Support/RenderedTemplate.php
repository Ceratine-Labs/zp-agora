<?php

namespace Modules\Email\Support;

final readonly class RenderedTemplate
{
    public function __construct(public string $subject, public string $html, public string $text) {}
}
