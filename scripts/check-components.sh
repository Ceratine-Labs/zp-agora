#!/usr/bin/env bash
#
# "Everything is a component" (plan §3.8, docs/components.md), enforced.
#
# The rule is prose in two documents and prose is not a check. What actually
# happens without one is that a screen in a hurry writes `<div class="kpi">`,
# it looks right that afternoon, and six months later the KPI tile cannot be
# changed in one place because eleven screens have their own copy of it. That
# is the failure this exists to stop, and it is the reason the mockup's own
# class names are the ones the components emit: a hand-written `class="kpi"` is
# now unambiguously somebody bypassing the library.
#
# Scope: blade views OUTSIDE a components directory. A component is allowed —
# required, in fact — to write its own markup, and so is a component that
# composes another one.
#
# If a legitimate usage trips this, fix the rule and say so. Do not add a class
# to the file to get past it, and do not delete the token: the point of the
# check is that the exceptions get discussed.

set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
say() { printf '  %s\n' "$1"; fail=1; }

# token|the component that owns it
OWNED='
kpi|<x-kpi>
kpis|<x-kpi-strip>
kpi-strip|<x-kpi-strip>
lbl|<x-kpi>
val|<x-kpi>
cmp|<x-kpi>
stripe|<x-kpi>
card|<x-card>
card-h|<x-card>
card-b|<x-card>
card-head|<x-card>
card-body|<x-card>
chip|<x-chip>
delta|<x-delta>
statstrip|<x-statstrip>
tabs|<x-tabs>
tabpane|<x-tab-panel>
params|<x-params>
runbar|<x-runbar>
checklist|<x-checklist>
exlist|<x-exception-list>
ex|<x-exception-row>
decisions|<x-decision-list>
decision|<x-decision-list>
proposal|<x-proposal>
libcard|<x-lib-card>
libmeta|<x-lib-card>
crumb|<x-crumb>
wsw|<x-workspace-switch>
rolebtn|<x-role-card>
signin-state|<x-system-state>
sqlbox|<x-sqlbox>
emptystate|<x-empty-state>
empty-state|<x-empty-state>
notice|<x-notice>
tip|<x-tip>
page-head|<x-page-head>
mega|<x-app-bar>
mega-col|<x-menu-branch>
mega-link|<x-menu-branch>
navbtn|<x-app-bar>
'

# The nav classes above are the "no blade hand-writes a nav link" rule
# (docs/feature-rules.md §G, rulebook: navigation is database-driven). It is
# deliberately narrow — the MENU markup, not every anchor. A grid cell linking
# to a record is required by feature-rules §3.7 and must not trip this.
#
# Not included, on purpose: `appbar`, `brand`, `tools`, `iconbtn` and
# `primary`. The gallery writes its own app bar because <x-app-bar> needs menu
# rows out of the database and the gallery must render without one. That is a
# real exception, so the rule does not claim those names rather than the
# gallery working around the rule.

# Views that are allowed to write component markup: the components themselves.
views=$(find resources/views Modules -path '*/views/*' -name '*.blade.php' 2>/dev/null \
        | grep -v '/components/' | sort)

if [ -z "$views" ]; then
    echo "check-components: no views to check."
    exit 0
fi

while IFS='|' read -r token component; do
    [ -z "$token" ] && continue

    # A class attribute whose value contains the token as a whole word: either
    # the entire value, or bounded by whitespace on the side(s) it has.
    # Single and double quotes both, because Blade views use both.
    pattern="class=\"([^\"]*[[:space:]])?${token}([[:space:]][^\"]*)?\"|class='([^']*[[:space:]])?${token}([[:space:]][^']*)?'"

    hits=$(grep -nE "$pattern" $views 2>/dev/null)

    if [ -n "$hits" ]; then
        while IFS= read -r hit; do
            say "${hit%%:*}:$(echo "$hit" | cut -d: -f2): hand-written class=\"${token}\" — use ${component}. A screen does not write component markup (plan §3.8)."
        done <<< "$hits"
    fi
done <<< "$OWNED"

