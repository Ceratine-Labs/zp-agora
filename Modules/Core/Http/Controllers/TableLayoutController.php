<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\UserGridColumn;

/** Personal layouts for the non-grid table component. */
class TableLayoutController extends Controller
{
    public function read(Request $request): JsonResponse
    {
        $input = $request->validate([
            'tables' => ['required', 'array', 'max:100'],
            'tables.*.key' => ['required', 'string'],
            'tables.*.signature' => ['required', 'string'],
        ]);
        $keys = [];
        foreach ($input['tables'] as $table) {
            $this->checkSignature($table['key'], $table['signature']);
            $keys[] = 'table:'.$table['key'];
        }
        $rows = UserGridColumn::query()->acrossBranches()->where('UserId', $request->user()->Id)
            ->whereIn('GridKey', $keys)->pluck('ColumnsJson', 'GridKey');
        $states = [];
        foreach ($rows as $key => $json) {
            $state = json_decode((string) $json, true, 8);
            $states[substr((string) $key, 6)] = is_array($state) ? $state : [];
        }

        return response()->json(['states' => $states]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->authoriseKey($request, $key);

        return response()->json(['state' => UserGridColumn::layout((int) $request->user()->Id, 'table:'.$key)]);
    }

    public function store(Request $request, string $key): JsonResponse
    {
        $this->authoriseKey($request, $key);
        $input = $request->validate([
            'order' => ['sometimes', 'array', 'max:100'],
            'order.*' => ['integer', 'min:0', 'max:99', 'distinct'],
            'hidden' => ['sometimes', 'array', 'max:100'],
            'hidden.*' => ['integer', 'min:0', 'max:99', 'distinct'],
            'widths' => ['sometimes', 'array', 'max:100'],
            'widths.*' => ['integer', 'min:60', 'max:640'],
            'text_size' => ['sometimes', 'in:compact,normal,large'],
            'height' => ['sometimes', 'in:compact,normal,large'],
        ]);
        $widths = [];
        foreach ($input['widths'] ?? [] as $index => $width) {
            if (ctype_digit((string) $index) && (int) $index < 100) {
                $widths[(int) $index] = $width;
            }
        }
        $input['widths'] = $widths;
        UserGridColumn::put((int) $request->user()->Id, 'table:'.$key, $input);

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $this->authoriseKey($request, $key);
        UserGridColumn::forget((int) $request->user()->Id, 'table:'.$key);

        return response()->json(['saved' => true]);
    }

    private function authoriseKey(Request $request, string $key): void
    {
        $signature = (string) $request->query('signature', '');
        $this->checkSignature($key, $signature);
    }

    private function checkSignature(string $key, string $signature): void
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $key) === 1
            && hash_equals(hash_hmac('sha256', $key, (string) config('app.key')), $signature), 404);
    }
}
