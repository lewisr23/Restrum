#!/usr/bin/env bash
#
# Build and start the production stack. Run it from anywhere on the server:
#
#   ./docker/deploy.sh
#
# It exists mostly so that --env-file is never forgotten. Compose uses that
# file for two separate jobs - filling the ${...} placeholders in the compose
# file, and handing configuration to the containers - and omitting it does
# not fail loudly, it just substitutes empty strings into half the stack.
#
# Migrations are not run here. The app container's entrypoint runs them on
# boot, and only in that container, so they happen exactly once per deploy
# rather than four times in parallel.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env.production ]; then
    echo "No .env.production. Copy .env.production.example and fill it in." >&2
    exit 1
fi

if [ ! -f certs/restrum.uk.pem ] || [ ! -f certs/restrum.uk.key ]; then
    echo "No origin certificate in certs/. See docs/deploy.md, step 5." >&2
    exit 1
fi

compose() {
    docker compose --env-file .env.production -f docker-compose.prod.yml "$@"
}

echo "Building and starting..."
compose up -d --build

# nginx resolves php-fpm's hostname once, when it starts, and then holds
# that address. Recreating the app container gives it a new IP, so an nginx
# that was left running from the previous deploy keeps dialling the old one
# and answers 502 to everything dynamic while still serving the static
# bundle happily - which reads as "the site is up but nothing works".
# Restarting it last costs a second and makes the deploy deterministic.
echo "Restarting nginx so it re-resolves the app container..."
compose restart nginx >/dev/null

# --build leaves the previous image dangling on every deploy, and 100GB of
# disk is small enough that a few months of those matter.
echo "Pruning old images..."
docker image prune -f >/dev/null

echo
compose ps
echo
echo "Done. To follow the logs:"
echo "  docker compose --env-file .env.production -f docker-compose.prod.yml logs -f app"
