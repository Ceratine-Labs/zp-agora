<?php

namespace Tests\Feature\Email;

use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Mail\StaffShortDecidedMail;
use Modules\Cash\Mail\StaffShortRaisedMail;
use Modules\Core\Database\Seeders\EmailTemplateSeeder;
use Modules\Core\Mail\PasswordResetMail;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PasswordResetService;
use Modules\Core\Services\PermissionService;
use Modules\Debtors\Mail\DebtorStatementMail;
use Modules\Email\Database\Seeders\PermissionSeeder;
use Modules\Email\Models\EmailTemplate;
use Modules\Email\Services\TemplateAccess;
use Modules\Email\Services\TemplateCatalog;
use Modules\Email\Services\TemplateMarkup;
use Modules\Email\Services\TemplateService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    private bool $transaction = false;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['app', 'erp', 'pos_landing', 'reporting'] as $role) {
            $connection = config('agora.connections.'.$role);
            if (! in_array(config('database.connections.'.$connection.'.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
                $this->markTestSkipped('Email writing tests require every connection to be local.');
            }
        }
        DB::beginTransaction();
        $this->transaction = true;
        foreach (app(TemplateCatalog::class)->all() as $definition) {
            app(TemplateService::class)->seed($definition);
        }
        app(PermissionSeeder::class)->run();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        if ($this->transaction) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function data(string $subject = 'TEST Hello {{name}}'): array
    {
        return ['Subject' => $subject, 'BodyHtml' => '<p>Hello {{name}}. <a href="{{url}}">Open</a></p>',
            'BodyText' => 'Hello {{name}}. {{url}}', 'IsActive' => 1, 'Version' => 0];
    }

    /** @param list<string> $permissions */
    private function person(array $permissions, bool $restricted = false): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'UserName' => 'TEST-email-templates', 'EmailAddress' => 'TEST-email-'.uniqid().'@agora.invalid',
            'PasswordHash' => 'TEST-local-password-123456', 'IsActive' => true, 'IsLocked' => false,
            'MustChangePassword' => false, 'Workspace' => $restricted ? 'branch' : 'ho',
            'HomeBranchId' => $restricted ? 999 : null,
        ]);
        foreach ($permissions as $code) {
            UserPermission::query()->acrossBranches()->create([
                'BranchId' => 2, 'UserId' => $user->Id,
                'PermissionId' => Permission::query()->acrossBranches()->where('Code', $code)->value('Id'),
            ]);
        }
        if ($restricted) {
            DB::table('agora.UserBranch')->insert(['BranchId' => 999, 'UserId' => $user->Id]);
        }
        app(PermissionService::class)->forget($user);

        return $user;
    }

    public function test_module_seeders_register_five_defaults_without_overwriting_edits(): void
    {
        $catalog = app(TemplateCatalog::class);
        $this->assertSame(['cash.staff_short_decided', 'cash.staff_short_raised', 'core.password_reset', 'core.welcome', 'debtors.statement'], array_keys($catalog->all()));
        $service = app(TemplateService::class);
        $row = $service->resolve('core.welcome', 2);
        $saved = $service->save(2, 'core.welcome', array_replace($this->data(), ['Version' => $row->Version]), null);
        app(EmailTemplateSeeder::class)->run();
        $this->assertSame($saved->Version, $service->resolve('core.welcome', 2)->Version);
        $this->assertSame('TEST Hello {{name}}', $service->resolve('core.welcome', 2)->Subject);
        $this->assertSame(['name', 'url'], $saved->VariablesJson);
    }

    public function test_unknown_variables_expressions_and_unsafe_html_are_refused_without_writes(): void
    {
        $service = app(TemplateService::class);
        $before = DB::table('agora.EmailTemplateVersion')->count();
        foreach ([
            ['Subject' => '{{unknown}}'], ['BodyText' => '{{name.toUpperCase()}}'],
            ['BodyHtml' => '<p>Hello</p><!--[if mso]><img src="https://example.invalid/pixel"><![endif]-->'],
            ['BodyHtml' => '<script>alert(1)</script>'], ['BodyHtml' => '<p onclick="alert(1)">Hello</p>'],
            ['BodyHtml' => '<img src="https://example.invalid/tracker">'], ['BodyHtml' => '<a href="javascript:alert(1)">Open</a>'],
            ['BodyHtml' => '<a href="{{name}}">Open</a>'], ['BodyHtml' => '<?php phpinfo(); ?>'],
            ['BodyHtml' => '<a href="https://example.invalid/{{name}}">Open</a>'],
            ['Subject' => "Hello\r\nBcc: injected@example.invalid"],
        ] as $bad) {
            try {
                $service->save(999, 'core.welcome', array_replace($this->data(), $bad), null);
                $this->fail('Unsafe or unknown markup was accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame($before, DB::table('agora.EmailTemplateVersion')->count());
        $this->assertFalse(EmailTemplate::query()->acrossBranches()->where('BranchId', 999)->where('Code', 'core.welcome')->exists());
    }

    public function test_override_is_exact_and_inactive_override_falls_back_and_versions_reject_stale_save(): void
    {
        $service = app(TemplateService::class);
        $global = $service->resolve('core.welcome', 2);
        $override = $service->save(999, 'core.welcome', $this->data(), null);
        app(BranchContext::class)->set(999);
        $this->assertSame($override->Id, $service->resolve('core.welcome', 999)->Id);
        $this->assertSame($global->Id, $service->resolve('core.welcome', 998)->Id);
        $inactive = $service->save(999, 'core.welcome', array_replace($this->data(), ['Version' => 1, 'IsActive' => false]), null);
        $this->assertSame(2, $inactive->Version);
        $this->assertSame($global->Id, $service->resolve('core.welcome', 999)->Id);
        $history = DB::table('agora.EmailTemplateVersion')->where('BranchId', 999)->where('TemplateId', $override->Id)->orderBy('Version')->get();
        $this->assertSame([1, 2], $history->pluck('Version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1, 0], $history->pluck('IsActive')->map(fn ($v) => (int) $v)->all());
        $this->expectException(ValidationException::class);
        $service->save(999, 'core.welcome', array_replace($this->data('TEST stale'), ['Version' => 1]), null);
    }

    public function test_unrestricted_editor_can_update_global_and_disabled_global_refuses_without_an_active_override(): void
    {
        $this->actingAs($this->person(['email.templates.view', 'email.templates.edit']));
        $service = app(TemplateService::class);
        $row = $service->resolve('core.welcome', 2);
        $this->post(route('app.setup.email-templates.save', ['branch' => 2, 'code' => 'core.welcome']),
            array_replace($this->data(), ['Version' => $row->Version, 'IsActive' => false]))->assertRedirect()->assertSessionHasNoErrors();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active email template');
        $service->resolve('core.welcome', 999);
    }

    public function test_group_grant_cannot_turn_a_restricted_editor_into_a_global_editor(): void
    {
        $context = app(BranchContext::class);
        foreach (['branch', 'ho'] as $workspace) {
            $context->setWorkspace($workspace)->setAllowed([2])->set(2);
            $access = app(TemplateAccess::class);
            $this->assertFalse($access->mayEditGlobal());
            $this->assertFalse($access->scopes()->has(2));
            $access->check(2); // Group fallback remains readable.
            try {
                $access->check(2, true);
                $this->fail('An explicit group grant permitted a global edit.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_preview_uses_samples_escapes_values_and_is_isolated(): void
    {
        $this->actingAs($this->person(['email.templates.view', 'email.templates.edit'], true));
        $this->post(route('app.setup.email-templates.preview', ['branch' => 999, 'code' => 'core.welcome']), $this->data())
            ->assertOk()->assertSee('Sample person')->assertDontSee('{{name}}')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; sandbox");
        $this->post(route('app.setup.email-templates.preview', ['branch' => 999, 'code' => 'core.welcome']), $this->data('{{unknown}}'))
            ->assertUnprocessable()->assertSee('Unknown variable: unknown.')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'; sandbox");
        $definition = app(TemplateCatalog::class)->get('core.welcome');
        $markup = app(TemplateMarkup::class);
        $rendered = $markup->render($definition, 'Hello {{name}}', '<p>{{name}}</p>', '{{name}}', ['name' => '<img src=x onerror=alert(1)>']);
        $this->assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $rendered->html);
        $this->assertSame('<img src=x onerror=alert(1)>', $rendered->text);
        $this->expectException(ValidationException::class);
        $markup->render($definition, 'Hello', '<a href="{{url}}">Open</a>', 'Hello', ['url' => 'javascript:alert(1)']);
    }

    public function test_http_permissions_scope_filter_unknown_variable_and_server_owned_metadata(): void
    {
        $this->actingAs($this->person([]));
        $index = route('app.setup.email-templates.index');
        $this->getJson($index)->assertForbidden();
        $user = $this->person(['email.templates.view', 'email.templates.edit'], true);
        $this->actingAs($user);
        $this->get($index.'?module=core')->assertOk()->assertSee('core.password_reset')->assertDontSee('cash.staff_short_raised</code>', false);
        $route = route('app.setup.email-templates.save', ['branch' => 999, 'code' => 'core.welcome']);
        $this->postJson($route, $this->data('{{bad}}'))->assertUnprocessable()->assertJsonValidationErrors('Subject');
        $this->post($route, $this->data() + ['ModuleTag' => 'cash', 'VariablesJson' => '["bad"]'])->assertRedirect();
        $row = app(TemplateService::class)->resolve('core.welcome', 999);
        $this->assertSame('core', $row->ModuleTag);
        $this->assertSame(['name', 'url'], $row->VariablesJson);
        $this->get(route('app.setup.email-templates.edit', ['branch' => 999, 'code' => 'core.welcome']))->assertOk()->assertSee('Version history')->assertSee('sandbox=""', false);
        foreach ([2, 998] as $branch) {
            $this->post(route('app.setup.email-templates.save', ['branch' => $branch, 'code' => 'core.welcome']), $this->data())->assertForbidden();
        }
        $this->get(route('app.setup.email-templates.edit', ['branch' => 998, 'code' => 'core.welcome']))->assertForbidden();
        $this->post(route('app.setup.email-templates.preview', ['branch' => 998, 'code' => 'core.welcome']), $this->data())->assertForbidden();
        $this->actingAs($this->person(['email.templates.view'], true));
        $this->post($route, array_replace($this->data(), ['Version' => 1]))->assertForbidden();
    }

    public function test_existing_mail_classes_render_templates_keep_recipients_and_pdf_and_send_mode(): void
    {
        $user = $this->person([]);
        $user->UserName = '<Sample user>';
        $password = new PasswordResetMail($user, 'TEST-token', '123456');
        $this->assertStringContainsString('&lt;Sample user&gt;', $password->render());
        $this->assertStringContainsString('123456', $password->render());
        $this->assertSame('Set your Agora password', $password->envelope()->subject);
        $this->assertStringContainsString(route('password.reset', ['token' => 'TEST-token']), $password->render());
        $this->assertStringContainsString('expire in '.PasswordResetService::LIFETIME_MINUTES.' minutes', preg_replace('/\s+/', ' ', $password->render()));
        $this->assertStringContainsString('After '.PasswordReset::MAX_ATTEMPTS.' incorrect codes', preg_replace('/\s+/', ' ', $password->render()));
        $short = ['branchId' => 999, 'no' => 42, 'site' => 'TEST-site', 'employee' => 'TEST-person', 'code' => 'TEST-code',
            'amount' => 'R 12.00', 'reason' => 'TEST-reason', 'day' => '8 Nov 2026', 'note' => null, 'raisedBy' => 'TEST-raiser', 'source' => null];
        $raised = new StaffShortRaisedMail($short, 'TEST-approver', 'https://example.invalid/short');
        $this->assertStringContainsString('waiting for your approval', $raised->render());
        $this->assertSame('Staff short 42 to approve — R 12.00, TEST-person', $raised->envelope()->subject);
        $decided = new StaffShortDecidedMail($short + ['decision' => 'withdrawn', 'decidedBy' => null, 'why' => 'TEST-why'], 'TEST-raiser', 'https://example.invalid/short');
        $this->assertStringContainsString('approval was taken back', $decided->render());
        foreach (['approved' => 'charged once it is posted', 'declined' => 'It is not charged.'] as $decision => $expected) {
            $mail = new StaffShortDecidedMail($short + ['decision' => $decision, 'decidedBy' => 'TEST-reviewer', 'why' => 'TEST-why'], 'TEST-raiser', 'https://example.invalid/short');
            $this->assertStringContainsString($expected, $mail->render());
            $this->assertStringContainsString('TEST-why', $mail->render());
        }
        $definition = app(TemplateCatalog::class)->get(StaffShortRaisedMail::TEMPLATE_CODE);
        app(TemplateService::class)->save(999, $definition->code, [
            'Subject' => 'TEST branch override', 'BodyHtml' => $definition->html, 'BodyText' => $definition->text,
            'IsActive' => true, 'Version' => 0,
        ], null);
        app(BranchContext::class)->set(998);
        $this->assertSame('TEST branch override', (new StaffShortRaisedMail($short, 'TEST-approver', 'https://example.invalid/short'))->envelope()->subject);
        app(BranchContext::class)->set(999);
        $elsewhere = array_replace($short, ['branchId' => 998]);
        $this->assertSame($raised->envelope()->subject, (new StaffShortRaisedMail($elsewhere, 'TEST-approver', 'https://example.invalid/short'))->envelope()->subject);
        unset($elsewhere['branchId']);
        $this->assertSame($raised->envelope()->subject, (new StaffShortRaisedMail($elsewhere, 'TEST-approver', 'https://example.invalid/short'))->envelope()->subject);
        $statement = new DebtorStatementMail(['branchId' => 999, 'site' => 'TEST-site', 'accountNo' => 'TEST-A', 'accountName' => 'TEST-customer',
            'period' => 'November 2026', 'periodEnd' => '30 Nov 2026', 'closing' => 'R 12.00', 'owing' => false], 'TEST-PDF', 'TEST-statement.pdf', 'customer@example.invalid');
        $this->assertStringContainsString('in credit', $statement->render());
        $this->assertStringContainsString('Nobody outside the business has seen it', $statement->render());
        $this->assertStringStartsWith('[TEST for customer@example.invalid]', $statement->envelope()->subject);
        $statement->assertHasAttachedData('TEST-PDF', 'TEST-statement.pdf', ['mime' => 'application/pdf']);
        Mail::to('TEST-recipient@example.invalid')->send($statement);
        Mail::assertSent(DebtorStatementMail::class, fn ($mail) => $mail->hasTo('TEST-recipient@example.invalid'));
        $this->assertSame('off', config('debtors.send_mode'));
    }
}
