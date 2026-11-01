<?php

namespace Tests\Feature\Grid;

use App\Grid\GridDefinition;
use App\Grid\GridQuery;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Tests\TestCase;

/** No database access: verify the scope passed to both page and export sources. */
class GridBranchScopeTest extends TestCase
{
    public function test_empty_head_office_selection_keeps_the_current_grants(): void
    {
        foreach ([[], ['branches' => []], ['branches' => ''], ['branches' => [' ', '']]] as $parameters) {
            $unrestricted = $this->gridQuery((new BranchContext)->setAllowed([]), $parameters);
            $this->assertSame([], $unrestricted->branchIds);
            $this->assertNull($unrestricted->procedureParameters()['BranchIds']);

            $restricted = $this->gridQuery((new BranchContext)->setAllowed([8, 15]), $parameters);
            $this->assertSame([8, 15], $restricted->branchIds);
            $this->assertSame('8,15', $restricted->procedureParameters()['BranchIds']);
        }
    }

    public function test_a_forbidden_selection_cannot_become_an_unrestricted_page_or_export(): void
    {
        $context = (new BranchContext)->setAllowed([8]);
        $query = $this->gridQuery($context, ['branches' => ['15', '24']]);

        $this->assertSame([-1], $query->branchIds);
        $this->assertSame('-1', $query->procedureParameters()['BranchIds']);
        $this->assertSame('-1', $query->forExport(100000)->procedureParameters()['BranchIds']);
        $this->assertSame('-1', $query->onPage(2)->procedureParameters()['BranchIds']);
    }

    public function test_invalid_selection_does_not_fall_back_to_all_sites_or_grants(): void
    {
        foreach ([[], [8]] as $allowed) {
            $context = (new BranchContext)->setAllowed($allowed);

            foreach (['garbage', '8junk', '0', '-1', '2147483648', [['8']], ['8.5']] as $value) {
                $query = $this->gridQuery($context, ['branches' => $value]);
                $this->assertSame([-1], $query->branchIds);
                $this->assertSame('-1', $query->procedureParameters()['BranchIds']);
            }
        }
    }

    public function test_a_valid_selection_is_narrowed_and_deduplicated(): void
    {
        $context = (new BranchContext)->setAllowed([8, 15]);

        foreach ([
            ['15', '24', '8', '15', ['8'], '8junk'],
            '15,24,8,15,8junk',
        ] as $value) {
            $query = $this->gridQuery($context, ['branches' => $value]);
            $this->assertSame([15, 8], $query->branchIds);
            $this->assertSame('15,8', $query->procedureParameters()['BranchIds']);
        }
    }

    public function test_a_branch_workspace_ignores_the_requested_sites_and_requires_a_pin(): void
    {
        $context = (new BranchContext)->setWorkspace('branch')->setAllowed([8, 15])->set(8);
        $this->assertSame([8], $this->gridQuery($context, ['branches' => [15]])->branchIds);

        $context->set(null);
        $query = $this->gridQuery($context, ['branches' => [15]]);
        $this->assertSame([-1], $query->branchIds);
        $this->assertSame('-1', $query->procedureParameters()['BranchIds']);
    }

    public function test_a_namespaced_grid_uses_its_own_selection_parameter(): void
    {
        $context = (new BranchContext)->setAllowed([8, 15]);
        $query = $this->gridQuery($context, ['branches' => [8], 'detail_branches' => [24]], key: 'app.test:detail');

        $this->assertSame([-1], $query->branchIds);
        $this->assertSame([8, 15], $this->gridQuery($context, ['branches' => [24]], key: 'app.test:detail')->branchIds);
    }

    public function test_head_office_grids_without_a_selector_keep_their_existing_scope_contract(): void
    {
        $context = (new BranchContext)->setAllowed([8]);
        $this->assertSame([], $this->gridQuery($context, ['branches' => [24]], selector: false)->branchIds);
    }

    /** @param array<string, mixed> $parameters */
    private function gridQuery(BranchContext $context, array $parameters, bool $selector = true, string $key = 'app.test'): GridQuery
    {
        $definition = new class($selector, $key) extends GridDefinition
        {
            public function __construct(private bool $selector, private string $gridKey) {}

            public function key(): string
            {
                return $this->gridKey;
            }

            public function title(): string
            {
                return 'Scope fixture';
            }

            public function columns(): array
            {
                return [];
            }

            public function source(): GridSource
            {
                return new ProcedureSource('agora.usp_TEST_GridScope');
            }

            public function branchSelector(): bool
            {
                return $this->selector;
            }
        };

        return (new GridService(new GridRegistry, $context))->query(
            $definition, Request::create('/app/test', 'GET', $parameters),
        );
    }
}
