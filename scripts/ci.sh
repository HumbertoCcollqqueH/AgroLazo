#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -e .env || -e application/.env ]]; then
    echo 'CI requires a fresh checkout without local environment files.' >&2
    exit 1
fi
python3 - <<'PY'
from pathlib import Path
import secrets, base64
db = secrets.token_hex(32)
redis = secrets.token_hex(32)
Path('.env').write_text(f'DB_DATABASE=agroconecta\nDB_USERNAME=agroconecta\nDB_PASSWORD={db}\nREDIS_PASSWORD={redis}\n')
values = {
    'APP_ENV': 'testing', 'APP_KEY': 'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),
    'DB_PASSWORD': db, 'REDIS_PASSWORD': redis, 'PRODUCER_LOOKUP_KEY': secrets.token_hex(32),
}
template = Path('application/.env.example').read_text()
Path('application/.env').write_text('\n'.join(k+'='+values.get(k,v) if '=' in line and not line.startswith('#') else line for line in template.splitlines() for k,v in [line.split('=',1) if '=' in line else ('','')])+'\n')
PY
compose=(docker compose -p agroconecta-ci -f docker-compose.yml)
trap '"${compose[@]}" down --remove-orphans' EXIT
"${compose[@]}" build app
"${compose[@]}" up -d --wait postgres redis
"${compose[@]}" exec -T postgres sh -ec 'createdb -U "$POSTGRES_USER" agroconecta_testing'
"${compose[@]}" run --rm --no-deps --user root app mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/framework/testing bootstrap/cache
"${compose[@]}" run --rm --no-deps --user root -e COMPOSER_ALLOW_SUPERUSER=1 app composer install --no-interaction --prefer-dist
"${compose[@]}" run --rm --no-deps --user root app vendor/bin/pint --format agent
git diff --exit-code -- application
"${compose[@]}" --profile tools run --rm --no-deps assets sh -ec 'npm ci --ignore-scripts --no-audit && npm run build && npm audit --audit-level=high'
"${compose[@]}" run --rm --user root app php artisan test --compact
"${compose[@]}" run --rm --no-deps --user root -e COMPOSER_ALLOW_SUPERUSER=1 app composer audit --locked --no-interaction
docker build -f docker/production/Dockerfile --target app -t "agroconecta-release:${GITHUB_SHA:-ci}" .
docker build -f docker/production/Dockerfile --target web -t "agroconecta-web:${GITHUB_SHA:-ci}" .
