<?php

namespace Modules\Debtors\Email;

use Modules\Email\Contracts\VariableRegistry as RegistryContract;
use Modules\Email\Support\TemplateDefinition;

final class VariableRegistry implements RegistryContract
{
    public function templates(): array
    {
        return [
            new TemplateDefinition(
                'debtors', 'debtors.statement',
                'Statement of account {{statement.accountNo}} — {{statement.site}}, {{statement.period}}',
                <<<'HTML'
<p>Dear {{statement.accountName}},</p><p>Please find attached your statement of account <strong>{{statement.accountNo}}</strong> with {{statement.site}} for {{statement.period}}.</p><p>{{statement.balanceSummary}}</p><p>Please quote your account number with every payment. If anything on the statement does not agree with your records, reply to this mail or contact {{statement.site}}.</p><p>{{statement.site}}</p>
HTML,
                <<<'TEXT'
Dear {{statement.accountName}},
Please find attached your statement of account {{statement.accountNo}} with {{statement.site}} for {{statement.period}}.
{{statement.balanceSummary}}
Please quote your account number with every payment. If anything does not agree with your records, reply or contact {{statement.site}}.
TEXT,
                [
                    'statement.site' => ['sample' => 'Sample forecourt'],
                    'statement.accountNo' => ['sample' => 'AC001'],
                    'statement.accountName' => ['sample' => 'Sample customer'],
                    'statement.period' => ['sample' => 'November 2026'],
                    'statement.periodEnd' => ['sample' => '30 November 2026'],
                    'statement.closing' => ['sample' => 'R 125.00'],
                    'statement.owing' => ['sample' => '1'],
                    'statement.balanceSummary' => ['sample' => 'The balance due at 30 November 2026 is R 125.00.'],
                    'testFor' => ['sample' => ''],
                ],
            ),
        ];
    }
}
