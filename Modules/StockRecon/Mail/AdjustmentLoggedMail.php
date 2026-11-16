<?php

namespace Modules\StockRecon\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Email\Support\RenderedTemplate;
use Modules\Email\Support\UsesEmailTemplate;

/** stockrecon.adjustment_logged — to config('stockrecon.notify') (Ryan, 16 Nov 2026). */
class AdjustmentLoggedMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEmailTemplate;

    public const TEMPLATE_CODE = 'stockrecon.adjustment_logged';

    /** @param array<string, mixed> $values the template's variables */
    public function __construct(public int $branchId, public array $values) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->template()->subject);
    }

    protected function template(): RenderedTemplate
    {
        return $this->templateFor(self::TEMPLATE_CODE, $this->branchId, $this->values);
    }
}
