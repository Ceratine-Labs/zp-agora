<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Mockery;
use Mockery\MockInterface;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Recon\Jobs\PostGroupSite;
use Modules\Recon\Models\ReconGroupPost;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunGroup;
use Modules\Recon\Services\ReconGroupService;
use Modules\Recon\Services\ReconService;
use RuntimeException;
use Tests\TestCase;

/**
 * Posting every site: one queued job per site (Ryan, 12 Nov 2026).
 *
 * The press used to post every ticked site inside one request, and a
 * three-month Smart ATM group timed out at 300 s on live (28 Sep 2026).
 *
 * WRITES Agora rows only — a group, previewed runs and their post rows, all
 * under the Note `TEST-group-post` and removed in tearDown. ReconService is
 * mocked, so usp_Recon_Commit is never called and nothing reaches PumpIT,
 * stub or otherwise. The real procedure under a real worker was proved by
 * hand against the local container (docs/recon-execute.md → "Posting every
 * site").
 */
class ReconGroupPostTest extends TestCase
{
    private const NAME = 'TEST-group-post';

    protected function tearDown(): void
    {
        $refs = ReconRunGroup::query()->acrossBranches()->where('Note', self::NAME)->pluck('GroupRef');

        if ($refs->isNotEmpty()) {
            ReconGroupPost::query()->acrossBranches()->whereIn('GroupRef', $refs)->delete();

            $runs = ReconRun::query()->acrossBranches()->whereIn('GroupRef', $refs)->pluck('Id');

            if ($runs->isNotEmpty()) {
                DB::connection(config('agora.connections.app'))
                    ->table(config('agora.schema').'.ReconRunLine')
                    ->whereIn('RunId', $runs)->delete();
            }

            ReconRun::query()->acrossBranches()->whereIn('GroupRef', $refs)->delete();
            ReconRunGroup::query()->acrossBranches()->whereIn('GroupRef', $refs)->delete();
        }

        parent::tearDown();
    }

    /**
     * N ticked sites queue N jobs, and the press itself posts nothing — so the
     * request is as short for twenty-six sites over three months as for one.
     */
    public function test_the_press_queues_one_job_per_site_and_posts_nothing_itself(): void
    {
        Queue::fake();

        [$group, $runs] = $this->groupWithRuns(3);

        $service = $this->service();
        $service->shouldNotReceive('commit');
        $service->shouldNotReceive('selectAll');

        $this->actingAs($this->admin())
            ->post(route('app.recon.group.execute', $group->GroupRef), ['runs' => $this->ids($runs)])
            ->assertRedirect(route('app.recon.group', $group->GroupRef));

        Queue::assertPushed(PostGroupSite::class, 3);
        Queue::assertPushedOn((string) config('recon.group_post.queue'), PostGroupSite::class);

        $posts = $this->posts($group);
        $this->assertCount(3, $posts);
        foreach ($runs as $run) {
            $post = $posts->get($run->BranchId);
            $this->assertNotNull($post, 'Every ticked site has a post row.');
            $this->assertSame('queued', $post->Status);
            $this->assertSame($run->Id, $post->RunId);
            Queue::assertPushed(PostGroupSite::class,
                fn (PostGroupSite $job) => $job->branchId === $run->BranchId && $job->postId === $post->Id);
            $this->assertSame('previewed', $run->fresh()?->Status);
        }

        // A second press while they are queued queues nothing more.
        $this->actingAs($this->admin())
            ->post(route('app.recon.group.execute', $group->GroupRef), ['runs' => $this->ids($runs)]);
        Queue::assertPushed(PostGroupSite::class, 3);

        // And the screen polls them.
        $this->actingAs($this->admin())->get(route('app.recon.group', $group->GroupRef))
            ->assertOk()
            ->assertSee('data-recon-group-posts', false)
            ->assertSee('Posting 3 sites in the background');

        $this->actingAs($this->admin())->getJson(route('app.recon.group.posts', $group->GroupRef))
            ->assertOk()
            ->assertJson(['active' => 3, 'finished' => 0, 'total' => 3]);
    }

