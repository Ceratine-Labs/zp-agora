<?php

namespace Modules\Cash\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Email\Support\RenderedTemplate;
use Modules\Email\Support\UsesEmailTemplate;

/** Module-owned editable content; recipients and delivery remain with the caller. */
class StaffShortDecidedMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEmailTemplate;

    /** Stable module-owned template code. */
    public const TEMPLATE_CODE = 'cash.staff_short_decided';

    /**
     * @param  array{branchId?: int, no: int, site: string, employee: string, code: string, amount: string, reason: string,
     *               day: string, note: ?string, decision: string, decidedBy: ?string, why: ?string}  $short
     */
    public function __construct(
        public array $short,
        public string $raiser,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->template()->subject);
    }

    protected function template(): RenderedTemplate
    {
        $short = $this->short;
        $short['explanation'] = match ($short['decision']) {
            'approved' => "It is charged once it is posted to a cashup's pump shortages.",
            'withdrawn' => 'Its approval was taken back and it is declined, so it is not charged.',
            default => 'It is not charged. If the short is still due, raise it again with what was missing.',
        };

        return $this->templateFor(self::TEMPLATE_CODE, (int) ($short['branchId'] ?? config('agora.group_branch_id')), [
            'short' => $short, 'raiser' => $this->raiser, 'url' => $this->url,
        ]);
    }
}
