<?php

namespace Modules\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\User;
use Modules\Core\Services\PasswordResetService;
use Modules\Email\Support\RenderedTemplate;
use Modules\Email\Support\UsesEmailTemplate;

/** Module-owned editable content; recipients and delivery remain with the caller. */
class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEmailTemplate;

    /** Stable module-owned template code. */
    public const TEMPLATE_CODE = 'core.password_reset';

    public function __construct(
        public User $user,
        public string $token,
        public string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->template()->subject);
    }

    protected function template(): RenderedTemplate
    {
        return $this->templateFor(self::TEMPLATE_CODE, (int) $this->user->BranchId, [
            'name' => $this->user->UserName,
            'url' => route('password.reset', ['token' => $this->token]).'?email='.urlencode((string) $this->user->EmailAddress),
            'otp' => $this->otp,
            'minutes' => PasswordResetService::LIFETIME_MINUTES,
            'attempts' => PasswordReset::MAX_ATTEMPTS,
        ]);
    }
}
