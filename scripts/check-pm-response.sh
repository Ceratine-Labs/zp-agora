#!/usr/bin/env bash
#
# Validate pm_response.md before it is committed.
#
# That file is the only way a GitHub agent's work reaches the project manager,
# and the replay happens days later — by which time a missing `minutes` or a
# duplicated entry number is someone else's puzzle. Catch it here, where the
# person who wrote the entry is still holding the context.
#
# Needs no database and no network.
#
#   scripts/check-pm-response.sh
#
set -uo pipefail
cd "$(dirname "$0")/.."

[ -f pm_response.md ] || { echo "check-pm-response: no pm_response.md — nothing to check."; exit 0; }

python3 - <<'PY'
import re, sys, pathlib

try:
    import yaml
except ImportError:
    print("check-pm-response: pyyaml is not installed; skipping (pip install pyyaml).")
    sys.exit(0)

text = pathlib.Path('pm_response.md').read_text()
blocks = re.findall(r'```yaml\n(.*?)```', text, re.S)

fail = []
def bad(n, msg):
    fail.append(f"  entry {n}: {msg}")

if not blocks:
    print("check-pm-response: no entries yet.")
    sys.exit(0)

seen = []
real = 0

for raw in blocks:
    try:
        e = yaml.safe_load(raw)
    except yaml.YAMLError as exc:
        fail.append(f"  a block does not parse as YAML: {str(exc).splitlines()[0]}")
        continue

    if not isinstance(e, dict):
        fail.append("  a block is not a mapping")
        continue

    n = e.get('entry', '?')

    # entry 0 is the shipped example; it is allowed to sit there until the
    # first real entry replaces it.
    if n == 0:
        continue
    real += 1

    if not isinstance(n, int):
        bad(n, "`entry` must be an integer")
    elif n in seen:
        bad(n, "duplicate entry number — they are never reused")
    else:
        seen.append(n)

    for field in ('kind', 'title', 'type', 'priority', 'status', 'covers', 'minutes', 'commits', 'files'):
        if field not in e or e[field] is None:
            bad(n, f"missing `{field}`")

    if e.get('type') not in (None, 'feature', 'bug', 'improvement', 'task', 'investigation', 'subtask'):
        bad(n, f"`type: {e['type']}` is not one the project manager accepts")
    if e.get('priority') not in (None, 'low', 'medium', 'high', 'critical'):
        bad(n, f"`priority: {e['priority']}` is not one of low/medium/high/critical")
    if e.get('status') not in (None, 'done', 'in_progress', 'blocked'):
        bad(n, f"`status: {e['status']}` is not one of done/in_progress/blocked")

    minutes = e.get('minutes')
    if isinstance(minutes, int) and minutes <= 0:
        bad(n, "`minutes` must be greater than zero — a done task is refused without a time entry")

    if e.get('status') == 'done':
        if not e.get('outcome'):
            bad(n, "`status: done` needs an `outcome` — the project manager refuses one without it")
        qa = e.get('qa')
        if e.get('type') in ('feature', 'bug', 'improvement', 'task'):
            if not isinstance(qa, dict):
                bad(n, f"`type: {e.get('type')}` needs a `qa` block before it can be done")
            else:
                if qa.get('status') not in ('passed', 'failed', 'blocked', 'pending'):
                    bad(n, "`qa.status` must be passed/failed/blocked/pending")
                if not qa.get('report'):
                    bad(n, "`qa.report` is empty — it must carry the commands and their real output")
                if 'verified_without_database' not in qa:
                    bad(n, "`qa.verified_without_database` must be stated either way")

    if e.get('status') == 'blocked' and not e.get('blocked_by'):
        bad(n, "`status: blocked` needs `blocked_by` saying what stopped you")

    if 'pm_task_id' in e and e['pm_task_id']:
        bad(n, "`pm_task_id` is set — leave it out unless Ryan gave you a real id")

if seen and sorted(seen) != list(range(min(seen), min(seen) + len(seen))):
    fail.append(f"  entry numbers are not sequential: {sorted(seen)}")

if fail:
    print("check-pm-response: pm_response.md will not replay cleanly.")
    print("\n".join(fail))
    sys.exit(1)

print(f"check-pm-response: {real} entr{'y' if real == 1 else 'ies'} OK.")
PY