    /**
     * One site refused and one faulting answer only for themselves: the third
     * posts, the group counts only it, and the refused two stay previews that
     * can be pressed again.
     */
    public function test_a_refused_or_faulting_site_marks_only_itself(): void
    {
        Queue::fake();

        [$group, $runs] = $this->groupWithRuns(3);
        [$good, $refused, $faulty] = $runs;

        $service = $this->service();
        $service->shouldReceive('selectAll')->times(3)->andReturn(1);
        $service->shouldReceive('commit')->times(3)->andReturnUsing(function (ReconRun $run, ?int $userId) use ($good, $refused) {
            $this->assertSame($this->admin()->getKey(), $userId, 'The job posts as the person who pressed.');

            if ($run->Id === $good->Id) {
                $run->forceFill(['Status' => 'committed', 'CommittedRows' => 4, 'CommittedTotal' => 120.5])->save();

                return ['status' => (object) ['Code' => 'COMMITTED', 'Message' => '4 of 4 reconciled.']];
            }

            if ($run->Id === $refused->Id) {
                throw new AgoraProcException('NOTHING_SELECTED', 'Nothing on this run is both selected and reconcilable.', 'usp_Recon_Commit');
            }

            throw new RuntimeException('TEST fault: the connection went away.');
        });

        $this->groupService()->queuePosts($group, $this->ids($runs), (int) $this->admin()->getKey());
        $this->runQueuedJobs();

        $posts = $this->posts($group);

        $this->assertSame('committed', $posts->get($good->BranchId)?->Status);
        $this->assertSame(4, $posts->get($good->BranchId)->CommittedRows);
        $this->assertSame('committed', $good->fresh()?->Status);

        $this->assertSame('refused', $posts->get($refused->BranchId)?->Status);
        $this->assertSame('NOTHING_SELECTED', $posts->get($refused->BranchId)->Code);
        $this->assertSame('previewed', $refused->fresh()?->Status);

        $this->assertSame('failed', $posts->get($faulty->BranchId)?->Status);
        $this->assertSame('ERROR', $posts->get($faulty->BranchId)->Code);
        $this->assertStringContainsString('connection went away', (string) $posts->get($faulty->BranchId)->Message);
        $this->assertSame('previewed', $faulty->fresh()?->Status);

        $group->refresh();
        $this->assertSame('committed', $group->Status);
        $this->assertSame(1, $group->CommittedBranches, 'Only the site that posted is counted.');
        $this->assertSame(4, $group->CommittedRows);
        $this->assertEquals(120.5, (float) $group->CommittedTotal);
        $this->assertEquals($this->admin()->getKey(), $group->CommittedBy);

        // The two that did not post are tickable again; the posted one is not.
        $page = $this->actingAs($this->admin())->get(route('app.recon.group', $group->GroupRef))->assertOk();
        $page->assertSee('value="'.$refused->Id.'"', false)
            ->assertSee('value="'.$faulty->Id.'"', false)
            ->assertDontSee('value="'.$good->Id.'"', false)
            ->assertSee('NOTHING_SELECTED');
    }

