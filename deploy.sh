#!/usr/bin/env bash
#
# Deployment auf den Server.
#
#   VT_SSH=benutzer@server.de VT_PATH=/var/www/vokabeln ./deploy.sh
#
# Nicht uebertragen werden: config.php (Zugangsdaten liegen nur auf dem Server),
# vendor/ (wird dort per composer erzeugt) und die generierten Icons.
#
# Es gibt zwei Wege, je nachdem was lokal installiert ist:
#   rsync  - uebertraegt nur Aenderungen und raeumt geloeschte Dateien weg
#   ssh    - Fallback ueber "git archive": uebertraegt den zuletzt committeten
#            Stand. Loescht nichts, was auf dem Server zusaetzlich liegt.

set -euo pipefail

: "${VT_SSH:?VT_SSH fehlt, z. B. VT_SSH=benutzer@server.de}"
: "${VT_PATH:?VT_PATH fehlt, z. B. VT_PATH=/var/www/vokabeln}"

cd "$(dirname "$0")"

if command -v rsync >/dev/null 2>&1; then
    echo "Uebertrage per rsync nach ${VT_SSH}:${VT_PATH} ..."
    # Ausgeschlossene Dateien ruehrt --delete nicht an.
    rsync -az --delete --human-readable \
        --exclude '.git/' \
        --exclude '.gitignore' \
        --exclude '.gitattributes' \
        --exclude 'config.php' \
        --exclude 'vendor/' \
        --exclude 'composer.phar' \
        --exclude 'storage/icons/*' \
        ./ "${VT_SSH}:${VT_PATH}/"
else
    echo "rsync nicht gefunden - nutze git archive ueber ssh."

    if ! git diff --quiet HEAD 2>/dev/null; then
        echo
        echo "Achtung: Es gibt nicht committete Aenderungen." >&2
        echo "Dieser Weg uebertraegt nur den letzten Commit." >&2
        echo "Erst committen, dann erneut deployen." >&2
        exit 1
    fi

    echo "Uebertrage Commit $(git rev-parse --short HEAD) nach ${VT_SSH}:${VT_PATH} ..."
    ssh "${VT_SSH}" "mkdir -p '${VT_PATH}'"
    # config.php, vendor/ und composer.phar stehen in .gitignore und sind
    # deshalb in git archive gar nicht erst enthalten.
    git archive --format=tar HEAD | ssh "${VT_SSH}" "tar -xf - -C '${VT_PATH}'"
fi

echo
echo "Abhaengigkeiten auf dem Server aktualisieren ..."
ssh "${VT_SSH}" "cd '${VT_PATH}' && composer install --no-dev --optimize-autoloader --no-interaction"

echo
echo "Fertig. Selbsttest aufrufen: https://<deine-domain>/admin/selfcheck.php"
