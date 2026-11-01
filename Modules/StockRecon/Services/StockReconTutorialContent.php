<?php

namespace Modules\StockRecon\Services;

/**
 * Teaching content, independent of a branch, a saved run and the live ledger.
 *
 * The fixtures illustrate selected rules; they are not a second implementation
 * of usp_StockRecon_PreviewBalancing. See docs/stock-recon.md and that procedure
 * for eligibility, opening repairs, duplicate evidence and the actual proposal.
 */
final class StockReconTutorialContent
{
    /** @return array{lessons: list<array<string, mixed>>, scenarios: list<array<string, mixed>>, events: list<array<string, mixed>>} */
    public function all(): array
    {
        return [
            'lessons' => $this->lessons(),
            'scenarios' => $this->scenarios(),
            'events' => $this->events(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lessons(): array
    {
        return [
            [
                'id' => 'purpose', 'title' => 'What stock recon answers', 'icon' => 'book',
                'kicker' => 'Start here',
                'summary' => 'Follow one item from opening stock to closing stock, and understand what the difference needs next.',
                'body' => [
                    'Stock recon compares quantities sold with quantities accounted for by opening stock, recorded issues and closing stock. It helps separate a count carried into the next shift from an issue problem, incomplete source data or a short still needing investigation.',
                    'A site contains counting areas. Each area has its own item chains. The same item in two areas is two separate chains; matching names alone do not permit stock to be moved between them.',
                    'A preview saves what Agora found and proposes. It does not amend PumpIT. A label such as Balanced or Missing issue captured describes the proposal until Amend succeeds. A variance or an employee name alone does not establish who caused a loss.',
                    'Every example in this tutorial is a training dataset. The examples explain a rule under stated assumptions; they do not run the full balancing algorithm or assess a real site.',
                ],
                'steps' => [
                    ['title' => 'Identify the scope', 'text' => 'Read the site, counting area and period before interpreting any quantity.', 'screen' => 'Stock recon centre → Balance a period'],
                    ['title' => 'Separate finding from action', 'text' => 'Read the proposed outcome, then check whether the run has actually committed.', 'screen' => 'Run → Proposals'],
                ],
                'scenario_id' => null, 'event_ids' => ['run-closed', 'residual-short'],
            ],
            [
                'id' => 'figures', 'title' => 'Read the four quantities', 'icon' => 'box',
                'kicker' => 'Parts definition',
                'summary' => 'Opening, issued, POS and closing explain the signed variance.',
                'body' => [
                    'Opening is stock at the start of the shift. Issued is stock recorded as supplied to this counting area. POS is the quantity sold through the till. Closing is stock counted at the end. Read all four in the item’s unit, such as each or kilograms.',
                    'Variance = POS − (Opening + Issued − Closing). A negative result is a short, a positive result is an over, and zero means these quantities agree. For example, opening 100, issued 20, POS 30 and closing 80 give 30 − 40 = −10.',
                    'Original values preserve the recorded staff basis. Current values are what PumpIT holds now. Proposed values are what Agora calculates for the run. The preview uses original opening, issue, closing and POS, with a narrow exception for an extreme issue-entry error already corrected in PumpIT. An opening may also be repaired to the previous closing.',
                    'A unit variance and a rand value answer different questions. Read the quantity first and check the value’s stated basis before comparing it with another report. Original/live count drift is evidence to review, not proof that a correction was forbidden.',
                ],
                'steps' => [
                    ['title' => 'Read one shift', 'text' => 'Open an item chain and find opening, issued, POS, closing and variance on a single shift.', 'screen' => 'Run → Proposals → expand item chain → open shift detail'],
                    ['title' => 'Name the basis', 'text' => 'Compare the original, current and proposed figures before explaining what will change.', 'screen' => 'Run → Proposals → shift detail'],
                ],
                'scenario_id' => 'zero-balance', 'event_ids' => ['count-drift'],
            ],
            [
                'id' => 'chains', 'title' => 'Follow the handover between shifts', 'icon' => 'link',
                'kicker' => 'Continuity and anchors',
                'summary' => 'The closing of one shift becomes the opening of the next.',
                'body' => [
                    'A chain is one item in one counting area over the chosen period. Read its shifts in date and shift order. The first opening and latest active closing anchor count balancing; the first opening is checked against the closing immediately before the period.',
                    'A break means a counted opening does not equal the previous closing. Agora repairs the opening to the previous closing. With the default option, a break of up to 3 units is reported and balanced through. A larger break normally splits the chain and pins the preceding closing; a nil-total chain can run through larger breaks.',
                    'An active shift has stock movement or a count difference. A dormant shift has no issue, no POS and equal opening and closing. If the neighbouring balance moves, a dormant shift’s opening and closing move together so that its variance remains zero.',
                    'The 3-unit break option is not a rounding tolerance. The preview compares counts at 0.0005 and variances at 0.005. Shared letters and colours identify neighbouring shifts linked by the proposed closing changes.',
                ],
                'steps' => [
                    ['title' => 'Trace the chain', 'text' => 'Read one closing and the next opening, including any reported Break quantity.', 'screen' => 'Run → Proposals → expand item chain'],
                    ['title' => 'Check its boundaries', 'text' => 'Read the first opening, latest closing and any split before comparing the chain total.', 'screen' => 'Run → Proposals → open shift detail → chain'],
                ],
                'scenario_id' => 'zero-balance', 'event_ids' => ['opening-break'],
            ],
            [
                'id' => 'balancing', 'title' => 'See a short and an over balance', 'icon' => 'scale',
                'kicker' => 'Count correction',
                'summary' => 'A shared closing can explain an equal short on one shift and over on the next.',
                'body' => [
                    'Raising a shift’s closing by 10 raises that shift’s variance by 10. The next opening also rises by 10, lowering the next shift’s variance by 10. The two changes cancel across the chain.',
                    'In the example, shift A is short 10 and shift B is over 10. A’s closing changes from 80 to 90 and B’s opening from 80 to 90. Both variances become zero. The first opening, final closing, issues and POS remain unchanged.',
                    'These are proposed amendments to the recorded counts. They are not a new physical stock count or a stock delivery. Count balancing must not turn an over into a short or make a shift shorter than its own count.',
                    'The example assumes a complete chain with valid source quantities and no blocking finding. A zero total does not override a possible duplicate hold or a proposal that cannot safely be written.',
                ],
                'steps' => [
                    ['title' => 'Compare both shifts', 'text' => 'Follow the same 10 units through the closing change and the next opening change.', 'screen' => 'Training example → A short and an equal over'],
                    ['title' => 'Review the full proposal', 'text' => 'In a real run, expand the chain and read every shift that moves before selecting it.', 'screen' => 'Run → Proposals → expand item chain'],
                ],
                'scenario_id' => 'zero-balance', 'event_ids' => [],
            ],
            [
                'id' => 'residual', 'title' => 'Understand the short left over', 'icon' => 'scale',
                'kicker' => 'Residual variance',
                'summary' => 'Balancing can remove the offsetting part while a net short remains.',
                'body' => [
                    'A residual is the variance left after the proposal. In a continuous chain with fixed ends and unchanged issues and POS, count changes preserve the total: total POS − (first opening + total issued − final closing).',
                    'A short of 5 and an over of 3 total a short of 2. Moving the shared count by 3 leaves the first shift short 2 and the second at zero. Balance … short remains is a valid result; it does not mean the process failed.',
                    'Treat the remaining short as an unresolved stock difference requiring supporting evidence. Check counts, issues and sales linkage before deciding its cause or responsibility. Where several people worked a shift, the names alone cannot divide that difference between them.',
                ],
                'steps' => [
                    ['title' => 'Add the signed variances', 'text' => 'Keep the signs: −5 + 3 = −2. Compare that total before and after the count proposal.', 'screen' => 'Training example → A short remains'],
                    ['title' => 'Investigate the remainder', 'text' => 'Read the surviving short and relevant exceptions with the count sheet and issue evidence.', 'screen' => 'Run → Proposals → item chain; Run → Exceptions'],
                ],
                'scenario_id' => 'residual-short', 'event_ids' => ['residual-short'],
            ],
            [
                'id' => 'no-offset', 'title' => 'Recognise when nothing can balance', 'icon' => 'search',
                'kicker' => 'No offset',
                'summary' => 'Two shorts do not cancel each other.',
                'body' => [
                    'A chain can be unblocked and still have no count amendment to make. If every active variance is short, there is no over to set against them. The existing shorts stay on the shifts where they occur.',
                    'In the example, shorts of 5 and 3 remain a total short of 8. Moving 3 from one shift to another would make one shift shorter; it would not remove the total. The correct teaching result is unchanged counts.',
                    'Short … nothing to balance differs from Already balanced in PumpIT. The first has no offsetting over; the second means the proposed answer is already in the live figures. Neither needs an Amend write just to make a button available.',
                ],
                'steps' => [
                    ['title' => 'Read the chain verdict', 'text' => 'Check whether it says nothing to balance, already balanced, or held back; each has a different reason.', 'screen' => 'Run → Proposals → item chain outcome'],
                    ['title' => 'Follow the evidence', 'text' => 'For a remaining short, review the underlying count and issue records instead of forcing a count move.', 'screen' => 'Run → Exceptions → relevant item'],
                ],
                'scenario_id' => 'no-offset', 'event_ids' => ['no-offset', 'residual-short'],
            ],
            [
                'id' => 'missing-issues', 'title' => 'Understand a missing issue', 'icon' => 'receipt',
                'kicker' => 'Issue correction',
                'summary' => 'An over left after eligible balancing is proposed as an additional issue.',
                'body' => [
                    'An over means the recorded quantities account for less stock than was sold or remains on hand. A count adjustment can offset an over against a short, but fixed chain ends prevent count changes from removing a net over.',
                    'On a chain that is not held back, the current process proposes adding each active shift’s remaining over to its issued quantity. In the example, issued 20 becomes 25 and the over of 5 becomes zero. Opening, closing and POS do not move.',
                    'Missing issue captured is a proposal until Amend writes it. The prior issue is kept for reversal. The U1–U4 or S1 finding can remain because it records what was found, even though the proposal resolves the variance.',
                    'The arithmetic identifies an issue quantity needed to balance; it is not a delivery note. Review the issue book and transfer or delivery evidence. If the live issue already matches the proposal, Agora reports that it is already in PumpIT and has no extra issue to write.',
                ],
                'steps' => [
                    ['title' => 'Read the issue change', 'text' => 'Compare issued before and proposed issued, alongside the over being removed.', 'screen' => 'Run → Proposals → Missing issue captured → item chain'],
                    ['title' => 'Confirm the evidence', 'text' => 'Check which shift received the stock and read any other hold before reviewing Amend.', 'screen' => 'Run → Exceptions; Run → Proposals → shift detail'],
                ],
                'scenario_id' => 'missing-issue', 'event_ids' => ['missing-issue'],
            ],
            [
                'id' => 'duplicates', 'title' => 'Separate clear and possible duplicates', 'icon' => 'receipt',
                'kicker' => 'D1 and D2',
                'summary' => 'A clear duplicate has sufficient evidence for removal; a possible duplicate needs a person to investigate.',
                'body' => [
                    'D1 means the issue appears twice and the independent count supports which entry did not arrive. The test also checks the neighbouring shift, timing, usual issue size, ambiguity and area type. Prepared-food batches are not treated as clear shelf-goods duplicates.',
                    'For a clear duplicate, the proposal removes the duplicate issue by setting it to zero. A related count proposal must be written with the required issue correction. If it is already removed in PumpIT, there may be nothing further to write.',
                    'D2 means the evidence is insufficient: for example both matching shifts are short, or the short only approximates the issue. It holds the whole item chain, including restoration of hand amendments. Agora does not automatically remove a D2 issue.',
                    'Open the case, check the issue book, stock count or delivery note, and record a meaningful release reason only when the investigation supports proceeding. Release records a decision in Agora; it does not correct the PumpIT issue. Preview again afterwards. A release stops applying if the issue changes or the release is withdrawn. Editing only the live issue does not erase the original D2 evidence.',
                ],
                'steps' => [
                    ['title' => 'Compare the pair', 'text' => 'Open the duplicate case and read the candidate, its twin and the reason for D1 or D2.', 'screen' => 'Run → Duplicate issues → case'],
                    ['title' => 'Resolve a possible duplicate', 'text' => 'For D2, check the source evidence, use Release with the finding as the reason when justified, then create a new preview.', 'screen' => 'Run → item chain → Release; Stock recon centre → Preview the balancing'],
                ],
                'scenario_id' => 'clear-duplicate', 'event_ids' => ['clear-duplicate', 'possible-duplicate'],
            ],
            [
                'id' => 'holds', 'title' => 'Read a hold without guessing', 'icon' => 'shield',
                'kicker' => 'Eligibility and exceptions',
                'summary' => 'A finding explains evidence; a hold controls what can be proposed or written.',
                'body' => [
                    'Read the chain verdict as well as the code. Missing-issue findings and small opening breaks can be reported while the chain balances. A code on the Exceptions tab does not always mean every write is blocked.',
                    'Possible duplicates hold the whole chain. Negative or impossible counts, implausibly large corrections, issues with no movement, missing POS linkage and conflicts with the existing audit can also stop normal count balancing. On nil-total chains some findings are reported without blocking; a possible duplicate or an unsafe write still holds.',
                    'Held back can include a specific proposal to put a forbidden hand amendment back to the staff basis, or a clear duplicate correction that is independently safe. Read that row’s proposed action; it does not mean the held chain was balanced. A D2-held chain receives neither write.',
                    'Incomplete days are a different boundary: the preview normally stops at the last complete recon day and excludes the first incomplete day and everything after it. Do not treat a missing day as a day with zero movement.',
                ],
                'steps' => [
                    ['title' => 'Find what holds the chain', 'text' => 'Open its detail and read the named reason and the shift that caused it.', 'screen' => 'Run → Proposals → held item chain'],
                    ['title' => 'Check the finding guidance', 'text' => 'Open the code explanation, verify the source and preview again after the underlying problem is resolved.', 'screen' => 'Run → Exceptions → code badge'],
                ],
                'scenario_id' => 'negative-count',
                'event_ids' => ['possible-duplicate', 'no-pos-link', 'count-fault', 'issue-nowhere', 'audit-conflict', 'incomplete-period'],
            ],
            [
                'id' => 'workflow', 'title' => 'Work through a real preview', 'icon' => 'check',
                'kicker' => 'Operator walkthrough',
                'summary' => 'Choose a scope, inspect its chains, review exceptions and check the exact selected work.',
                'body' => [
                    'In Stock recon centre, select the site and counting area. In a branch workspace the site is already fixed. Choose From; To is calculated as six days later or today, whichever comes first. Name the run if that will help distinguish repeated previews.',
                    'Preview the balancing reads the source and saves a run in Agora without changing PumpIT. Check the effective complete period and any held-back rows, then read Proposals and Exceptions together. Options are saved with the run; the default keeps incomplete days out.',
                    'Expand an item chain, then a shift’s detail. Compare original and proposed quantities, the current PumpIT figures, the chain result and any duplicate case. Shared letters show which shifts balance against one another.',
                    'Selections and filters affect the write. Rows excluded by a filter are excluded from submission; paging can retain selections on other pages. Read the Amend count and confirmation for the exact selected work. A label that sounds resolved is not a successful commit receipt.',
                ],
                'steps' => [
                    ['title' => 'Create the preview', 'text' => 'Select Site, Counting area and From, check To, then press Preview the balancing.', 'screen' => 'Stock recon centre → Balance a period'],
                    ['title' => 'Review the result', 'text' => 'Read Proposals, expand chains, open shift detail and inspect Exceptions and Duplicate issues.', 'screen' => 'Run → Proposals / Exceptions / Duplicate issues'],
                    ['title' => 'Check the selected work', 'text' => 'Review filters, ticked shifts and the Amend count before proceeding with an authorised write.', 'screen' => 'Run → Proposals → Amend'],
                ],
                'scenario_id' => 'possible-duplicate', 'event_ids' => ['incomplete-period', 'source-changed'],
            ],
            [
                'id' => 'commit-recovery', 'title' => 'Commit, verify and recover', 'icon' => 'history',
                'kicker' => 'Actions and audit trail',
                'summary' => 'Amend writes a reviewed proposal; Reset and Reverse have distinct meanings.',
                'body' => [
                    'In the configured live mode, Amend writes eligible selected opening and closing changes and the explicit issue corrections to PumpIT. It records prior values first. The commit rechecks source values and dependencies, so a ticked row can still be skipped. Read what succeeded and why anything was skipped.',
                    'Once a commit writes at least one row, it closes that saved run to another commit, even if some candidates were skipped. Make a fresh preview to review remaining work. If every candidate is skipped, nothing is written and the run stays open. Do not describe every selected row as written merely because a run has a committed status.',
                    'Reset to original restores the run’s eligible opening, issue, closing and POS values, plus the preceding closing used as an anchor, to their recorded originals. It keeps the priors, leaves D2-held chains and other runs’ standing balancing amendments alone, and creates a fresh preview. It is not a routine step for every run.',
                    'Reverse requires a reason and restores exactly the values that stood immediately before that run’s writes, not necessarily the original values. It refuses if subsequent count, issue or POS changes would be overwritten. If a later balancing run stands on a reset, reverse that later balancing first. Use the recorded run and audit trail to identify the correct action.',
                ],
                'steps' => [
                    ['title' => 'Read the commit receipt', 'text' => 'Check the actual written and skipped results, then preview again to assess what remains.', 'screen' => 'Run → Proposals → Amend result; Run another'],
                    ['title' => 'Choose recovery deliberately', 'text' => 'Use Reset to original for a return to the original basis; use Reverse on the run whose exact previous values must be restored.', 'screen' => 'Run → Proposals → Reset to original / Reverse'],
                    ['title' => 'Verify the history', 'text' => 'Read the recorded prior and new quantities and the reason alongside the current source.', 'screen' => 'Control → Governance → Audit trail'],
                ],
                'scenario_id' => null, 'event_ids' => ['source-changed', 'run-closed', 'audit-conflict'],
            ],
            [
                'id' => 'review', 'title' => 'Close the review with evidence', 'icon' => 'search',
                'kicker' => 'Review habit',
                'summary' => 'Explain what changed, what remains and what evidence supports the next action.',
                'body' => [
                    'For each material finding, record the item, site, area, period, original difference, proposed or written change, and what remains. State whether you are reading a saved preview or a completed commit.',
                    'Check the count sheet, issue book, deliveries and POS linkage against the relevant shift. A persistent short, whole-pack short, off-pack issue or carried-forward-count pattern guides the investigation; a pattern alone is not a finding of misconduct.',
                    'Global comparison can help review related quantities across areas when the run covers every area. Read units, ratios and the selected basis. A possible offset or production comparison is evidence for investigation, not permission to transfer or net off unrelated stock.',
                    'Finish by separating completed writes, holds, remaining differences and evidence still needed. Weekly cases and their authorisation are a separate review workflow; completing a shift-balancing run does not by itself authorise a staff charge or close every weekly case.',
                ],
                'steps' => [
                    ['title' => 'Follow the unresolved evidence', 'text' => 'Use Exceptions and the item chain to identify the source record needed for each remaining finding.', 'screen' => 'Run → Exceptions → item; Run → Proposals → chain'],
                    ['title' => 'Check broader comparisons', 'text' => 'Where relevant, review linked areas and units without assuming a candidate comparison is a correction.', 'screen' => 'Run → Global comparison'],
                    ['title' => 'State the next action', 'text' => 'Name the unresolved difference, the evidence needed and the person responsible for the investigation.', 'screen' => 'Review notes and supporting records'],
                ],
                'scenario_id' => null, 'event_ids' => ['residual-short', 'count-drift'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function scenarios(): array
    {
        return [
            [
                'id' => 'zero-balance', 'title' => 'A short and an equal over', 'kind' => 'count-balancing',
                'summary' => '−10 and +10 become 0 and 0 by correcting the shared count.',
                'explanation' => 'Training dataset: one item, one area, two consecutive active shifts, complete source and no hold. Raise A’s closing from 80 to 90; B’s opening follows from 80 to 90. First opening 100 and final closing 70 stay fixed. Issues and POS are unchanged.',
                'checks' => ['Before: A = 30 − (100 + 20 − 80) = −10; B = 20 − (80 + 0 − 70) = +10.', 'After: A = 30 − (100 + 20 − 90) = 0; B = 20 − (90 + 0 − 70) = 0.', 'The total is zero before and after; no stock was added.'],
                'before' => [
                    ['shift' => 'A', 'open' => 100, 'issued' => 20, 'pos' => 30, 'close' => 80],
                    ['shift' => 'B', 'open' => 80, 'issued' => 0, 'pos' => 20, 'close' => 70],
                ],
                'after' => [
                    ['shift' => 'A', 'open' => 100, 'issued' => 20, 'pos' => 30, 'close' => 90],
                    ['shift' => 'B', 'open' => 90, 'issued' => 0, 'pos' => 20, 'close' => 70],
                ],
                'steps' => [['title' => 'Find the offset', 'text' => 'A’s short and B’s over are equal and opposite.'], ['title' => 'Move the shared count', 'text' => 'Add 10 to A’s closing and to B’s opening.'], ['title' => 'Check both ends', 'text' => 'Opening 100 and closing 70 remain the anchors; both shifts now agree.']],
                'event_ids' => [],
            ],
            [
                'id' => 'residual-short', 'title' => 'A short remains', 'kind' => 'count-balancing',
                'summary' => '−5 and +3 become −2 and 0; the total short cannot disappear through count balancing.',
                'explanation' => 'Training dataset: a complete, unblocked two-shift chain. Raise A’s closing and B’s opening from 35 to 38. Only 3 of A’s short can be offset. The remaining 2 needs investigation; this example does not establish its cause.',
                'checks' => ['Before: A = 10 − (50 − 35) = −5; B = 8 − (35 − 30) = +3.', 'After: A = 10 − (50 − 38) = −2; B = 8 − (38 − 30) = 0.', 'The total is −2 before and after, with the same first opening and final closing.'],
                'before' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 35],
                    ['shift' => 'B', 'open' => 35, 'issued' => 0, 'pos' => 8, 'close' => 30],
                ],
                'after' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 38],
                    ['shift' => 'B', 'open' => 38, 'issued' => 0, 'pos' => 8, 'close' => 30],
                ],
                'steps' => [['title' => 'Add the signed quantities', 'text' => '−5 + 3 leaves −2 for the chain.'], ['title' => 'Offset only the available over', 'text' => 'Move the shared count by 3; B goes to zero and A retains −2.'], ['title' => 'Investigate the remainder', 'text' => 'Check the evidence for the remaining 2 rather than forcing both shifts to zero.']],
                'event_ids' => ['residual-short'],
            ],
            [
                'id' => 'no-offset', 'title' => 'Two shorts with no offset', 'kind' => 'unchanged',
                'summary' => '−5 and −3 stay unchanged: there is no over to balance against.',
                'explanation' => 'Training dataset: both shifts are short and there is no other offset on the chain. The after figures repeat the before figures to show no proposed count change. A chain can be unblocked without having anything to write.',
                'checks' => ['A = 10 − (50 − 35) = −5; B = 8 − (35 − 24) = −3.', 'The chain remains short 8.', 'Moving a short between these shifts would not remove it and must not make either shift shorter.'],
                'before' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 35],
                    ['shift' => 'B', 'open' => 35, 'issued' => 0, 'pos' => 8, 'close' => 24],
                ],
                'after' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 35],
                    ['shift' => 'B', 'open' => 35, 'issued' => 0, 'pos' => 8, 'close' => 24],
                ],
                'steps' => [['title' => 'Look for an over', 'text' => 'Neither shift has a positive variance.'], ['title' => 'Keep the counts', 'text' => 'No eligible offset means no count proposal in this example.'], ['title' => 'Review the short', 'text' => 'The 8-unit difference remains for investigation.']],
                'event_ids' => ['no-offset'],
            ],
            [
                'id' => 'missing-issue', 'title' => 'An over needs an issue', 'kind' => 'issue-correction',
                'summary' => '+5 becomes 0 by increasing issued stock from 20 to 25.',
                'explanation' => 'Training dataset: an active shift has an over of 5 after count balancing and its chain has no hold. The explicit proposal is an additional issue of 5. Opening, closing and POS remain unchanged. This is an issue correction, not a count adjustment or a delivery record.',
                'checks' => ['Before: 30 − (100 + 20 − 95) = +5.', 'Proposed issued = 20 + 5 = 25; after: 30 − (100 + 25 − 95) = 0.', 'The total changes because issued changes; no boundary count moves.'],
                'before' => [['shift' => 'A', 'open' => 100, 'issued' => 20, 'pos' => 30, 'close' => 95]],
                'after' => [['shift' => 'A', 'open' => 100, 'issued' => 25, 'pos' => 30, 'close' => 95]],
                'steps' => [['title' => 'Identify the remaining over', 'text' => 'The recorded stock accounts for 25 sold while POS shows 30.'], ['title' => 'Name the actual correction', 'text' => 'Propose 5 additional issued units, giving issued 25.'], ['title' => 'Review and verify', 'text' => 'Check the issue evidence and the commit result; the finding remains visible.']],
                'event_ids' => ['missing-issue'],
            ],
            [
                'id' => 'clear-duplicate', 'title' => 'A clear duplicate issue', 'kind' => 'issue-correction',
                'summary' => 'A second issue of 12 did not arrive; removing it changes −12 to 0.',
                'explanation' => 'Training dataset: assume the full D1 evidence is confirmed: shelf goods, an unambiguous same-day neighbouring pair, A’s issue arrived, B is short exactly its issue, and no other hold or conflicting live amendment exists. These quantities alone are not the complete duplicate test. B’s proposed issue becomes 0; counts and POS stay unchanged.',
                'checks' => ['A = 4 − (20 + 12 − 28) = 0: its issue arrived.', 'Before B = 3 − (28 + 12 − 25) = −12; after B = 3 − (28 + 0 − 25) = 0.', 'Only B’s issue is removed; A’s real issue of 12 stays.'],
                'before' => [
                    ['shift' => 'A', 'open' => 20, 'issued' => 12, 'pos' => 4, 'close' => 28],
                    ['shift' => 'B', 'open' => 28, 'issued' => 12, 'pos' => 3, 'close' => 25],
                ],
                'after' => [
                    ['shift' => 'A', 'open' => 20, 'issued' => 12, 'pos' => 4, 'close' => 28],
                    ['shift' => 'B', 'open' => 28, 'issued' => 0, 'pos' => 3, 'close' => 25],
                ],
                'steps' => [['title' => 'Check the independent count', 'text' => 'A balances with its issue; B’s count falls only by its POS sales.'], ['title' => 'Require the complete D1 evidence', 'text' => 'The timing, area type and unambiguous pair must also satisfy the duplicate test.'], ['title' => 'Remove the duplicate issue', 'text' => 'The eligible proposal sets only B’s issue to zero and records the prior when committed.']],
                'event_ids' => ['clear-duplicate'],
            ],
            [
                'id' => 'possible-duplicate', 'title' => 'A possible duplicate stays held', 'kind' => 'held',
                'summary' => 'Both shifts are short by their issue of 12, so the counts cannot identify which issue was real.',
                'explanation' => 'Training dataset: neighbouring shifts have matching issues, but both are short by 12. Assume the duplicate candidate is D2 and unreleased. No after proposal is supplied: the whole item chain remains held while a person checks the issue book, count or delivery evidence.',
                'checks' => ['A = 4 − (20 + 12 − 16) = −12; B = 3 − (16 + 12 − 13) = −12.', 'Matching issue quantities do not prove which entry should be removed.', 'Release records a supported decision; it does not set either issue to zero. A new preview is required.'],
                'before' => [
                    ['shift' => 'A', 'open' => 20, 'issued' => 12, 'pos' => 4, 'close' => 16],
                    ['shift' => 'B', 'open' => 16, 'issued' => 12, 'pos' => 3, 'close' => 13],
                ],
                'after' => null,
                'steps' => [['title' => 'Recognise ambiguity', 'text' => 'Neither matching shift provides a balancing witness that its issue arrived.'], ['title' => 'Hold the chain', 'text' => 'Do not invent a count or choose an issue to remove.'], ['title' => 'Investigate, then preview', 'text' => 'Check supporting records; if release is justified, record the reason and preview again.']],
                'event_ids' => ['possible-duplicate'],
            ],
            [
                'id' => 'negative-count', 'title' => 'A negative count needs correction', 'kind' => 'held',
                'summary' => 'A closing count of −1 is invalid source evidence, not a quantity to balance away.',
                'explanation' => 'Training dataset: a physical stock count is recorded below zero. The formula can still produce a number, but that arithmetic does not make the source count valid. No proposed after figures are supplied; verify the count sheet and correct the source through the appropriate process before a new preview.',
                'checks' => ['The formula gives 2 − (10 + 0 − (−1)) = −9.', 'The closing of −1 is the problem; the −9 is not enough evidence to assign responsibility.', 'A numeric result does not override the data-fault hold.'],
                'before' => [['shift' => 'A', 'open' => 10, 'issued' => 0, 'pos' => 2, 'close' => -1]],
                'after' => null,
                'steps' => [['title' => 'Inspect the raw quantity', 'text' => 'Identify the negative closing instead of starting with the shortage total.'], ['title' => 'Verify the source', 'text' => 'Compare the physical count and its recorded unit with the count sheet.'], ['title' => 'Preview after correction', 'text' => 'Use corrected evidence to obtain a new proposal; do not force the old one.']],
                'event_ids' => ['count-fault'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return [
            [
                'id' => 'opening-break', 'title' => 'Opening differs from the previous closing', 'code' => 'S10a / S10b', 'tone' => 'warn',
                'meaning' => 'The handover is discontinuous. S10a is an opening below the previous closing; S10b is above. The finding identifies the mismatch, not why it happened.',
                'example' => 'The prior shift closes at 40; the next opens at 38. The next opening needs to read 40, and the difference is reported.',
                'checks' => ['Compare both original counts and the handover record.', 'Read the Break quantity and the run’s keep-the-chain-going option.', 'Check whether the chain is split or another finding holds it.'],
                'next' => ['Open the chain and inspect the repaired opening.', 'Confirm the handover evidence with the responsible site staff.'],
                'location' => 'Run → Proposals → Break / item chain; Run → Exceptions → S10a or S10b',
                'lesson_id' => 'chains',
                'write_effect' => 'An eligible proposal repairs the opening. Default breaks up to 3 units do not split the chain; larger breaks normally pin the previous closing, with a nil-chain exception.',
            ],
            [
                'id' => 'residual-short', 'title' => 'Balancing leaves a short', 'code' => 'Short remains', 'tone' => 'warn',
                'meaning' => 'Count balancing has used the available offset, but the chain still has a negative total. The cause requires supporting evidence.',
                'example' => 'A short of 5 and an over of 3 become a short of 2 and zero.',
                'checks' => ['Compare the total before and after.', 'Check issues, count sheets and POS linkage.', 'Distinguish a remaining difference from an established cause or responsibility.'],
                'next' => ['Review the surviving short on its shift.', 'Record the evidence needed for the remaining difference.'],
                'location' => 'Run → Proposals → chain outcome and proposed variance',
                'lesson_id' => 'residual',
                'write_effect' => 'Eligible count changes can be written while a short remains. Count balancing does not erase the net short.',
            ],
            [
                'id' => 'no-offset', 'title' => 'Nothing to balance', 'code' => 'No offset', 'tone' => 'neutral',
                'meaning' => 'There is no over to set against the shorts, so an unblocked chain may have no amendment to make.',
                'example' => 'Two shifts short 5 and 3 remain short 8 together, with unchanged counts.',
                'checks' => ['Look for a positive variance anywhere on the chain.', 'Read the exact verdict: nothing to balance differs from already balanced in PumpIT.'],
                'next' => ['Investigate the remaining shorts.', 'Leave unchanged quantities unchanged; no count move is needed just to create a write.'],
                'location' => 'Run → Proposals → chain outcome',
                'lesson_id' => 'no-offset', 'write_effect' => 'No balancing write is proposed in this case.',
            ],
            [
                'id' => 'missing-issue', 'title' => 'Missing issue proposed', 'code' => 'U1–U4 / S1', 'tone' => 'warn',
                'meaning' => 'Recorded stock does not account for what was sold or remains. U1 concerns sales beyond available stock, U2 an excessive closing, U3 sales with no recorded issues, U4 a net over, and S1 a whole-pack over pattern.',
                'example' => 'An eligible shift remains over 5; its issued quantity is proposed to rise from 20 to 25.',
                'checks' => ['Read the issue proposal separately from count changes.', 'Check the issue book, transfer or delivery evidence.', 'Confirm there is no hold and whether the issue is already in PumpIT.'],
                'next' => ['Review the shift receiving the proposed issue.', 'After an authorised Amend, verify the result and the recorded issue prior.'],
                'location' => 'Run → Proposals → Missing issue captured; Run → Exceptions → U1–U4 or S1',
                'lesson_id' => 'missing-issues',
                'write_effect' => 'On an eligible active shift, Amend adds the remaining over to issued quantity. The exception can remain as the record of what was found.',
            ],
            [
                'id' => 'clear-duplicate', 'title' => 'Clear duplicate issue', 'code' => 'D1', 'tone' => 'good',
                'meaning' => 'The complete duplicate test supports that one recorded issue did not arrive and duplicates a neighbouring issue that did.',
                'example' => 'A’s issue of 12 arrived and A balances. B repeats 12, but its independent count falls only by sales and it is short exactly 12; the other D1 conditions are assumed satisfied.',
                'checks' => ['Read the twin, timing, area type and reason in the case.', 'Check whether it is already removed in PumpIT.', 'Keep required issue and count proposals together.'],
                'next' => ['Review the full duplicate case.', 'Verify that the completed commit removed only the identified duplicate issue.'],
                'location' => 'Run → Duplicate issues → clear duplicate case',
                'lesson_id' => 'duplicates',
                'write_effect' => 'Eligible D1 correction sets that shift’s issue to zero and records its prior. A held chain permits an independent D1 write only where it is safe; D2 holds permit none.',
            ],
            [
                'id' => 'possible-duplicate', 'title' => 'Possible duplicate needs evidence', 'code' => 'D2', 'tone' => 'serious',
                'meaning' => 'Matching nearby issues suggest a duplicate, but the evidence cannot prove which issue was real or whether one should be removed.',
                'example' => 'Both shifts carry an issue of 12 and both are short 12. Neither is the independent witness that the other duplicated a real delivery.',
                'checks' => ['Read the specific reason the candidate is only possible.', 'Check the issue book, count or delivery evidence.', 'Check whether a release still matches the issue and remains active.'],
                'next' => ['Investigate before releasing.', 'If justified, use Release with a specific evidence-based reason, then preview again.'],
                'location' => 'Run → Duplicate issues → possible duplicate case; item chain → Release',
                'lesson_id' => 'duplicates',
                'write_effect' => 'An unreleased D2 holds the whole chain. Release writes an Agora decision only; it neither removes the issue nor changes PumpIT counts.',
            ],
            [
                'id' => 'no-pos-link', 'title' => 'Stock falls with too little POS', 'code' => 'E1', 'tone' => 'crit',
                'meaning' => 'The pattern indicates a possible selling-code or recipe linkage problem: counts fall while little corresponding POS quantity reaches the item.',
                'example' => 'Over several days an item’s counts reduce substantially while its linked sales remain near zero.',
                'checks' => ['Check the selling PLU and recipe mapping.', 'Check that POS was posted for the period.', 'Do not turn absent sales linkage into an assumption of staff loss.'],
                'next' => ['Ask the master-data or POS owner to verify the link.', 'Preview again once the source is corrected.'],
                'location' => 'Run → Exceptions → E1; Proposals → held chain',
                'lesson_id' => 'holds',
                'write_effect' => 'The affected non-nil chain is held from normal count balancing. Read any separate hand-restoration proposal explicitly.',
            ],
            [
                'id' => 'count-fault', 'title' => 'Invalid count or implausible correction', 'code' => 'F1', 'tone' => 'crit',
                'meaning' => 'A source count is below zero, the proposal would produce a negative closing, or a correction exceeds the run’s permitted caps.',
                'example' => 'A physical closing is recorded as −1, or a very large count change is proposed against a small amount of stock.',
                'checks' => ['Verify quantity, unit and the original count sheet.', 'Read the exact fault and the stored amendment caps.', 'Distinguish an actual invalid count from a cap finding on an otherwise nil chain.'],
                'next' => ['Verify or correct the source through its normal process.', 'Preview again; do not enlarge caps simply to hide a suspect quantity.'],
                'location' => 'Run → Exceptions → F1; Proposals → held chain',
                'lesson_id' => 'holds',
                'write_effect' => 'Unsafe writes remain held. Nil-total chains can report cap findings without blocking, so the chain verdict determines eligibility.',
            ],
            [
                'id' => 'issue-nowhere', 'title' => 'Issue with no movement', 'code' => 'F2', 'tone' => 'warn',
                'meaning' => 'Stock is recorded as issued, POS is zero and opening equals closing. The issue or count needs verification.',
                'example' => 'Opening 20, issued 12, POS 0 and closing 20 leave a short of 12 without recorded stock movement.',
                'checks' => ['Check whether the issue was physically delivered.', 'Check whether the closing was counted or copied.', 'Review a duplicate case if one is also present.'],
                'next' => ['Check the issue book and the count sheet.', 'Preview again when reliable evidence is available.'],
                'location' => 'Run → Exceptions → F2; Proposals → item chain',
                'lesson_id' => 'holds',
                'write_effect' => 'Can hold a non-nil chain from normal balancing. A nil chain can still balance while the finding remains reported.',
            ],
            [
                'id' => 'audit-conflict', 'title' => 'Proposal conflicts with existing live edits', 'code' => 'F3', 'tone' => 'warn',
                'meaning' => 'Writing the proposed counts alongside current PumpIT values would create a new forbidden audit result. A manually changed issue, POS or preceding closing may be involved.',
                'example' => 'A live issue was changed and counts were moved to match; writing only the newly balanced counts would leave incompatible figures.',
                'checks' => ['Compare original and current issue, POS and boundary closing.', 'Read the specific audit conflict.', 'Check for a standing amendment from another run.'],
                'next' => ['Review whether Reset to original is the appropriate authorised recovery.', 'After a reset, use its fresh preview and inspect the new proposal.'],
                'location' => 'Run → Exceptions → F3; Proposals → Reset to original',
                'lesson_id' => 'commit-recovery',
                'write_effect' => 'The conflicting proposal is withdrawn. Reset is a separate audited operation, with duplicate and other-run safeguards.',
            ],
            [
                'id' => 'incomplete-period', 'title' => 'The period stops before the chosen end', 'code' => 'Incomplete day', 'tone' => 'warn',
                'meaning' => 'Expected shift lines, closings or posted POS do not yet satisfy the completion check. The default preview excludes the first incomplete day and later days.',
                'example' => 'A seven-day request includes an unfinished Friday; the effective complete period ends before Friday.',
                'checks' => ['Read the requested dates and the actual complete-through date.', 'Check missing shifts, count capture and POS posting.', 'Check the stored Hold back days the recon has not finished option.'],
                'next' => ['Complete or verify the missing source work.', 'Create a new preview when the day is ready.'],
                'location' => 'Stock recon centre → Options; Run → complete-period and held-back notice',
                'lesson_id' => 'workflow',
                'write_effect' => 'Excluded days receive no balancing proposal in this run. An unfinished period is not filled with assumed zero quantities.',
            ],
            [
                'id' => 'source-changed', 'title' => 'The source changed after preview', 'code' => 'Skipped / moved', 'tone' => 'serious',
                'meaning' => 'A saved proposal is a snapshot. Later counts, issues, releases or standing amendments can make it unsafe to write or reverse.',
                'example' => 'A recount changes a closing after preview; Amend skips the stale candidate rather than overwriting the recount.',
                'checks' => ['Read each skipped row’s reason.', 'Compare the live source with the saved preview.', 'For Reverse, check COUNT_MOVED, ISSUE_MOVED or POS_MOVED and later runs.'],
                'next' => ['Create a fresh preview for remaining balancing work.', 'Investigate later writes before retrying a refused reversal.'],
                'location' => 'Run → Amend result / reversal refusal; Run another',
                'lesson_id' => 'commit-recovery',
                'write_effect' => 'A stale candidate or its dependent work is skipped. A reversal that would overwrite later changes is refused before it writes.',
            ],
            [
                'id' => 'count-drift', 'title' => 'Original and live counts differ', 'code' => 'C1', 'tone' => 'neutral',
                'meaning' => 'The preview found an original/live opening or closing difference without a higher-priority count or hand-balancing finding on the line. The difference alone proves no breach.',
                'example' => 'Original closing 25 and current closing 26 may reflect a supported correction; the count sheet decides the context.',
                'checks' => ['Compare the saved original and live values.', 'Find the supporting count correction or source record.', 'Do not confuse an informational difference with a balancing hold.'],
                'next' => ['Record what supports the change.', 'Use the chain verdict and actual proposal to determine what happens next.'],
                'location' => 'Run → Exceptions → C1; item or shift detail → original/current counts',
                'lesson_id' => 'figures',
                'write_effect' => 'Information only: C1 does not itself block balancing or order a restoration.',
            ],
            [
                'id' => 'run-closed', 'title' => 'A committed run needs a fresh preview', 'code' => 'Committed run', 'tone' => 'neutral',
                'meaning' => 'The saved run has completed its commit. Its status does not guarantee every selected candidate was written; the result lists any skips.',
                'example' => 'A commit writes eligible shifts and skips a changed row. The remaining work belongs in a fresh preview.',
                'checks' => ['Read the commit message and each skipped reason.', 'If every candidate was skipped, nothing was written and the run remains open.', 'Check the audit trail for actual writes.', 'Keep Reset and Reverse separate from re-previewing.'],
                'next' => ['Use Run another to preview the same intended period again.', 'Use Reverse only when the purpose is to undo that run’s recorded writes.'],
                'location' => 'Run → commit result; Run another; Proposals → Reverse',
                'lesson_id' => 'commit-recovery',
                'write_effect' => 'Another commit of a committed run is refused. Fresh preview reads the current state without amending PumpIT.',
            ],
        ];
    }
}
