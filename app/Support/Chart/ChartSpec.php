<?php

namespace App\Support\Chart;

use App\Support\Format;
use InvalidArgumentException;

/**
 * What a chart is, before a chart library is involved.
 *
 * `<x-chart>` hands the browser a JSON payload and `resources/js/components/chart.js`
 * turns it into ApexCharts options. This class is the thing in between, and it
 * exists for three reasons that are each worth a file:
 *
 *  1. **It is where the shapes are decided.** Six chart types, six row shapes,
 *     all reduced to `label` and `value` so a controller writes the same array
 *     whichever chart it is feeding. The alternative — every screen composing
 *     ApexCharts options by hand — is how a component library rots.
 *  2. **It is where "no hex in a component" is enforced rather than hoped for.**
 *     Every colour in the payload is a token reference (`token:--s1`), never a
 *     value, and {@see self::assertNoColourLiteral()} throws if a literal gets
 *     in — including through a caller's `apex` overrides. A rule a test can
 *     break is a rule; a rule in a comment is a wish.
 *  3. **It builds the table twin.** Every chart ships a table of the same
 *     figures, formatted through {@see Format}. That is the accessibility
 *     fallback the dataviz rules require, and it is also the relief for the
 *     three light-theme series colours that sit under 3:1 against white
 *     (`--s3`, `--s4`, `--s5` — measured, see docs/charts.md).
 *
 * Nothing here queries anything. Rows arrive from the controller.
 */
final class ChartSpec
{
    /** Every method of App\Support\Format that takes a number and returns a string. Nothing else may format a figure. */
    public const FORMATS = ['n', 'R', 'Rk', 'Lk', 'litres', 'pct', 'cpl', 'delta'];

    /** #abc, #abcd, #aabbcc, #aabbccdd — the shapes a colour sneaks in as. */
    private const COLOUR_LITERAL = '/#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})\b/i';

    /** @var list<array<string, mixed>> */
    private array $rows;

