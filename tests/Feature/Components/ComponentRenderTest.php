<?php

namespace Tests\Feature\Components;

use App\Support\Format;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The component library, rendered and asserted on.
 *
 * The acceptance for T013 is "renders with zero console errors in both themes
 * at desktop and 375px", and no phpunit test can see a console or a pixel.
 * What it CAN prove, deterministically and without a browser, is the half that
 * a screenshot would not have caught anyway:
 *
 *   - the mockup's class names come out, so the design sheet's CSS applies;
 *   - the props contract holds, including the awkward values;
 *   - a missing figure is an em dash rather than a zero;
 *   - the empty states are rendered rather than left as a bare container;
 *   - nothing has been given a colour of its own.
 *
 * Read-only throughout: nothing here touches the database, which is also the
 * rule the components themselves live under.
 */
class ComponentRenderTest extends TestCase
{
    /**
     * Render a component in isolation, the way a view would.
     *
     * @param  array<string, mixed>  $data
     */
    private function render(string $template, array $data = []): string
    {
        return (string) Blade::render($template, $data);
    }

    // ------------------------------------------------------------------ card

    public function test_the_card_carries_the_mockup_class_names(): void
    {
        $html = $this->render('<x-card title="Daily banking" sub="4 September">body</x-card>');

        // card-h / card-b / .sub / .right are the design sheet's own names, and
        // the reason the mockup CSS ports without a translation table.
        $this->assertStringContainsString('class="card"', $html);
        $this->assertStringContainsString('class="card-h"', $html);
        $this->assertStringContainsString('class="card-b"', $html);
        $this->assertStringContainsString('<div class="sub">4 September</div>', $html);
        $this->assertStringContainsString('<h3>Daily banking</h3>', $html);
    }

    public function test_a_card_without_a_title_has_no_head(): void
    {
        $html = $this->render('<x-card>body</x-card>');

        $this->assertStringNotContainsString('card-h', $html);
        $this->assertStringContainsString('card-b', $html);
    }

    public function test_a_collapsible_card_is_a_details_element(): void
    {
        // <details> rather than a click handler: the browser then supplies the
        // keyboard behaviour and it works with scripting off.
        $html = $this->render('<x-card title="Provenance" collapsible remember="k">body</x-card>');

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary class="card-h">', $html);
        $this->assertStringContainsString('data-remember="card:k"', $html);

        // Blade has @checked, @disabled, @selected, @readonly and @required —
        // it has NO @open and no @hidden. Written that way they render as
        // literal text inside the tag and the attribute never applies, which is
        // a disclosure whose `open` prop silently does nothing.
        $this->assertStringNotContainsString('@open', $html);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\bopen\b/', $html);
    }

    public function test_an_open_collapsible_card_really_is_open(): void
    {
        $this->assertMatchesRegularExpression(
            '/<details[^>]*\bopen\b/',
            $this->render('<x-card title="Answer" collapsible :open="true">body</x-card>')
        );
    }

    public function test_flush_removes_the_body_padding_class(): void
    {
        $this->assertStringContainsString(
            'class="card-b flush"',
            $this->render('<x-card flush>x</x-card>')
        );
    }

    // ------------------------------------------------------------------- kpi

    public function test_the_kpi_carries_lbl_val_cmp_and_stripe(): void
    {
        $html = $this->render(
            '<x-kpi label="Shop turnover" :value="$v" unit="ex VAT" :compare="[$a, $b]" stripe="s2" />',
            ['v' => Format::rk(2208437), 'a' => 'against R2.08m', 'b' => '25 sites']
        );

        $this->assertStringContainsString('class="kpi tone-neutral"', $html);
        $this->assertStringContainsString('<div class="lbl">Shop turnover</div>', $html);
        $this->assertStringContainsString('R2.21m', $html);
        $this->assertStringContainsString('<small>ex VAT</small>', $html);
        $this->assertStringContainsString('<div class="cmp">', $html);
        $this->assertStringContainsString('<span>against R2.08m</span>', $html);
        $this->assertStringContainsString('<span>25 sites</span>', $html);
        $this->assertStringContainsString('style="background: var(--s2)"', $html);
    }

