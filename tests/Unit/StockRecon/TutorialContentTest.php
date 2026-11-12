<?php

namespace Tests\Unit\StockRecon;

use Modules\StockRecon\Services\StockReconTutorialContent;
use PHPUnit\Framework\TestCase;

class TutorialContentTest extends TestCase
{
    public function test_navigation_references_resolve_and_every_example_is_reachable(): void
    {
        $content = (new StockReconTutorialContent)->all();
        $ids = [];
        foreach ($content as $collection => $records) {
            $this->assertTrue(array_is_list($records));
            $ids[$collection] = array_column($records, 'id');
            $this->assertSame($ids[$collection], array_values(array_unique($ids[$collection])));
            foreach ($records as $record) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]+$/', $record['id']);
                $this->assertNotSame('', trim($record['title']));
            }
        }
        $reachable = [];
        foreach ($content['lessons'] as $lesson) {
            $this->assertNotEmpty($lesson['body']);
            $this->assertTrue($lesson['flow'] !== [] || $lesson['id'] === 'visual-summary');
            foreach ($lesson['scenario_ids'] as $id) {
                $this->assertContains($id, $ids['scenarios']);
                $reachable[] = $id;
            }
            foreach ($lesson['event_ids'] as $id) {
                $this->assertContains($id, $ids['events']);
            }
        }
        $this->assertEqualsCanonicalizing($ids['scenarios'], array_unique($reachable));
        foreach ($content['events'] as $event) {
            $this->assertContains($event['lesson_id'], $ids['lessons']);
            $this->assertNotEmpty($event['checks']);
            $this->assertNotEmpty($event['next']);
        }
    }

    public function test_worked_quantities_produce_the_advertised_variances_at_every_stage(): void
    {
        $expected = [
            'basic-variance' => [[-5], null, null],
            'zero-balance' => [[-5, 0], [-5, 5], [0, 0]],
            'residual-short' => [[-61, 5], [-61, 60], [-1, 0]],
            'ordinary-offset' => [[-5, 5], null, [-5, 5]],
            'no-offset' => [[-5, -3], null, [-5, -3]],
            'missing-issue' => [[0, 12, 0], null, [0, 2, 0]],
            'issue-reduction' => [[0, -15, 0], null, [0, -5, 0]],
            'clear-duplicate' => [[0, -12], null, [0, 0]],
            'possible-duplicate' => [[-12, -12], null, null],
            'negative-count' => [[-9], null, null],
            'removal-return' => [[-40, 40], null, [-40, 40]],
            'waste-cap' => [[-8], null, [0]],
            'waste-and-issue' => [[0, -15, 0], [0, -11, 0], [0, -1, 0]],
        ];
        foreach ($this->scenarios() as $id => $scenario) {
            foreach (['before', 'prepared', 'after'] as $index => $state) {
                $actual = $scenario[$state] === null ? null : array_map(self::variance(...), $scenario[$state]);
                $this->assertSame($expected[$id][$index], $actual, $id.' '.$state);
            }
        }
    }

    public function test_only_the_opening_exception_examples_move_counted_shorts(): void
    {
        foreach ($this->scenarios() as $scenario) {
            if ($scenario['kind'] !== 'opening-exception') {
                continue;
            }
            $original = $scenario['before'];
            $repaired = $scenario['prepared'];
            $after = $scenario['after'];
            $this->assertGreaterThan($original[0]['close'], $original[1]['open']);
            $this->assertLessThan(0, self::variance($repaired[0]));
            $this->assertGreaterThan(0, self::variance($repaired[1]));
            $this->assertSame($original[0]['close'], $repaired[1]['open']);
            $this->assertSame($original[0]['open'], $after[0]['open']);
            $this->assertSame($original[1]['close'], $after[1]['close']);
            $this->assertSame(array_column($original, 'issued'), array_column($after, 'issued'));
            $this->assertSame(array_column($original, 'pos'), array_column($after, 'pos'));
            $this->assertSame(array_sum(array_map(self::variance(...), $repaired)), array_sum(array_map(self::variance(...), $after)));
            $this->assertNotSame(array_sum(array_map(self::variance(...), $original)), array_sum(array_map(self::variance(...), $repaired)), 'The raw and repaired bases must not be presented as identical.');
        }
        foreach (['ordinary-offset', 'no-offset', 'removal-return'] as $id) {
            $this->assertSame($this->scenarios()[$id]['before'], $this->scenarios()[$id]['after']);
        }
    }

    public function test_issue_examples_preserve_boundaries_counts_and_whole_multiple_remainders(): void
    {
        foreach (['missing-issue', 'issue-reduction', 'waste-and-issue'] as $id) {
            $scenario = $this->scenarios()[$id];
            $last = count($scenario['before']) - 1;
            foreach ($scenario['before'] as $i => $row) {
                $after = $scenario['after'][$i];
                foreach (['open', 'close', 'pos'] as $key) {
                    $this->assertSame($row[$key], $after[$key], $id.' '.$key);
                }
                $delta = $after['issued'] - $row['issued'];
                $this->assertSame(0, $delta % $scenario['multiple']);
                $this->assertGreaterThanOrEqual(0, $after['issued']);
                if ($i === 0 || $i === $last) {
                    $this->assertSame(0, $delta, 'Boundary issues must stay unchanged.');
                }
            }
            $this->assertLessThan($scenario['multiple'], abs(self::variance($scenario['after'][1])));
        }
        $d1 = $this->scenarios()['clear-duplicate'];
        $this->assertSame(12, $d1['after'][0]['issued']);
        $this->assertSame(0, $d1['after'][1]['issued']);
    }

    public function test_waste_is_separate_capped_and_not_counted_twice(): void
    {
        $cap = $this->scenarios()['waste-cap'];
        $this->assertSame(-self::variance($cap['before'][0]), $cap['after'][0]['waste']);
        foreach (['open', 'issued', 'pos', 'close'] as $key) {
            $this->assertSame($cap['before'][0][$key], $cap['after'][0][$key]);
        }
        $combined = $this->scenarios()['waste-and-issue'];
        $this->assertSame(20, $combined['prepared'][1]['issued']);
        $this->assertSame(4, $combined['prepared'][1]['waste']);
        $this->assertSame(4, $combined['after'][1]['waste']);
        $this->assertSame(-1, self::variance($combined['after'][1]));
        foreach (['possible-duplicate', 'negative-count'] as $id) {
            $this->assertNull($this->scenarios()[$id]['after']);
        }
    }

    public function test_changing_master_multiple_explains_the_same_quantity_without_conversion(): void
    {
        $lessons = array_column((new StockReconTutorialContent)->all()['lessons'], null, 'id');
        foreach ($lessons['item-settings']['comparison'] as $example) {
            $this->assertSame(12, $example['issued'] - 20 + $example['remainder']);
        }
        $examples = $lessons['item-settings']['comparison'];
        $this->assertSame([20, 30, 32, 30], array_column($examples, 'issued'));
        $this->assertSame([12, 2, 0, 2], array_column($examples, 'remainder'));
    }

    public function test_visual_summary_is_last_and_paths_reuse_the_verified_worked_cases(): void
    {
        $content = (new StockReconTutorialContent)->all();
        $this->assertSame('visual-summary', $content['lessons'][array_key_last($content['lessons'])]['id']);
        $this->assertCount(8, $content['flows']);
        $lessons = array_column($content['lessons'], 'id');
        foreach ($content['flows'] as $case) {
            $this->assertContains($case['lesson_id'], $lessons);
            $this->assertArrayHasKey($case['scenario_id'], $this->scenarios());
            $scenario = $this->scenarios()[$case['scenario_id']];
            $this->assertNotEmpty($case['next']);
            $this->assertNotEmpty($case['assumptions']);
            $this->assertGreaterThanOrEqual(4, count($case['steps']));
            if ($case['outcome'] === 'held') {
                $this->assertNull($scenario['after'], 'Held paths must not invent a proposal.');
                $this->assertContains('hold', array_column($case['steps'], 'stage'));
            } elseif (in_array($case['outcome'], ['unchanged', 'separate'], true)) {
                $this->assertSame($scenario['before'], $scenario['after']);
            } else {
                $this->assertNotNull($scenario['after']);
                $this->assertNotSame($scenario['before'], $scenario['after']);
            }
            foreach ($case['steps'] as $step) {
                $this->assertNotEmpty($step['value']);
                $this->assertSame(isset($step['answer']), isset($step['alternative']), 'A decision explains its other path.');
            }
        }
        $paths = array_column($content['flows'], null, 'id');
        $stages = array_column($paths['waste-then-issue']['steps'], 'stage');
        $this->assertLessThan(array_search('final', $stages, true), array_search('waste', $stages, true));
        $this->assertTrue($this->scenarios()[$paths['removal-return']['scenario_id']]['separate_periods']);
    }

    /** @return array<string, array<string, mixed>> */
    private function scenarios(): array
    {
        return array_column((new StockReconTutorialContent)->all()['scenarios'], null, 'id');
    }

    /** @param array<string, mixed> $row */
    private static function variance(array $row): int|float
    {
        return $row['pos'] - ($row['open'] + $row['issued'] - $row['close']) + ($row['waste'] ?? 0);
    }
}
