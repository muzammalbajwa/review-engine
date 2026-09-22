#!/usr/bin/env bash
#
# Checks the five background processes a full local Paddle/webhook/email
# verification pass needs (see 2026-09-22's session, where Mailpit died
# silently mid-session with nothing watching it): the Laravel backend,
# the queue worker, Mailpit, the ngrok tunnel, and the Next.js frontend.
#
# Real evidence only — a listening port (lsof) and/or a matching process
# (pgrep), never "probably fine." Exits 0 if all five are up, 1 otherwise,
# so this is safe to use as a precondition check in another script.
#
# Usage: backend/scripts/dev-status.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

BACKEND_PORT=8123
FRONTEND_PORT=3000
MAILPIT_HTTP_PORT=8025
NGROK_ADMIN_PORT=4040

all_up=true

port_pid() {
  lsof -nP -iTCP:"$1" -sTCP:LISTEN -t 2>/dev/null | head -n1
}

check() {
  local name="$1" pid="$2" extra="${3:-}"
  if [[ -n "$pid" ]]; then
    printf "  UP    %-14s pid %-8s %s\n" "$name" "$pid" "$extra"
  else
    printf "  DOWN  %-14s %s\n" "$name" "$extra"
    all_up=false
  fi
}

echo "== ReviewEngine local dev status =="

pid=$(port_pid "$BACKEND_PORT")
check "backend" "$pid" "php artisan serve --port=$BACKEND_PORT"

pid=$(pgrep -f "artisan queue:work" | head -n1)
check "queue worker" "$pid" "php artisan queue:work redis"

pid=$(port_pid "$MAILPIT_HTTP_PORT")
check "mailpit" "$pid" "http://localhost:$MAILPIT_HTTP_PORT"

pid=$(port_pid "$NGROK_ADMIN_PORT")
if [[ -n "$pid" ]]; then
  # The admin API (127.0.0.1:4040) is what actually confirms a tunnel is
  # live, not just that some ngrok process exists — a crashed/stalled
  # tunnel can leave the process running with the port dead.
  tunnel_url=$(curl -s "http://127.0.0.1:${NGROK_ADMIN_PORT}/api/tunnels" \
    | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['tunnels'][0]['public_url'])" 2>/dev/null)
  check "ngrok" "$pid" "${tunnel_url:-(admin API up, but no active tunnel found)}"
else
  check "ngrok" "" "not running"
fi

pid=$(port_pid "$FRONTEND_PORT")
check "frontend" "$pid" "https://localhost:$FRONTEND_PORT"

echo
if $all_up; then
  echo "All five up."
  exit 0
else
  echo "One or more down — run backend/scripts/dev-up.sh to start what's missing."
  exit 1
fi
