# pm_response — what was actually done

The write-back channel. An agent working from GitHub has no project-manager
access, so it records what it did **here**, and a later session with a token
replays these entries into the project manager as tasks, time entries, QA
entries, outcomes, comments, decisions and follow-ups.

**Append only.** Never edit or delete an entry someone has already written, and
never renumber. If you got something wrong, add a new entry that corrects it.

**One entry per unit of work**, written when that work is finished — not a
running diary. Each is a fenced ```yaml block, because that is what the replay
parses. Everything between the blocks is for humans and is ignored.

The contract is in `pm_tasks.md` → *Playing back what you did*. Read it before
your first entry; the fields are not optional and a missing one stops the
replay.

---

## Entries

<!-- Append below. Newest last. Keep the entry numbers sequential. -->

```yaml
# ─── EXAMPLE. Delete this block when you write your first real one. ─────────
entry: 0
kind: task
title: "Component library v1: chip, delta and note"
type: feature            # feature | bug | improvement | task | investigation
priority: medium         # low | medium | high | critical
status: done             # done | in_progress | blocked
covers: [1]              # task numbers from pm_tasks.md → The work
minutes: 95              # honest wall-clock; the PM refuses a done task without time
started: 2026-09-04
finished: 2026-09-04

outcome: |
  Built <x-chip>, <x-delta> and <x-note> in resources/views/components, each
  taking colour from the tokens in _tokens.scss with no hex of its own, and
  each rendering in both themes.

  <x-delta> reads flat below 0.05 and shows no arrow, matching
  Format::delta() — the two must agree and now do.

  Added all three to the gallery at resources/views/dev/styleguide.blade.php
  and a row each to docs/components.md.

qa:
  title: "Rendered in both themes at desktop and 375px"
  status: passed          # passed | failed | blocked | pending
  verified_without_database: true
  report: |
    $ composer check-fast
    check-migrations: 2 migration(s) OK.
    check-procs: 2 procedure(s) OK.
    {"tool":"pint","result":"passed"}

    $ npm run build
    ✓ built in 3.41s

    $ php artisan view:cache
    INFO  Blade templates cached successfully.

    Not verified against a database: no SQL Server in this environment, so
    the suite was not run. Nothing here queries.

commits:
  - sha: abc1234
    message: "Component library: chip, delta and note"

files:
  - path: resources/views/components/chip.blade.php
    why: "New. Five tones, from the tokens."
  - path: docs/components.md
    why: "A row per component — one that is not listed does not exist."

comments:
  - internal: true
    body: |
      <x-delta> takes `invert` so the caller decides which direction is good.
      A variance where lower is better would otherwise render green for a
      number that is bad.

decisions: []             # only for a real architectural choice; see the contract

follow_ups:
  - title: "delta and Format::delta() should share their threshold"
    note: |
      Both hard-code 0.05 independently. Not blocking, and not mine to
      restructure without Ryan.

blocked_by: null          # a sentence, when status is blocked
```
