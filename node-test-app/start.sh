#!/bin/bash
set -e
export DATABASE_URL="postgresql://${DATABASE_USERNAME}:${DATABASE_PASSWORD}@${DATABASE_HOST}:${DATABASE_PORT}/${DATABASE_PATH}"
export REDIS_URL="${CACHE_SCHEME:-redis}://${CACHE_HOST:-localhost}:${CACHE_PORT:-6379}"
export RABBITMQ_URL="${QUEUE_SCHEME:-amqp}://${QUEUE_USERNAME:-guest}:${QUEUE_PASSWORD:-guest}@${QUEUE_HOST:-localhost}:${QUEUE_PORT:-5672}/${QUEUE_PATH}"
exec node dist/app.js
