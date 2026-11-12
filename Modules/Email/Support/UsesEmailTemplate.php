<?php

namespace Modules\Email\Support;

use Illuminate\Mail\Mailables\Content;
use Modules\Email\Services\TemplateService;

trait UsesEmailTemplate
{
    private ?RenderedTemplate $renderedEmail = null;

    /** @param array<string, mixed> $values */
    protected function templateFor(string $code, int $branchId, array $values): RenderedTemplate
    {
        return $this->renderedEmail ??= app(TemplateService::class)->render($code, $branchId, $values);
    }

    public function content(): Content
    {
        $rendered = $this->template();

        return new Content(htmlString: $rendered->html, text: 'email::mail.text', with: ['bodyText' => $rendered->text]);
    }

    abstract protected function template(): RenderedTemplate;
}
