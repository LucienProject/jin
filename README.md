# Jin — API publique pour les modèles Ollama locaux

Ollama (hôte, :11434) → Caddy (auth Bearer, :8080) → Cloudflare Tunnel (HTTPS public)

## Commandes

```bash
docker compose up -d                     # proxy local seulement (http://localhost:8080)
docker compose --profile public up -d    # + tunnel public
./tunnel-url.sh                          # URL publique (change à chaque redémarrage du tunnel)
./test.sh [URL] [MODELE]                 # vérifie auth + chat
docker compose --profile public down     # tout arrêter
```

## Interface de chat (Symfony, dossier `web/`)

http://localhost:8000 : choix du modèle, conversation avec réponses en streaming (Markdown, réflexion des modèles qui raisonnent).
Colonne de gauche : conversations enregistrées, mises à jour automatiquement après chaque échange et classables en dossiers (fichiers JSON dans `web/var/share/prod/conversations/`).
Bouton 🌐 de la zone de saisie : **recherche web**. Le modèle peut alors appeler deux outils, `web_search` (via SearXNG, service `searxng` sur `127.0.0.1:8888`) et `fetch_url` (lecture d'une page, réseau local interdit), puis répond en citant ses sources. Les modèles sans appel de fonctions (phi4…) répondent sans chercher.
Bouton 📎 (ou glisser-déposer, ou coller) : **fichiers joints**. Le serveur en extrait le texte (PDF, Word `.docx`, OpenDocument `.odt`, et tout fichier texte : md, csv, json, code…), 20 Mo et 100 000 caractères max. par fichier (au-delà, seul le début est lu). Le texte part avec le message et est enregistré avec la conversation ; rien n'est gardé côté serveur. Les PDF scannés (images sans texte) ne sont pas lisibles.
**Images** (png, jpeg, webp, gif…) : réduites à 1 536 px dans le navigateur puis envoyées telles quelles aux modèles qui savent les lire (gemma4, qwen3.6, mistral…, détectés via `/api/show`). Les autres modèles répondent sans les voir, avec un avertissement. Les images sont enregistrées dans le JSON de la conversation.
Elle passe par Caddy (`localhost:8080`) avec le même token, donc fonctionne même si le tunnel est coupé.

Protégée par mot de passe (utilisateur `lucien`, 5 essais par minute et par IP). Tourne en mode `prod`.

```bash
./set-password.sh                                               # définir / changer le mot de passe
docker compose --profile ngrok up -d                            # exposer l'interface sur NGROK_DOMAIN (.env)
docker compose up -d web                                        # démarrer l'interface
docker run --rm -u 1000:1000 -v $PWD/web:/app -w /app php:8.5-cli php bin/phpunit   # tests
```

Le token est dans `.env` (`API_TOKEN`). Après l'avoir changé : `docker compose up -d --force-recreate caddy`.

## Utilisation (API compatible OpenAI)

```python
from openai import OpenAI
client = OpenAI(base_url="https://xxx.trycloudflare.com/v1", api_key="<API_TOKEN>")
client.chat.completions.create(model="gemma4:12b", messages=[{"role": "user", "content": "Salut"}])
```
