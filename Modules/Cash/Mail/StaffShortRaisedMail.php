<?php

namespace Modules\Cash\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "A staff short is waiting for you" — to each approver, when a short is raised
 * (T040's acceptance: raising sends the approver an email).
 *
 * The body is a Blade view for now. T019 — the customer-editable template
 * registry, agora.EmailTemplate — is NOT BUILT; when it is, it seeds a template
 * under TEMPLATE_CODE and this class renders that instead, with the variables
 * content() passes. The code is a constant here, not a view name, so the swap
 * changes nothing a caller sees. PasswordResetMail is the same arrangement.
 *
 * Plain values, not models: a queued mail must not re-read a row that has
 * moved since, and the figures in it are the ones the approver is being told.
 */
class StaffShortRaisedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** The agora.EmailTemplate code T019 will seed. Do not rename it. */
    public const TEMPLATE_CODE = 'cash.staff_short_raised';

    /**
     * @param  array{no: int, site: string, employee: string, code: string, amount: string, reason: string,
     *               day: string, note: ?string, raisedBy: string, source: ?string}  $short
     */
    public function __construct(
        public array $short,
        public string $approver,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Staff short '.$this->short['no'].' to approve — '.$this->short['amount'].', '.$this->short['employee'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'cash::mail.staff_short_raised',
            with: ['short' => $this->short, 'approver' => $this->approver, 'url' => $this->url],
        );
    }
}
