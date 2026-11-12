<?php

namespace Modules\Cash\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Email\Support\RenderedTemplate;
use Modules\Email\Support\UsesEmailTemplate;

/** Module-owned editable content; recipients and delivery remain with the caller. */
class StaffShortRaisedMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEmailTemplate;

    /** Stable module-owned template code. */
    public const TEMPLATE_CODE = 'cash.staff_short_raised';

    /**
     * @param  array{branchId?: int, no: int, site: string, employee: string, code: string, amount: string, reason: string,
     *               day: string, note: ?string, raisedBy: string, source: ?string}  $short
     */
    public function __construct(
        public array $short,
        public string $approver,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->template()->subject);
    }

    protected function template(): RenderedTemplate
    {
        return $this->templateFor(self::TEMPLATE_CODE, (int) ($this->short['branchId'] ?? config('agora.group_branch_id')), [
            'short' => $this->short, 'approver' => $this->approver, 'url' => $this->url,
        ]);
    }
}
