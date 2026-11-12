<?php

namespace Modules\Core\Email;

use Modules\Core\Models\PasswordReset;
use Modules\Core\Services\PasswordResetService;
use Modules\Email\Contracts\VariableRegistry as RegistryContract;
use Modules\Email\Support\TemplateDefinition;

final class VariableRegistry implements RegistryContract
{
    public function templates(): array
    {
        return [
            new TemplateDefinition(
                'core', 'core.password_reset',
                'Set your Agora password',
                <<<'HTML'
<p>Hello {{name}},</p>

<p>Somebody asked to set the password on your Agora account. If that was you,
   follow this link and enter the code below.</p>

<p><a href="{{url}}">{{url}}</a></p>

<p>Your code is <strong>{{otp}}</strong></p>

<p>{{otp}}</p>

<p>The link and the code both expire in {{minutes}} minutes, and the link
   works once. After {{attempts}} incorrect codes it stops working and you
   will need to ask for a new one.</p>

<p>If it was not you, nothing has happened to your account. Do not follow the
   link, and the code on its own is no use to anybody.</p>

<p>Agora — Zululand Retail &amp; Petroleum</p>
HTML,
                <<<'TEXT'
Hello {{name}},

Somebody asked to set your Agora password. Follow {{url}} and enter code {{otp}}.
The link and code expire in {{minutes}} minutes and work once. After {{attempts}} incorrect codes ask for a new link.
If it was not you, nothing has happened to your account. Do not follow the link.
Agora — Zululand Retail & Petroleum
TEXT,
                [
                    'name' => ['sample' => 'Sample person'],
                    'url' => ['sample' => 'https://example.invalid/reset', 'type' => 'url'],
                    'otp' => ['sample' => '123456'],
                    'minutes' => ['sample' => (string) PasswordResetService::LIFETIME_MINUTES],
                    'attempts' => ['sample' => (string) PasswordReset::MAX_ATTEMPTS],
                ],
            ),
            new TemplateDefinition(
                'core', 'core.welcome',
                'Welcome to Agora',
                <<<'HTML'
<p>Hello {{name}},</p><p>Welcome to Agora.</p><p><a href="{{url}}">Open Agora</a></p>
HTML,
                <<<'TEXT'
Hello {{name}},
Welcome to Agora. Open {{url}}.
TEXT,
                [
                    'name' => ['sample' => 'Sample person'],
                    'url' => ['sample' => 'https://example.invalid/login', 'type' => 'url'],
                ],
            ),
        ];
    }
}
