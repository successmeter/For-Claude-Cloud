#!/bin/sh
# api/docker/entrypoint.sh
# Roles: web | worker | scheduler | migrate. Configuration comes from the environment (ECS injects
# secrets from AWS Secrets Manager), so caches are built at start, not at image build.
set -eu

cache() {
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
}

case "${1:-web}" in
  web)
    cache
    exec frankenphp php-server --listen ":${PORT:-8080}" --root public/
    ;;
  worker)
    cache
    exec php artisan queue:work --sleep=1 --tries=3 --max-time=3600
    ;;
  scheduler)
    cache
    exec php artisan schedule:work
    ;;
  migrate)
    # One-off task at each deploy: schema changes as the owner, then the app role's password.
    php artisan migrate --database=pgsql --force
    exec php artisan db:sync-app-role
    ;;
  *)
    exec "$@"
    ;;
esac
