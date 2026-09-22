#!/usr/bin/env bash
#
# Starts whatever's missing from the five processes a full local
# Paddle/webhook/email verification pass needs; leaves anything already
# running alone. Not a supervisor — nothing here watches these processes
# after they start, and nothing auto-restarts on crash. Run this again
# any time backend/scripts/dev-status.sh reports something down.
#
# Usage: backend/scripts/dev-up.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
FRONTEND_DIR="$(cd "${BACKEND_DIR}/../frontend" && pwd)"

BACKEND_PORT=8123
FRONTEND_PORT=3000
MAILPIT_HTTP_PORT=8025
NGROK_ADMIN_PORT=4040

port_pid() {
  lsof -nP -iTCP:"$1" -sTCP:LISTEN -t 2>/dev/null | head -n1
}

start_if_down() {
  local name="$1" check_pid="$2" start_cmd="$3" log="$4"
  if [[ -n "$check_pid" ]]; then
    echo "  already up: $name (pid $check_pid)"
    return
  fi
  echo "  starting: $name  (log: $log)"
  eval "$start_cmd" >"$log" 2>&1 &
  disown
}

echo "== Starting whatever's missing =="

start_if_down "backend" "$(port_pid "$BACKEND_PORT")" \
  "cd '$BACKEND_DIR' && php artisan serve --port=$BACKEND_PORT" \
  "/tmp/re_backend.log"

start_if_down "queue worker" "$(pgrep -f 'artisan queue:work' | head -n1)" \
  "cd '$BACKEND_DIR' && php artisan queue:work redis --queue=transactional,default" \
  "/tmp/re_queue.log"

start_if_down "mailpit" "$(port_pid "$MAILPIT_HTTP_PORT")" \
  "mailpit" \
  "/tmp/re_mailpit.log"

ngrok_was_down=false
if [[ -z "$(port_pid "$NGROK_ADMIN_PORT")" ]]; then
  ngrok_was_down=true
fi
start_if_down "ngrok" "$(port_pid "$NGROK_ADMIN_PORT")" \
  "ngrok http $BACKEND_PORT --log=stdout" \
  "/tmp/re_ngrok.log"

start_if_down "frontend" "$(port_pid "$FRONTEND_PORT")" \
  "cd '$FRONTEND_DIR' && npx next dev --experimental-https --port $FRONTEND_PORT" \
  "/tmp/re_frontend.log"

# Give freshly-started processes a moment before the final status check
# and the ngrok URL lookup below.
sleep 3

if $ngrok_was_down; then
  tunnel_url=$(curl -s "http://127.0.0.1:${NGROK_ADMIN_PORT}/api/tunnels" \
    | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['tunnels'][0]['public_url'])" 2>/dev/null)
  if [[ -n "$tunnel_url" ]]; then
    echo
    echo "  ngrok was (re)started — it gets a NEW random URL every time."
    echo "  New tunnel: $tunnel_url"
    echo "  Update the Paddle sandbox webhook destination to:"
    echo "    ${tunnel_url}/api/v1/paddle/webhook"
    echo "  (the previous destination URL is now dead — webhooks will not"
    echo "   arrive until this is updated)"
  fi
fi

echo
"${SCRIPT_DIR}/dev-status.sh"