    public function test_the_kpi_stripe_only_accepts_a_token_name(): void
    {
        // The stripe is the one place a value reaches a style attribute. An
        // unknown one is dropped rather than interpolated — that is both the
        // no-hex rule and an injection point closed.
        $html = $this->render('<x-kpi label="x" value="1" stripe="red; background: url(evil)" />');

        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('class="stripe"', $html);
    }

    public function test_a_kpi_with_an_href_is_a_link(): void
    {
        $html = $this->render('<x-kpi label="x" value="1" href="/app/control" />');

        $this->assertStringContainsString('<a ', $html);
        $this->assertStringContainsString('href="/app/control"', $html);
    }

    public function test_a_missing_kpi_figure_is_an_em_dash(): void
    {
        // "A missing figure is —, never R0.00." The component does not format,
        // so this is really an assertion that the pair is used correctly.
        $html = $this->render('<x-kpi label="Bank unmatched" :value="$v" />', ['v' => Format::rk(null)]);

        $this->assertStringContainsString(Format::NOTHING, $html);
        $this->assertStringNotContainsString('R0', $html);
    }

    public function test_the_kpi_strip_is_the_mockups_kpis_grid(): void
    {
        $this->assertStringContainsString(
            'class="kpis"',
            $this->render('<x-kpi-strip><x-kpi label="a" value="1" /></x-kpi-strip>')
        );
    }

    // ------------------------------------------------------------------ chip

    public function test_the_chip_carries_the_tone_and_a_dot(): void
    {
        $html = $this->render('<x-chip tone="crit">Not banked</x-chip>');

        // Both spellings: `chip crit` is the mockup's, `tone-crit` is what the
        // views already in the tree were written against.
        $this->assertStringContainsString('class="chip crit tone-crit"', $html);
        $this->assertStringContainsString('<span class="dot" aria-hidden="true"></span>', $html);
    }

    public function test_a_chip_can_drop_its_dot(): void
    {
        $this->assertStringNotContainsString(
            'class="dot"',
            $this->render('<x-chip tone="neutral" :dot="false">Finance</x-chip>')
        );
    }

    // ----------------------------------------------------------------- delta

    public function test_the_delta_takes_both_halves_from_format(): void
    {
        $up = $this->render('<x-delta :value="4.23" />');
        $down = $this->render('<x-delta :value="-4.23" />');
        $flat = $this->render('<x-delta :value="0.02" />');
        $none = $this->render('<x-delta :value="null" />');

        $this->assertStringContainsString('class="delta up"', $up);
        $this->assertStringContainsString(Format::delta(4.23), $up);
        $this->assertStringContainsString('class="delta dn"', $down);
        $this->assertStringContainsString('class="delta flat"', $flat);
        $this->assertStringContainsString('class="delta flat"', $none);
        $this->assertStringContainsString(Format::NOTHING, $none);
    }

    public function test_invert_makes_a_rise_the_bad_news(): void
    {
        // Shrinkage up is not good news, and only the caller knows that.
        $this->assertStringContainsString('class="delta dn"', $this->render('<x-delta :value="4.23" invert />'));
        $this->assertStringContainsString('class="delta up"', $this->render('<x-delta :value="-4.23" invert />'));
    }

    // ------------------------------------------------------------- statstrip

    public function test_the_statstrip_carries_both_the_mockup_and_the_alias_names(): void
    {
        // This is the load-bearing one. Modules/Recon and Modules/Reports have
        // live screens rendering <x-statstrip> and their stylesheet expectations
        // were written against `.stat / .stat-label / .stat-value`. The mockup
        // names are what the component now emits; the alias is what keeps those
        // screens rendering. Losing either is a silently unstyled screen.
        $html = $this->render('<x-statstrip :stats="$s" />', ['s' => [
            ['label' => 'Would reconcile', 'value' => Format::n(168), 'note' => Format::r(1284310.55), 'tone' => 'good'],
        ]]);

        $this->assertStringContainsString('class="statstrip"', $html);
        $this->assertStringContainsString('class="s stat tone-good"', $html);
        $this->assertStringContainsString('class="l stat-label"', $html);
        $this->assertStringContainsString('class="v stat-value"', $html);
        $this->assertStringContainsString('class="n stat-note"', $html);
        $this->assertStringContainsString('R1 284 310.55', $html);
    }

