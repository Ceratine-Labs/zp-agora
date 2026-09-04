<?php

namespace Tests\Fixtures\Seeders;

/**
 * What the fixture seeders below did, so a test can assert a seeder ran exactly
 * once without any of them writing to the customer's database.
 */
class TestState
{
    /** @var list<string> */
    public static array $invocations = [];

    public static function reset(): void
    {
        self::$invocations = [];
    }

    public static function ran(string $seeder): void
    {
        self::$invocations[] = $seeder;
    }

    public static function count(string $seeder): int
    {
        return count(array_filter(self::$invocations, fn (string $s) => $s === $seeder));
    }
}
