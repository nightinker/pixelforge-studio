#!/usr/bin/env bash
#
# Uploads the DEPLOYED Pixelforge API key and its webhook signing secret to the
# Worker, then redeploys so the container picks them up.
#
# Run:  bash ~/Sites/pixelforge-studio/deploy/set-pixelforge-secrets.sh
#
# Use the values of the SECOND key (the one whose webhook URL is the live app),
# not your local key. Input is hidden and nothing is written to disk.

set -euo pipefail

EDGE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/edge" && pwd)"
URL="https://pixelforge-api.harlequin-doyon.workers.dev"
cd "$EDGE_DIR"

read -r -s -p "Deployed Pixelforge API key (hidden): " KEY; echo
read -r -s -p "Its webhook signing secret, starts with whsec_ (hidden): " SECRET; echo

if [ -z "$KEY" ] || [ -z "$SECRET" ]; then
  echo "Both values are required."; exit 1
fi
case "$SECRET" in
  whsec_*) ;;
  *) echo "The webhook secret should start with whsec_ — did you paste the API key by mistake?"; exit 1 ;;
esac

printf '%s' "$KEY"    | npx wrangler secret put PIXELFORGE_API_KEY
printf '%s' "$SECRET" | npx wrangler secret put PIXELFORGE_WEBHOOK_SECRET
unset KEY SECRET

echo "==> Redeploying so the container restarts with the new secrets"
npx wrangler deploy

echo "==> Waiting for $URL/up"
for i in $(seq 1 30); do
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$URL/up" || true)"
  [ "$code" = "200" ] && { echo "Live and ready: $URL"; exit 0; }
  echo "  attempt $i: HTTP ${code:-none}"; sleep 10
done
echo "Did not come up in time. Check:  cd $EDGE_DIR && npx wrangler tail"; exit 1
