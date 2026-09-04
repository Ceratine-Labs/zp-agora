#!/usr/bin/env bash
#
# Serve Agora, run Vite, and tail the log — one terminal, one Ctrl-C.
#
# There is no database container to start. Agora talks to the customer's SQL
# Server directly (see docs/rules.md), so the only local moving parts are PHP's
# built-in server and Vite.

set -euo pipefail
cd "$(dirname "$0")"

HOST="${AGORA_HOST:-127.0.0.1}"
PORT="${AGORA_PORT:-8123}"

if [ ! -f .env ]; then
    echo "No .env — copy .env.example, fill the connection blocks, run key:generate." >&2
    exit 1
fi

echo "Checking the customer connections before serving..."
php artisan agora:db-check >/dev/null || {
    echo "A connection failed. Run 'php artisan agora:db-check' to see which." >&2
    exit 1
}

pids=()
cleanup() {
    for pid in "${pids[@]:-}"; do kill "$pid" 2>/dev/null || true; done
}
trap cleanup EXIT INT TERM

php artisan serve --host="$HOST" --port="$PORT" & pids+=($!)
npm run dev -- --clearScreen=false & pids+=($!)
php artisan pail --timeout=0 & pids+=($!)

echo ""
echo "  Agora   http://$HOST:$PORT"
echo "  Ctrl-C  stops everything"
echo ""

wait