    /**
     * A redelivered or duplicated job never posts a site twice.
     *
     * Three ways it arrives again: the same job delivered twice; a job whose
     * worker died after usp_Recon_Commit committed but before the row said
     * so; and a new press for a site that is already posted. The procedure is
     * asked exactly once across all of it.
     */
    public function test_a_duplicate_or_redelivered_job_for_a_posted_site_is_a_no_op(): void
    {
        Queue::fake();

        [$group, $runs] = $this->groupWithRuns(2);
        [$run, $orphan] = $runs;

        $service = $this->service();
        $service->shouldReceive('selectAll')->once()->andReturn(1);
        $service->shouldReceive('commit')->once()->andReturnUsing(function (ReconRun $run) {
            $run->forceFill(['Status' => 'committed', 'CommittedRows' => 2, 'CommittedTotal' => 50])->save();

            return ['status' => (object) ['Code' => 'COMMITTED', 'Message' => '2 of 2 reconciled.']];
        });

        $this->groupService()->queuePosts($group, [$run->Id], (int) $this->admin()->getKey());
        $post = $this->posts($group)->get($run->BranchId);
        $this->assertNotNull($post);

        $job = new PostGroupSite($post->BranchId, $post->Id);
        app()->call([$job, 'handle']);
        // The same job, delivered again.
        app()->call([$job, 'handle']);
        // And the queue giving up on it afterwards.
        $job->failed(new RuntimeException('TEST redelivered past its one try'));

        $after = $post->fresh();
        $this->assertSame('committed', $after?->Status);
        $this->assertSame(1, $after->Attempts, 'Claimed once.');

        $group->refresh();
        $this->assertSame(1, $group->CommittedBranches, 'Counted once.');
        $this->assertSame(2, $group->CommittedRows);

        // The second site: its worker claimed it, the procedure committed the
        // run, and the worker died before writing the answer.
        $this->groupService()->queuePosts($group->fresh(), [$orphan->Id], (int) $this->admin()->getKey());
        $orphanPost = $this->posts($group)->get($orphan->BranchId);
        $orphanPost?->forceFill(['Status' => 'running', 'StartedAt' => now(), 'Attempts' => 1])->save();
        $orphan->forceFill(['Status' => 'committed', 'CommittedRows' => 7, 'CommittedTotal' => 70])->save();

        // Redelivered: nothing to claim, nothing called.
        app()->call([new PostGroupSite($orphan->BranchId, (int) $orphanPost?->Id), 'handle']);
        $this->assertSame('running', $orphanPost?->fresh()?->Status);

        // Failed past its one try: the row is reconciled from the run.
        (new PostGroupSite($orphan->BranchId, (int) $orphanPost->Id))->failed(new RuntimeException('TEST worker lost'));
        $this->assertSame('committed', $orphanPost->fresh()->Status);
        $this->assertSame('ALREADY_POSTED', $orphanPost->fresh()->Code);
        $this->assertSame(7, $orphanPost->fresh()->CommittedRows);
        $this->assertSame(2, $group->fresh()?->CommittedBranches, 'Each site counted once.');
        $this->assertSame(9, $group->fresh()->CommittedRows);

        // A new press for either posted site queues nothing.
        Queue::fake();
        $results = $this->groupService()->queuePosts($group->fresh(), $this->ids($runs), (int) $this->admin()->getKey());
        Queue::assertNothingPushed();
        $this->assertSame(['NOT_PREVIEWED', 'NOT_PREVIEWED'], array_column($results, 'code'));
        $this->assertSame(2, $group->fresh()->CommittedBranches);
    }

    /**
     * A worker that times out or dies leaves the site failed — and only that
     * site — and the next press queues it again.
     */
    public function test_an_interrupted_site_fails_alone_and_can_be_pressed_again(): void
    {
        Queue::fake();

        [$group, $runs] = $this->groupWithRuns(2);
        [$slow, $other] = $runs;

        $service = $this->service();
        $service->shouldReceive('selectAll')->andReturn(1);
        $service->shouldReceive('commit')->andReturnUsing(function (ReconRun $run) {
            $run->forceFill(['Status' => 'committed', 'CommittedRows' => 1, 'CommittedTotal' => 10])->save();

            return ['status' => (object) ['Code' => 'COMMITTED', 'Message' => '1 of 1 reconciled.']];
        });

        $this->groupService()->queuePosts($group, $this->ids($runs), (int) $this->admin()->getKey());
        $posts = $this->posts($group);

        // The slow site's worker is killed at its timeout mid-procedure.
        $slowPost = $posts->get($slow->BranchId);
        $slowPost?->forceFill(['Status' => 'running', 'StartedAt' => now()])->save();
        (new PostGroupSite($slow->BranchId, (int) $slowPost?->Id))
            ->failed(new RuntimeException('TEST job has timed out'));

        app()->call([new PostGroupSite($other->BranchId, (int) $posts->get($other->BranchId)?->Id), 'handle']);

        $this->assertSame('failed', $slowPost?->fresh()?->Status);
        $this->assertSame('INTERRUPTED', $slowPost->fresh()->Code);
        $this->assertSame('previewed', $slow->fresh()?->Status);
        $this->assertSame('committed', $posts->get($other->BranchId)?->fresh()?->Status);
        $this->assertSame(1, $group->fresh()?->CommittedBranches);

        Queue::fake();
        $this->groupService()->queuePosts($group->fresh(), [$slow->Id], (int) $this->admin()->getKey());
        Queue::assertPushed(PostGroupSite::class, 1);
        $this->assertSame('queued', $slowPost->fresh()->Status);
        $this->assertNull($slowPost->fresh()->Code);
    }

