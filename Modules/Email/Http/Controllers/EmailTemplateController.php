<?php

namespace Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Email\Models\EmailTemplate;
use Modules\Email\Services\TemplateAccess;
use Modules\Email\Services\TemplateCatalog;
use Modules\Email\Services\TemplateService;

final class EmailTemplateController extends Controller
{
    public function __construct(private TemplateCatalog $catalog, private TemplateService $service, private TemplateAccess $access) {}

    public function index(Request $request): View
    {
        $definitions = $this->catalog->all();
        $modules = array_values(array_unique(array_map(fn ($item) => $item->module, $definitions)));
        $module = (string) $request->query('module', '');
        abort_unless($module === '' || in_array($module, $modules, true), 404);
        $scopes = $this->access->scopes();
        $group = app(BranchContext::class)->groupId();
        $names = $scopes->all() + [$group => 'Group default (all branches)'];
        $templates = EmailTemplate::query()->acrossBranches()->whereIn('BranchId', array_keys($names))
            ->when($module !== '', fn ($query) => $query->where('ModuleTag', $module))
            ->orderBy('ModuleTag')->orderBy('Code')->orderBy('BranchId')->get();

        return view('email::templates.index', compact('templates', 'definitions', 'modules', 'module', 'scopes', 'names'));
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'scope' => ['required', 'integer']]);
        $this->catalog->get($data['code']);
        $this->access->check((int) $data['scope']);

        return redirect()->route('app.setup.email-templates.edit', ['branch' => $data['scope'], 'code' => $data['code']]);
    }

    public function edit(int $branch, string $code): View
    {
        $definition = $this->catalog->get($code);
        $this->access->check($branch);
        $template = EmailTemplate::query()->acrossBranches()->where('BranchId', $branch)->where('Code', $code)->first();
        $source = $template ?? EmailTemplate::query()->acrossBranches()
            ->where('BranchId', app(BranchContext::class)->groupId())->where('Code', $code)->firstOrFail();
        $history = DB::table(config('agora.schema').'.EmailTemplateVersion')->where('BranchId', $branch)
            ->where('Code', $code)->orderByDesc('Version')->get();
        $mayEdit = $this->access->scopes()->has($branch);
        $scopeName = $branch === app(BranchContext::class)->groupId() ? 'Group default (all branches)' : $this->access->scopes()->get($branch);

        return view('email::templates.edit', compact('template', 'source', 'definition', 'history', 'branch', 'code', 'mayEdit', 'scopeName'));
    }

    public function save(Request $request, int $branch, string $code): RedirectResponse
    {
        $this->access->check($branch, true);
        $request->validate(['Version' => ['required', 'integer', 'min:0']]);
        $this->service->save($branch, $code, $request->all(), (int) $request->user()->getAuthIdentifier());

        return redirect()->route('app.setup.email-templates.edit', compact('branch', 'code'))->with('status', 'Email template saved.');
    }

    public function preview(Request $request, int $branch, string $code): Response
    {
        $this->access->check($branch);
        try {
            $rendered = $this->service->preview($code, $request->all());
            $response = response()->view('email::templates.preview', compact('rendered'));
        } catch (ValidationException $exception) {
            $response = response()->view('email::templates.preview-errors', ['messages' => $exception->errors()], 422);
        }

        return $response
            ->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; sandbox")
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store');
    }
}