    public function test_a_stat_without_a_value_is_an_em_dash(): void
    {
        $html = $this->render('<x-statstrip :stats="[[\'label\' => \'Bank side\']]" />');

        $this->assertStringContainsString(Format::NOTHING, $html);
    }

    public function test_the_recon_result_stat_shape_still_renders(): void
    {
        // The exact prop shape Modules/Recon/Resources/views/partials/result.blade.php
        // passes, rendered here so a change to <x-statstrip> cannot break that
        // screen without a test going red first.
        $html = $this->render('<x-statstrip :stats="$s" />', ['s' => [
            ['label' => 'Would reconcile', 'value' => Format::n(168), 'note' => Format::r(1284310.55), 'tone' => 'good'],
            ['label' => 'Amount mismatch', 'value' => Format::n(3), 'note' => 'both sides present, totals differ', 'tone' => 'warn'],
            ['label' => 'Bank only', 'value' => Format::n(28), 'note' => 'no deposit behind them', 'tone' => 'serious'],
            ['label' => 'Deposit only', 'value' => Format::n(0), 'note' => 'never shown by the exe', 'tone' => 'neutral'],
            ['label' => 'Bank side', 'value' => Format::r(1284984.10), 'note' => 'total in scope'],
        ]]);

        $this->assertSame(5, substr_count($html, 'class="s stat tone-'));
        $this->assertSame(5, substr_count($html, 'class="v stat-value"'));
        foreach (['tone-good', 'tone-warn', 'tone-serious', 'tone-neutral'] as $tone) {
            $this->assertStringContainsString($tone, $html);
        }
    }

    // ------------------------------------------------------------------ tabs

