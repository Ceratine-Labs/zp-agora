<?php

namespace Modules\Debtors\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A debtor's statement of account, as a PDF, with a short covering note.
 *
 * The body is a Blade view for now. T019 — the customer-editable template
 * registry, agora.EmailTemplate — is NOT BUILT; when it is, it seeds a template
 * under TEMPLATE_CODE and this class renders that instead. StaffShortRaisedMail
 * is the same arrangement.
 *
 * Plain values, not models: the figures in the note are the ones on the PDF
 * attached to it, rendered once.
 */
class DebtorStatementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** The agora.EmailTemplate code T019 will seed. Do not rename it. */
    public const TEMPLATE_CODE = 'debtors.statement';

    /**
     * @param  array{site: string, accountNo: string, accountName: string, period: string, periodEnd: string,
     *               closing: string, owing: bool}  $statement
     */
    public function __construct(
        public array $statement,
        public string $pdf,
        public string $filename,
        public ?string $testFor = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = 'Statement of account '.$this->statement['accountNo'].' — '.$this->statement['site']
            .', '.$this->statement['period'];

        return new Envelope(
            subject: $this->testFor !== null ? '[TEST for '.$this->testFor.'] '.$subject : $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'debtors::mail.statement',
            with: ['statement' => $this->statement, 'testFor' => $this->testFor],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf'),
        ];
    }
}