# A module that serves screens must seed its own navigation. Without this, a
# module ships, its routes resolve, and nothing in the application links to it —
# which is indistinguishable from it not existing, and is only noticed when
# somebody asks where the screen went.
for module in Modules/*/; do
    name=$(basename "$module")

    if [ -f "${module}Routes/web.php" ] && ! ls "${module}"Database/Seeders/*MenuSeeder.php >/dev/null 2>&1; then
        say "Modules/${name}: has Routes/web.php but no MenuSeeder. Navigation is database-driven — a module with screens seeds its own menu entries."
    fi
done

# A class a view writes that NOTHING defines is markup that does nothing.
#
# The blocklist above catches a screen REUSING a component's class name. It
# cannot catch the opposite mistake, which is what actually happened on
# 20 September 2026: the stock master detail screen was written with
# `.grid-cards` and `.gc` — classes the report-writing template defines and
# Agora never has — and it rendered as a wall of unstyled text. Every gate
# passed, phpstan passed, and the only thing that caught it was looking at a
# screenshot.
#
# So: every class in a static class="..." in a non-component view must appear
# somewhere in the compiled CSS, the JavaScript, or a component's own markup.
# Dynamic class expressions are skipped — a class built at render time cannot
# be checked here and pretending otherwise would make the check lie.
#
# Run `npm run build` first if you have changed a stylesheet; this reads the
# BUILT css, deliberately, because that is what the browser gets.
if ls public/build/assets/*.css >/dev/null 2>&1; then
    defined=$(mktemp)

    # From the stylesheet: selectors only. CSS carries no prose, so a leading
    # dot is unambiguous there.
    cat public/build/assets/*.css 2>/dev/null \
        | grep -oE '\.[a-zA-Z][A-Za-z0-9_-]*' | cut -c2- > "$defined"

    # From the JavaScript: quoted strings, which is how classList and
    # querySelector name a class.
    find resources/js -name '*.js' -exec cat {} + 2>/dev/null \
        | grep -oE "['\"\`][a-zA-Z][A-Za-z0-9_-]*" | cut -c2- >> "$defined"

    # From the components: the class ATTRIBUTES they write, and nothing else.
    # NOT their prose — a docblock explaining why `.grid-cards` is wrong would
    # otherwise define `.grid-cards`, which is exactly how the first cut of
    # this check passed a deliberately broken view.
    find resources/views Modules -path '*/views/components/*' -name '*.blade.php' -exec cat {} + 2>/dev/null \
        | grep -oE "class=[\"'][^\"']*[\"']" | tr " \"'" '\\n\\n\\n' | grep -vE '^(class=|)$' >> "$defined"

    # And the class names a component composes into a string.
    find resources/views Modules -path '*/views/components/*' -name '*.blade.php' -exec cat {} + 2>/dev/null \
        | grep -oE "'[a-zA-Z][A-Za-z0-9_-]*'" | tr -d "'" >> "$defined"

    # And the selectors in a component's own inline <style> — signin-layout
    # carries the whole sign-in stylesheet that way, so `.signin-links` is
    # defined there and nowhere else. A selector is a dot-name followed by
    # `{`, `,` or another selector; prose naming `.a-class` in a sentence is
    # followed by a backtick or a space and a word, so it does not match.
    find resources/views Modules -path '*/views/components/*' -name '*.blade.php' -exec cat {} + 2>/dev/null \
        | grep -oP '\.[a-zA-Z][A-Za-z0-9_-]*(?=[[:space:]]*[{,.:])' | cut -c2- >> "$defined"

    sort -u -o "$defined" "$defined"

    for view in $views; do
        # Static class attributes only: no {{ }}, no @if, no blade at all.
        used=$(grep -oE 'class="[^"{}@]+"' "$view" 2>/dev/null \
               | sed -E 's/^class="//; s/"$//' | tr ' ' '\n' | grep -v '^$' | sort -u)

        for class in $used; do
            if ! grep -qxF "$class" "$defined"; then
                say "${view}: class=\"${class}\" is defined in no stylesheet, script or component. Dead markup, or a class copied in from somewhere that is not this application."
            fi
        done
    done

    rm -f "$defined"
else
    echo "  (skipping the class audit — no built CSS. Run: npm run build)"
fi

# docs/components.md carries a generated block. A component added without its
# row is a component the next session will build a second version of.
if ! php artisan agora:components-doc --check >/dev/null 2>&1; then
    say "docs/components.md is out of date — run: php artisan agora:components-doc"
fi

if [ "$fail" -eq 0 ]; then
    echo "check-components: $(echo "$views" | wc -l) view(s) OK — no hand-written component markup."
fi

exit "$fail"
