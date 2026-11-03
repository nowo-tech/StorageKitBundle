#!/bin/sh
set -e

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		if [ -f /app/Caddyfile.dev ]; then
			cp /app/Caddyfile.dev /etc/caddy/Caddyfile
		elif [ -f /etc/frankenphp/Caddyfile.dev ]; then
			cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		fi
		;;
	worker)
		if [ -f /app/Caddyfile ]; then
			cp /app/Caddyfile /etc/caddy/Caddyfile
		fi
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

git config --global --add safe.directory /app 2>/dev/null || true
git config --global --add safe.directory /var/storage-kit-bundle 2>/dev/null || true

mkdir -p /app/var/cache /app/var/log /app/var
chmod -R 777 /app/var 2>/dev/null || true

if [ ! -f /app/.env ]; then
	cp /app/.env.example /app/.env 2>/dev/null || true
fi

if [ ! -f /app/vendor/autoload_runtime.php ]; then
	echo "📦 vendor not found, running composer install..."
	if ! composer install --no-interaction --working-dir=/app; then
		echo "❌ composer install failed."
		echo "   Waiting so the container stays up for debugging..."
		exec tail -f /dev/null
	fi
	echo "✅ Composer install done."
fi

if [ -d /app/vendor ]; then
	echo "📦 Creating database and schema if needed..."
	php /app/bin/console doctrine:database:create --if-not-exists --no-interaction 2>/dev/null || true
	echo "📦 Generating Halite encryption key if missing..."
	php /app/bin/console doctrine:encrypt:generate-secret-key --no-interaction 2>/dev/null || \
		php /app/bin/console doctrine:encrypt:generate-secret-key 2>/dev/null || true
	php /app/bin/console doctrine:schema:update --force --no-interaction 2>/dev/null || true
	echo "📦 Creating the demo buckets and the instance storage profile if needed..."
	php /app/bin/console app:demo:seed --no-interaction 2>/dev/null || true
	echo "✅ Database, encryption key and buckets ready."
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
