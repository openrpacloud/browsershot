#!/bin/sh
set -e

# ============================================================
# browsershot entrypoint
# Launches a persistent Chromium instance before starting PHP,
# so all screenshot requests connect via WebSocket (no per-request
# browser spawn) — eliminates the EAGAIN / signal-6 crashes under
# load.
# ============================================================

CHROMIUM_BIN="${PUPPETEER_EXECUTABLE_PATH:-/usr/bin/chromium-browser}"
WS_PORT="${CHROMIUM_WS_PORT:-9222}"

echo "[entrypoint] Starting persistent Chromium on ws://127.0.0.1:${WS_PORT} ..."

# Launch Chromium in the background — headless, no sandbox, with a
# known debug port so puppeteer can connect via browserWSEndpoint.
# --disable-gpu avoids GPU-process crashes inside containers.
"${CHROMIUM_BIN}" \
    --headless=new \
    --no-sandbox \
    --disable-gpu \
    --disable-dev-shm-usage \
    --remote-debugging-port="${WS_PORT}" \
    --remote-debugging-address=127.0.0.1 \
    --disable-setuid-sandbox \
    --disable-software-rasterizer \
    --disable-extensions \
    --disable-background-networking \
    --disable-default-apps \
    --disable-sync \
    --metrics-recording-only \
    --no-first-run \
    --safebrowsing-disable-auto-update \
    &

CHROMIUM_PID=$!

# Wait until Chromium's debug port is responding (max 15s).
echo "[entrypoint] Waiting for Chromium debug port ${WS_PORT} ..."
RETRIES=0
MAX_RETRIES=30
while [ $RETRIES -lt $MAX_RETRIES ]; do
    if curl -s http://127.0.0.1:${WS_PORT}/json/version > /dev/null 2>&1; then
        echo "[entrypoint] Chromium is ready (PID=${CHROMIUM_PID})."
        break
    fi
    RETRIES=$((RETRIES + 1))
    sleep 0.5
done

if [ $RETRIES -eq $MAX_RETRIES ]; then
    echo "[entrypoint] ERROR: Chromium did not start within 15s. Aborting."
    exit 1
fi

# Cache the WebSocket endpoint URL to a file so PHP can read it
# instantly without querying Chromium's HTTP debug endpoint (~4s delay).
WS_ENDPOINT=$(curl -s http://127.0.0.1:${WS_PORT}/json/version | sed -n 's/.*"webSocketDebuggerUrl":"\([^"]*\)".*/\1/p')
if [ -n "$WS_ENDPOINT" ]; then
    echo "$WS_ENDPOINT" > /tmp/chromium_ws_endpoint.cache
    echo "[entrypoint] Cached WebSocket endpoint: ${WS_ENDPOINT}"
else
    echo "[entrypoint] WARNING: Could not extract webSocketDebuggerUrl. PHP will query it on first request."
fi

export CHROMIUM_WS_PORT="${WS_PORT}"

echo "[entrypoint] Starting PHP server on 0.0.0.0:8080 ..."

# Start the PHP built-in server (or replace with php-fpm in production).
php -S 0.0.0.0:8080 -t server server/server.php

# When PHP exits, clean up Chromium.
echo "[entrypoint] PHP server stopped. Killing Chromium (PID=${CHROMIUM_PID})."
kill $CHROMIUM_PID 2>/dev/null || true
wait $CHROMIUM_PID 2>/dev/null || true