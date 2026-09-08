<?php

namespace Modules\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\User;
use Modules\Core\Services\PasswordResetService;

/**
 * The forgot-password mail.
 *
 * The body is a Blade view for now. T019 replaces it with a row in
 * `agora.EmailTemplate` under the code below, editable by the customer with a
 * variable registry behind it — so the code is a constant here rather than a
 * string in a view name, and the variables the template needs are the ones
 * `content()` passes. When T019 lands it swaps the view for a rendered
 * template and this class's signature does not change.
 */
class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** The agora.EmailTemplate code T019 will seed. Do not rename it. */
    public const TEMPLATE_CODE = 'core.password_reset';

    public function __construct(
        public User $user,
        public string $token,
        public string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set your Agora password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'core::mail.password_reset',
            with: [
                'name' => $this->user->UserName,
                'url' => route('password.reset', ['token' => $this->token])
                    .'?email='.urlencode((string) $this->user->EmailAddress),
                'otp' => $this->otp,
                'minutes' => PasswordResetService::LIFETIME_MINUTES,
                'attempts' => PasswordReset::MAX_ATTEMPTS,
            ],
        );
    }
}
