#!/usr/bin/env bash
# Active l'envoi des e-mails (mot de passe oublié, alertes) avec une boîte Hostinger du domaine, sans afficher le mot
# de passe : vérifie d'abord la connexion en envoyant un e-mail de test à la boîte elle-même, puis enregistre les
# réglages dans .env et redémarre l'application. Rien ne change si la vérification échoue.
#
# Usage, sur le serveur, dans le dossier de déploiement (ex. /opt/reyel16-staging) : ./set-mail.sh
# Prérequis : la boîte existe dans hPanel (E-mails > Comptes e-mail), par exemple no-reply@reyel16paryaj.com.
set -euo pipefail
cd "$(dirname "$0")"

HOST=smtp.hostinger.com
PORT=465

read -rp "Adresse de la boîte d'envoi [no-reply@reyel16paryaj.com] : " ADDRESS
ADDRESS=$(printf '%s' "${ADDRESS:-no-reply@reyel16paryaj.com}" | tr -d '\r[:space:]')
[[ "$ADDRESS" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ ]] || { echo "Adresse invalide. Rien n'a changé."; exit 1; }

read -rsp "Mot de passe de la boîte puis Entrée (rien ne s'affiche) : " PASSWORD; echo
[ -n "$PASSWORD" ] || { echo "Mot de passe vide. Rien n'a changé."; exit 1; }
# Valeur écrite entre apostrophes dans .env (lue telle quelle) : une apostrophe ne peut pas y figurer.
[[ "$PASSWORD" != *"'"* ]] || { echo "Le mot de passe contient une apostrophe ('), non prise en charge : changez-le dans hPanel. Rien n'a changé."; exit 1; }

# Vérification réelle : connexion SMTP chiffrée et envoi d'un e-mail de test à la boîte elle-même.
MESSAGE=$(mktemp); trap 'rm -f "$MESSAGE"' EXIT
printf 'From: REYEL 16 PARYAJ <%s>\r\nTo: <%s>\r\nSubject: Test d envoi REYEL 16 PARYAJ\r\n\r\nLe serveur peut envoyer des e-mails.\r\n' "$ADDRESS" "$ADDRESS" > "$MESSAGE"
if ! curl -sS --max-time 30 --ssl-reqd --url "smtps://$HOST:$PORT" --user "$ADDRESS:$PASSWORD" \
     --mail-from "$ADDRESS" --mail-rcpt "$ADDRESS" --upload-file "$MESSAGE" >/dev/null 2>&1; then
  echo "Connexion refusée par $HOST (adresse ou mot de passe incorrect, ou boîte pas encore active). Rien n'a changé."
  exit 1
fi

set_env() { # set_env CLÉ VALEUR : remplace la ligne si elle existe, sinon l'ajoute (valeur passée par l'environnement)
  KEY="$1" VALUE="$2" python3 - <<'PY'
import os, re
key, value = os.environ["KEY"], os.environ["VALUE"]
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

cp -p .env .env.avant-mail
set_env MAIL_MAILER smtp
set_env MAIL_SCHEME smtps
set_env MAIL_HOST "$HOST"
set_env MAIL_PORT "$PORT"
set_env MAIL_USERNAME "$ADDRESS"
set_env MAIL_PASSWORD "'$PASSWORD'"
set_env MAIL_FROM_ADDRESS "$ADDRESS"
set_env MAIL_FROM_NAME '"REYEL 16 PARYAJ"'

export IMAGE_TAG; IMAGE_TAG=$(cat .deploy/current)
docker compose up -d --force-recreate --wait backend queue scheduler >/dev/null 2>&1 || true
docker compose ps --format '{{.Service}} {{.Status}}' backend queue scheduler
echo "Envoi d'e-mails activé depuis $ADDRESS (sauvegarde : .env.avant-mail)."
echo "Un e-mail de test vient d'être envoyé à $ADDRESS : vérifiez sa réception dans le webmail Hostinger."
