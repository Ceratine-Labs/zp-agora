# Stock recon — presentation guide (20 minutes)

For presenting stock recon to Zululand Petroleum's management and finance.
Show it from the in-app tutorial; each step below links straight to its chapter.

Tutorial: https://agora.ceratine.com/app/stock-recon/tutorial
(needs `stockrecon.runs.view`; `?lesson=<id>` opens a chapter)

## The one sentence to land

**Agora only corrects a difference it can explain, and an ordinary short stays visible.**

## Before you start

- Sign in as a user who can open both the tutorial and Stock recon.
- Open a real saved run for one site, last full week. Know its site, area and
  week before the meeting.
- Keep the tutorial in one tab and the run in another.

## Run sheet

| Min | Show | Chapter (`?lesson=`) | Point to make |
|---|---|---|---|
| 0–2 | The ten-step sequence | `purpose` | Every correction has a step, and the order stops a short being explained twice. |
| 2–4 | The formula | `figures` | Variance = POS − (Opening + Issued − Closing). Worked example: 35 − (100 + 20 − 80) = −5, a 5-unit short. |
| 4–6 | Chains and gaps | `chains`, `gaps` | One site + area + item + unbroken period. A missing shift holds the chain. Agora never invents a count. |
| 6–9 | Shorts stay put | `no-offset` | A −5 and a +5 with a clean handover stay −5 and +5. A net of zero is not a balanced chain. |
| 9–12 | The one exception | `balancing` | Opening break: B opens at 40 though A closed at 35. Repair B to 35, which exposes +5 beside A's −5, then both go to 0. Only that pair qualifies. |
| 12–14 | Duplicates | `duplicates` | D1 (clear) is taken out and the issue set to 0. D2 (possible) holds the chain until someone releases it with evidence. |
| 14–16 | Issues and waste | `missing-issues`, `waste` | Only whole issue multiples, never on the first or last shift. Example: +12 with multiple 5 adds 10 and leaves +2. Authorised good waste is applied up to the short and no further. |
| 16–18 | Preview, then Amend | `workflow`, `commit-recovery` | Preview writes nothing to PumpIT. Amend writes the selected lines and records the prior values. Reverse puts those exact priors back. |
| 18–20 | Reading the money | `finance`, then the live run | Short value = remaining short × saved selling price (5 × R12 = R60). That is not cost, and not proof of staff liability. |

## Likely questions

- **"Is it just hiding our shorts?"** No. Ordinary shorts are protected
  (`no-offset`). Only an opening break, D1, a whole issue multiple or
  authorised waste can reduce one, and each is shown separately.
- **"Can we undo it?"** Yes. Reverse restores the exact values Amend replaced.
  It refuses if PumpIT has changed since, so a later edit is never overwritten.
- **"Why is this chain held?"** Read the code badge. Every code opens a plain
  explanation (MISSING_SHIFT, D2, F1, F3 and the rest), and the tutorial's event
  index lists them all.
- **"Who is responsible for the short?"** The recon shows a stock difference.
  A name on a shared shift is not proof. Staff charges go through the separate
  approved process.

## Don't

- Present a net figure without the shorts and overs beside it.
- Call a preview "balanced". Only the Amend receipt says what was written.
- Promise that every week will balance. Some chains stay held, by design.
