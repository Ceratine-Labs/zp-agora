<?php

namespace Modules\StockRecon\Database\Seeders;

use App\Support\BranchContext;
use Illuminate\Database\Seeder;
use Modules\Email\Models\EmailTemplate;
use Modules\Email\Services\TemplateService;
use Modules\StockRecon\Email\VariableRegistry;

/**
 * The adjustment mail names which value was changed (Ryan, 16 Nov 2026: the
 * opening and the issue can be set by hand too, not only the closing).
 *
 * EmailTemplateSeeder only creates a template that is missing, so the one it
 * seeded that morning still says "closing adjusted by hand". This puts the
 * new wording in as a new version, through TemplateService like an edit on
 * the Email templates screen — and only while the template still reads as
 * seeded, so a version somebody wrote by hand is left alone.
 */
final class AdjustmentTemplateFieldSeeder extends Seeder
{
    public int $seedOrder = 87;

    private const SEEDED_SUBJECT = 'Stock recon: closing adjusted by hand — {{adj.item}}, {{adj.site}}';

    public function run(): void
    {
        $definition = collect((new VariableRegistry)->templates())
            ->firstWhere('code', 'stockrecon.adjustment_logged');
        $group = app(BranchContext::class)->groupId();
        $template = EmailTemplate::query()->acrossBranches()
            ->where('BranchId', $group)->where('Code', 'stockrecon.adjustment_logged')->first();

        if ($definition === null || $template === null || $template->Subject !== self::SEEDED_SUBJECT) {
            return;
        }

        app(TemplateService::class)->save($group, 'stockrecon.adjustment_logged', [
            'Subject' => $definition->subject,
            'BodyHtml' => $definition->html,
            'BodyText' => $definition->text,
            'IsActive' => (bool) $template->IsActive,
            'Version' => (int) $template->Version,
        ], null);
    }
}
