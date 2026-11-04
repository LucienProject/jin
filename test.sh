#!/usr/bin/env bash
# Usage : ./test.sh [URL] [MODELE]
# Sans URL, teste le proxy local. Ex : ./test.sh https://xxx.trycloudflare.com gemma4:12b
set -euo pipefail
cd "$(dirname "$0")"
source .env

URL="${1:-http://localhost:8080}"
MODEL="${2:-gemma4:12b}"

echo "== Sans token (attendu : 401)"
curl -s -o /dev/null -w "%{http_code}\n" "$URL/v1/models"

echo "== Liste des modèles"
curl -sf "$URL/v1/models" -H "Authorization: Bearer $API_TOKEN" | grep -o '"id":"[^"]*"'

echo "== Chat avec $MODEL"
curl -sf "$URL/v1/chat/completions" \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"model\":\"$MODEL\",\"messages\":[{\"role\":\"user\",\"content\":\"Réponds juste: OK\"}]}" \
  | grep -o '"content":"[^"]*"'
