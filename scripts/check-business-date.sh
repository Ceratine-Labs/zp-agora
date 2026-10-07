#!/usr/bin/env bash
#
# A date a person reads is South African. This keeps it that way.
#
# The application runs on UTC and stores instants in UTC (config app.timezone,
# v1__13py). A BUSINESS date — "today", a default To date, the week to date — is
# the customer's calendar date, and between midnight and 02:00 SAST the UTC date
# is still yesterday. On 7 Oct 2026 fourteen places took "today" from the UTC
# clock (Ryan: "we are utc+2 … should we not correct that"); they now take it
# from App\Support\BusinessDate::today().
#
# So outside that class, nothing in app/, Modules/ or resources/views may ask
# for today's date from the server's clock: Carbon::today(), today(),
# now()->toDateString(), or a validation rule keyed on `today`. An instant —
# now() as a timestamp, a duration — is fine and is not matched.
#
# A legitimate use that trips this: say why in the class it belongs to, and add
# its path to EXEMPT below. Do not weaken the pattern.

set -uo pipefail
cd "$(dirname "$0")/.."

EXEMPT='app/Support/BusinessDate.php'

hits=$(grep -rnE "Carbon::today\(\)|[^>:a-zA-Z_]today\(\)|now\(\)->toDateString\(\)|Carbon::now\(\)->toDateString\(\)|(before|after)(_or_equal)?:today" \
           --include='*.php' app Modules resources/views 2>/dev/null \
       | grep -v -E "^(${EXEMPT}):" \
       | grep -v -E "/(tests|Tests)/" \
       | grep -v -E "^[^:]+:[0-9]+:\s*(\*|//|#)")

if [ -n "$hits" ]; then
    echo "check-business-date: today taken from the UTC clock — use App\\Support\\BusinessDate::today():"
    echo "$hits" | sed 's/^/  /'
    exit 1
fi

echo "check-business-date: every business date is South African."
