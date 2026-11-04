#!/usr/bin/env bash
# Affiche l'URL publique du tunnel Cloudflare (change à chaque redémarrage)
cd "$(dirname "$0")"
docker compose logs cloudflared 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1
