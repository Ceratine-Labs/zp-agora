<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run a writing test anywhere but the local container.
     *
     * `phpunit.xml` deliberately has no sqlite fallback, so a test runs
     * against whatever `agora` points at — and on a checkout pointed at the
     * customer's instance that is a 249 GB database people are trading on.
     * Every test that writes calls this first.
     *
     * It lived as a private copy in two test classes before this; a third
     * copy was about to be written, which is the moment a duplicated guard
     * becomes a guard somebody forgets.
     */
    protected function skipUnlessLocalStub(string $because = 'This test writes rows.'): void
    {
        $connection = config('agora.connections.app');
        $host = config("database.connections.{$connection}.host");

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped(
                "{$because} [{$connection}] points at [{$host}], so this runs against the local container only."
            );
        }
    }
}
