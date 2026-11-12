<?php

namespace Tests\Feature\Console;

use App\Console\Commands\InitSchemaCommand;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class InitSchemaCommandTest extends TestCase
{
    #[DataProvider('loopbackHosts')]
    public function test_loopback_target_is_named_and_enter_defaults_to_no(string $host): void
    {
        $this->database($host);
        $command = $this->command();
        $command->setInputs(['']);

        $this->assertSame(Command::FAILURE, $command->execute([], ['interactive' => true]));
        $output = $command->getDisplay();
        $this->assertStringContainsString("About to CREATE SCHEMA [agora] in ResolvedAgora on {$host}.", $output);
        $this->assertStringContainsString('local (loopback) connection', $output);
        $this->assertStringNotContainsString('customer production', $output);
        $this->assertStringNotContainsString('MisleadingDatabase', $output);
        $this->assertStringContainsString('Proceed?', $output);
        $this->assertStringContainsString('Aborted. No statement was sent.', $output);
    }

    /** @return array<string, array{string}> */
    public static function loopbackHosts(): array
    {
        return [
            'IPv4' => ['127.0.0.1'],
            'hostname' => ['localhost'],
            'IPv6' => ['::1'],
            'hostname case' => ['LOCALHOST'],
        ];
    }

    public function test_remote_target_warns_about_customer_production_and_defaults_to_no(): void
    {
        $this->database('192.0.2.10');
        $command = $this->command();
        $command->setInputs(['']);

        $this->assertSame(Command::FAILURE, $command->execute([], ['interactive' => true]));
        $output = $command->getDisplay();
        $this->assertStringContainsString('ResolvedAgora on 192.0.2.10', $output);
        $this->assertStringContainsString('remote connection; the target may be customer production', $output);
        $this->assertStringNotContainsString('local (loopback)', $output);
        $this->assertStringContainsString('Proceed?', $output);
        $this->assertStringContainsString('Aborted. No statement was sent.', $output);
    }

    public function test_explicit_confirmation_creates_the_schema(): void
    {
        $this->database('localhost', create: true);
        $command = $this->command();
        $command->setInputs(['yes']);

        $this->assertSame(Command::SUCCESS, $command->execute([], ['interactive' => true]));
        $this->assertStringContainsString('Proceed?', $command->getDisplay());
        $this->assertStringContainsString('Created schema [agora] in ResolvedAgora on localhost.', $command->getDisplay());
    }

    public function test_force_retains_the_target_warning_and_skips_confirmation(): void
    {
        $this->database('192.0.2.10', create: true);
        $command = $this->command();

        $this->assertSame(Command::SUCCESS, $command->execute(['--force' => true], ['interactive' => true]));
        $output = $command->getDisplay();
        $this->assertStringContainsString('remote connection; the target may be customer production', $output);
        $this->assertStringNotContainsString('Proceed?', $output);
        $this->assertStringContainsString('Created schema [agora] in ResolvedAgora on 192.0.2.10.', $output);
    }

    public function test_an_existing_schema_is_a_no_op_and_still_names_the_target(): void
    {
        $this->database('192.0.2.10', exists: true);
        $command = $this->command();

        $this->assertSame(Command::SUCCESS, $command->execute([], ['interactive' => true]));
        $output = $command->getDisplay();
        $this->assertStringContainsString('Schema [agora] already exists in ResolvedAgora on 192.0.2.10. Nothing to do.', $output);
        $this->assertStringNotContainsString('CREATE SCHEMA', $output);
        $this->assertStringNotContainsString('Proceed?', $output);
    }

    public function test_help_names_the_application_database(): void
    {
        $description = (new InitSchemaCommand)->getDescription();

        $this->assertStringContainsString('application database', $description);
        $this->assertStringNotContainsString('PumpIT', $description);
    }

    private function command(): CommandTester
    {
        $command = new InitSchemaCommand;
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }

    /** Every database operation is mocked, including the metadata lookup. */
    private function database(string $host, bool $exists = false, bool $create = false): void
    {
        config([
            'agora.schema' => 'agora',
            'agora.connections.app' => 'schema_target',
            // Resolved connection metadata must win over stale raw config.
            'database.connections.schema_target.database' => 'MisleadingDatabase',
            'database.connections.schema_target.host' => 'misleading.invalid',
        ]);
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('getDatabaseName')->willReturn('ResolvedAgora');
        $db->expects($this->once())->method('getConfig')->with('host')->willReturn($host);
        $db->expects($this->once())->method('selectOne')
            ->with('SELECT SCHEMA_ID(?) AS id', ['agora'])
            ->willReturn((object) ['id' => $exists ? 5 : null]);
        if ($create) {
            $db->expects($this->once())->method('statement')->with('CREATE SCHEMA [agora]')->willReturn(true);
        } else {
            $db->expects($this->never())->method('statement');
        }
        DB::shouldReceive('connection')->once()->with('schema_target')->andReturn($db);
    }
}
