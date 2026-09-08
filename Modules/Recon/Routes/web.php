<?php

use Illuminate\Support\Facades\Route;
use Modules\Recon\Http\Controllers\ReconController;
use Modules\Recon\Http\Controllers\ReconGroupController;
use Modules\Recon\Http\Controllers\ReconTraceController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
/*
 | Every route carries its own permission (T008). The split that matters is
 | between looking and committing: `view` and `create` let a person run a
 | preview and read what it found, while `execute` and `reverse` stamp rows in
 | the customer's estate and are granted to Finance and Admin alone. Operations
 | can preview and discard; the Auditor can only look. feature-rules is explicit
 | that edit, save and delete each sit behind their own permission per resource,
 | and this is that rule applied to the one module that already writes.
 */
Route::middleware('auth')->prefix('recon')->name('recon.')->group(function () {
    Route::get('/', [ReconController::class, 'index'])->middleware('can:recon.runs.view')->name('index');

    /*
     | Trace: one typed value against the whole ledger and the legacy estate
     | behind it. Not an area tab — a trace crosses all five areas and both
     | sides, and scoping it to one would mean knowing the answer first.
     */
    Route::get('trace', [ReconTraceController::class, 'index'])->middleware('can:recon.runs.view')->name('trace');

    // A run is the record of a preview, addressable on its own so a figure
    // can be sent to someone rather than described to them.
    Route::get('runs/{run}', [ReconController::class, 'show'])->middleware('can:recon.runs.view')->name('run');
    // The fragment a proposal row expands into. Its own URL, so the detail is
    // fetched only when somebody asks for it — a month of ABSA is a few hundred
    // proposals and drilling all of them up front is a few hundred queries.
    Route::get('runs/{run}/lines/{line}', [ReconController::class, 'line'])->middleware('can:recon.runs.view')->name('line');
    Route::post('runs/{run}/execute', [ReconController::class, 'execute'])->middleware('can:recon.runs.execute')->name('execute');
    Route::post('runs/{run}/reverse', [ReconController::class, 'reverse'])->middleware('can:recon.runs.reverse')->name('reverse');

    // Discarding previews. One run, or every uncommitted run in scope — the
    // procedure refuses a committed one either way.
    Route::delete('runs/{run}', [ReconController::class, 'discard'])->middleware('can:recon.runs.delete')->name('discard');
    Route::delete('runs', [ReconController::class, 'discard'])->middleware('can:recon.runs.delete')->name('clear');

    /*
     | The area workbench, and its three sibling tabs.
     |
     | One area is four screens now — the automatic preview, the manual match
     | beside it, the runs the person has already made, and the configuration
     | the previews read — and each is its own result set with its own scope.
     | So the tabs are LINKS, not panels: a tab is somewhere you can send
     | someone, which is the rule everywhere else in Agora.
     |
     | They nest under `auto/{area}` rather than sitting beside it because
     | `runs/{run}` above already owns the `runs/` prefix, and `runs/ABSA`
     | would be an area arriving where a run id is expected. Nesting also
     | leaves `/app/recon/auto/{area}` exactly where the menu seeder and every
     | existing link already point.
     |
     | Last in the group, so `auto/{area}` cannot swallow `runs/{run}`.
     */
    Route::get('auto/{area}', [ReconController::class, 'area'])->middleware('can:recon.runs.view')->name('area');
    Route::get('auto/{area}/match', [ReconController::class, 'manualMatch'])->middleware('can:recon.runs.view')->name('match');
    // Matching by hand writes to the customer's estate exactly as Execute
    // does — the same batch counter, the same ledger, the same reversal — so
    // it sits behind the same permission.
    Route::post('auto/{area}/match', [ReconController::class, 'match'])
        ->middleware('can:recon.runs.execute')->name('match.save');
    Route::get('auto/{area}/runs', [ReconController::class, 'runList'])->middleware('can:recon.runs.view')->name('runs');
    Route::get('auto/{area}/config', [ReconController::class, 'configuration'])->middleware('can:recon.criteria.view')->name('config');
    // One rule's edit form, as the fragment the modal opens onto. Looking is
    // `view`; the form inside it renders its controls only for `edit`.
    // {rule} is the view's AutoReconId — POSITIVE for one of the customer's
    // rules, NEGATIVE for one of Agora's, so the pattern allows a sign. It is
    // NOT (branch, area, process order): that triple does not identify a rule,
    // and one site has two FNB rules sharing a process order.
    Route::get('auto/{area}/config/{branch}/{rule}', [ReconController::class, 'configEdit'])
        ->whereNumber('branch')->where('rule', '-?[0-9]+')
        ->middleware('can:recon.criteria.view')->name('config.edit');
    // Both writes go to agora.ReconCriteria and nowhere else. Agora does not
    // write to the customer's BRN_AutoReconCriteria, ever.
    Route::post('auto/{area}/config', [ReconController::class, 'configSave'])
        ->middleware('can:recon.criteria.edit')->name('config.save');
    Route::post('auto/{area}/config/copy', [ReconController::class, 'configCopy'])
        ->middleware('can:recon.criteria.edit')->name('config.copy');

    Route::post('auto', [ReconController::class, 'preview'])->middleware('can:recon.runs.create')->name('preview');

    /*
     | The master controller: a period, no site, every branch the person may
     | reconcile. Head office by shape — in a branch workspace "every site" is
     | the one site already pinned, which is the Auto tab with more steps — so
     | the controller refuses that rather than showing a group of one.
     |
     | The previews are driven ONE BRANCH AT A TIME from the browser, which is
     | why `groups/{group}/branches/{branch}` exists as its own route. Twenty-
     | six previews of a busy month is minutes of wall clock: a single request
     | either times out or shows a spinner that cannot say which site it is on.
     */
    Route::get('auto/{area}/all', [ReconGroupController::class, 'form'])->middleware('can:recon.runs.view')->name('all');
    Route::post('auto/{area}/all', [ReconGroupController::class, 'start'])->middleware('can:recon.runs.create')->name('all.start');

    Route::get('groups/{group}', [ReconGroupController::class, 'show'])->middleware('can:recon.runs.view')->name('group');
    Route::post('groups/{group}/branches/{branch}', [ReconGroupController::class, 'runBranch'])
        ->whereNumber('branch')->middleware('can:recon.runs.create')->name('group.branch');
    // Posting a group writes to the customer's estate, branch by branch, so it
    // sits behind `execute` exactly as a single run's does.
    Route::post('groups/{group}/execute', [ReconGroupController::class, 'execute'])
        ->middleware('can:recon.runs.execute')->name('group.execute');
});
