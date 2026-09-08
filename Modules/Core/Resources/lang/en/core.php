<?php

return [
    'title' => 'Core',
    'blurb' => '',

    /*
     | The name, and what it means.
     |
     | Verbatim from PIT.brand in docs/reference/agoraretailconsole.html — the
     | wordmark, the expansion, the tagline and the paragraph that explains why
     | the system is not called PumpIT any more. Here rather than in the blade
     | because the sign-in page, the app bar and eventually a report footer all
     | want the same four strings, and three copies is three chances to be
     | subtly different.
     |
     | `wordmark_html` carries one <em>, which is the sand-coloured O. It is the
     | only markup in this file and it is why that key is rendered unescaped;
     | everything else is plain text.
     */
    'brand' => [
        'wordmark' => 'AGORA',
        'wordmark_html' => 'AG<em>O</em>RA',
        'owner' => 'Zululand Retail & Petroleum',
        'expansion' => 'All Group Operations, Reconciliation & Analysis',
        'tagline' => 'The whole estate, in one market square',
        'why' => 'PumpIT named the technology and one profit centre, and two thirds of the group\'s '
            .'turnover is not fuel. The agora was the place where all trade happened — the forecourt, '
            .'the shop, the Wimpy, the liquor store and the lube bay on equal terms — and it was policed '
            .'by officials whose only job was to check that the weights and measures traders used were '
            .'honest. That is exactly this system\'s job: the dip must tie to the pump, the Z-read to the '
            .'cashup, the declaration to the bank.',
    ],

    'signin' => [
        'heading' => 'Sign in',
        'blurb' => 'Your Agora account is your work email address.',
        'system_state' => 'System state',
        'email' => 'Email address',
        'password' => 'Password',
        'remember' => 'Keep me signed in on this device',
        'submit' => 'Sign in',
        'forgot' => 'Forgot your password?',
    ],

    'password' => [
        'forgot_heading' => 'Set your password',
        'forgot_blurb' => 'Everyone brought across from PumpIT starts here: no password came with you, '
            .'and nobody at head office can see the one you choose.',
        'reset_heading' => 'Choose a password',
        'change_heading' => 'Change your password',
        'change_forced' => 'This password was set for you. Choose your own before going any further.',
        'new' => 'New password',
        'confirm' => 'Confirm the new password',
        'current' => 'Your current password',
        'send' => 'Send me a link',
        'save' => 'Save it',
        'back' => 'Back to sign in',

        // The one-time code on the link (7 Sep 2026).
        'otp_heading' => 'Enter your code',
        'otp_blurb' => 'The mail with this link also carries a :length-character code. '
            .'The link says which request this is; the code says it is you.',
        'otp_label' => 'Code from the mail',
        'otp_submit' => 'Continue',
        'otp_expired' => 'Ask for a new link',
    ],
];
