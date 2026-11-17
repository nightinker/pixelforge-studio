#!/usr/bin/env bash
#
# One-time first deploy of Pixelforge Studio to Cloudflare Containers.
#
# Run it from anywhere:  bash ~/Sites/pixelforge-studio/deploy/first-deploy.sh
#
# What it does:
#   1. Asks for the Neon database password (hidden) and uploads it as DB_PASSWORD.
#   2. Checks the other required secrets are present (names only, never values).
#   3. Builds the container image and deploys the Worker (needs Docker/OrbStack running).
#   4. Waits for the app to answer on /up and prints the live URL.
#
# Safe to re-run. Nothing secret is written to disk or to git.

set -euo pipefail

EDGE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/edge" && pwd)"
URL="https://pixelforge-api.harlequin-doyon.workers.dev"
REQUIRED=(APP_KEY APP_URL DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD DB_SSLMODE PIXELFORGE_BASE_URL)

cd "$EDGE_DIR"

echo "==> Checking Docker is running"
docker info >/dev/null 2>&1 || { echo "Docker isn't running. Start OrbStack (orbctl start) and re-run."; exit 1; }

echo "==> Installing Worker dependencies"
npm install --no-audit --no-fund >/dev/null

echo
read -r -s -p "Neon database password (hidden, press Enter to skip if already set): " DB_PASSWORD_VALUE
echo
if [ -n "$DB_PASSWORD_VALUE" ]; then
  printf '%s' "$DB_PASSWORD_VALUE" | npx wrangler secret put DB_PASSWORD
  unset DB_PASSWORD_VALUE
fi

echo "==> Checking secrets"
SECRETS="$(npx wrangler secret list 2>/dev/null || true)"
MISSING=()
for name in "${REQUIRED[@]}"; do
  grep -q "\"$name\"" <<<"$SECRETS" || MISSING+=("$name")
done
if [ "${#MISSING[@]}" -gt 0 ]; then
  echo "Missing secrets: ${MISSING[*]}"
  echo "Set each with:  cd $EDGE_DIR && npx wrangler secret put NAME"
  exit 1
fi
echo "All required secrets are present."

echo "==> Building the image and deploying (the first build takes a few minutes)"
npx wrangler deploy

echo "==> Waiting for the app to come up at $URL/up"
for i in $(seq 1 30); do
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$URL/up" || true)"
  if [ "$code" = "200" ]; then
    echo "Live: $URL  (GET /up -> 200)"
    echo
    echo "Next: create the second Pixelforge API key with this webhook URL:"
    echo "  $URL/webhooks/pixelforge"
    exit 0
  fi
  echo "  attempt $i: HTTP ${code:-none}, retrying in 10s"
  sleep 10
done

echo "The deploy finished but /up did not return 200 within ~5 minutes."
echo "Check logs with:  cd $EDGE_DIR && npx wrangler tail"
exit 1
