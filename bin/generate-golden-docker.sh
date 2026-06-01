#!/usr/bin/env bash
#
# Regenerate the golden-master snapshots on PHP 7.4 — the last PHP version the old
# parser (phpdocumentor/reflection ~3.0 + nikic/php-parser 1.x) runs on cleanly.
# On PHP 8.x that stack emits a flood of deprecations/warnings and produces
# unreliable output, so the baseline MUST be captured on 7.4.
#
# Reuses the host's already-installed vendor/ (pure PHP, version-agnostic), so no
# Composer or build tooling is needed inside the container.
#
# Prereq:  composer install --no-dev      # installs the OLD locked parser stack
# Usage:   bin/generate-golden-docker.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f vendor/phpdocumentor/reflection/composer.json ]; then
	echo "ERROR: old parser not installed. Run: composer install --no-dev" >&2
	exit 1
fi

exec docker run --rm \
	-u "$(id -u):$(id -g)" -e HOME=/tmp \
	-v "$PWD":/app -w /app \
	php:7.4-cli php bin/generate-golden.php