    public function test_link_mode_renders_anchors_and_no_tab_machinery(): void
    {
        $html = $this->render('<x-tabs active="b" :items="$i" />', ['i' => [
            ['key' => 'a', 'label' => 'One', 'href' => '/a'],
            ['key' => 'b', 'label' => 'Two', 'href' => '/b'],
        ]]);

        $this->assertStringContainsString('class="tabs"', $html);
        $this->assertStringContainsString('<a href="/b" class="on"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        // No data-tabs: tabs.js must not claim a strip that is navigation.
        $this->assertStringNotContainsString('data-tabs ', $html);
    }

    public function test_panel_mode_ships_the_inactive_panes_hidden(): void
    {
        // The correct pane is on screen before any script runs. Without this,
        // a page with scripting off shows every pane stacked.
        $html = $this->render(
            '<x-tabs active="b" persist="k" :items="$i">'
            .'<x-tab-panel key="a">AAA</x-tab-panel><x-tab-panel key="b">BBB</x-tab-panel>'
            .'</x-tabs>',
            ['i' => [['key' => 'a', 'label' => 'One'], ['key' => 'b', 'label' => 'Two']]]
        );

        $this->assertStringContainsString('data-tabs', $html);
        $this->assertStringContainsString('data-tabs-persist="k"', $html);
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('aria-selected="true"', $html);

        $this->assertMatchesRegularExpression('/data-tab-panel="a"[^>]*hidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-tab-panel="b"[^>]*hidden/', $html);
    }

    // ---------------------------------------------------------------- params

    public function test_a_disabled_param_is_greyed_rather_than_hidden(): void
    {
        // The whole point of the component: a report library where every screen
        // shows the same parameter set, dimmed where it does not apply.
        $html = $this->render('<x-param name="region" label="Branch region" :choices="[\'\' => \'All\']" disabled />');

        $this->assertStringContainsString('class="f dis"', $html);
        $this->assertStringContainsString('Branch region', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_a_param_only_asks_for_tomselect_when_told_to(): void
    {
        $plain = $this->render('<x-param name="b" label="Branch" :choices="[1 => \'One\']" />');
        $rich = $this->render('<x-param name="b" label="Branch" :choices="[1 => \'One\']" searchable />');

        $this->assertStringNotContainsString('data-select', $plain);
        $this->assertStringContainsString('data-select', $rich);
    }

    public function test_a_collapsible_params_block_is_a_details_element(): void
    {
        $html = $this->render('<x-params collapsible :open="false" remember="r" summary="Parameters">x</x-params>');

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('data-remember="params:r"', $html);
        $this->assertStringContainsString('<summary>Parameters</summary>', $html);
        $this->assertStringContainsString('class="params"', $html);
    }

    // ---------------------------------------------------------------- runbar

    public function test_the_runbar_names_its_procedure_and_announces_its_status(): void
    {
        $html = $this->render('<x-runbar procedure="agora.usp_Cash_GridDailyBanking" status="Ready." />');

        // feature-rules §3.4: the customer opens these in SSMS.
        $this->assertStringContainsString('agora.usp_Cash_GridDailyBanking', $html);
        $this->assertStringContainsString('data-runbar', $html);
        $this->assertStringContainsString('data-runbar-go', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('role="status"', $html);
    }

    // ------------------------------------------------------------- checklist

    public function test_a_checklist_step_with_no_destination_is_not_a_link(): void
    {
        // feature-rules §3.7: a reference that leads nowhere is not rendered as
        // a link.
        $html = $this->render('<x-checklist :steps="$s" />', ['s' => [
            ['title' => 'Import POS files', 'detail' => 'All tills loaded', 'done' => true, 'href' => '/app/x'],
            ['title' => 'Drop safe', 'detail' => '3 bags not collected', 'done' => false],
        ]]);

        $this->assertMatchesRegularExpression('/<a class="step-in"\s+href="\/app\/x"\s*>/', $html);
        $this->assertMatchesRegularExpression('/<div class="step-in"\s*>/', $html);
        $this->assertSame(1, substr_count($html, '<a class="step-in"'));
        $this->assertStringContainsString('class="step is-done"', $html);
        $this->assertStringContainsString('class="step is-open"', $html);
        // A mark and a chip, so the state is readable without relying on hue.
        $this->assertStringContainsString('Done', $html);
        $this->assertStringContainsString('Outstanding', $html);
        $this->assertStringContainsString('aria-label="1 of 2 complete"', $html);
    }

    public function test_an_empty_checklist_says_so(): void
    {
        $this->assertStringContainsString(
            'No steps for this day.',
            $this->render('<x-checklist :steps="[]" />')
        );
    }

    // ------------------------------------------------------------ exceptions

    public function test_an_exception_row_is_a_native_disclosure(): void
    {
        $html = $this->render(
            '<x-exception-row severity="critical" title="Declared cash is under the Z-read total"'
            .' detail="Four cashups on 3 September." category="Banking" site="Ngwelezane" age="2 days"'
            .' owner="Finance" :value="$v" unit="declared vs Z-read" href="/app/x" />',
            ['v' => Format::r(-4210)]
        );

        $this->assertStringContainsString('class="ex critical"', $html);
        $this->assertStringContainsString('<div class="bar" aria-hidden="true"></div>', $html);
        // <details>, so Enter and Space open it and a screen reader calls it a
        // disclosure. The mockup toggled a class on click.
        $this->assertStringContainsString('<details class="body"', $html);
        $this->assertStringContainsString('<summary>', $html);
        $this->assertStringContainsString('class="t"', $html);
        $this->assertStringContainsString('class="d"', $html);
        $this->assertStringContainsString('CRITICAL', $html);
        $this->assertStringContainsString('Banking · Ngwelezane · 2 days', $html);
        $this->assertStringContainsString('-R4 210.00', $html);
    }

    public function test_an_empty_exception_list_reads_as_the_good_outcome(): void
    {
        $this->assertStringContainsString(
            'Nothing open.',
            $this->render('<x-exception-list />')
        );
    }

    // ------------------------------------------------------------- decisions

    public function test_a_decision_without_an_amount_renders_no_figure(): void
    {
        $html = $this->render('<x-decision-list :items="$i" />', ['i' => [
            ['who' => 'Thandeka Mkhize', 'what' => 'Staff short', 'detail' => 'Till 2', 'amount' => Format::r(310.50), 'href' => '/app/x'],
            ['who' => 'Sipho Zulu', 'what' => 'Leave request', 'detail' => '12–16 September', 'href' => '/app/y'],
        ]]);

        $this->assertSame(1, substr_count($html, 'class="amount"'));
        $this->assertStringContainsString('R310.50', $html);
        $this->assertStringContainsString('Staff short · Till 2', $html);
    }

    public function test_an_empty_decision_list_says_nothing_is_waiting(): void
    {
        $this->assertStringContainsString(
            'Nothing waiting.',
            $this->render('<x-decision-list :items="[]" />')
        );
    }

    // -------------------------------------------------------------- proposal

    public function test_a_proposal_carries_its_confidence_and_its_evidence(): void
    {
        $html = $this->render(
            '<x-proposal confidence="certain" headline="Day Shift · Thandeka Mkhize" :evidence="$e" />',
            ['e' => ['Operator ID 14 has run till 2 on 47 of the last 50 Day Shifts.']]
        );

        $this->assertStringContainsString('class="proposal conf-certain"', $html);
        $this->assertStringContainsString('Certain', $html);
        $this->assertStringContainsString('The system suggests', $html);
        $this->assertStringContainsString('<ul class="evidence">', $html);
        $this->assertStringContainsString('47 of the last 50', $html);
    }

    public function test_manual_is_a_first_class_confidence(): void
    {
        // "No history for this operator ID" is information, not an absence of
        // an answer.
        $html = $this->render('<x-proposal confidence="manual" />');

        $this->assertStringContainsString('conf-manual', $html);
        $this->assertStringContainsString('Needs a person', $html);
    }

    // ------------------------------------------------------------- lib-card

    public function test_a_library_card_carries_the_legacy_names_and_is_searchable(): void
    {
        $html = $this->render(
            '<x-lib-card name="Daily Banking Reconciliation" desc="Cashups beside the bank."'
            .' scope="Branch · one day" :was="$w" :tags="$t" href="/app/x" />',
            ['w' => ['BANKREC01', 'Daily Cash Recon'], 't' => [['tone' => 'good', 'label' => 'Runnable']]]
        );

        $this->assertStringContainsString('class="libcard"', $html);
        $this->assertStringContainsString('Was: BANKREC01 · Daily Cash Recon', $html);
        $this->assertStringContainsString('Scope: Branch · one day', $html);
        $this->assertStringContainsString('class="libmeta"', $html);
        // The filter box covers the same fields on every category because the
        // component builds the haystack, not the page.
        $this->assertStringContainsString('data-s="daily banking reconciliation', $html);
        $this->assertStringContainsString('bankrec01', $html);
    }

    // ---------------------------------------------------------------- chrome

    public function test_the_crumb_leaves_the_current_page_unlinked(): void
    {
        $html = $this->render('<x-crumb :parts="$p" />', ['p' => [
            ['label' => 'Agora', 'href' => '/app'],
            ['label' => 'Control', 'href' => '/app/control'],
            ['label' => 'What does not reconcile', 'href' => '/app/control/open'],
        ]]);

        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringContainsString('<a href="/app/control">Control</a>', $html);
        $this->assertStringContainsString('<b aria-current="page">What does not reconcile</b>', $html);
        $this->assertStringNotContainsString('href="/app/control/open"', $html);
        $this->assertStringContainsString('class="sep" aria-hidden="true"', $html);
    }

    public function test_the_workspace_switch_is_anchors_that_keep_the_query(): void
    {
        // Switching workspace is a navigation, so it belongs in the URL and the
        // back button should undo it.
        $this->get('/app?branch=8');

        $html = $this->render(
            '<x-workspace-switch :workspaces="$w" current="ho" />',
            ['w' => ['ho' => 'Head office', 'branch' => 'Branch']]
        );

        $this->assertStringContainsString('class="wsw"', $html);
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('<a href=', $html);
        $this->assertStringContainsString('ws=branch', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
    }

    // -------------------------------------------------------------- messages

    public function test_the_empty_state_has_a_headline_form_and_a_one_line_form(): void
    {
        $panel = $this->render('<x-empty-state title="Pick a Z-read" text="Choose a row on the left." />');
        $line = $this->render('<x-empty-state text="Nothing to show." />');

        $this->assertStringContainsString('<div class="emptystate empty-state">', $panel);
        $this->assertStringContainsString('<div class="big">Pick a Z-read</div>', $panel);
        $this->assertStringContainsString('<p class="emptystate empty-state">', $line);
        $this->assertStringNotContainsString('class="big"', $line);
    }

    public function test_the_sqlbox_shows_the_procedure_and_starts_closed(): void
    {
        $html = $this->render('<x-sqlbox procedure="agora.usp_Cash_GridDailyBanking">SELECT 1</x-sqlbox>');

        $this->assertStringContainsString('class="sqlwrap"', $html);
        $this->assertStringContainsString('agora.usp_Cash_GridDailyBanking', $html);
        $this->assertStringContainsString('<pre class="sqlbox">', $html);
        // Provenance, not the point of the screen.
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\bopen\b/', $html);
    }

    public function test_the_tooltip_host_is_hidden_and_out_of_the_accessibility_tree(): void
    {
        $html = $this->render('<x-tip />');

        $this->assertStringContainsString('id="tip"', $html);
        $this->assertStringContainsString('role="tooltip"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('hidden', $html);
    }

    // ------------------------------------------------- table tools + action bar

    public function test_the_table_only_offers_the_tools_when_it_is_asked_to(): void
    {
        // Opt-in on purpose: filtering page one of nine in the browser answers
        // a different question from the one the reader asked while looking
        // exactly like the right answer. See feature-rules §B.
        $plain = $this->render('<x-table :count="1"><tr><td>x</td></tr></x-table>');
        $this->assertStringNotContainsString('data-table-tools', $plain);

        $tooled = $this->render('<x-table tools :count="1"><tr><td>x</td></tr></x-table>');
        $this->assertStringContainsString('data-table-tools', $tooled);
    }

    public function test_the_action_bar_points_at_the_table_it_commits(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-action-bar for="run-lines">
                <button data-count-verb="Reconcile" data-count-noun="batch"
                        data-count-plural="batches">Reconcile 3 batches</button>
                <x-slot:note>What the press does.</x-slot:note>
            </x-action-bar>
        BLADE);

        $this->assertStringContainsString('data-action-bar="run-lines"', $html);
        $this->assertStringContainsString('is-sticky', $html);

        // The count is correct with no JavaScript at all — the server renders
        // it, and table-tools.js only keeps it correct afterwards.
        $this->assertStringContainsString('Reconcile 3 batches', $html);
        $this->assertStringContainsString('action-bar-note', $html);

        // The place the "N rows are hidden by a filter" warning goes. It only
        // exists on a bar that has a table to count, because it is the only
        // bar that can know.
        $this->assertStringContainsString('data-action-bar-scope', $html);
    }

    public function test_an_action_bar_with_no_table_counts_nothing_and_can_stay_in_the_flow(): void
    {
        $html = $this->render('<x-action-bar :sticky="false"><button>Match</button></x-action-bar>');

        $this->assertStringNotContainsString('data-action-bar=', $html);
        $this->assertStringNotContainsString('data-action-bar-scope', $html);
        $this->assertStringNotContainsString('is-sticky', $html);
    }

    // ------------------------------------------------------------- the rules

    public function test_no_component_declares_a_colour_of_its_own(): void
    {
        // "Every component takes its colours from the tokens in _tokens.scss.
        // No hex in a component." This is the check that stops that rule being
        // broken quietly, one inline style at a time.
        $offenders = [];

        foreach (glob(resource_path('views/components/*.blade.php')) as $file) {
            $body = file_get_contents($file);

            // #abc, #aabbcc, rgb(), hsl() — anything that names a colour rather
            // than referring to a token. The app bar's brand mark is the one
            // exception and it is an SVG logo, not a themed surface.
            if (preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $body)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(['app-bar.blade.php'], $offenders,
            'A component named a colour. Use a token from resources/scss/_tokens.scss.');
    }
}