    /** @var array<string, mixed> */
    private array $options;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     */
    private function __construct(private readonly ChartType $type, array $rows, array $options)
    {
        $this->rows = array_values($rows);
        $this->options = $options;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $options
     */
    public static function make(string $type, array $rows, array $options = []): self
    {
        $resolved = ChartType::tryFrom($type) ?? throw new InvalidArgumentException(
            "Unknown chart type [{$type}]. Known types: ".implode(', ', ChartType::names()).'.'
        );

        $spec = new self($resolved, $rows, $options);
        $spec->assertNoColourLiteral($options['apex'] ?? [], 'apex');

        return $spec;
    }

    public function type(): ChartType
    {
        return $this->type;
    }

    /**
     * No rows, or rows that carry nothing to draw.
     *
     * An empty chart renders as an empty state and emits no `[data-chart]` at
     * all, which means a page whose only chart has no data never downloads
     * ApexCharts. That is the cheapest possible loading behaviour and it falls
     * out of doing the empty state properly.
     */
    public function isEmpty(): bool
    {
        if ($this->rows === []) {
            return true;
        }

        if ($this->type === ChartType::Line) {
            foreach ($this->rows as $series) {
                if (! empty($series['points'])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The payload that goes into `data-chart`.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [
            'type' => $this->type->value,
            'format' => $this->formatSpec('format', 'n'),
            'axisFormat' => $this->formatSpec('axisFormat', null) ?? $this->formatSpec('format', 'n'),
            'label' => $this->option('label'),
            'height' => $this->option('height'),
            'rows' => $this->normalisedRows(),
            'apex' => $this->option('apex', []),
        ];

        $payload += match ($this->type) {
            ChartType::DailyBars => [
                'name' => (string) $this->option('name', 'Value'),
                'average' => $this->option('average') === null ? null : (int) $this->option('average'),
                'averageName' => (string) $this->option('averageName', 'Moving average'),
            ],
            ChartType::Line => [
                'reference' => $this->reference(),
            ],
            ChartType::Donut => [
                'centreLabel' => $this->option('centreLabel'),
                'centreFormat' => $this->formatSpec('centreFormat', null) ?? $this->formatSpec('format', 'n'),
            ],
            ChartType::Mix => [
                'measures' => $this->measures(),
            ],
            ChartType::Bridge => [
                'name' => (string) $this->option('name', 'Effect'),
            ],
            ChartType::Diverging => [
                'name' => (string) $this->option('name', 'Variance'),
            ],
        };

        $this->assertNoColourLiteral($payload, 'payload');

        return $payload;
    }

    public function json(): string
    {
        return (string) json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The same figures as a table, already formatted.
     *
     * @return array{columns: list<string>, aligns: list<string>, rows: list<list<string>>}
     */
    public function table(): array
    {
        return match ($this->type) {
            ChartType::DailyBars => $this->simpleTable((string) $this->option('labelHeading', 'Period'), (string) $this->option('name', 'Value')),
            ChartType::Diverging => $this->simpleTable((string) $this->option('labelHeading', 'Item'), (string) $this->option('name', 'Variance')),
            ChartType::Donut => $this->donutTable(),
            ChartType::Line => $this->lineTable(),
            ChartType::Mix => $this->mixTable(),
            ChartType::Bridge => $this->bridgeTable(),
        };
    }

    /**
     * The sentence that has to sit under the chart whether the caller asked for
     * it or not.
     *
     * A waterfall's vertical axis is zoomed to the range the steps occupy — a
     * zero-based axis would render four of the five bars as hairlines against a
     * R25m budget. A truncated axis that does not say so is a lie, so the
     * component says so for you and the caller cannot forget.
     */
    public function caption(): ?string
    {
        $own = $this->option('caption');

        $automatic = $this->type === ChartType::Bridge
            ? 'The vertical axis is zoomed to the range in play so the steps are readable; it does not start at zero.'
            : null;

        return match (true) {
            is_string($own) && $automatic !== null => $own.' '.$automatic,
            is_string($own) => $own,
            default => $automatic,
        };
    }

    // ---------------------------------------------------------------- rows

    /**
     * Rows reduced to what the browser needs, with the labels the table shows
     * already computed here so PHP and JavaScript cannot disagree about a
     * figure that appears in both.
     *
     * @return list<array<string, mixed>>
     */
    private function normalisedRows(): array
    {
        return match ($this->type) {
            ChartType::DailyBars => array_map(fn (array $r) => [
                'label' => (string) ($r['label'] ?? ''),
                'value' => $this->number($r['value'] ?? null),
                'quiet' => (bool) ($r['quiet'] ?? false),
                'note' => isset($r['note']) ? (string) $r['note'] : null,
            ], $this->rows),

            ChartType::Line => array_map(fn (array $s) => [
                'name' => (string) ($s['name'] ?? ''),
                'points' => array_map(fn (array $p) => [
                    'label' => (string) ($p['label'] ?? ''),
                    'value' => $this->number($p['value'] ?? null),
                ], array_values($s['points'] ?? [])),
            ], $this->rows),

            ChartType::Mix => array_map(fn (array $r) => [
                'label' => (string) ($r['label'] ?? ''),
                'values' => array_map(
                    fn (string $m) => $this->number(($r['values'] ?? [])[$m] ?? null),
                    $this->measures()
                ),
            ], $this->rows),

            ChartType::Bridge => array_map(fn (array $r) => [
                'label' => (string) ($r['label'] ?? ''),
                'value' => $this->number($r['value'] ?? null),
                'total' => (bool) ($r['total'] ?? false),
                'note' => isset($r['note']) ? (string) $r['note'] : null,
            ], $this->rows),

            default => array_map(fn (array $r) => [
                'label' => (string) ($r['label'] ?? ''),
                'value' => $this->number($r['value'] ?? null),
                'note' => isset($r['note']) ? (string) $r['note'] : null,
            ], $this->rows),
        };
    }

    /**
     * The measure names of a mix chart, in the order they are read: last year,
     * budget, actual.
     *
     * @return list<string>
     */
    private function measures(): array
    {
        $measures = $this->option('measures');

        if (is_array($measures) && $measures !== []) {
            return array_values(array_map('strval', $measures));
        }

        $first = $this->rows[0]['values'] ?? [];

        return array_values(array_map('strval', array_keys(is_array($first) ? $first : [])));
    }

    /** @return array{value: float, label: string}|null */
    private function reference(): ?array
    {
        $reference = $this->option('reference');

        if (! is_array($reference) || ! isset($reference['value']) || ! is_numeric($reference['value'])) {
            return null;
        }

        return [
            'value' => (float) $reference['value'],
            'label' => (string) ($reference['label'] ?? 'Reference'),
        ];
    }

    // ------------------------------------------------------------- tables

    /**
     * @return array{columns: list<string>, aligns: list<string>, rows: list<list<string>>}
     */
    private function simpleTable(string $labelHeading, string $valueHeading): array
    {
        return [
            'columns' => [$labelHeading, $valueHeading],
            'aligns' => ['text', 'num'],
            'rows' => array_map(fn (array $r) => [
                (string) ($r['label'] ?? ''),
                $this->formatted($r['value'] ?? null),
            ], $this->rows),
        ];
    }

    /**
     * @return array{columns: list<string>, aligns: list<string>, rows: list<list<string>>}
     */
    private function donutTable(): array
    {
        $total = array_sum(array_map(fn (array $r) => (float) $this->number($r['value'] ?? null), $this->rows));

        return [
            'columns' => [(string) $this->option('labelHeading', 'Slice'), (string) $this->option('name', 'Value'), 'Share'],
            'aligns' => ['text', 'num', 'num'],
            'rows' => array_map(fn (array $r) => [
                (string) ($r['label'] ?? ''),
                $this->formatted($r['value'] ?? null),
                // A share of nothing is not 0%, it is unanswerable.
                $total > 0.0 ? Format::pct((float) $this->number($r['value'] ?? null) / $total * 100) : Format::NOTHING,
            ], $this->rows),
        ];
    }

    /**
     * @return array{columns: list<string>, aligns: list<string>, rows: list<list<string>>}
     */
    private function lineTable(): array
    {
        $names = array_map(fn (array $s) => (string) ($s['name'] ?? ''), $this->rows);

        $labels = [];
        foreach ($this->rows as $series) {
            foreach (array_values($series['points'] ?? []) as $index => $point) {
                $labels[$index] ??= (string) ($point['label'] ?? '');
            }
        }

        $rows = [];
        foreach ($labels as $index => $label) {
            $row = [$label];
            foreach ($this->rows as $series) {
                $row[] = $this->formatted(array_values($series['points'] ?? [])[$index]['value'] ?? null);
            }
            $rows[] = $row;
        }

        return [
            'columns' => array_merge([(string) $this->option('labelHeading', 'Period')], $names),
            'aligns' => array_merge(['text'], array_fill(0, count($names), 'num')),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{columns: list<string>, aligns: list<string>, rows: list<list<string>>}
     */
    private function mixTable(): array
    {
        $measures = $this->measures();

        return [
            'columns' => array_merge([(string) $this->option('labelHeading', 'Profit centre')], $measures),
            'aligns' => array_merge(['text'], array_fill(0, count($measures), 'num')),
            'rows' => array_map(function (array $r) use ($measures) {
                $row = [(string) ($r['label'] ?? '')];
                foreach ($measures as $measure) {
                    $row[] = $this->formatted(($r['values'] ?? [])[$measure] ?? null);
                }

                return $row;
            }, $this->rows),
        ];
    }

    /**
     * The bridge's table carries the running total as well as the step, because
     * the running total is the whole point of a waterfall and the chart shows
     * it only as a bar's position.
     *
     * @return array{columns: list<string>, aligns: list<string>, rows: list<list<string>>}
     */
    private function bridgeTable(): array
    {
        $running = 0.0;
        $rows = [];

        foreach ($this->rows as $row) {
            $value = (float) $this->number($row['value'] ?? null);
            $total = (bool) ($row['total'] ?? false);
            $running = $total ? $value : $running + $value;

            $rows[] = [
                (string) ($row['label'] ?? ''),
                $total ? Format::NOTHING : $this->formatted($value),
                $this->formatted($running),
            ];
        }

        return [
            'columns' => [(string) $this->option('labelHeading', 'Step'), 'Effect', 'Running total'],
            'aligns' => ['text', 'num', 'num'],
            'rows' => $rows,
        ];
    }

    // -------------------------------------------------------------- support

    /**
     * A figure, through Format and nothing else.
     *
     * `number_format` is banned in this project and `Intl` is banned in its
     * JavaScript twin; both are stated in App\Support\Format. A chart is where
     * that rule is most likely to be broken quietly, because the library will
     * happily print a raw float.
     */
    private function formatted(mixed $value): string
    {
        /** @var array{0: string, 1: int|null} $format */
        $format = $this->formatSpec('format', 'n');
        [$name, $dp] = $format;

        $number = $this->number($value);

        return $dp === null
            ? Format::{$name}($number)
            : Format::{$name}($number, $dp);
    }

    /**
     * A format is a Format method name and, optionally, a decimal place count.
     *
     * @return array{0: string, 1: int|null}|null
     */
    private function formatSpec(string $key, ?string $default): ?array
    {
        $raw = $this->option($key, $default);

        if ($raw === null) {
            return null;
        }

        $name = is_array($raw) ? (string) ($raw[0] ?? 'n') : (string) $raw;
        $dp = is_array($raw) && isset($raw[1]) ? (int) $raw[1] : null;

        if (! in_array($name, self::FORMATS, true)) {
            throw new InvalidArgumentException(
                "Unknown number format [{$name}]. App\\Support\\Format offers: ".implode(', ', self::FORMATS).
                '. If the figure you have needs a format that is not there, add it to BOTH halves of the pair '.
                '(App\\Support\\Format and resources/js/format.js) as a reviewed change — do not invent one here.'
            );
        }

        return [$name, $dp];
    }

    /** Null stays null: a missing figure is an em dash downstream, never a zero. */
    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Walks anything and refuses a colour literal.
     *
     * This is the enforcement half of "no hex in a component". It runs over the
     * caller's `apex` overrides on construction — so a screen cannot smuggle a
     * palette in — and over the finished payload before it is serialised.
     */
    private function assertNoColourLiteral(mixed $value, string $path): void
    {
        if (is_string($value) && preg_match(self::COLOUR_LITERAL, $value) === 1) {
            throw new InvalidArgumentException(
                "A colour literal reached the chart payload at [{$path}]: \"{$value}\". ".
                'Charts take their colours from the theme tokens at draw time — pass a token reference '.
                'such as "token:--s1" and let resources/js/components/chart.js resolve it, or the chart '.
                'will keep its light colours after the reader switches to dark.'
            );
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertNoColourLiteral($child, $path.'.'.$key);
            }
        }
    }
}
