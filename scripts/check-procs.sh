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

if [ "$#" -gt 0 ]; then
    procs=("$@")
else
    mapfile -t procs < <(find Modules -path '*/Database/Procedures/*.sql' 2>/dev/null | sort)
fi

if [ "${#procs[@]}" -eq 0 ]; then
    echo "check-procs: no procedures yet."
    exit 0
fi

for file in "${procs[@]}"; do
    if [[ ! "$file" =~ ^Modules/[^/]+/Database/Procedures/[^/]+\.sql$ ]] || [ ! -f "$file" ]; then
        say "$file: expected an existing module procedure file relative to the repository."
        continue
    fi
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

    # A procedure that declares @FiltersJson must actually read the shape the
    # grid sends.
    #
    # GridQuery::filtersJson() sends a JSON ARRAY of {column, type, op, value}
    # objects. The column name is INSIDE each element at $.column — it is NOT
    # the OPENJSON key, which over an array is the index 0, 1, 2. A procedure
    # that reads it the other way compiles, runs, returns rows, and filters
    # NOTHING: every variable stays NULL and every predicate short-circuits to
    # true. That is how agora.usp_Core_GridUsers shipped with header filters
    # that had never narrowed anything, on a live screen, past review and a
    # passing suite — because any test that filters on a value which IS present
    # passes either way.
    #
    # Prose in a comment did not catch it. This does — but only with the
    # COMMENTS STRIPPED FIRST. The first cut of this check grepped the whole
    # file, and the header it was written alongside explains the bug using the
    # very string being looked for, so the check passed on a file that had been
    # deliberately broken to test it. Any anchor you can name in prose will
    # occur in the prose that names it.
    if grep -qiE '@FiltersJson' "$file"; then
        code=$(perl -0pe 's{/\*.*?\*/}{}gs; s{--[^\n]*}{}g' "$file")

        # A here-string, not `printf | grep -q`: under pipefail grep -q leaves
        # on its first match, printf dies of SIGPIPE writing the rest, and the
        # pipeline fails — so a file that DOES read $.column was reported as
        # not reading it, now and then (7 Oct 2026, under `composer check-fast`).
        # A verified direct delegation may centralise the exact filter scope.
        # Only this helper is accepted, and its real parser is checked too.
        # SQL string literals cannot masquerade as an executable EXEC call.
        delegated=0
        helper=Modules/Recon/Database/Procedures/usp_Recon_RunScope.sql
        if [ -f "$helper" ] && perl -0777 -e '
            my $code = <>;
            $code =~ s/\x27(?:\x27\x27|[^\x27])*\x27//gs;
            while ($code =~ /\bEXEC(?:UTE)?\s+\[?agora\]?\s*\.\s*\[?usp_Recon_RunScope\]?\s+([^;]+);/gi) {
                exit 0 if $1 =~ /(?:^|,)\s*\@FiltersJson\s*=\s*\@FiltersJson\s*(?:,|$)/i;
            }
            exit 1;
        ' <<< "$code"; then
            helper_code=$(perl -0pe 's{/\*.*?\*/}{}gs; s{--[^\n]*}{}g' "$helper")
            if grep -qiE 'CREATE +OR +ALTER +PROCEDURE +\[?agora\]?\.\[?usp_Recon_RunScope\]?([[:space:]]|$)' <<< "$helper_code" \
                && grep -qiE 'OPENJSON[[:space:]]*\([[:space:]]*@FiltersJson[[:space:]]*\)' <<< "$helper_code" \
                && perl -0777 -ne 'exit(/\bJSON_VALUE\s*\([^,;]+,\s*\x27\$\.column\x27\s*\)/i ? 0 : 1)' <<< "$helper_code" \
                && ! grep -qE '\.\[key\] *=' <<< "$helper_code"; then
                delegated=1
            fi
        fi
        if ! grep -qF '$.column' <<< "$code" && [ "$delegated" -ne 1 ]; then
            say "$file: declares @FiltersJson but never reads \$.column — over a JSON array OPENJSON's [key] is the index, so the filters silently do nothing. See docs/grid.md."
        fi

        # The broken idiom itself, named so the message can point at it.
        if grep -qE '\.\[key\] *=' <<< "$code"; then
            say "$file: keys @FiltersJson on OPENJSON's [key], which over a JSON array is the index (0, 1, 2), not the column name. The column is at \$.column. See docs/grid.md."
        fi
    fi

    # A writer that never opens a transaction is a rule with no atomicity.
    #
    # Inserts into table variables and temporary tables do not modify persistent
    # data; reporting procedures use both to stage results.
    if grep -qiE 'INSERT +INTO +(\[?agora|\[?dbo|[A-Za-z])|UPDATE +\[?agora|DELETE +FROM' "$file"; then
        if ! grep -qiE 'XACT_ABORT' "$file"; then
            say "$file: writes but does not SET XACT_ABORT ON."
        fi
    fi
done

if [ "$fail" -eq 0 ]; then
    echo "check-procs: ${#procs[@]} procedure(s) OK."
fi

exit "$fail"
