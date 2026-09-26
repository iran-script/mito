#!/bin/sh
set -eu

cd /opt/mito
if [ ! -f .env ]; then
    umask 077
    app_key="$(openssl rand -base64 32 | tr -d '\n')"
    db_password="$(openssl rand -hex 32)"
    cat > .env <<EOF
APP_NAME=Mito
APP_ENV=production
APP_KEY=base64:${app_key}
APP_DEBUG=false
APP_URL=http://127.0.0.1:8081
LOG_CHANNEL=stderr
LOG_LEVEL=info
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=mito
DB_USERNAME=mito
DB_PASSWORD=${db_password}
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
MAIL_MAILER=log
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
EOF
    echo ENV_CREATED
else
    echo ENV_PRESERVED
fi
chmod 600 .env
