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
    /** @return array{lessons: list<array<string, mixed>>, scenarios: list<array<string, mixed>>, events: list<array<string, mixed>>, flows: list<array<string, mixed>>} */
    public function all(): array
    {
        return [
            'lessons' => $this->lessons(),
            'scenarios' => $this->scenarios(),
            'events' => $this->events(),
            'flows' => (new StockReconTutorialFlows)->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lessons(): array
    {
        return [
            [
                'id' => 'purpose',
                'title' => 'The reconciliation sequence',
                'icon' => 'book',
                'kicker' => 'Management guide',
                'summary' => 'Understand what the algorithm changes, why it changes it, and what still needs a decision.',
                'body' => [
                    'For administrators and financial reviewers: follow the calculation from source records to a saved proposal and an audited write. A short is a stock difference to explain; an employee name on a shared shift is not proof of individual liability.',
                    'The sequence below matters. In particular, eligible exact issue corrections happen before count balancing; authorised good waste is applied before the final whole-multiple issue correction. The same shortage must not be explained twice.',
                    'These are fixed training examples, reviewed against the implemented rules on 7 November 2026. They neither calculate a real run nor change stock. A real run’s saved profile, modes, source records and commit receipt determine its result.',
                ],
                'flow' => [
                    [
                        'title' => 'Read the evidence',
                        'text' => 'Original counts, issues and POS; current values for comparison; item, area, profile and waste authorisation.',
                    ],
                    [
                        'title' => 'Build continuous periods',
                        'text' => 'One site + counting area + item. Honour removals and returns; require the configured shifts.',
                    ],
                    [
                        'title' => 'Repair the opening basis',
                        'text' => 'Use the preceding original closing where continuity exists; expose the opening break.',
                    ],
                    [
                        'title' => 'Classify duplicates',
                        'text' => 'Remove an eligible clear duplicate from the calculation. Hold an unreleased possible duplicate.',
                    ],
                    [
                        'title' => 'Correct exact issue multiples',
                        'text' => 'On eligible interior shifts, correct a whole-multiple issue variance before counts are considered.',
                    ],
                    [
                        'title' => 'Propose count changes',
                        'text' => 'Protect ordinary counted shorts. Only the identified opening-break pair gets the narrow offset exception.',
                    ],
                    [
                        'title' => 'Check the proposal',
                        'text' => 'Apply count, source, duplicate, continuity and audit safeguards; withdraw unsafe count proposals.',
                    ],
                    [
                        'title' => 'Apply authorised good waste',
                        'text' => 'Cover only the remaining short, capped at that short. This is an accounting adjustment.',
                    ],
                    [
                        'title' => 'Correct remaining issue multiples',
                        'text' => 'Add or subtract complete issue multiples on eligible interior shifts; leave any remainder visible.',
                    ],
                    [
                        'title' => 'Save, review, then Amend',
                        'text' => 'Preview saves evidence in Agora. Amend rechecks eligible selected writes and records actual priors.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'run-closed',
                ],
            ],
            [
                'id' => 'figures',
                'title' => 'Inputs and signed variance',
                'icon' => 'box',
                'kicker' => 'Step 1 · choose the basis',
                'summary' => 'Variance = POS − (Opening + Issued − Closing). Negative is short; positive is over.',
                'body' => [
                    'Read every quantity in the item’s own unit. Opening is stock at the start; issued is stock supplied to that area; POS is recorded sales; closing is the end count. Do not add kilograms to units and call the total a physical quantity.',
                    'Original is the recorded staff basis. Current is the PumpIT value read for the preview. Proposed is Agora’s answer for that saved run. The algorithm normally starts from original opening, issue, closing and POS, then repairs continuity. It also saves current values so the reviewer can see earlier edits.',
                    'There is one narrow issue-basis exception: when original issued exceeds 20 and is more than five times the greater of current issued or 1, the calculation uses the current issue. This accommodates a very large issue-entry error already corrected in PumpIT; it is not a general switch to current counts.',
                ],
                'flow' => [
                    [
                        'title' => 'Input',
                        'text' => 'Opening 100 + issued 20 − closing 80 = 40 accounted as sold.',
                    ],
                    [
                        'title' => 'Compare',
                        'text' => 'POS records 35 sold. 35 − 40 = −5.',
                    ],
                    [
                        'title' => 'Output',
                        'text' => 'A 5-unit short. The formula identifies a difference, not its cause.',
                    ],
                ],
                'scenario_ids' => [
                    'basic-variance',
                ],
                'event_ids' => [
                    'count-drift',
                ],
            ],
            [
                'id' => 'item-settings',
                'title' => 'Connect the item to its stock master',
                'icon' => 'box',
                'kicker' => 'Configuration → calculation',
                'summary' => 'The item’s issue multiple is a quantity step, not a unit conversion or a balancing profile.',
                'body' => [
                    'Open Setup → Trading rules → Stock master, choose the site and item, then read What this line is → Unit (which shows Issue multiple). In Change this stock line → How the counting behaves, Issue multiple is the editable field. The screen’s Held by field identifies whether an Agora override or the customer master is in force.',
                    'Agora’s effective stock-item view uses an unparked Agora override for the item; otherwise it reads the customer master. The balancing lookup prefers the row for the same area. It takes that row’s non-null IssueMultiple, including explicit 0 or 1. Only a null value falls back to the imported site/area/item issue-multiple record; if neither supplies a value, use 0. A changed value affects a fresh preview and is rechecked before an ordinary issue write.',
                    'Keep the quantities in their recorded unit. Multiple 5 means adjustments of 5, 10, 15 … of that unit. For an item recorded in kilograms, multiple 2.5 means steps of 2.5 kg; it does not turn 2.5 kg into one unit. Only multiples greater than 1 enable these automatic corrections. Changing Unit does not rescale historical opening, issued, POS or closing quantities.',
                    'Weighed classification uses the imported weighed flag, a KG/KGS/L/LT/LTR unit, or recognised kilogram/litre wording on the source master description. It affects duplicate tolerances and whole-pack pattern flags. It does not convert quantity. Confirm that unit and description accurately describe the item before interpreting those findings.',
                    'The master’s Pre-production ratio and type provide production context. Ratio is not multiplied into the balancing variance or the Global comparison quantities. Factor is a price multiplier, not an issue-quantity step. POS code and system identify sales context; preview reads the already-recorded original POS quantity and does not regenerate it from a new mapping.',
                    'Variance allowance, IssueMultiplePercentage, calculated-close and allow-negative flags are separate master/count-entry settings; this balancing calculation does not use them to relax its count, issue or shortage safeguards. The stock-master On hand figure is POS-file context, not the opening stock of a historical shift. The monetary short uses the selling price saved from the recon line, not a fresh recalculation from the master’s price or Factor.',
                    'Balancing profiles live separately: Control → Stock → Counting areas → choose the area → Balancing configuration → Item mappings. An item mapping chooses an active custom profile; an unmapped item uses Generic. Profile caps and break thresholds control count proposals, while Issue multiple controls issue-quantity steps. A profile or mapping change requires a fresh preview.',
                ],
                'flow' => [
                    [
                        'title' => 'Master in force',
                        'text' => 'Unparked Agora override → otherwise customer master. Confirm the site, item and area.',
                    ],
                    [
                        'title' => 'Issue multiple precedence',
                        'text' => 'Effective master value, even 0 or 1 → if null, imported area/item fallback → otherwise 0.',
                    ],
                    [
                        'title' => 'Calculation',
                        'text' => 'Correct eligible whole multiples only. Retain the remainder and protect boundary shifts.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'missing-issue',
                    'source-changed',
                ],
                'comparison' => [
                    [
                        'multiple' => '0 or 1',
                        'issued' => 20,
                        'remainder' => 12,
                        'why' => 'Automatic issue correction disabled; a fallback does not override these explicit values.',
                    ],
                    [
                        'multiple' => '5',
                        'issued' => 30,
                        'remainder' => 2,
                        'why' => 'Final pass: 2 × 5 = 10 additional units; leave 2.',
                    ],
                    [
                        'multiple' => '6',
                        'issued' => 32,
                        'remainder' => 0,
                        'why' => 'Early exact pass: 2 × 6 = 12, if its stricter period conditions pass.',
                    ],
                    [
                        'multiple' => '10',
                        'issued' => 30,
                        'remainder' => 2,
                        'why' => 'Final pass: 1 × 10 = 10 additional units; leave 2.',
                    ],
                ],
            ],
            [
                'id' => 'chains',
                'title' => 'Continuity, removals and returns',
                'icon' => 'link',
                'kicker' => 'Step 2 · establish the boundaries',
                'summary' => 'A chain belongs to one site, area, item and uninterrupted period of presence.',
                'body' => [
                    'The latest original closing before the window supplies the first opening where continuity exists. The search is not restricted to the last few days. A recorded removal ends that period: a returning item starts a new period with its own opening anchor.',
                    'The first and last observed shifts of each continuous period keep their issue quantities for ordinary issue correction. The latest active closing of every count-balancing segment is fixed. Clear duplicates use their own evidence rules.',
                    'A dormant shift has no issue or POS and equal repaired opening and closing. It can carry the preceding count adjustment through both counts, remaining at zero variance. A leading dormant shift receives no such adjustment.',
                ],
                'flow' => [
                    [
                        'title' => 'Period 1',
                        'text' => 'An item is counted in this area. Its last count supports a recorded removal.',
                    ],
                    [
                        'title' => 'Recorded removal',
                        'text' => 'The chain stops. Missing activity during a valid absence does not bridge the stock forward.',
                    ],
                    [
                        'title' => 'Period 2 · return',
                        'text' => 'Start a new opening anchor and protect its own first/last issues. Keep its result separate.',
                    ],
                ],
                'scenario_ids' => [
                    'removal-return',
                ],
                'event_ids' => [
                    'opening-break',
                ],
            ],
            [
                'id' => 'gaps',
                'title' => 'Missing shifts are not zero shifts',
                'icon' => 'alert',
                'kicker' => 'Step 2 · prove completeness',
                'summary' => 'A required gap holds the affected continuous chain. Agora does not invent the missing count.',
                'body' => [
                    'Required shifts come from the counting area’s Day, Afternoon and Night settings. Agora compares the next expected slot with the next recorded slot, including the predecessor before the selected period. A genuinely new first observation has no invented history.',
                    'A missing tail is detectable when later area activity or an explicit completed-area record proves that another count was expected. A valid removal at the last counted shift ends that obligation. A removal whose last-count evidence has disappeared is itself unsafe.',
                    'Explicitly completing an area with no rows can expose an entirely absent item. In that case the area’s chains can be held because the missing item cannot supply its own row. A later gap in a returned period does not erase the separate earlier period.',
                    'The optional incomplete-day cutoff is a different test, based on site row counts, nonzero closings and POS coverage. If enabled, it excludes the first incomplete day and later days. The reviewed Generic profile has this option off; required-shift gap checks still apply.',
                ],
                'flow' => [
                    [
                        'title' => 'Expected',
                        'text' => 'Day → Afternoon → Night, when all three shifts are enabled for the area.',
                    ],
                    [
                        'title' => 'Observed',
                        'text' => 'Day → missing Afternoon → Night.',
                    ],
                    [
                        'title' => 'Output · held',
                        'text' => 'MISSING_SHIFT. Resolve the source gap or supported removal evidence and preview again.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'missing-shift',
                    'incomplete-period',
                ],
            ],
            [
                'id' => 'duplicates',
                'title' => 'D1 removes; D2 holds',
                'icon' => 'receipt',
                'kicker' => 'Step 3 · resolve duplicate evidence',
                'summary' => 'Matching issue quantities alone do not prove a duplicate.',
                'body' => [
                    'A clear duplicate (D1) needs a positive issue whose short matches that issue, plus an identical nearby issue whose shift balances. The twin must be inside the preview window, within two observed shifts and one day, and the pair must be unambiguous. Prepared-food areas do not qualify as clear duplicates.',
                    'The short-match tolerance is 0.005 for whole units and 2% of the issue for weighed items. A next-day twin also requires that this is not the usual delivery size: it occurs on fewer than half the issues in the window plus the 56-day lookback. A near match within 10%, or ambiguous evidence, can become D2 instead.',
                    'D1 removes the duplicate from the calculation before count balancing and proposes issued zero when an actual write is needed. D2 holds the whole continuous chain while unreleased. Release requires supporting evidence and a meaningful reason; it only records a decision in Agora. Preview again afterwards. It does not itself remove an issue.',
                    'A release must still match the issue basis and the current issue and must not have been withdrawn. Once a hold is released, other eligible reconciliation rules may apply. A D1 write on an otherwise held chain is allowed only in its independently safe case, never through a duplicate, missing-shift or shortage-movement hold.',
                ],
                'flow' => [
                    [
                        'title' => 'D1 · supported',
                        'text' => 'A balances with its issue; B repeats the issue and is short that amount. All timing and ambiguity rules pass.',
                    ],
                    [
                        'title' => 'D2 · uncertain',
                        'text' => 'Both shifts are short, or the amounts only approximately match. Hold and investigate.',
                    ],
                    [
                        'title' => 'Release · decision',
                        'text' => 'Record evidence, then preview. A released D2 is not an automatic instruction to zero the issue.',
                    ],
                ],
                'scenario_ids' => [
                    'clear-duplicate',
                    'possible-duplicate',
                ],
                'event_ids' => [
                    'clear-duplicate',
                    'possible-duplicate',
                ],
            ],
            [
                'id' => 'missing-issues',
                'title' => 'Issue corrections happen twice',
                'icon' => 'receipt',
                'kicker' => 'Steps 4 and 8 · whole multiples',
                'summary' => 'Correct only complete multiples on eligible interior shifts. An over need not disappear.',
                'body' => [
                    'The current stock-item IssueMultiple is authoritative, including 0 or 1. The configured fallback is used only when that field is null. A multiple of 0 or 1 disables automatic issue additions and reductions. These corrections also require the applicable issue modes to be enabled.',
                    'First pass, before count balancing: the whole continuous period must have no opening break, no D1/D2 finding and no authorised good waste. An active interior shift can correct an exact whole-multiple variance, within 0.005. An issue reduction cannot exceed the amount issued.',
                    'Second pass, after count safeguards and waste: an unblocked active interior shift with no earlier issue correction can add complete multiples from an over, or remove complete multiples from a short. A reduction is capped by complete multiples already issued and never makes issued negative. Any fractional or off-multiple remainder stays visible.',
                    'First and last observed shifts of each continuous period are excluded from both ordinary passes because transactions outside the period may explain the difference. D1 is a separate rule. The label Missing issue captured still describes a proposal until Amend succeeds; arithmetic alone is not delivery evidence.',
                ],
                'flow' => [
                    [
                        'title' => 'Early exact pass',
                        'text' => 'Example: +10 with multiple 5 adds 10 before count balancing, only if the whole-period conditions pass.',
                    ],
                    [
                        'title' => 'Count checks, then waste',
                        'text' => 'Protect shorts and account for authorised waste first. Do not use the same shortage twice.',
                    ],
                    [
                        'title' => 'Final whole-multiple pass',
                        'text' => 'Example: +12 with multiple 5 adds 10, leaving +2. A short uses the equivalent capped reduction.',
                    ],
                ],
                'scenario_ids' => [
                    'missing-issue',
                    'issue-reduction',
                ],
                'event_ids' => [
                    'missing-issue',
                ],
            ],
            [
                'id' => 'balancing',
                'title' => 'The narrow opening-break exception',
                'icon' => 'scale',
                'kicker' => 'Step 5 · repair, then balance',
                'summary' => 'An overstated opening can hide the over that belongs beside the preceding short.',
                'body' => [
                    'Ordinary counted shortages stay on their shifts. The exception requires all three facts: the current original opening is higher than the preceding closing, the immediately preceding repaired variance is short, and the current repaired variance is over. Only the identified pair or connected exception group is eligible for that offset.',
                    'In the example, B originally opens at 40 even though A closed at 35. B looks balanced. Repair B’s opening to 35 first: that exposes an over of 5 beside A’s short of 5. The shared count can then move to 40, leaving both shifts at zero.',
                    'The raw original total is −5; the repaired continuous basis totals zero. Do not claim that these two different bases have the same total. Count balancing preserves the repaired total with fixed ends and unchanged issues and POS. The opening repair is what changes the basis.',
                    'A break larger than the profile’s threshold normally splits count balancing and pins the preceding closing. A zero repaired total or this qualifying opening pair can keep the break connected, but neither overrides a duplicate hold, a missing shift or an unsafe write.',
                ],
                'flow' => [
                    [
                        'title' => 'Original',
                        'text' => 'A: −5. B: 0, but opening 40 disagrees with A’s closing 35.',
                    ],
                    [
                        'title' => 'Repair the basis',
                        'text' => 'B opening 40 → 35 exposes +5. Repaired pair: −5 and +5.',
                    ],
                    [
                        'title' => 'Eligible proposal',
                        'text' => 'A closing 35 → 40; B opening becomes 40. Both zero; final closing 32 stays.',
                    ],
                ],
                'scenario_ids' => [
                    'zero-balance',
                ],
                'event_ids' => [
                    'opening-break',
                    'short-protected',
                ],
            ],
            [
                'id' => 'residual',
                'title' => 'How the count adjustment is chosen',
                'icon' => 'scale',
                'kicker' => 'Step 5 · the running totals',
                'summary' => 'The algorithm calculates a bounded adjustment; it does not guess a new physical count.',
                'body' => [
                    'For each count-balancing segment, active shifts produce a running total P of repaired variance after duplicate and early issue corrections. T is the segment’s final total. Looking from each shift to the end, take the smallest later P when T is positive, otherwise the largest later P. This is the reverse envelope E.',
                    'Clamp E between the lower and upper of 0 and T to obtain Q. The candidate closing adjustment is Q − P. The latest active closing is pinned. If the continuous chain contains an original counted short, count adjustments are zero outside the opening-exception segment.',
                    'Rebuild each opening from the previous proposed closing, carry an adjustment through dormant shifts, then apply the safety checks. Outside the exception, any movement of a counted short beyond its supported duplicate or early issue correction holds the chain; a new short also holds it.',
                    'The larger example below is the same narrow exception: original −61 and +5 become repaired −61 and +60, then proposed −1 and 0. It is not permission to combine an arbitrary −61 with an arbitrary +60. The opening break is the evidence that makes this pair eligible.',
                ],
                'flow' => [
                    [
                        'title' => 'Running totals P',
                        'text' => 'For the small opening example, repaired variances −5, +5 give P = [−5, 0] and T = 0.',
                    ],
                    [
                        'title' => 'Envelope and clamp',
                        'text' => 'Reverse maxima E = [0, 0]. Clamp to the interval [0, 0]: Q = [0, 0].',
                    ],
                    [
                        'title' => 'Closing adjustments',
                        'text' => 'Q − P = [+5, 0]. A closing rises 5; the last closing stays fixed.',
                    ],
                ],
                'scenario_ids' => [
                    'residual-short',
                ],
                'event_ids' => [
                    'residual-short',
                ],
            ],
            [
                'id' => 'no-offset',
                'title' => 'Ordinary shorts stay where counted',
                'icon' => 'shield',
                'kicker' => 'Step 5 · the governing protection',
                'summary' => 'A net zero total does not give permission to erase a short against another shift’s over.',
                'body' => [
                    'If a continuous chain contains a counted shortage, the count-balancing calculation proposes no closing adjustment outside the qualifying opening-exception segment. In the example, A is short 5 and B is over 5 with a perfectly continuous handover. Both differences remain.',
                    'The protection concerns count movements. A supported D1, an eligible issue-multiple correction, or authorised good waste can change the amount that remains on that same shift. Each explanation must be visible separately.',
                    'Two shorts also stay on their own shifts. No offset, held back, and already balanced in PumpIT mean different things: respectively no count correction to make, an unsafe proposal, and a proposal already reflected in the live source.',
                ],
                'flow' => [
                    [
                        'title' => 'Ordinary −5 / +5',
                        'text' => 'No opening mismatch. Same item and area, but there is no qualifying opening exception.',
                    ],
                    [
                        'title' => 'Keep both differences',
                        'text' => 'A remains −5; B remains +5. A zero net must not hide the short.',
                    ],
                    [
                        'title' => 'Investigate the evidence',
                        'text' => 'Review issues, counts and POS on each shift. Do not manufacture a shared count move.',
                    ],
                ],
                'scenario_ids' => [
                    'ordinary-offset',
                    'no-offset',
                ],
                'event_ids' => [
                    'short-protected',
                    'no-offset',
                ],
            ],
            [
                'id' => 'waste',
                'title' => 'Authorised good waste comes first',
                'icon' => 'box',
                'kicker' => 'Step 7 · explain the remaining short',
                'summary' => 'Waste reduces a short only as far as the authorised amount supports. It does not write a count or issue.',
                'body' => [
                    'Agora reads positive good-waste quantity from the source waste line only when it is authorised. It snapshots the quantity, authorisation date and authorising person. Bad waste and unauthorised good waste are not applied by this rule.',
                    'Applied good waste is the smaller of the authorised good waste and the remaining short. Short 8 with authorised 3 leaves short 5; authorised 12 covers only 8 and leaves zero. An over receives no waste adjustment. A held chain can still display this accounting adjustment without being eligible for a PumpIT write.',
                    'Waste is applied before the final issue reduction. This order prevents both waste and a reduced issue from explaining the same units. An authorised waste record anywhere in the continuous period also disables the early exact-issue pass.',
                ],
                'flow' => [
                    [
                        'title' => 'Starting short',
                        'text' => 'The example is short 15 before waste.',
                    ],
                    [
                        'title' => 'Apply authorised 4',
                        'text' => 'Short 15 − waste 4 = short 11. Counts and issues have not changed.',
                    ],
                    [
                        'title' => 'Then reduce one issue multiple',
                        'text' => 'With multiple 10, issued 20 → 10 covers 10 more. Final short: 1, not 5 and not zero.',
                    ],
                ],
                'scenario_ids' => [
                    'waste-cap',
                    'waste-and-issue',
                ],
                'event_ids' => [
                    'good-waste',
                ],
            ],
            [
                'id' => 'holds',
                'title' => 'Safeguards and exceptions',
                'icon' => 'shield',
                'kicker' => 'Step 6 · can this proposal stand?',
                'summary' => 'Read the chain verdict as well as its code. A finding and a blocking condition are different.',
                'body' => [
                    'Missing shifts, unreleased D2, an unsafe negative closing, dormant-shift variance and forbidden movement of a short block normal balancing. Count caps, an issue with no movement (F2), and a low-POS stock-loss pattern (E1) also need attention. On a nil total allowing for potential authorised waste, some cap, F2 and E1 findings can remain without blocking.',
                    'S1 and U1–U4 describe issue patterns; they are not automatic prohibitions. A short active chain is flagged, but the minimum-active-shifts setting alone is not a blanket block. The actual chain verdict controls the write.',
                    'Agora also tests proposed counts against live figures for new forbidden audit results, such as making an over short, increasing a short, moving the latest closing, or breaking the handover. A conflicting count proposal is withdrawn (F3). Review the source and whether a deliberate reset is justified.',
                    'Some held chains can carry a distinct proposal to restore a forbidden earlier hand amendment to the original basis. That is not successful balancing. Duplicate-held, gap-held and shortage-movement-held chains do not get this restoration. Read the action on the row.',
                ],
                'flow' => [
                    [
                        'title' => 'Finding',
                        'text' => 'A code names evidence: a missing issue pattern, an opening break or a cap exceeded.',
                    ],
                    [
                        'title' => 'Eligibility',
                        'text' => 'The full set of findings decides whether the chain can be written. A green total alone cannot decide.',
                    ],
                    [
                        'title' => 'Output',
                        'text' => 'Eligible proposal, explicit safe restoration, or held with a reason. No forced balancing.',
                    ],
                ],
                'scenario_ids' => [
                    'negative-count',
                ],
                'event_ids' => [
                    'no-pos-link',
                    'count-fault',
                    'issue-nowhere',
                    'audit-conflict',
                ],
            ],
            [
                'id' => 'profiles',
                'title' => 'Profiles, exclusions and precision',
                'icon' => 'check',
                'kicker' => 'Controls saved with the preview',
                'summary' => 'The selected item profile makes the limits explicit and reviewable.',
                'body' => [
                    'An item uses its mapped active balancing profile or the active Generic profile. A missing or inactive required profile refuses the preview. The run saves the profile and revision; a changed profile at commit time requires a fresh preview.',
                    'For context, the reviewed Generic profile uses a maximum count amendment of 9,999 units, a relative cap of 100% of on-hand, minimum 2 active shifts, and a keep-break threshold of 3 units, with the incomplete-day cutoff off. These are reviewed settings, not permanent guarantees. Read the profile saved on the actual run.',
                    'Changing a profile changes the limits, not the source counts. A 4-unit opening break normally splits at a threshold of 3 but stays connected at 6; the zero-total and opening-pair exceptions still apply. On a non-nil candidate with no waiver, lowering the absolute correction cap from 10 to 4 makes a proposed 5-unit count change exceed that cap. Neither setting permits an ordinary shortage to move.',
                    'Explicit stock meters and excluded area groups such as Virtual Items are outside this process. Commit also refuses meter rows. Small-number comparisons use 0.0005 for counts and 0.005 for variances; the 3-unit break threshold is a business setting, not a rounding tolerance.',
                ],
                'flow' => [
                    [
                        'title' => 'Select',
                        'text' => 'Counting area and stock item determine the active profile or Generic fallback.',
                    ],
                    [
                        'title' => 'Snapshot',
                        'text' => 'Preview saves the profile revision, options, source bases and findings.',
                    ],
                    [
                        'title' => 'Recheck',
                        'text' => 'If the profile or relevant evidence changes before Amend, preview again.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'source-changed',
                ],
            ],
            [
                'id' => 'workflow',
                'title' => 'Preview is a saved explanation',
                'icon' => 'search',
                'kicker' => 'Review before any write',
                'summary' => 'A preview creates a review record in Agora. It does not amend PumpIT.',
                'body' => [
                    'Choose the site, area and From date; To is six days later or today, whichever comes first. The default period is the last full Monday-to-Sunday week. Check the actual period, profile and holds before reading totals.',
                    'Review Proposals, Exceptions and Duplicate issues together. Expand a chain and its shifts to compare original, current and proposed values, issue changes, applied waste, the opening break and the remaining variance. A single displayed code may be only the highest-priority finding.',
                    'The saved output includes the proposal, evidence, profile revision, authorised waste and selection state. A line needs an actual supported change before it can be written. A target already in PumpIT is not another issue to add.',
                    'Read the exact Amend selection and confirmation. Dependent count/issue corrections must be selected together. Check the resulting per-line written/skipped receipt; a resolved-sounding proposal label is not that receipt.',
                ],
                'flow' => [
                    [
                        'title' => 'Input',
                        'text' => 'Site + area + dates + profile and modes.',
                    ],
                    [
                        'title' => 'Saved output',
                        'text' => 'Original/current/proposed quantities, findings, waste, remaining variance and eligible amendments.',
                    ],
                    [
                        'title' => 'Review decision',
                        'text' => 'Approve the intended selection, resolve evidence first, or prepare a fresh preview. Preview itself writes no PumpIT stock.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'source-changed',
                    'run-closed',
                ],
            ],
            [
                'id' => 'commit-recovery',
                'title' => 'Amend, Reset and Reverse',
                'icon' => 'history',
                'kicker' => 'Separate operations, separate evidence',
                'summary' => 'Amend applies a proposal; Reset restores the original basis; Reverse restores the actual prior state.',
                'body' => [
                    'In live mode, Amend writes selected eligible opening, closing and explicit issue changes, recording the actual prior and new values in the audit ledger. Ordinary Amend does not change original columns or POS. Journal mode records amendments without the live stock writes; read the run’s mode.',
                    'Commit uses one transaction for its accepted writes and ledger. It checks profile revision, predecessor and continuity/removal evidence, missing source rows, waste authorisation, shortage protection, boundary issues, issue multiples, counts, releases and dependent selection. It rechecks the current issue when an issue write is proposed. It is not a new full recomputation of every POS figure.',
                    'Some candidates can be deliberately skipped while others write. At least one write closes the run to a second commit; use a fresh preview for the rest. If all candidates are skipped, nothing is written and the run stays previewed. An SQL failure rolls back accepted writes and their ledger together.',
                    'Reset to original is a separate audited action over eligible rows in the run, not just the Amend checkboxes. It restores original opening and closing, issues when enabled, and POS; it also restores the predecessor’s closing anchor only. It respects duplicate, continuity and other-run safeguards and creates a fresh preview.',
                    'Reverse requires a reason. It restores the exact recorded priors for that run, which may differ from original values. Later count, issue or POS changes can refuse reversal. If later balancing stands on a reset, resolve that later balancing first; use the history to identify the right run.',
                ],
                'flow' => [
                    [
                        'title' => 'Amend',
                        'text' => 'Reviewed proposal → eligible selected live counts/issues + audit of actual priors.',
                    ],
                    [
                        'title' => 'Reset to original',
                        'text' => 'Eligible run scope → original counts, configured issues, POS and predecessor closing → fresh preview.',
                    ],
                    [
                        'title' => 'Reverse',
                        'text' => 'One run’s recorded writes → its actual prior values, only if later changes will not be overwritten.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'source-changed',
                    'run-closed',
                    'audit-conflict',
                ],
            ],
            [
                'id' => 'finance',
                'title' => 'Read the financial result',
                'icon' => 'scale',
                'kicker' => 'Quantity, value and responsibility',
                'summary' => 'Show remaining shorts and overs separately before presenting any net figure.',
                'body' => [
                    'After-variance includes the proposed count and issue corrections and applied authorised good waste. It is still a preview until the write receipt confirms what happened. A chain’s earlier diagnostic net can use a different basis; use the proposed variance total when explaining the end result.',
                    'The short-value measure sums negative proposed quantities at the selling price saved for the run. For example, a remaining short of 5 at R12 gives R60 at selling price. This is not automatically replacement cost, margin loss, cash missing, or an authorised payroll deduction.',
                    'Global comparison is read-only. It looks for nearly opposite quantities within 0.01 in matching units and with a meaningful production, product or area relationship. It does not convert recipe ratios, transfer stock, or automatically net items. Separate removed/returned periods of the same item and area are not offered as a cross-item offset.',
                    'Production and related-stock context are evidence for review; production is not deducted again from an already reconciled variance. A weekly case or staff-charge decision is a separate authorised workflow. Shared-shift names cannot allocate individual blame.',
                ],
                'flow' => [
                    [
                        'title' => 'Quantity',
                        'text' => '5 units remain short after the proposed corrections. State the unit and the source basis.',
                    ],
                    [
                        'title' => 'Valuation',
                        'text' => '5 × saved selling price R12 = R60 of short at selling price. Do not relabel it as cost.',
                    ],
                    [
                        'title' => 'Decision',
                        'text' => 'Verify the actual writes and supporting records before any separate financial or staff action.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'residual-short',
                ],
            ],
            [
                'id' => 'review',
                'title' => 'Explain the result to management',
                'icon' => 'book',
                'kicker' => 'A review that can be checked',
                'summary' => 'Connect each changed number to a rule, a source record and an actual outcome.',
                'body' => [
                    'For each material item, name the site, counting area, continuous period and unit; show original and repaired differences; separate count changes, issue corrections and applied waste; then show the remaining short and over.',
                    'State whether this is a preview or a completed write. Record what actually wrote, what was skipped or held, and why. Match the saved proposal with the receipt and audit priors. A committed run is not evidence that every selected row wrote.',
                    'For an unresolved difference, identify the specific count sheet, issue book, waste authorisation or POS linkage needed and the owner of that investigation. State responsibility only when evidence and the separate authorised process support it.',
                ],
                'flow' => [
                    [
                        'title' => 'Why did it change?',
                        'text' => 'Name opening repair, eligible issue correction, D1 or authorised waste. A total alone cannot explain it.',
                    ],
                    [
                        'title' => 'What is still unresolved?',
                        'text' => 'Keep shorts, overs, holds and skipped writes visible, even when the net is zero.',
                    ],
                    [
                        'title' => 'What happened in the source?',
                        'text' => 'Verify the receipt and audit. A preview label and an actual write are different stages.',
                    ],
                ],
                'scenario_ids' => [],
                'event_ids' => [
                    'short-protected',
                    'good-waste',
                ],
            ],
            [
                'id' => 'visual-summary',
                'title' => 'Follow an item through the chain',
                'icon' => 'link',
                'kicker' => 'Visual summary',
                'summary' => 'Start at the top. Follow the arrows to see what changes, what stays, and what happens next.',
                'body' => [
                    'Choose a case to trace one example item through the relevant decisions. Each uses the same worked quantities as the guide; the assumptions shown with the case matter.',
                    'All quantities are fixed training data in units. A preview is a proposal: only a separate authorised Amend can write eligible selected changes, and its receipt confirms the actual result.',
                ],
                'flow' => [
                ],
                'scenario_ids' => [
                ],
                'event_ids' => [
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function scenarios(): array
    {
        return [
            [
                'id' => 'basic-variance',
                'title' => 'A five-unit short',
                'kind' => 'arithmetic',
                'summary' => 'Four inputs give one signed difference.',
                'explanation' => 'Training dataset: one shift shown in isolation to explain the formula. Stock accounted as sold is 40; POS is 35. No correction is implied.',
                'before' => [
                    ['shift' => 'A', 'open' => 100, 'issued' => 20, 'pos' => 35, 'close' => 80],
                ],
                'prepared' => null,
                'after' => null,
                'checks' => [
                    '35 − (100 + 20 − 80) = −5.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'zero-balance',
                'title' => 'An opening break reveals the offset',
                'kind' => 'opening-exception',
                'summary' => 'Original → repaired basis → eligible proposal. Follow all three stages.',
                'explanation' => 'Training dataset: a complete two-shift period with a supported high opening break and no other hold. A is short 5; B originally balances. Repairing B’s opening reveals an over of 5. Only this identified opening pair can use the shared count correction.',
                'before' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 35],
                    ['shift' => 'B', 'open' => 40, 'issued' => 0, 'pos' => 8, 'close' => 32],
                ],
                'prepared' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 35],
                    ['shift' => 'B', 'open' => 35, 'issued' => 0, 'pos' => 8, 'close' => 32],
                ],
                'after' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 40],
                    ['shift' => 'B', 'open' => 40, 'issued' => 0, 'pos' => 8, 'close' => 32],
                ],
                'checks' => [
                    'Break = B original opening 40 − A closing 35 = +5.',
                    'Original variances: −5 and 0. Repaired: −5 and +5. Proposed: 0 and 0.',
                    'The repaired total is zero before and after count balancing. The raw original total is −5.',
                ],
                'steps' => [
                    [
                        'title' => 'Repair first',
                        'text' => 'B opening 40 → 35 reveals the over.',
                    ],
                    [
                        'title' => 'Calculate the permitted offset',
                        'text' => 'A closing rises by 5; B opening follows the new closing.',
                    ],
                    [
                        'title' => 'Keep the last count',
                        'text' => 'B closing stays 32; issues and POS never move.',
                    ],
                ],
            ],
            [
                'id' => 'residual-short',
                'title' => 'The opening exception can leave a short',
                'kind' => 'opening-exception',
                'summary' => 'A larger break explains 60 of a 61-unit short; one unit remains.',
                'explanation' => 'Training dataset: complete supported opening-exception pair, no other hold, final closing fixed. B’s original opening 258 is 55 above A’s closing 203. Repair exposes +60; the eligible shared count move of 60 leaves −1 on A. Do not add a missing issue of 60 to B.',
                'before' => [
                    ['shift' => 'A', 'open' => 288, 'issued' => 0, 'pos' => 24, 'close' => 203],
                    ['shift' => 'B', 'open' => 258, 'issued' => 0, 'pos' => 27, 'close' => 236],
                ],
                'prepared' => [
                    ['shift' => 'A', 'open' => 288, 'issued' => 0, 'pos' => 24, 'close' => 203],
                    ['shift' => 'B', 'open' => 203, 'issued' => 0, 'pos' => 27, 'close' => 236],
                ],
                'after' => [
                    ['shift' => 'A', 'open' => 288, 'issued' => 0, 'pos' => 24, 'close' => 263],
                    ['shift' => 'B', 'open' => 263, 'issued' => 0, 'pos' => 27, 'close' => 236],
                ],
                'checks' => [
                    'Original: −61 + 5 = −56. Repaired: −61 + 60 = −1. Proposed: −1 + 0 = −1.',
                    'First opening 288, last closing 236, issues and POS stay fixed.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'ordinary-offset',
                'title' => 'A short and over that must stay separate',
                'kind' => 'unchanged',
                'summary' => '−5 and +5 remain −5 and +5, even though the net is zero.',
                'explanation' => 'Training dataset: a continuous handover with no opening break, no waste and issue multiple 1, so there is no eligible issue correction. A’s counted short cannot be erased by B’s ordinary over. No count amendment is proposed.',
                'before' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 0, 'close' => 45],
                    ['shift' => 'B', 'open' => 45, 'issued' => 0, 'pos' => 0, 'close' => 50],
                ],
                'prepared' => null,
                'after' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 0, 'close' => 45],
                    ['shift' => 'B', 'open' => 45, 'issued' => 0, 'pos' => 0, 'close' => 50],
                ],
                'checks' => [
                    'B opening 45 already equals A closing 45. There is no opening-break exception.',
                    'Net zero does not mean every shift balances.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'no-offset',
                'title' => 'Two shorts stay on their own shifts',
                'kind' => 'unchanged',
                'summary' => '−5 and −3 stay unchanged; the total remains −8.',
                'explanation' => 'Training dataset: complete chain, no eligible issue or waste correction and no qualifying opening exception. Count changes cannot move either ordinary short.',
                'before' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 35],
                    ['shift' => 'B', 'open' => 35, 'issued' => 0, 'pos' => 8, 'close' => 24],
                ],
                'prepared' => null,
                'after' => [
                    ['shift' => 'A', 'open' => 50, 'issued' => 0, 'pos' => 10, 'close' => 35],
                    ['shift' => 'B', 'open' => 35, 'issued' => 0, 'pos' => 8, 'close' => 24],
                ],
                'checks' => [
                    'A remains −5; B remains −3. No balancing write is needed merely to create a write.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'missing-issue',
                'title' => 'An interior over leaves a remainder',
                'kind' => 'issue-correction',
                'summary' => 'With issue multiple 5, +12 adds 10 to issued and leaves +2.',
                'explanation' => 'Training dataset: A and C are the protected boundary shifts of a complete unblocked period. B is active and interior. Multiple 5 is authoritative; issue modes are on. +12 is not an exact multiple, so the final pass adds two multiples, after count checks. Counts and POS stay fixed.',
                'before' => [
                    ['shift' => 'A · first', 'open' => 100, 'issued' => 0, 'pos' => 10, 'close' => 90],
                    ['shift' => 'B · interior', 'open' => 90, 'issued' => 20, 'pos' => 32, 'close' => 90],
                    ['shift' => 'C · last', 'open' => 90, 'issued' => 0, 'pos' => 10, 'close' => 80],
                ],
                'prepared' => null,
                'after' => [
                    ['shift' => 'A · first', 'open' => 100, 'issued' => 0, 'pos' => 10, 'close' => 90],
                    ['shift' => 'B · interior', 'open' => 90, 'issued' => 30, 'pos' => 32, 'close' => 90],
                    ['shift' => 'C · last', 'open' => 90, 'issued' => 0, 'pos' => 10, 'close' => 80],
                ],
                'checks' => [
                    'B: 32 − (90 + 20 − 90) = +12. floor(12 ÷ 5) × 5 = 10.',
                    'With issued 30, B becomes +2. Neither boundary issue changes.',
                ],
                'steps' => [],
                'multiple' => 5,
            ],
            [
                'id' => 'issue-reduction',
                'title' => 'Reduce only complete issued multiples',
                'kind' => 'issue-correction',
                'summary' => 'With issue multiple 10, a short of 15 permits a reduction of 10, leaving short 5.',
                'explanation' => 'Training dataset: three complete shifts on one day, with no duplicate, count break, waste or other hold. B is interior. Its issue 20 can be reduced by one multiple of 10 after count checks; A and C are protected boundaries. No shortage is moved into another shift.',
                'before' => [
                    ['shift' => 'A · first', 'open' => 60, 'issued' => 0, 'pos' => 10, 'close' => 50],
                    ['shift' => 'B · interior', 'open' => 50, 'issued' => 20, 'pos' => 10, 'close' => 45],
                    ['shift' => 'C · last', 'open' => 45, 'issued' => 0, 'pos' => 10, 'close' => 35],
                ],
                'prepared' => null,
                'after' => [
                    ['shift' => 'A · first', 'open' => 60, 'issued' => 0, 'pos' => 10, 'close' => 50],
                    ['shift' => 'B · interior', 'open' => 50, 'issued' => 10, 'pos' => 10, 'close' => 45],
                    ['shift' => 'C · last', 'open' => 45, 'issued' => 0, 'pos' => 10, 'close' => 35],
                ],
                'checks' => [
                    'B before: 10 − (50 + 20 − 45) = −15. After issue 10: −5.',
                    'The reduction never exceeds complete multiples already issued.',
                ],
                'steps' => [],
                'multiple' => 10,
            ],
            [
                'id' => 'clear-duplicate',
                'title' => 'A clear duplicate issue',
                'kind' => 'issue-correction',
                'summary' => 'B repeats an issue of 12 that the evidence shows did not arrive.',
                'explanation' => 'Training dataset: assume the full D1 evidence is confirmed: shelf goods, an unambiguous same-day neighbouring pair, A’s issue arrived, B is short exactly its issue, and no conflicting live amendment or hold. These quantities alone are not the complete duplicate test. B’s issue becomes zero; counts and POS stay unchanged.',
                'before' => [
                    ['shift' => 'A', 'open' => 20, 'issued' => 12, 'pos' => 4, 'close' => 28],
                    ['shift' => 'B', 'open' => 28, 'issued' => 12, 'pos' => 3, 'close' => 25],
                ],
                'prepared' => null,
                'after' => [
                    ['shift' => 'A', 'open' => 20, 'issued' => 12, 'pos' => 4, 'close' => 28],
                    ['shift' => 'B', 'open' => 28, 'issued' => 0, 'pos' => 3, 'close' => 25],
                ],
                'checks' => [
                    'A balances with its issue: 4 − (20 + 12 − 28) = 0.',
                    'B before: −12. B after issued zero: 3 − (28 − 25) = 0.',
                    'D1 uses its own evidence rule; the ordinary boundary issue rule is not its test.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'possible-duplicate',
                'title' => 'A possible duplicate stays held',
                'kind' => 'held',
                'summary' => 'Both matching shifts are short 12. Neither proves which issue arrived.',
                'explanation' => 'Training dataset: neighbouring matching issues, both short by 12, with an unreleased D2. No worked proposal is offered. Check the count, issue and delivery evidence; a release records a decision, not an issue correction.',
                'before' => [
                    ['shift' => 'A', 'open' => 20, 'issued' => 12, 'pos' => 4, 'close' => 16],
                    ['shift' => 'B', 'open' => 16, 'issued' => 12, 'pos' => 3, 'close' => 13],
                ],
                'prepared' => null,
                'after' => null,
                'checks' => [
                    'A = −12; B = −12. Matching issues are not independent evidence of arrival.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'negative-count',
                'title' => 'A negative count is not usable evidence',
                'kind' => 'held',
                'summary' => 'Closing −1 is unsafe, regardless of the arithmetic result.',
                'explanation' => 'Training dataset: a recorded closing below zero. Verify the count and unit, resolve the source fault through the appropriate process, then preview again. No invented proposal is offered.',
                'before' => [
                    ['shift' => 'A', 'open' => 10, 'issued' => 0, 'pos' => 2, 'close' => -1],
                ],
                'prepared' => null,
                'after' => null,
                'checks' => [
                    '2 − (10 − (−1)) = −9. That result does not make closing −1 valid.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'removal-return',
                'title' => 'Removal and return are separate periods',
                'kind' => 'separate-periods',
                'summary' => 'Period 1 is short 40; period 2 is over 40. They do not cancel.',
                'explanation' => 'Training dataset: a valid recorded removal at the end of period 1, then a return with its own opening anchor. Issue multiple 1 and no other correction. These are separate review results, even for the same item and area.',
                'before' => [
                    ['shift' => 'Before removal', 'open' => 100, 'issued' => 0, 'pos' => 10, 'close' => 50, 'period' => 1],
                    ['shift' => 'After return', 'open' => 100, 'issued' => 0, 'pos' => 50, 'close' => 90, 'period' => 2],
                ],
                'prepared' => null,
                'after' => [
                    ['shift' => 'Before removal', 'open' => 100, 'issued' => 0, 'pos' => 10, 'close' => 50, 'period' => 1],
                    ['shift' => 'After return', 'open' => 100, 'issued' => 0, 'pos' => 50, 'close' => 90, 'period' => 2],
                ],
                'checks' => [
                    'Period 1: 10 − (100 − 50) = −40. Period 2: 50 − (100 − 90) = +40.',
                    'The period 2 opening must not be replaced by the period 1 closing.',
                ],
                'steps' => [],
                'separate_periods' => true,
            ],
            [
                'id' => 'waste-cap',
                'title' => 'Waste stops at the remaining short',
                'kind' => 'waste',
                'summary' => 'Authorised good waste 12 can cover only the short of 8.',
                'explanation' => 'Training dataset: one short shift with authorised good waste 12. Apply 8, leaving zero; the unused 4 creates no over and is not an issue or count change.',
                'before' => [
                    ['shift' => 'A', 'open' => 30, 'issued' => 0, 'pos' => 12, 'close' => 10, 'waste' => 0],
                ],
                'prepared' => null,
                'after' => [
                    ['shift' => 'A', 'open' => 30, 'issued' => 0, 'pos' => 12, 'close' => 10, 'waste' => 8],
                ],
                'checks' => [
                    'Raw variance = 12 − (30 − 10) = −8. Applied waste = min(12, 8) = 8.',
                    'Final explained variance = −8 + 8 = 0. All four source quantities stay unchanged.',
                ],
                'steps' => [],
            ],
            [
                'id' => 'waste-and-issue',
                'title' => 'Waste, then an issue reduction',
                'kind' => 'waste-and-issue',
                'summary' => 'Short 15 → waste-adjusted short 11 → final short 1.',
                'explanation' => 'Training dataset: complete unblocked period, B interior, authoritative multiple 10, issued 20 and authorised good waste 4. Waste disables the early exact-issue pass. Apply waste first, then remove one full issued multiple. No count or POS changes.',
                'before' => [
                    ['shift' => 'A · first', 'open' => 60, 'issued' => 0, 'pos' => 10, 'close' => 50, 'waste' => 0],
                    ['shift' => 'B · interior', 'open' => 50, 'issued' => 20, 'pos' => 10, 'close' => 45, 'waste' => 0],
                    ['shift' => 'C · last', 'open' => 45, 'issued' => 0, 'pos' => 10, 'close' => 35, 'waste' => 0],
                ],
                'prepared' => [
                    ['shift' => 'A · first', 'open' => 60, 'issued' => 0, 'pos' => 10, 'close' => 50, 'waste' => 0],
                    ['shift' => 'B · interior', 'open' => 50, 'issued' => 20, 'pos' => 10, 'close' => 45, 'waste' => 4],
                    ['shift' => 'C · last', 'open' => 45, 'issued' => 0, 'pos' => 10, 'close' => 35, 'waste' => 0],
                ],
                'after' => [
                    ['shift' => 'A · first', 'open' => 60, 'issued' => 0, 'pos' => 10, 'close' => 50, 'waste' => 0],
                    ['shift' => 'B · interior', 'open' => 50, 'issued' => 10, 'pos' => 10, 'close' => 45, 'waste' => 4],
                    ['shift' => 'C · last', 'open' => 45, 'issued' => 0, 'pos' => 10, 'close' => 35, 'waste' => 0],
                ],
                'checks' => [
                    'B raw −15; add authorised waste 4 to reach −11.',
                    'Remove issued 10: raw −5 plus applied waste 4 = −1.',
                    'The same 4 units are never covered again by the issue correction.',
                ],
                'steps' => [],
                'prepared_label' => 'After authorised waste',
                'multiple' => 10,
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
                'write_effect' => 'An eligible proposal repairs the opening. The reviewed threshold is 3 units. Larger breaks normally pin the prior closing, except a nil repaired chain or the qualifying opening pair; other safeguards still apply.',
            ],
            [
                'id' => 'residual-short', 'title' => 'Balancing leaves a short', 'code' => 'Short remains', 'tone' => 'warn',
                'meaning' => 'The supported corrections leave a negative proposed variance. Ordinary counted shorts are preserved; only the identified opening-exception pair can use a shared count offset.',
                'example' => 'In the opening-exception example, repaired −61 and +60 become −1 and zero. The high opening break is required evidence.',
                'checks' => ['Compare the total before and after.', 'Check issues, count sheets and POS linkage.', 'Distinguish a remaining difference from an established cause or responsibility.'],
                'next' => ['Review the surviving short on its shift.', 'Record the evidence needed for the remaining difference.'],
                'location' => 'Run → Proposals → chain outcome and proposed variance',
                'lesson_id' => 'residual',
                'write_effect' => 'Eligible corrections can leave a short. Count balancing preserves the repaired total and cannot freely move ordinary shortages.',
            ],
            [
                'id' => 'no-offset', 'title' => 'Nothing to balance', 'code' => 'No offset', 'tone' => 'neutral',
                'meaning' => 'There is no over to set against the shorts, so an unblocked chain may have no amendment to make.',
                'example' => 'Two shifts short 5 and 3 remain short 8 together, with unchanged counts.',
                'checks' => ['Check whether any correction is actually eligible; an ordinary over cannot cancel a counted short.', 'Read the exact verdict: nothing to balance differs from already balanced in PumpIT.'],
                'next' => ['Investigate the remaining shorts.', 'Leave unchanged quantities unchanged; no count move is needed just to create a write.'],
                'location' => 'Run → Proposals → chain outcome',
                'lesson_id' => 'no-offset', 'write_effect' => 'No balancing write is proposed in this case.',
            ],
            [
                'id' => 'missing-issue', 'title' => 'Missing issue proposed', 'code' => 'U1–U4 / S1', 'tone' => 'warn',
                'meaning' => 'Recorded stock does not account for what was sold or remains. U1 concerns sales beyond available stock, U2 an excessive closing, U3 sales with no recorded issues, U4 a net over, and S1 a whole-pack over pattern.',
                'example' => 'An eligible interior shift remains over 12 with issue multiple 5. Issued 20 becomes 30; an over of 2 remains.',
                'checks' => ['Read the issue proposal separately from count changes.', 'Check the issue book, transfer or delivery evidence.', 'Confirm there is no hold and whether the issue is already in PumpIT.'],
                'next' => ['Review the shift receiving the proposed issue.', 'After an authorised Amend, verify the result and the recorded issue prior.'],
                'location' => 'Run → Proposals → Missing issue captured; Run → Exceptions → U1–U4 or S1',
                'lesson_id' => 'missing-issues',
                'write_effect' => 'On an eligible active interior shift, whole issue multiples can be added or reduced. Boundaries and multiples 0 or 1 are protected. The finding remains as evidence.',
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
                'meaning' => 'When the optional incomplete-day cutoff is enabled, coverage checks exclude the first incomplete day and later days. The reviewed Generic profile has it off. Required-shift gap checks operate separately.',
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
            [
                'id' => 'short-protected',
                'title' => 'An ordinary short must stay on its shift',
                'code' => 'Short movement hold',
                'tone' => 'serious',
                'meaning' => 'Count balancing must preserve a counted short outside the narrow opening-break exception, allowing for supported issue corrections.',
                'example' => 'A continuous −5 / +5 pair stays −5 / +5. A zero net does not erase the short.',
                'checks' => [
                    'Check the original variance and opening break.',
                    'Separate issue and waste explanations from count moves.',
                ],
                'next' => [
                    'Inspect the exact held reason.',
                    'Review source evidence and prepare a fresh preview after correction.',
                ],
                'location' => 'Run → Proposals → item chain and held reason',
                'lesson_id' => 'no-offset',
                'write_effect' => 'No forbidden shift of a shortage is written. Commit rechecks shortage protection.',
            ],
            [
                'id' => 'missing-shift',
                'title' => 'A required shift is missing',
                'code' => 'MISSING_SHIFT',
                'tone' => 'crit',
                'meaning' => 'Configured shifts, later area activity or explicit completion establish that a count should exist, but it does not.',
                'example' => 'Day and Night exist in a three-shift area, but Afternoon is missing.',
                'checks' => [
                    'Check enabled area shifts and completion records.',
                    'Verify any recorded removal against its last actual count.',
                ],
                'next' => [
                    'Resolve the missing count or supported removal evidence.',
                    'Preview the affected period again.',
                ],
                'location' => 'Run → Proposals → held chain; Counting areas → completions and removals',
                'lesson_id' => 'gaps',
                'write_effect' => 'The affected continuous chain is held. A missing item in an explicitly completed area can hold the area’s chains.',
            ],
            [
                'id' => 'good-waste',
                'title' => 'Authorised good waste explains a short',
                'code' => 'Applied good waste',
                'tone' => 'neutral',
                'meaning' => 'Positive authorised good waste covers only the remaining shortage, before the final whole-multiple issue reduction.',
                'example' => 'Short 15, authorised waste 4 and eligible issue reduction 10 leave short 1.',
                'checks' => [
                    'Check the authorisation, quantity, date and person.',
                    'Separate raw stock variance from the waste-adjusted result.',
                ],
                'next' => [
                    'Read applied waste on the saved proposal.',
                    'Refresh the preview if authorisation has changed.',
                ],
                'location' => 'Run → Proposals → item chain / shift detail',
                'lesson_id' => 'waste',
                'write_effect' => 'An accounting adjustment only: no count, issue or POS write for the waste. A changed authorisation can refuse commit.',
            ],
        ];
    }
}
