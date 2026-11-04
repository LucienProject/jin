#!/usr/bin/env bash
# Définit le mot de passe de l'interface web (utilisateur "lucien").
# Usage : ./set-password.sh            (demande le mot de passe)
#         JIN_PASSWORD=... ./set-password.sh
set -euo pipefail
cd "$(dirname "$0")"

if [ -z "${JIN_PASSWORD:-}" ]; then
    read -rsp "Nouveau mot de passe : " JIN_PASSWORD; echo
    read -rsp "Confirmation : " confirm; echo
    [ "$JIN_PASSWORD" = "$confirm" ] || { echo "Les mots de passe ne correspondent pas." >&2; exit 1; }
fi
[ ${#JIN_PASSWORD} -ge 12 ] || { echo "12 caractères minimum (la page est publique)." >&2; exit 1; }

hash=$(docker run --rm -u "$(id -u):$(id -g)" -e JIN_PASSWORD -v "$PWD/web:/app" -w /app php:8.5-cli \
    php -r 'echo password_hash(getenv("JIN_PASSWORD"), PASSWORD_BCRYPT, ["cost" => 13]);')

# guillemets simples : docker compose ne doit pas interpréter les "$" du hash
sed -i '/^JIN_UI_PASSWORD_HASH=/d' .env
printf "JIN_UI_PASSWORD_HASH='%s'\n" "$hash" >> .env

grep -q '^APP_SECRET=' .env || printf 'APP_SECRET=%s\n' "$(openssl rand -hex 16)" >> .env

docker compose up -d --force-recreate web
echo "Mot de passe mis à jour."
