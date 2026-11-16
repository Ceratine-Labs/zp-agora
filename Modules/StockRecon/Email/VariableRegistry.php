<?php

namespace Modules\StockRecon\Email;

use Modules\Email\Contracts\VariableRegistry as RegistryContract;
use Modules\Email\Support\TemplateDefinition;

/**
 * Stock recon mail (Ryan, 16 Nov 2026: "send me an email ... whenever there's
 * an edit logged or a support call"): one to the people in
 * config('stockrecon.notify') when a shift's opening, issue or closing is adjusted
 * by hand (the closing only until the afternoon of 16 Nov), and one when
 * a support request is raised on a chain.
 */
final class VariableRegistry implements RegistryContract
{
    public function templates(): array
    {
        return [
            new TemplateDefinition(
                'stockrecon', 'stockrecon.adjustment_logged',
                'Stock recon: {{adj.field}} adjusted by hand — {{adj.item}}, {{adj.site}}',
                <<<'HTML'
<p>A shift's {{adj.field}} was adjusted by hand on run <strong>#{{adj.run}}</strong>.</p><table><tr><td>Site</td><td>{{adj.site}}</td></tr><tr><td>Area</td><td>{{adj.area}}</td></tr><tr><td>Item</td><td>{{adj.item}}</td></tr><tr><td>Shift</td><td>{{adj.shift}}</td></tr><tr><td>Changed</td><td>The {{adj.field}}</td></tr><tr><td>As counted or keyed</td><td>{{adj.counted}}</td></tr><tr><td>Agora proposed</td><td>{{adj.prior}}</td></tr><tr><td>Set by hand to</td><td><strong>{{adj.new}}</strong></td></tr><tr><td>Reason</td><td>{{adj.reason}}</td></tr><tr><td>By</td><td>{{adj.by}}</td></tr></table><p>It changes the run only; it reaches PumpIT when somebody presses Amend. Open the run: <a href="{{url}}">{{url}}</a></p><p>Agora — Zululand Retail &amp; Petroleum</p>
HTML,
                <<<'TEXT'
A shift's {{adj.field}} was adjusted by hand on run #{{adj.run}}.
Site: {{adj.site}}
Area: {{adj.area}}
Item: {{adj.item}}
Shift: {{adj.shift}}
Changed: the {{adj.field}}
As counted or keyed: {{adj.counted}}
Agora proposed: {{adj.prior}}
Set by hand to: {{adj.new}}
Reason: {{adj.reason}}
By: {{adj.by}}
It changes the run only; it reaches PumpIT when somebody presses Amend.
Open the run: {{url}}
TEXT,
                [
                    'adj.field' => ['sample' => 'closing'],
                    'adj.run' => ['sample' => '80536'],
                    'adj.site' => ['sample' => 'Pongola Convenience Centre'],
                    'adj.area' => ['sample' => 'Debonairs BOH'],
                    'adj.item' => ['sample' => 'D Cheddar Cheese Kg (129)'],
                    'adj.shift' => ['sample' => '10 Nov 2026, shift 2 (Afternoon)'],
                    'adj.counted' => ['sample' => '0'],
                    'adj.prior' => ['sample' => '0'],
                    'adj.new' => ['sample' => '58.616'],
                    'adj.reason' => ['sample' => 'The closing was not counted; recounted by the manager'],
                    'adj.by' => ['sample' => 'Sample manager'],
                    'url' => ['sample' => 'https://example.invalid/stock-recon/runs/80536', 'type' => 'url'],
                ],
            ),
            new TemplateDefinition(
                'stockrecon', 'stockrecon.support_logged',
                'Stock recon support request #{{ticket.id}} — {{ticket.item}}, {{ticket.site}}',
                <<<'HTML'
<p>A support request was raised on a chain.</p><table><tr><td>Request</td><td><strong>#{{ticket.id}}</strong></td></tr><tr><td>Site</td><td>{{ticket.site}}</td></tr><tr><td>Area</td><td>{{ticket.area}}</td></tr><tr><td>Item</td><td>{{ticket.item}}</td></tr><tr><td>Chain</td><td>{{ticket.chain}}</td></tr><tr><td>Run</td><td>#{{ticket.run}}</td></tr><tr><td>Raised by</td><td>{{ticket.by}}</td></tr><tr><td>Screenshot</td><td>{{ticket.screenshot}}</td></tr></table><p><strong>Message:</strong></p><p>{{ticket.message}}</p><p>The request and its screenshot: <a href="{{url}}">{{url}}</a></p><p>The run: <a href="{{runUrl}}">{{runUrl}}</a></p><p>Agora — Zululand Retail &amp; Petroleum</p>
HTML,
                <<<'TEXT'
A support request was raised on a chain.
Request: #{{ticket.id}}
Site: {{ticket.site}}
Area: {{ticket.area}}
Item: {{ticket.item}}
Chain: {{ticket.chain}}
Run: #{{ticket.run}}
Raised by: {{ticket.by}}
Screenshot: {{ticket.screenshot}}
Message:
{{ticket.message}}
The request and its screenshot: {{url}}
The run: {{runUrl}}
TEXT,
                [
                    'ticket.id' => ['sample' => '12'],
                    'ticket.site' => ['sample' => 'Pongola Convenience Centre'],
                    'ticket.area' => ['sample' => 'Debonairs BOH'],
                    'ticket.item' => ['sample' => 'D Cheddar Cheese Kg (129)'],
                    'ticket.chain' => ['sample' => '9 Nov – 15 Nov 2026'],
                    'ticket.run' => ['sample' => '80536'],
                    'ticket.by' => ['sample' => 'Sample manager'],
                    'ticket.screenshot' => ['sample' => 'Attached on the request'],
                    'ticket.message' => ['sample' => 'The 10 Nov afternoon closing was counted but Agora shows 0.'],
                    'url' => ['sample' => 'https://example.invalid/stock-recon/support', 'type' => 'url'],
                    'runUrl' => ['sample' => 'https://example.invalid/stock-recon/runs/80536', 'type' => 'url'],
                ],
            ),
        ];
    }
}
