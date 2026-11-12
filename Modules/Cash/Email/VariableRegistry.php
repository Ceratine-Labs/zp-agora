<?php

namespace Modules\Cash\Email;

use Modules\Email\Contracts\VariableRegistry as RegistryContract;
use Modules\Email\Support\TemplateDefinition;

final class VariableRegistry implements RegistryContract
{
    public function templates(): array
    {
        return [
            new TemplateDefinition(
                'cash', 'cash.staff_short_raised',
                'Staff short {{short.no}} to approve — {{short.amount}}, {{short.employee}}',
                <<<'HTML'
<p>Hello {{approver}},</p><p>Staff short <strong>{{short.no}}</strong> is waiting for your approval.</p><table><tr><td>Site</td><td>{{short.site}}</td></tr><tr><td>Against</td><td>{{short.employee}}</td></tr><tr><td>Employee code</td><td>{{short.code}}</td></tr><tr><td>Amount</td><td>{{short.amount}}</td></tr><tr><td>Reason</td><td>{{short.reason}}</td></tr><tr><td>Day</td><td>{{short.day}}</td></tr><tr><td>Note</td><td>{{short.note}}</td></tr><tr><td>Raised by</td><td>{{short.raisedBy}}</td></tr><tr><td>Source</td><td>{{short.source}}</td></tr></table><p>Approve or decline it here: <a href="{{url}}">{{url}}</a></p><p>An approved short is charged to the person once it is posted to a cashup. A declined one is not charged, and the person who raised it is told why.</p><p>Agora — Zululand Retail &amp; Petroleum</p>
HTML,
                <<<'TEXT'
Hello {{approver}},
Staff short {{short.no}} is waiting for approval.
Site: {{short.site}}
Against: {{short.employee}} ({{short.code}})
Amount: {{short.amount}}
Reason: {{short.reason}}
Day: {{short.day}}
Note: {{short.note}}
Raised by: {{short.raisedBy}}
Source: {{short.source}}
Approve or decline: {{url}}
An approved short is charged once posted to a cashup. A declined one is not charged.
TEXT,
                [
                    'short.no' => ['sample' => '1201'],
                    'short.site' => ['sample' => 'Sample forecourt'],
                    'short.employee' => ['sample' => 'Sample employee'],
                    'short.code' => ['sample' => 'EMP001'],
                    'short.amount' => ['sample' => 'R 125.00'],
                    'short.reason' => ['sample' => 'Cash shortage'],
                    'short.day' => ['sample' => '8 November 2026'],
                    'short.note' => ['sample' => 'Sample note'],
                    'short.raisedBy' => ['sample' => 'Sample cashier'],
                    'short.source' => ['sample' => 'Cash capture'],
                    'approver' => ['sample' => 'Sample approver'],
                    'url' => ['sample' => 'https://example.invalid/staff-short', 'type' => 'url'],
                ],
            ),
            new TemplateDefinition(
                'cash', 'cash.staff_short_decided',
                'Staff short {{short.no}} {{short.decision}} — {{short.amount}}, {{short.employee}}',
                <<<'HTML'
<p>Hello {{raiser}},</p><p>Staff short <strong>{{short.no}}</strong> — {{short.amount}} against {{short.employee}} ({{short.code}}) at {{short.site}}, {{short.reason}}, {{short.day}} — was <strong>{{short.decision}}</strong>.</p><p>Decided by: {{short.decidedBy}}</p><p>Reason: {{short.why}}</p><p>{{short.explanation}}</p><p><a href="{{url}}">{{url}}</a></p><p>Agora — Zululand Retail &amp; Petroleum</p>
HTML,
                <<<'TEXT'
Hello {{raiser}},
Staff short {{short.no}} — {{short.amount}} against {{short.employee}} ({{short.code}}) at {{short.site}}, {{short.reason}}, {{short.day}} — was {{short.decision}}.
Decided by: {{short.decidedBy}}
Reason: {{short.why}}
{{short.explanation}}
{{url}}
TEXT,
                [
                    'short.no' => ['sample' => '1201'],
                    'short.site' => ['sample' => 'Sample forecourt'],
                    'short.employee' => ['sample' => 'Sample employee'],
                    'short.code' => ['sample' => 'EMP001'],
                    'short.amount' => ['sample' => 'R 125.00'],
                    'short.reason' => ['sample' => 'Cash shortage'],
                    'short.day' => ['sample' => '8 November 2026'],
                    'short.note' => ['sample' => 'Sample note'],
                    'short.decision' => ['sample' => 'approved'],
                    'short.decidedBy' => ['sample' => 'Sample approver'],
                    'short.why' => ['sample' => 'Sample decision reason'],
                    'short.explanation' => ['sample' => 'It is charged once it is posted to a cashup\'s pump shortages.'],
                    'raiser' => ['sample' => 'Sample cashier'],
                    'url' => ['sample' => 'https://example.invalid/staff-short', 'type' => 'url'],
                ],
            ),
        ];
    }
}
