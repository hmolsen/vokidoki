#!/usr/bin/env bash
#
# Deployment auf den Server, per SSH.
#
#   VT_SSH=benutzer@server.de VT_PATH=/pfad/zum/webroot ./deploy.sh
#
# Der Webroot sieht danach so aus:
#
#   index.html, bilder/ ...   aus website/  - die Startseite
#   app/                      aus app/      - die Anwendung; vendor/ entsteht dort
#   daten/                    einmal aus daten-vorlage/, danach nie wieder
#
# app/ wird bei jedem Lauf vollstaendig abgeglichen, auch geloeschte Dateien
# verschwinden dort - ausser app/vendor/: Die Abhaengigkeiten legt composer
# auf dem Server an, am Ende dieses Skripts. Sonst liegt in app/ nichts, was
# ein Update ueberleben muss; config.php und storage/ stehen in daten/.
# Die Startseite wird nur ueberschrieben, nicht abgeglichen: Im Webroot
# liegen auch app/ und daten/, und ein --delete dort raeumte beides mit weg.
#
# Wer per FTP hochlaedt, macht dasselbe von Hand - siehe README, "Einrichtung".

set -euo pipefail

: "${VT_SSH:?VT_SSH fehlt, z. B. VT_SSH=benutzer@server.de}"
: "${VT_PATH:?VT_PATH fehlt, z. B. VT_PATH=/var/www/vokidoki}"

cd "$(dirname "$0")"

ssh "${VT_SSH}" "mkdir -p '${VT_PATH}/app'"

if command -v rsync >/dev/null 2>&1; then
    echo "app/ per rsync nach ${VT_SSH}:${VT_PATH}/app/ ..."
    rsync -az --delete --exclude '/vendor/' --human-readable app/ "${VT_SSH}:${VT_PATH}/app/"
    echo "Startseite nach ${VT_SSH}:${VT_PATH}/ ..."
    rsync -az --human-readable website/ "${VT_SSH}:${VT_PATH}/"
else
    # Ohne rsync (etwa Git Bash unter Windows): tar über ssh. Das
    # überschreibt, löscht aber nichts, was auf dem Server zusätzlich liegt.
    echo "rsync nicht gefunden - übertrage per tar über ssh."
    tar -C app --exclude ./vendor -cf - . | ssh "${VT_SSH}" "tar -xf - -C '${VT_PATH}/app'"
    tar -C website -cf - . | ssh "${VT_SSH}" "tar -xf - -C '${VT_PATH}'"
fi

# daten/ nur beim allerersten Mal - danach gehört der Ordner dem Server.
# Liegt er oberhalb des Webroots (vokidoki-daten/), gibt es nichts zu tun.
ssh "${VT_SSH}" "cd '${VT_PATH}' && if [ ! -d daten ] && [ ! -d ../vokidoki-daten ]; then
    mkdir -p daten && echo 'daten/ fehlt noch - lege die Vorlage an.'
fi"
if ssh "${VT_SSH}" "[ -d '${VT_PATH}/daten' ] && [ ! -f '${VT_PATH}/daten/config.php' ]"; then
    tar -C daten-vorlage -cf - . | ssh "${VT_SSH}" "tar -xf - -C '${VT_PATH}/daten'"
    echo
    echo "Jetzt auf dem Server daten/config.example.php als daten/config.php ausfüllen."
fi

echo
echo "Abhängigkeiten auf dem Server ..."
ssh "${VT_SSH}" "cd '${VT_PATH}/app' && composer install --no-dev --optimize-autoloader --no-interaction"

echo
echo "Fertig. Selbsttest aufrufen: https://<deine-domain>/app/admin/selfcheck.php"
