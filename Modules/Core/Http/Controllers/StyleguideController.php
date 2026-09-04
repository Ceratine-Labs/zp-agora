<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Format;
use Illuminate\Contracts\View\View;

/**
 * The theme, on one page.
 *
 * Every colour token, the type scale, and the number formats — rendered rather
 * than described, so "does this read in dark" is a question you answer by
 * looking instead of by reasoning about hex values.
 *
 * It also carries the parity table: each formatting case shows what PHP
 * produced, and the page's own JavaScript fills in what the JS twin produces
 * from the same input. tests/e2e/format.spec.js asserts the two columns match,
 * which is the only way to be sure the pair has not drifted — two separate
 * test suites would each pass while disagreeing with each other.
 */
class StyleguideController extends Controller
{
    /** The four token families, in the order the design sheet lists them. */
    private const TOKENS = [
        'Surfaces' => ['paper', 'surface', 'surface-2', 'surface-3'],
        'Ink' => ['ink', 'ink-2', 'muted', 'line', 'line-soft'],
        'Brand' => ['brand', 'brand-soft', 'brand-ink', 'sand'],
        'Series' => ['s1', 's2', 's3', 's4', 's5'],
        'Status' => ['good', 'warn', 'serious', 'crit'],
        'Status backgrounds' => ['good-bg', 'warn-bg', 'serious-bg', 'crit-bg'],
        'Status ink' => ['good-ink', 'warn-ink', 'serious-ink', 'crit-ink'],
        'Chrome' => ['chrome', 'chrome-2', 'chrome-3', 'chrome-ink', 'chrome-muted'],
        'Chart' => ['grid', 'axis'],
    ];

    public function __invoke(): View
    {
        return view('core::dev.styleguide', [
            'tokens' => self::TOKENS,
            'cases' => $this->formatCases(),
        ]);
    }

    /**
     * The formatting cases, with the PHP answer already computed.
     *
     * Deliberately includes the awkward ones: a negative, a zero, a null, a
     * value on each side of every threshold, and a movement small enough to be
     * flat. A parity table of tidy numbers proves very little.
     *
     * @return array<int, array{fn: string, args: array<int, mixed>, php: string}>
     */
    private function formatCases(): array
    {
        $cases = [
            ['n', [1234567.891, 2]],
            ['n', [0, 0]],
            ['n', [-42.5, 1]],
            ['n', [null, 0]],
            ['R', [1234.5]],
            ['R', [-1234.5]],
            ['R', [0]],
            ['R', [null]],
            ['Rk', [2208437]],
            ['Rk', [999]],
            ['Rk', [1000]],
            ['Rk', [999999]],
            ['Rk', [1000000]],
            ['Rk', [-2208437]],
            ['Rk', [1500000000]],
            ['Lk', [1240000]],
            ['Lk', [847300]],
            ['Lk', [312]],
            ['litres', [12480.5]],
            ['pct', [12.44]],
            ['pct', [-3.06, 2]],
            ['cpl', [175.25]],
            ['delta', [4.23]],
            ['delta', [-4.23]],
            ['delta', [0.02]],
            ['delta', [0]],
            ['delta', [null]],
        ];

        return array_map(fn (array $case) => [
            'fn' => $case[0],
            'args' => $case[1],
            'php' => Format::{$case[0]}(...$case[1]),
        ], $cases);
    }
}
