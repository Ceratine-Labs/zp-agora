<?php

namespace Tests\Feature\Grid;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Models\UserGridColumn;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

class TableLayoutTest extends TestCase
{
    use GrantsAccess;

    private bool $started = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::connection(config('agora.connections.app'))->beginTransaction();
        $this->started = true;
    }

    protected function tearDown(): void
    {
        if ($this->started) {
            DB::connection(config('agora.connections.app'))->rollBack();
        }
        parent::tearDown();
    }

    private function user(): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'), 'UserName' => 'TEST-layout',
            'EmailAddress' => 'TEST-layout-'.uniqid().'@agora.invalid', 'IsActive' => true,
            'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only',
        ]);
        $this->grantProfile($user, 'admin');

        return $user;
    }

    public function test_table_component_renders_a_signed_layout_url(): void
    {
        $this->actingAs($this->user());
        $html = Blade::render('<x-table layout-key="TEST-table"><x-slot:head><tr><th>Name</th></tr></x-slot:head><tr><td>Example</td></tr></x-table>');
        $this->assertStringContainsString('data-table-layout-url=', $html);
        $this->assertStringContainsString('signature=', $html);
        $withSelection = Blade::render('<x-table layout-key="TEST-table"><x-slot:head><tr><th></th><th>Name</th></tr></x-slot:head><tr><td></td><td>Example</td></tr></x-table>');
        preg_match('/data-table-layout-url="([^"]+)"/', $html, $plainUrl);
        preg_match('/data-table-layout-url="([^"]+)"/', $withSelection, $selectedUrl);
        $this->assertNotSame($plainUrl[1], $selectedUrl[1]);
    }

    public function test_layout_round_trip_is_isolated_by_user_and_rejects_invalid_requests(): void
    {
        $first = $this->user();
        $second = $this->user();
        $key = hash('sha256', 'TEST-table-layout');
        $signature = hash_hmac('sha256', $key, (string) config('app.key'));
        $url = route('app.table-layouts.show', ['key' => $key, 'signature' => $signature]);
        $state = ['order' => [1, 0], 'hidden' => [1], 'widths' => [0 => 220], 'text_size' => 'large', 'height' => 'compact'];

        $this->actingAs($first)->postJson($url, $state + ['unwanted' => 'ignored'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('state.text_size', 'large')->assertJsonPath('state.order', [1, 0]);
        $this->postJson(route('app.table-layouts.read'), ['tables' => [['key' => $key, 'signature' => $signature]]])
            ->assertOk()->assertJsonPath('states.'.$key.'.text_size', 'large');
        $this->assertArrayNotHasKey('unwanted', UserGridColumn::layout((int) $first->Id, 'table:'.$key));
        $this->actingAs($second)->getJson($url)->assertOk()->assertJsonPath('state', []);
        $this->postJson($url, ['widths' => [0 => 10000]])->assertUnprocessable();
        $this->getJson(route('app.table-layouts.show', ['key' => $key, 'signature' => 'bad']))->assertNotFound();
        $this->postJson(route('app.table-layouts.read'), ['tables' => [['key' => $key, 'signature' => 'bad']]])->assertNotFound();
        $this->actingAs($first)->deleteJson($url)->assertOk();
        $this->getJson($url)->assertJsonPath('state', []);
    }
}
