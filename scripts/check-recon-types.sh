#!/usr/bin/env bash
#
# Every area's bank statement Type must be reachable from the drill.
#
# RCN_BankStatementLinesPumpIT.Type carries the customer's vocabulary, and it
# is NOT always the name Agora gives the area: usp_Recon_PreviewCashBags
# filters on 'CashDeposit' while the area is called 'CashBags'. The other four
# areas use the same word for both, which is why `l.Type = @ReconArea` in
# usp_Recon_DrillBank looked right for a year.
#
# It was not a cosmetic bug. usp_Recon_Commit re-reads the bank side through
# that drill, so CashBags found no lines and every batch was skipped as "no
# longer on the statement". Five runs on the customer's instance, all stuck at
# `previewed`, not one committed row — and nothing anywhere said why.
#
# So: pull the Type each preview filters on, and refuse if the drill has no
# way to produce it. Prose in a comment would not have caught this.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
checked=0
drill="Modules/Recon/Database/Procedures/usp_Recon_DrillBank.sql"

if [ ! -f "$drill" ]; then
    echo "check-recon-types: $drill is missing — has it been renamed?" >&2
    exit 1
fi

for preview in Modules/Recon/Database/Procedures/usp_Recon_Preview*.sql; do
    [ -e "$preview" ] || continue

    area=$(basename "$preview" .sql | sed 's/^usp_Recon_Preview//')

    # The Type this preview reads the bank statement with. A preview that does
    # not filter on Type at all has nothing to disagree about.
    type=$(grep -ho "l\.Type = '[A-Za-z]*'" "$preview" | sed "s/.*'\(.*\)'/\1/" | sort -u | head -1)
    [ -z "$type" ] && continue

    checked=$((checked + 1))

    # Either the drill maps the area to it by name, or the area IS the type and
    # the drill's fallback covers it.
    if [ "$type" = "$area" ]; then
        continue
    fi

    # COMMENTS STRIPPED FIRST. The first cut of this check grepped the whole
    # file and passed against a deliberately reintroduced bug, because the
    # docblock explaining the mapping contains the very string it was looking
    # for. A guard that its own documentation satisfies is not a guard.
    if ! sed 's|--.*$||' "$drill" | perl -0777 -pe 's{/\*.*?\*/}{}gs' | grep -q "'${type}'"; then
        printf "  %s filters the statement on Type = '%s', but %s cannot produce it.\n" \
            "$(basename "$preview")" "$type" "$(basename "$drill")"
        printf "     The drill would return no bank lines for this area — and the commit re-reads through it.\n"
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "check-recon-types: FAILED — an area whose drill finds nothing cannot be reconciled at all." >&2
    exit 1
fi

echo "check-recon-types: ${checked} area(s) checked, every statement Type reachable from the drill."
