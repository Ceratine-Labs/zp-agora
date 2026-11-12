<?php

namespace Modules\People\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\People\Http\Requests\ReverseWriteRequest;
use Modules\People\Http\Requests\SaveEmployeeAreasRequest;
use Modules\People\Http\Requests\SaveEmployeeRequest;
use Modules\People\Services\EmployeeWriteService;

/**
 * Editing an employee (T023) — a named write to PumpIT (CLAUDE.md rule 1,
 * PM decision 01a1ceba-4d74). Two forms on one screen, each its own
 * permission and its own procedure: the person (people.employees.edit) and
 * their counting areas (people.areas.edit). Put back reverses one press.
 *
 * With people.write_mode off nothing here is reachable: the routes 404 and
 * the controls are not drawn.
 */
class EmployeeEditController extends Controller
{
    public function __construct(private EmployeeWriteService $writes) {}

    public function edit(int $branch, string $code, Request $request): View
    {
        $this->guard($request, ['people.employees.edit', 'people.areas.edit']);

        $form = $this->writes->form($branch, $code);
        abort_if((bool) $form['employee']->IsSystemAccount, 403, 'PumpIT system accounts are not changed from Agora.');

        return view('people::employees.edit', $form);
    }

    public function update(int $branch, string $code, SaveEmployeeRequest $request): RedirectResponse
    {
        $this->guard($request, ['people.employees.edit']);

        return $this->run(fn () => $this->writes->saveEmployee($branch, $code, $request->validated()), $branch, $code);
    }

    public function updateAreas(int $branch, string $code, SaveEmployeeAreasRequest $request): RedirectResponse
    {
        $this->guard($request, ['people.areas.edit']);

        return $this->run(fn () => $this->writes->saveAreas($branch, $code, ['Reason' => $request->validated('AreaReason')] + $request->validated()), $branch, $code);
    }

    public function reverse(int $branch, string $code, string $write, ReverseWriteRequest $request): RedirectResponse
    {
        $this->guard($request, ['people.employees.edit', 'people.areas.edit']);

        return $this->run(fn () => $this->writes->reverse($branch, $write, (string) $request->validated('Reason')), $branch, $code, 'show');
    }

    /** @param  callable(): object  $write */
    private function run(callable $write, int $branch, string $code, string $back = 'edit'): RedirectResponse
    {
        try {
            $result = $write();
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.employees.'.$back, ['branch' => $branch, 'code' => trim($code)])
            ->with('status', $result->Message);
    }

    /** @param  array<int, string>  $any */
    private function guard(Request $request, array $any): void
    {
        abort_unless(EmployeeWriteService::live(), 404);
        abort_unless(collect($any)->contains(fn (string $p) => (bool) $request->user()?->can($p)), 403);
    }
}
