<?php

namespace Tests\Unit\StockRecon;

use Modules\StockRecon\Services\StockReconTutorialContent;
use PHPUnit\Framework\TestCase;

class TutorialContentTest extends TestCase
{
    public function test_navigation_references_resolve_and_every_example_is_reachable(): void
    {
        $content = (new StockReconTutorialContent)->all();
        $this->assertSame(['lessons', 'scenarios', 'events'], array_keys($content));
        $this->assertCount(12, $content['lessons']);
        $this->assertCount(7, $content['scenarios']);
        $this->assertCount(14, $content['events']);

        $ids = [];
        foreach ($content as $collection => $records) {
            $this->assertTrue(array_is_list($records), $collection);
            $ids[$collection] = array_column($records, 'id');
            $this->assertSame($ids[$collection], array_values(array_unique($ids[$collection])), $collection);
            foreach ($records as $record) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]+$/', $record['id']);
                $this->assertNotSame('', trim($record['title']));
            }
        }

        $reachable = [];
        foreach ($content['lessons'] as $lesson) {
            $this->assertSame(['id', 'title', 'icon', 'kicker', 'summary', 'body', 'steps', 'scenario_id', 'event_ids'], array_keys($lesson));
            $this->assertContains($lesson['icon'], ['book', 'box', 'link', 'scale', 'alert', 'receipt', 'shield', 'check', 'search', 'history']);
            $this->assertNotEmpty($lesson['body']);
            $this->assertNotEmpty($lesson['steps']);
            foreach ($lesson['steps'] as $step) {
                $this->assertSame(['title', 'text', 'screen'], array_keys($step));
                $this->assertNotSame('', trim($step['screen']));
            }
            if ($lesson['scenario_id'] !== null) {
                $this->assertContains($lesson['scenario_id'], $ids['scenarios']);
                $reachable[] = $lesson['scenario_id'];
            }
            foreach ($lesson['event_ids'] as $eventId) {
                $this->assertContains($eventId, $ids['events']);
            }
        }
        $this->assertEqualsCanonicalizing($ids['scenarios'], array_values(array_unique($reachable)));

        foreach ($content['scenarios'] as $scenario) {
            $this->assertSame(['id', 'title', 'kind', 'summary', 'explanation', 'checks', 'before', 'after', 'steps', 'event_ids'], array_keys($scenario));
            $this->assertStringContainsString('Training dataset:', $scenario['explanation']);
            foreach ($scenario['event_ids'] as $eventId) {
                $this->assertContains($eventId, $ids['events']);
            }
        }
        foreach ($content['events'] as $event) {
            $this->assertSame(['id', 'title', 'code', 'tone', 'meaning', 'example', 'checks', 'next', 'location', 'lesson_id', 'write_effect'], array_keys($event));
            $this->assertContains($event['lesson_id'], $ids['lessons']);
            $this->assertContains($event['tone'], ['good', 'warn', 'serious', 'crit', 'neutral']);
            $this->assertNotEmpty($event['checks']);
            $this->assertNotEmpty($event['next']);
            $this->assertNotSame('', trim($event['write_effect']));
        }
    }

    public function test_worked_quantities_produce_the_advertised_signed_variances(): void
    {
        $expected = [
            'zero-balance' => ['before' => [-10, 10], 'after' => [0, 0]],
            'residual-short' => ['before' => [-5, 3], 'after' => [-2, 0]],
            'no-offset' => ['before' => [-5, -3], 'after' => [-5, -3]],
            'missing-issue' => ['before' => [5], 'after' => [0]],
            'clear-duplicate' => ['before' => [0, -12], 'after' => [0, 0]],
            'possible-duplicate' => ['before' => [-12, -12], 'after' => null],
            'negative-count' => ['before' => [-9], 'after' => null],
        ];

        foreach ((new StockReconTutorialContent)->all()['scenarios'] as $scenario) {
            foreach (['before', 'after'] as $stage) {
                if ($expected[$scenario['id']][$stage] === null) {
                    $this->assertNull($scenario[$stage], $scenario['id']);

                    continue;
                }
                $actual = [];
                foreach ($scenario[$stage] as $index => $row) {
                    $this->assertSame(['shift', 'open', 'issued', 'pos', 'close'], array_keys($row));
                    foreach (['open', 'issued', 'pos', 'close'] as $quantity) {
                        $this->assertTrue(is_int($row[$quantity]) || is_float($row[$quantity]), $quantity);
                    }
                    $actual[] = self::variance($row);
                    if ($index > 0) {
                        $this->assertSame($scenario[$stage][$index - 1]['close'], $row['open'], $scenario['id'].' continuity');
                    }
                }
                $this->assertSame($expected[$scenario['id']][$stage], $actual, $scenario['id'].' '.$stage);
            }
        }
    }

    public function test_count_balancing_preserves_anchors_issues_sales_and_total(): void
    {
        foreach ((new StockReconTutorialContent)->all()['scenarios'] as $scenario) {
            if ($scenario['kind'] !== 'count-balancing') {
                continue;
            }
            $before = $scenario['before'];
            $after = $scenario['after'];
            $last = count($before) - 1;
            $this->assertSame($before[0]['open'], $after[0]['open']);
            $this->assertSame($before[$last]['close'], $after[$last]['close']);
            $this->assertSame(array_column($before, 'issued'), array_column($after, 'issued'));
            $this->assertSame(array_column($before, 'pos'), array_column($after, 'pos'));
            $this->assertSame(array_sum(array_map(self::variance(...), $before)), array_sum(array_map(self::variance(...), $after)));

            foreach ($before as $index => $row) {
                $was = self::variance($row);
                $becomes = self::variance($after[$index]);
                if ($was < 0) {
                    $this->assertGreaterThanOrEqual($was, $becomes, 'A count proposal must not increase a short.');
                } elseif ($was > 0) {
                    $this->assertSame(0, $becomes, 'An over must reach zero without becoming a short.');
                }
            }
        }
    }

    public function test_issue_examples_change_only_the_explicit_issue_and_explain_that_change(): void
    {
        $scenarios = array_column((new StockReconTutorialContent)->all()['scenarios'], null, 'id');
        foreach (['missing-issue', 'clear-duplicate'] as $id) {
            $scenario = $scenarios[$id];
            $this->assertSame('issue-correction', $scenario['kind']);
            foreach ($scenario['before'] as $index => $row) {
                foreach (['shift', 'open', 'pos', 'close'] as $unchanged) {
                    $this->assertSame($row[$unchanged], $scenario['after'][$index][$unchanged], $id.' '.$unchanged);
                }
            }
        }

        $missing = $scenarios['missing-issue'];
        $over = self::variance($missing['before'][0]);
        $this->assertGreaterThan(0, $over);
        $this->assertSame($over, $missing['after'][0]['issued'] - $missing['before'][0]['issued']);
        $this->assertStringContainsString('additional issue of 5', $missing['explanation']);

        $duplicate = $scenarios['clear-duplicate'];
        $this->assertSame(12, $duplicate['after'][0]['issued'], 'The real neighbouring issue must stay.');
        $this->assertSame(0, $duplicate['after'][1]['issued']);
        $this->assertStringContainsString('full D1 evidence is confirmed', $duplicate['explanation']);
        $this->assertStringContainsString('not the complete duplicate test', $duplicate['explanation']);
    }

    public function test_unresolved_cases_do_not_offer_a_fabricated_correction(): void
    {
        $scenarios = array_column((new StockReconTutorialContent)->all()['scenarios'], null, 'id');
        $this->assertSame($scenarios['no-offset']['before'], $scenarios['no-offset']['after']);
        foreach (['possible-duplicate', 'negative-count'] as $id) {
            $this->assertSame('held', $scenarios[$id]['kind']);
            $this->assertNull($scenarios[$id]['after']);
        }
    }

    /** @param array{shift: string, open: int|float, issued: int|float, pos: int|float, close: int|float} $row */
    private static function variance(array $row): int|float
    {
        return $row['pos'] - ($row['open'] + $row['issued'] - $row['close']);
    }
}
