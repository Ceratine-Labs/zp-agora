<?php

namespace Modules\Debtors\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Email\Support\RenderedTemplate;
use Modules\Email\Support\UsesEmailTemplate;

/** Module-owned editable content; recipients and delivery remain with the caller. */
class DebtorStatementMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEmailTemplate;

    /** Stable module-owned template code. */
    public const TEMPLATE_CODE = 'debtors.statement';

    /**
     * @param  array{branchId?: int, site: string, accountNo: string, accountName: string, period: string, periodEnd: string,
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
        $subject = $this->template()->subject;

        return new Envelope(subject: $this->testFor !== null ? '[TEST for '.$this->testFor.'] '.$subject : $subject);
    }

    protected function template(): RenderedTemplate
    {
        $statement = $this->statement;
        $statement['balanceSummary'] = $statement['owing']
            ? 'The balance due at '.$statement['periodEnd'].' is '.$statement['closing'].'.'
            : 'At '.$statement['periodEnd'].' your account is '.$statement['closing'].' in credit.';
        $rendered = $this->templateFor(self::TEMPLATE_CODE, (int) ($statement['branchId'] ?? config('agora.group_branch_id')), [
            'statement' => $statement, 'testFor' => $this->testFor,
        ]);
        if ($this->testFor === null) {
            return $rendered;
        }
        // A template editor cannot remove the test-recipient warning.
        $warning = 'Test statement. In live mode this goes to '.$this->testFor.'. Nobody outside the business has seen it.';

        return new RenderedTemplate($rendered->subject, '<p><strong>'.e($warning).'</strong></p>'.$rendered->html, $warning."\n\n".$rendered->text);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf'),
        ];
    }
}
