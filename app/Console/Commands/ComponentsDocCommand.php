<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Core\Support\ComponentCatalogue;

/**
 * Writes the component inventory in docs/components.md from the catalogue the
 * gallery renders.
 *
 * The doc's job is to stop the next session building a second version of
 * something that already exists. A doc maintained by hand, beside a gallery
 * maintained by hand, is two inventories — and two inventories drift, which is
 * the exact failure it was written to prevent. So one of them is generated.
 *
 * Only the two marked blocks are touched. Everything else in that file is
 * prose — why the number formats are stated rather than delegated to a locale,
 * what the stylesheet order means, which library loads when — and prose does
 * not survive being regenerated. Replacing the whole file from a PHP array
 * would have cost more than the drift it prevents.
 *
 * `--check` writes nothing and exits non-zero when the file is stale;
 * scripts/check-components.sh runs it, so a component added to the catalogue
 * without regenerating the doc fails the gate rather than being noticed a
 * month later.
 */
class ComponentsDocCommand extends Command
{
    protected $signature = 'agora:components-doc {--check : Exit non-zero if the doc is out of date, and write nothing}';

    protected $description = 'Regenerate the component tables in docs/components.md from the gallery catalogue.';

    private const FILE = 'docs/components.md';

    /** Each block is replaced between its markers; the rest of the file is prose and is left alone. */
    private const BLOCKS = ['shipped', 'pending'];

    public function handle(): int
    {
        $path = base_path(self::FILE);

        if (! is_file($path)) {
            $this->components->error(self::FILE.' is missing.');

            return self::FAILURE;
        }

        $before = file_get_contents($path);
        $after = $before;

        foreach (self::BLOCKS as $block) {
            $body = $block === 'shipped' ? $this->shipped() : $this->pending();
            $replaced = $this->replace($after, $block, $body);

            if ($replaced === null) {
                $this->components->error("docs/components.md has no <!-- components:{$block} --> marker.");

                return self::FAILURE;
            }

            $after = $replaced;
        }

        if ($after === $before) {
            $this->components->info('docs/components.md is up to date.');

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            $this->components->error('docs/components.md is out of date — run: php artisan agora:components-doc');

            return self::FAILURE;
        }

        file_put_contents($path, $after);
        $this->components->info('docs/components.md regenerated from the component catalogue.');

        return self::SUCCESS;
    }

    /** Swap what sits between a pair of markers. Null when the markers are not there. */
    private function replace(string $doc, string $block, string $body): ?string
    {
        $open = "<!-- components:{$block} -->";
        $close = "<!-- /components:{$block} -->";

        $from = strpos($doc, $open);
        $to = strpos($doc, $close);

        if ($from === false || $to === false || $to < $from) {
            return null;
        }

        return substr($doc, 0, $from + strlen($open))
            ."\n\n".trim($body)."\n\n"
            .substr($doc, $to);
    }

    /** One table per group: the component, its props, what it is, and the notes. */
    private function shipped(): string
    {
        $components = collect(ComponentCatalogue::all())->groupBy('group');
        $out = [];

        foreach (ComponentCatalogue::GROUPS as $group => $blurb) {
            $rows = $components->get($group);

            if ($rows === null) {
                continue;
            }

            $out[] = "### {$group}";
            $out[] = '';
            $out[] = $blurb;
            $out[] = '';
            $out[] = '| Component | Props | What it is | Notes |';
            $out[] = '|---|---|---|---|';

            foreach ($rows as $component) {
                /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> $declared */
                $declared = $component['props'];
                $props = implode(' ', array_map(fn (array $prop) => '`'.$prop[0].'`', $declared));

                $notes = (string) $component['notes'];

                if ($component['js']) {
                    $notes = trim($notes.' Behaviour: `'.$component['js'].'`.');
                }

                $out[] = sprintf(
                    '| `<%s>` | %s | %s | %s |',
                    (string) $component['name'],
                    $props !== '' ? $props : '—',
                    $this->cell((string) $component['summary']),
                    $this->cell($notes),
                );
            }

            $out[] = '';
        }

        $out[] = 'Every prop, every variant and the mockup class names each component'
            .' carries are rendered at `/dev/components`, which is where this table is'
            .' generated from. Add a component to `Modules\Core\Support\ComponentCatalogue`'
            .' and run `php artisan agora:components-doc`; `scripts/check-components.sh`'
            .' fails the build if you forget.';

        return implode("\n", $out);
    }

    private function pending(): string
    {
        $out = [
            'Named here so the next session does not invent a second version of one.',
            'The gallery renders a labelled slot for each, so whoever builds it can drop',
            'it into a page that is already the right shape.',
            '',
            '| Component | Owned by | What it will be |',
            '|---|---|---|',
        ];

        foreach (ComponentCatalogue::pending() as $item) {
            $out[] = sprintf(
                '| `<%s>` | %s | %s |',
                $item['name'],
                $item['owner'],
                $this->cell($item['what']),
            );
        }

        return implode("\n", $out);
    }

    /** A pipe inside a cell ends the cell, and a newline ends the row. */
    private function cell(string $text): string
    {
        return trim(str_replace(['|', "\n"], ['\\|', ' '], $text));
    }
}
