#!/usr/bin/env bash
#
# The migration rules that are mechanical, enforced mechanically.
#
# Every one of these is in CLAUDE.md as prose, and prose is not a check. Agora
# migrates a production database on its first run, so "someone will remember"
# is not a control.

set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
say() { printf '  %s\n' "$1"; fail=1; }

files=$(find Modules -path '*/Database/Migrations/*.php' 2>/dev/null | sort)

if [ -z "$files" ]; then
    echo "check-migrations: no module migrations found."
    exit 0
fi

for file in $files; do
    name=$(basename "$file")

    # v1__01_core_tables.php · v1__12a_cash_drop_safe.php · v1__01p_core_procs.php
    if ! echo "$name" | grep -qE '^v[0-9]+__[0-9]{2}[a-z]?_[a-z0-9_]+\.php$'; then
        say "$file: name does not match v{N}__{NN}[letter]_{module}_{what}.php"
    fi

    # A create must go through MigrationHelper, which is what refuses to build
    # anything outside the agora schema. A bare Schema::create can name dbo.
    if grep -qE '^\s*Schema::create\(' "$file"; then
        line=$(grep -nE '^\s*Schema::create\(' "$file" | head -1 | cut -d: -f1)
        say "$file:$line: Schema::create() directly — use MigrationHelper::table(), which refuses any schema but agora."
    fi

    # Foreign keys live in one v1__95_{module}_foreign_keys.php per module: the
    # reference graph has cycles SQL Server rejects inside a create.
    if ! echo "$name" | grep -q '_foreign_keys\.php$'; then
        if grep -qE -- '->(constrained|foreign)\(' "$file"; then
            line=$(grep -nE -- '->(constrained|foreign)\(' "$file" | head -1 | cut -d: -f1)
            say "$file:$line: foreign key outside a v1__95_{module}_foreign_keys.php file."
        fi
    fi

    # No enum columns — a small reference table or TINYINT + a check constraint.
    if grep -qE '\$table->enum\(' "$file"; then
        line=$(grep -nE '\$table->enum\(' "$file" | head -1 | cut -d: -f1)
        say "$file:$line: enum column. Use a reference table or TINYINT + check constraint."
    fi

    # migrate:fresh is forbidden outright; a migration must not invite it.
    if grep -qE 'migrate:fresh|dropAllTables' "$file"; then
        say "$file: references migrate:fresh or dropAllTables. Live is forward-only."
    fi

    # dbo is the customer's estate. A migration may MENTION it in a comment —
    # several legitimately explain what they were copied from — but it may not
    # aim a schema builder or a DDL statement at it.
    #
    # So comments are stripped first, and the match is on the operation rather
    # than on the word. The previous version of this check tested for the word
    # inside a malformed bracket expression, which meant it never matched
    # anything at all and passed every file vacuously.
    code=$(sed -E 's;//.*$;;; s;^[[:space:]]*\*.*$;;' "$file")

    if echo "$code" | grep -qiE "Schema::[a-zA-Z]+\(\s*['\"]dbo\."; then
        say "$file: points a Schema:: call at the dbo schema. Migrations only create objects in agora."
    fi

    if echo "$code" | grep -qiE "(CREATE|ALTER|DROP|TRUNCATE)[[:space:]]+(TABLE|INDEX|VIEW|PROCEDURE|SCHEMA)?[[:space:]]*\[?dbo\]?\."; then
        say "$file: DDL against the dbo schema. The customer runs schema changes to their own tables."
    fi
done

if [ "$fail" -eq 0 ]; then
    echo "check-migrations: $(echo "$files" | wc -l) migration(s) OK."
fi

exit "$fail"
