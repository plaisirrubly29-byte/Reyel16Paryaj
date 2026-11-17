#!/usr/bin/env bash
# Active les statistiques des matchs (API-Football, V2-D09) qui règlent les paris sur la 1re mi-temps, les corners et
# les cartons, sans afficher la clé : vérifie d'abord la clé auprès d'API-Football (appel /status, gratuit), puis
# l'enregistre dans .env et redémarre l'application. Rien ne change si la vérification échoue.
#
# Usage, sur le serveur, dans le dossier de déploiement (ex. /opt/reyel16-staging) : ./set-statistics-key.sh
# Prérequis : un compte sur dashboard.api-football.com avec une formule payante (la gratuite ne couvre pas la saison
# en cours) ; la clé est sur la page « Account > My Access ».
set -euo pipefail
cd "$(dirname "$0")"

URL=https://v3.football.api-sports.io

read -rsp "Clé API-Football puis Entrée (rien ne s'affiche) : " KEY; echo
KEY=$(printf '%s' "$KEY" | tr -d '\r[:space:]')
[[ "$KEY" =~ ^[A-Za-z0-9]{16,64}$ ]] || { echo "Clé vide ou invalide. Rien n'a changé."; exit 1; }

# Vérification réelle : l'appel /status ne compte pas dans la limite du jour.
STATUS=$(curl -sS --max-time 20 -H "x-apisports-key: $KEY" "$URL/status" || true)
CHECK=$(printf '%s' "$STATUS" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("ERREUR réponse illisible"); sys.exit()
if d.get("errors"):
    print("ERREUR " + json.dumps(d["errors"], ensure_ascii=False)); sys.exit()
r = d.get("response") or {}
sub, req = r.get("subscription") or {}, r.get("requests") or {}
print("OK formule {} (active : {}, fin : {}), {} requêtes sur {} aujourd hui".format(
    sub.get("plan"), sub.get("active"), sub.get("end"), req.get("current"), req.get("limit_day")))
')
case "$CHECK" in
  OK*) echo "Clé acceptée — ${CHECK#OK }" ;;
  *) echo "Clé refusée par API-Football (${CHECK#ERREUR }). Rien n'a changé."; exit 1 ;;
esac

set_env() { # set_env CLÉ VALEUR : remplace la ligne si elle existe, sinon l'ajoute (valeur passée par l'environnement)
  KEY_NAME="$1" VALUE="$2" python3 - <<'PY'
import os, re
key, value = os.environ["KEY_NAME"], os.environ["VALUE"]
lines = open(".env", encoding="utf-8").read().splitlines()
line = f"{key}={value}"
pattern = re.compile(rf"^{re.escape(key)}=")
for i, current in enumerate(lines):
    if pattern.match(current):
        lines[i] = line
        break
else:
    lines.append(line)
open(".env", "w", encoding="utf-8").write("\n".join(lines) + "\n")
PY
}

cp -p .env .env.avant-statistiques
set_env STATISTICS_PROVIDER apifootball
set_env STATISTICS_API_KEY "$KEY"

export IMAGE_TAG; IMAGE_TAG=$(cat .deploy/current)
docker compose up -d --force-recreate --wait backend queue scheduler >/dev/null 2>&1 || true
docker compose ps --format '{{.Service}} {{.Status}}' backend queue scheduler
echo "Statistiques activées (sauvegarde : .env.avant-statistiques)."
echo "Les marchés mi-temps, corners et cartons apparaîtront à la prochaine synchro des marchés supplémentaires."
