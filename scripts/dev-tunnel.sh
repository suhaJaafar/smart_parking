#!/usr/bin/env bash
#
# Start the Mini App dev tunnel and point Telegram at it.
#
# A trycloudflare "quick tunnel" is handed a brand-new random hostname on every
# start, so the URL in .env and the one registered as the bot's menu button go
# stale the moment the tunnel restarts. Telegram then keeps opening the dead
# host and the Mini App shows a blank screen with no error. This script closes
# that loop: bring the tunnel up, read the hostname back, write it to .env and
# re-register the menu button.
#
# Usage: scripts/dev-tunnel.sh [port]   (defaults to the Next.js dev server)

set -euo pipefail

PORT="${1:-3000}"
# Pinned rather than random, so the hostname can be read back deterministically.
METRICS_ADDR="127.0.0.1:20241"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"

command -v cloudflared >/dev/null 2>&1 || {
	echo "cloudflared is not installed (brew install cloudflared)." >&2
	exit 1
}
[[ -f "$ENV_FILE" ]] || {
	echo "No .env found at $ENV_FILE" >&2
	exit 1
}

cloudflared tunnel --url "http://localhost:$PORT" --metrics "$METRICS_ADDR" &
CF_PID=$!
trap 'kill "$CF_PID" 2>/dev/null || true' EXIT INT TERM

# The hostname only exists once Cloudflare's edge has accepted the tunnel.
host=''
for _ in $(seq 1 40); do
	host="$(curl -fsS --max-time 2 "http://$METRICS_ADDR/quicktunnel" 2>/dev/null |
		sed -n 's/.*"hostname":"\([^"]*\)".*/\1/p')"
	[[ -n "$host" ]] && break
	sleep 1
done

if [[ -z "$host" ]]; then
	echo "Tunnel never came up — leaving .env untouched." >&2
	wait "$CF_PID"
	exit 1
fi

url="https://$host/miniapp"

# Rewrite only this one key. Redirecting into the existing file rather than
# moving a temp file over it keeps the original permissions on a file full of
# secrets.
tmp="$(mktemp)"
awk -v url="$url" '
	/^TELEGRAM_MINI_APP_URL=/ { print "TELEGRAM_MINI_APP_URL=" url; found = 1; next }
	{ print }
	END { if (!found) print "TELEGRAM_MINI_APP_URL=" url }
' "$ENV_FILE" >"$tmp"
cat "$tmp" >"$ENV_FILE"
rm -f "$tmp"

echo ""
echo "  Mini App URL : $url"
(cd "$ROOT" && php artisan config:clear >/dev/null && php artisan telegram:set-menu-button)
echo "  Menu button registered. Force-quit Telegram once to drop its cached URL."
echo ""

wait "$CF_PID"
