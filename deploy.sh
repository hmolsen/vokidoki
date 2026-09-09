#!/usr/bin/env bash
#
# Deployment per rsync ueber SSH.
#
#   VT_SSH=benutzer@server.de VT_PATH=/var/www/vokabeln ./deploy.sh
#
# Nicht uebertragen werden: config.php (Zugangsdaten liegen nur auf dem Server),
# vendor/ (wird dort per composer erzeugt) und die generierten Icons.
# Ausgeschlossene Dateien werden von --delete nicht angeruehrt.

set -euo pipefail

: "${VT_SSH:?VT_SSH fehlt, z. B. VT_SSH=benutzer@server.de}"
: "${VT_PATH:?VT_PATH fehlt, z. B. VT_PATH=/var/www/vokabeln}"

echo "Uebertrage nach ${VT_SSH}:${VT_PATH} ..."

rsync -az --delete --human-readable --progress \
    --exclude '.git/' \
    --exclude '.gitignore' \
    --exclude 'config.php' \
    --exclude 'vendor/' \
    --exclude 'composer.phar' \
    --exclude 'deploy.sh' \
    --exclude 'storage/icons/*' \
    ./ "${VT_SSH}:${VT_PATH}/"

echo
echo "Abhaengigkeiten auf dem Server aktualisieren ..."
ssh "${VT_SSH}" "cd '${VT_PATH}' && composer install --no-dev --optimize-autoloader --no-interaction"

echo
echo "Fertig. Selbsttest aufrufen: <deine-domain>/admin/selfcheck.php"
