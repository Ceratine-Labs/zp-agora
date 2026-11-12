<?php

namespace Modules\Email\Services;

use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Email\Models\EmailTemplate;
use Modules\Email\Support\RenderedTemplate;
use Modules\Email\Support\TemplateDefinition;
use RuntimeException;

final class TemplateService
{
    public function __construct(private TemplateCatalog $catalog, private TemplateMarkup $markup) {}

    /** Rendering has an explicit business branch; it never uses the caller's selected workspace. */
    public function resolve(string $code, int $branchId): EmailTemplate
    {
        $this->catalog->get($code);
        $group = app(BranchContext::class)->groupId();
        $templates = EmailTemplate::query()->acrossBranches()->where('Code', $code)
            ->whereIn('BranchId', [$group, $branchId])->where('IsActive', true)->get();

        return $templates->firstWhere('BranchId', $branchId)
            ?? $templates->firstWhere('BranchId', $group)
            ?? throw new RuntimeException('No active email template for '.$code.'.');
    }

    /** @param array<string, mixed> $values */
    public function render(string $code, int $branchId, array $values): RenderedTemplate
    {
        $template = $this->resolve($code, $branchId);

        return $this->markup->render($this->catalog->get($code), $template->Subject, $template->BodyHtml, $template->BodyText, $values);
    }

    /** @param array<string, mixed> $input */
    public function preview(string $code, array $input): RenderedTemplate
    {
        $data = $this->validated($code, $input);
        $definition = $this->catalog->get($code);

        return $this->markup->render($definition, $data['Subject'], $data['BodyHtml'], $data['BodyText'], $definition->samples());
    }

    /** Version 0 creates; a saved version is required for every subsequent change. */
    /** @param array<string, mixed> $input */
    public function save(int $branchId, string $code, array $input, ?int $userId): EmailTemplate
    {
        $data = $this->validated($code, $input);
        $version = (int) ($input['Version'] ?? -1);
        $definition = $this->catalog->get($code);

        return DB::connection(config('agora.connections.app'))->transaction(function () use ($branchId, $code, $data, $version, $definition, $userId): EmailTemplate {
            $template = EmailTemplate::query()->acrossBranches()->where('BranchId', $branchId)->where('Code', $code)
                ->lock('WITH (UPDLOCK, HOLDLOCK)')->first();
            if (($template->Version ?? 0) !== $version) {
                throw ValidationException::withMessages(['Version' => 'This template changed. Reload it before saving.']);
            }
            $template ??= new EmailTemplate(['BranchId' => $branchId, 'Code' => $code, 'CreatedBy' => $userId]);
            $template->fill($data + ['ModuleTag' => $definition->module, 'Version' => $version + 1, 'UpdatedBy' => $userId]);
            $template->save();
            DB::table(config('agora.schema').'.EmailTemplateVersion')->insert([
                'BranchId' => $branchId, 'TemplateId' => $template->Id, 'Code' => $code,
                'ModuleTag' => $definition->module, 'Version' => $template->Version,
                'Subject' => $template->Subject, 'BodyHtml' => $template->BodyHtml, 'BodyText' => $template->BodyText,
                'VariablesJson' => json_encode($template->VariablesJson, JSON_THROW_ON_ERROR), 'IsActive' => $template->IsActive,
                'CreatedAt' => now(), 'CreatedBy' => $userId, 'UpdatedAt' => now(), 'UpdatedBy' => $userId,
            ]);

            return $template;
        });
    }

    /** Initial defaults never overwrite customer edits or reactivate a disabled template. */
    public function seed(TemplateDefinition $definition): void
    {
        $group = app(BranchContext::class)->groupId();
        if (! EmailTemplate::query()->acrossBranches()->where('BranchId', $group)->where('Code', $definition->code)->exists()) {
            $this->save($group, $definition->code, [
                'Subject' => $definition->subject, 'BodyHtml' => $definition->html, 'BodyText' => $definition->text,
                'IsActive' => true, 'Version' => 0,
            ], null);
        }
    }

    /** @param array<string, mixed> $input
     * @return array{Subject: string, BodyHtml: string, BodyText: string, IsActive: bool, VariablesJson: list<string>}
     */
    private function validated(string $code, array $input): array
    {
        $data = Validator::make($input, [
            'Subject' => ['required', 'string', 'max:250'],
            'BodyHtml' => ['required', 'string', 'max:100000'],
            'BodyText' => ['required', 'string', 'max:100000'],
            'IsActive' => ['required', 'boolean'],
        ])->validate();
        $variables = $this->markup->validate($this->catalog->get($code), $data['Subject'], $data['BodyHtml'], $data['BodyText']);

        return ['Subject' => $data['Subject'], 'BodyHtml' => $data['BodyHtml'], 'BodyText' => $data['BodyText'],
            'IsActive' => (bool) $data['IsActive'], 'VariablesJson' => $variables];
    }
}