    /** On the sync connection (live until the worker runs) the press answers in the request, as it did. */
    public function test_on_the_sync_connection_the_press_reports_what_answered(): void
    {
        [$group, $runs] = $this->groupWithRuns(2);
        [$good, $refused] = $runs;

        $service = $this->service();
        $service->shouldReceive('selectAll')->andReturn(1);
        $service->shouldReceive('commit')->andReturnUsing(function (ReconRun $run) use ($refused) {
            if ($run->Id === $refused->Id) {
                throw new AgoraProcException('TEST_REFUSED', 'Site refused.', 'usp_Recon_Commit');
            }
            $run->forceFill(['Status' => 'committed', 'CommittedRows' => 3, 'CommittedTotal' => 25])->save();

            return ['status' => (object) ['Code' => 'COMMITTED', 'Message' => '3 of 3 reconciled.']];
        });

        $this->actingAs($this->admin())
            ->post(route('app.recon.group.execute', $group->GroupRef), ['runs' => $this->ids($runs)])
            ->assertRedirect(route('app.recon.group', $group->GroupRef))
            ->assertSessionHas('posted', fn (array $posted) => count($posted) === 2
                && collect($posted)->firstWhere('RunId', $good->Id)['Ok'] === true
                && collect($posted)->firstWhere('RunId', $refused->Id)['Code'] === 'TEST_REFUSED');

        $this->assertSame(1, $group->fresh()?->CommittedBranches);
    }

    /*
    | Helpers
    */

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    private function service(): MockInterface
    {
        // Partial: the screen's own reads (area, rules, options) are real;
        // only the two calls that reach usp_Recon_Commit are replaced.
        $service = Mockery::mock(ReconService::class, [app(ProcedureService::class)])->makePartial();
        $this->app->instance(ReconService::class, $service);
        $this->app->forgetInstance(ReconGroupService::class);

        return $service;
    }

    private function groupService(): ReconGroupService
    {
        return app(ReconGroupService::class);
    }

    /** Run what Queue::fake() caught, in order, the way a worker would. */
    private function runQueuedJobs(): void
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            $this->fail('Queue::fake() must be called first.');
        }

        foreach ($queue->pushed(PostGroupSite::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    /**
     * A group over N trading sites, each with a previewed run.
     *
     * @return array{0: ReconRunGroup, 1: array<int, ReconRun>}
     */
    private function groupWithRuns(int $n): array
    {
        $sites = Branch::query()->acrossBranches()
            ->where('IsActive', true)->where('IsTrading', true)
            ->orderBy('BranchId')->limit($n)
            ->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->all();

        if (count($sites) < $n) {
            $this->markTestSkipped("Fewer than {$n} trading sites on this instance.");
        }

        $this->actingAs($this->admin());

        $group = ReconRunGroup::create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'GroupRef' => (string) Str::uuid(),
            'ReconArea' => 'ABSA', 'FromDate' => '2026-08-01', 'ToDate' => '2026-08-31',
            'ParamsJson' => '[]', 'Note' => self::NAME, 'Status' => 'complete',
            'BranchCount' => $n, 'CompletedCount' => $n,
            'CreatedBy' => $this->admin()->getKey(), 'CreatedAt' => now(),
        ])->fresh();

        $runs = array_map(fn (int $site) => ReconRun::create([
            'BranchId' => $site, 'GroupRef' => $group->GroupRef, 'ReconArea' => 'ABSA',
            'FromDate' => '2026-08-01', 'ToDate' => '2026-08-31',
            'Status' => 'previewed', 'StampMode' => config('recon.stamp_mode'),
            'ProcedureName' => config('agora.schema').'.'.config('recon.areas.ABSA.procedure'),
            'ParamsJson' => '[]', 'Note' => self::NAME,
            'TotalRows' => 5, 'MatchedRows' => 5, 'MatchedTotal' => 100,
            'CreatedBy' => $this->admin()->getKey(), 'CreatedAt' => now(),
        ])->fresh(), $sites);

        return [$group, $runs];
    }

    /**
     * @param  array<int, ReconRun>  $runs
     * @return array<int, int>
     */
    private function ids(array $runs): array
    {
        return array_map(fn (ReconRun $run) => $run->Id, $runs);
    }

    /** @return Collection<int, ReconGroupPost> */
    private function posts(ReconRunGroup $group): Collection
    {
        return $this->groupService()->posts($group);
    }
}
