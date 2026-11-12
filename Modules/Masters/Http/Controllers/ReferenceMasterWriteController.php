<?php

namespace Modules\Masters\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Masters\Grids\ReferenceMasterGrid;
use Modules\Masters\Services\MasterWriteService;
use Modules\Masters\Services\ReferenceMasterService;

/**
 * Editing a reference master (T022) — a write back to PumpIT's own table
 * (CLAUDE.md rule 1, PM decision 01a1cecf-8e08). One controller for every
 * master that writes: what may change is declared on its ReferenceMasterGrid,
 * and the rules are its save procedure's. Put back reverses one press.
 *
 * With masters.write_mode off nothing here is reachable: the routes 404 and
 * the controls are not drawn. The master is named by the route's `resource`
 * default, read by name as the listing's controller does.
 */
class ReferenceMasterWriteController extends Controller
{
    public function __construct(private ReferenceMasterService $masters, private MasterWriteService $writes) {}

    public function edit(Request $request): View
    {
        [$definition, $branch, $key] = $this->resolve($request);

        return view('masters::reference.edit', $this->masters->record($definition, $branch, $key) + ['definition' => $definition]);
    }

    public function update(Request $request): RedirectResponse
    {
        [$definition, $branch, $key] = $this->resolve($request);
        $input = $request->validate($this->rules($definition));

        try {
            $result = $this->writes->save($definition, $branch, $key, $input);
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.'.$definition->slug().'.show', ['branch' => $branch, 'key' => $key])
            ->with('status', $result->Message);
    }

    public function reverse(Request $request): RedirectResponse
    {
        [$definition, $branch, $key] = $this->resolve($request);
        $input = $request->validate(['Reason' => ['required', 'string', 'min:'.(int) config('masters.reason_min', 10), 'max:400']]);

        try {
            $result = $this->writes->reverse($definition, $branch, (string) $request->route('write'), $input['Reason']);
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.'.$definition->slug().'.show', ['branch' => $branch, 'key' => $key])
            ->with('status', $result->Message);
    }

    /** @return array{0: ReferenceMasterGrid, 1: int, 2: string} */
    private function resolve(Request $request): array
    {
        $definition = $this->masters->definition((string) $request->route('resource'));
        abort_unless($definition->writeEntity() !== null && MasterWriteService::live(), 404);
        abort_unless(MasterWriteService::canEdit($definition), 403);

        return [$definition, (int) $request->route('branch'), (string) $request->input('key', $request->query('key', ''))];
    }

    /**
     * The SHAPE of the form, from the definition. The rules — the band, the
     * stale check, the reason — are the save procedure's (feature-rules §F).
     *
     * @return array<string, array<int, string>>
     */
    private function rules(ReferenceMasterGrid $definition): array
    {
        $rules = ['key' => ['required', 'string', 'max:200'],
            'Reason' => ['required', 'string', 'min:'.(int) config('masters.reason_min', 10), 'max:400']];

        foreach ($definition->editableFields() as $name => $field) {
            $shape = match ($field['type'] ?? 'text') {
                'bool' => ['required', 'boolean'],
                'decimal' => ['required', 'numeric'],
                default => ['nullable', 'string', 'max:'.(int) ($field['max'] ?? 100)],
            };
            $rules[$name] = $shape;
            $rules['Expected'.$name] = ($field['type'] ?? 'text') === 'text' ? ['nullable', 'string', 'max:400'] : $shape;
        }

        return $rules;
    }
}
