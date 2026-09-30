<?php

namespace Modules\Cash\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your staff short was approved / declined" — to the person who raised it.
 *
 * T019's template registry (agora.EmailTemplate) is NOT BUILT; the body is a
 * Blade view until it is, under TEMPLATE_CODE — see StaffShortRaisedMail.
 */
class StaffShortDecidedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** The agora.EmailTemplate code T019 will seed. Do not rename it. */
    public const TEMPLATE_CODE = 'cash.staff_short_decided';

    /**
     * @param  array{no: int, site: string, employee: string, code: string, amount: string, reason: string,
     *               day: string, note: ?string, decision: string, decidedBy: ?string, why: ?string}  $short
     */
    public function __construct(
        public array $short,
        public string $raiser,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Staff short '.$this->short['no'].' '.$this->short['decision'].' — '.$this->short['amount'].', '.$this->short['employee'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'cash::mail.staff_short_decided',
            with: ['short' => $this->short, 'raiser' => $this->raiser, 'url' => $this->url],
        );
    }
}
