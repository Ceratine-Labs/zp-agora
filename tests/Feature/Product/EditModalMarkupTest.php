<?php

namespace Tests\Feature\Product;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * The edit modal's MARKUP, because Ryan could not use it on the live site.
 *
 * Reported 21 September 2026: on /app/master/stock/3/1 the Edit button does
 * nothing, and after clicking it Back to the list can no longer be clicked.
 * That second half is the diagnosis — a native <dialog> opened by showModal()
 * makes the rest of the page inert, so "nothing happened AND now I cannot
 * click anything" means the dialog DID open and is not visible.
 *
 * Every earlier test asserted the modal's CONTENT was present in the HTML.
 * None of them asserted the structure a browser needs to render it, which is
 * why they were all green while the screen was unusable.
 */
class EditModalMarkupTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private int $branchId;

    /** @var array<int, User> */
    private array $made = [];

    private string $html = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes stock master rows.');
        $this->branchId = (int) config('agora.group_branch_id', 2);
        $this->cleanUp();
        $this->seedFixture();

        $this->html = $this->actingAs($this->admin())
            ->get(route('app.master.stock.show', ['branch' => self::BRANCH, 'item' => '10']))
            ->assertOk()
            ->getContent();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserBranch::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_the_dialog_element_is_actually_on_the_page(): void
    {
        // modal.js binds to [data-modal]; without the element there is nothing
        // to open and the click does nothing at all.
        $this->assertStringContainsString('id="edit-stock-item"', $this->html);
        $this->assertStringContainsString('data-modal', $this->html);
    }

    public function test_the_trigger_names_the_dialog_it_opens(): void
    {
        $this->assertStringContainsString('data-modal-open="edit-stock-item"', $this->html);
    }

    public function test_the_edit_form_is_not_nested_inside_the_close_form(): void
    {
        /*
         * <x-modal> writes its own <form method="dialog"> for the close
         * button. A nested form is invalid HTML and the browser reparents it,
         * which would take the edit form's fields out of the form that
         * submits them.
         *
         * They are siblings in the component, so this should pass — it is
         * here because it is the first thing anybody will suspect, and a test
         * that rules it out is cheaper than reading the component again.
         */
        $dialog = $this->dialogHtml();
        $closeForm = strpos($dialog, '<form method="dialog"');
        $editForm = strpos($dialog, 'action="');

        $this->assertNotFalse($closeForm);
        $this->assertNotFalse($editForm);

        // The close form must be closed before the edit form opens.
        $closeFormEnd = strpos($dialog, '</form>', $closeForm);
        $this->assertLessThan($editForm, $closeFormEnd, 'The edit form is nested inside the close form.');
    }

    public function test_the_dialog_carries_content_a_browser_can_show(): void
    {
        /*
         * THE ONE THAT MATTERS. A <dialog> opened with showModal() makes the
         * whole page inert. If it opens with nothing visible in it, the user
         * sees "the button did nothing" and then cannot click anything —
         * which is exactly what was reported.
         */
        $dialog = $this->dialogHtml();

        $this->assertStringContainsString('Change this stock line', $dialog, 'The dialog has no title.');
        $this->assertStringContainsString('name="reason"', $dialog, 'The dialog has no reason field.');
        $this->assertStringContainsString('Save the override', $dialog, 'The dialog has no submit button.');

        // Something with actual height. A dialog containing only whitespace
        // renders as a sliver nobody notices.
        $text = trim(strip_tags($dialog));
        $this->assertGreaterThan(200, strlen($text), 'The dialog renders almost no text.');
    }

    public function test_the_form_posts_where_it_should_with_a_token_and_a_method(): void
    {
        $dialog = $this->dialogHtml();

        $this->assertStringContainsString(
            route('app.master.stock.update', ['branch' => self::BRANCH, 'item' => '10']),
            $dialog
        );
        $this->assertStringContainsString('name="_token"', $dialog);
        $this->assertStringContainsString('value="PUT"', $dialog);
    }

    /** Everything between the opening <dialog and its closing tag. */
    private function dialogHtml(): string
    {
        $start = strpos($this->html, '<dialog');
        $this->assertNotFalse($start, 'There is no <dialog> on the page at all.');

        $end = strpos($this->html, '</dialog>', $start);
        $this->assertNotFalse($end, 'The <dialog> is never closed.');

        return substr($this->html, $start, $end - $start + 9);
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'EmailAddress' => 'TEST-modal-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-admin',
            'IsActive' => true,
            'IsLocked' => false,
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, 'admin');
        $this->made[] = $user;

        return $user;
    }

    private function seedFixture(): void
    {
        $this->db()->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => 1,
            'AreaDescription' => 'TEST-Shop floor', 'AreaGroup' => 'Retail Items',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 0,
            'ShowReport' => 1, 'IsCaptureWaste' => 0,
        ]);

        $this->db()->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH, 'StockItemNo' => '10',
            'StockItemDescription' => 'TEST-PIE CHICKEN', 'AreaNo' => 1,
            'Location' => 'WINBRANCH', 'POSCode' => '1200',
            'SellingPrice' => 29.99, 'PriceType' => 'Set Price', 'Factor' => 0,
            'UOMCode' => 'Each', 'IssueMultiple' => 1, 'IssueMultiplePercentage' => 0,
            'QtyVarAllowance' => 0, 'isMonitoredItem' => 0, 'IsDoCloseQtyCalc' => 1,
            'IsAllowNegativeQtyIssued' => 0, 'IsAllowNegativeQtyClose' => 0,
            'IsStockItemPreProduction' => 0, 'isPreProductionItem' => 0,
            'PreProductionTypeNo' => 0, 'Ratio' => 0, 'ProduceLimitPercentage' => 0,
            'CreateDateTime' => '2026-01-01 00:00:00',
        ]);
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.StockItem')->where('BranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->where('SSBranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
