#!/usr/bin/env bash
#
# Static calls in Blade that PHPStan cannot see.
#
# phpstan analyses PHP, and a Blade file is PHP only after it is compiled — so
# `\App\Support\Format::datetime(...)` in a view passes every gate in the repo
# and then fatals when somebody renders it. That is not hypothetical: it
# reached agora.ceratine.com on 7 September 2026 and 500'd the user view
# screen. The call sat on a branch nothing had exercised — a person who has
# actually signed in — so the tests, the browser walk and the deploy smoke test
# all missed it, and Ryan found it.
#
# The rule this enforces: every `Format::x()` written in a Blade file must be a
# real public static method on App\Support\Format. Cheap, mechanical, and it
# closes the exact hole. Prose in a doc would not have.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
checked=0

# What the class actually offers, straight from the source of truth.
methods=$(grep -oE 'public static function [a-zA-Z_][a-zA-Z0-9_]*' app/Support/Format.php \
    | awk '{print $4}' | sort -u)

if [ -z "$methods" ]; then
    echo "check-blades: could not read App\\Support\\Format — has it moved?" >&2
    exit 1
fi

# Every Format::call( in every Blade file, with its file and line.
while IFS= read -r hit; do
    [ -z "$hit" ] && continue
    file=${hit%%:*}
    rest=${hit#*:}
    line=${rest%%:*}
    call=$(printf '%s' "$hit" | grep -oE 'Format::[a-zA-Z_][a-zA-Z0-9_]*' | head -1)
    method=${call#Format::}
    checked=$((checked + 1))

    # NOTHING is a constant, not a method, and is written without parentheses.
    if ! printf '%s\n' "$methods" | grep -qx "$method"; then
        printf '  %s:%s: Format::%s() is not a method on App\\Support\\Format.\n' "$file" "$line" "$method"
        fail=1
    fi
done < <(grep -rnoE 'Format::[a-zA-Z_][a-zA-Z0-9_]*\(' \
    --include='*.blade.php' Modules resources 2>/dev/null)

if [ "$fail" -ne 0 ]; then
    echo "check-blades: FAILED — phpstan cannot see inside a Blade file, so this is the only guard." >&2
    exit 1
fi

echo "check-blades: ${checked} Format:: call(s) in views, all real."
