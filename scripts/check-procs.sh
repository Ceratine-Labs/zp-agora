#!/usr/bin/env bash
#
# Every stored procedure is deployed by a migration, and says so.
#
# A .sql file that nothing deploys is the worst kind of dead code here: it reads
# like the rule the system follows, and the database has never seen it.

set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
say() { printf '  %s\n' "$1"; fail=1; }

procs=$(find Modules -path '*/Database/Procedures/*.sql' 2>/dev/null | sort)

if [ -z "$procs" ]; then
    echo "check-procs: no procedures yet."
    exit 0
fi

for file in $procs; do
    module=$(echo "$file" | cut -d/ -f2)

    # CREATE OR ALTER, so re-running a migration is safe and a diff shows the
    # change to the body rather than a drop and recreate.
    if ! grep -qiE 'CREATE +OR +ALTER +PROCEDURE' "$file"; then
        say "$file: not a CREATE OR ALTER PROCEDURE — re-deploying it would fail."
    fi

    # Schema-qualified, and the schema is agora.
    if ! grep -qiE 'CREATE +OR +ALTER +PROCEDURE +\[?agora\]?\.' "$file"; then
        say "$file: procedure is not qualified as agora.<name>."
    fi

    # One per file.
    count=$(grep -ciE 'CREATE +OR +ALTER +PROCEDURE' "$file")
    if [ "$count" -gt 1 ]; then
        say "$file: $count procedures in one file. One per file — T-SQL needs each in its own batch."
    fi

    # Something in this module must actually deploy it — either by extending
    # ProcedureMigration, which is the normal way, or by globbing the directory
    # itself. The rule is that the file gets deployed; it is not a rule about
    # how. Testing for one particular implementation string is what broke this
    # check the day the base class was introduced.
    if ! grep -rqE "ProcedureMigration|Procedures/\*\.sql|Procedures'" "Modules/$module/Database/Migrations" 2>/dev/null; then
        say "$file: no migration in Modules/$module deploys the Procedures directory."
    fi

    # A writer that never opens a transaction is a rule with no atomicity.
    #
    # `INSERT INTO @Something` is not a write — it fills a table variable, which
    # is how a read-only reporting procedure stages its two sides before it
    # compares them. Counting those as writes flagged all five recon previews,
    # which touch nothing, and the fix for that is not to make them declare a
    # transaction they have no use for.
    if grep -qiE 'INSERT +INTO +(\[?agora|\[?dbo|[A-Za-z#])|UPDATE +\[?agora|DELETE +FROM' "$file"; then
        if ! grep -qiE 'XACT_ABORT' "$file"; then
            say "$file: writes but does not SET XACT_ABORT ON."
        fi
    fi
done

if [ "$fail" -eq 0 ]; then
    echo "check-procs: $(echo "$procs" | wc -l) procedure(s) OK."
fi

exit "$fail"
