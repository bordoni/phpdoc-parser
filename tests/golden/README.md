# Golden-master parser harness

A **characterization test** that pins the parser's output so it can be rewritten
safely. The plugin's whole downstream (the importer, the DevHub theme, the
Posts-to-Posts relationships) consumes only the array returned by
`WP_Parser\parse_files()`. The migration's #1 rule is that the rewritten parser
must reproduce that array **key-for-key**.

This harness captures the **old** parser's output as JSON snapshots, then asserts
the current parser still matches them.

```
bin/generate-golden.php          # writes snapshots from the installed parser
tests/golden/golden.php          # shared corpus + normalize + JSON helpers
tests/golden/bootstrap.php       # standalone (WordPress-free) PHPUnit bootstrap
tests/golden/test-golden-master.php  # the comparison test
tests/golden/snapshots/*.json    # committed baseline (the oracle)
phpunit-golden.xml.dist          # config for this suite
```

The corpus is every `tests/source/*.php` fixture plus every `tests/**/*.inc`
fixture, each parsed on its own. `parse_files()` is WordPress-free, so nothing
here needs the WP test framework.

## 1. Capture the baseline (do this once, on the OLD stack)

The old stack (`phpdocumentor/reflection ~3.0`, `nikic/php-parser 1.x`) floods
PHP 8.x with deprecations/warnings and produces unreliable output, so the
baseline must be captured on **PHP 7.4**. The installed `vendor/` is pure PHP and
version-agnostic, so install it on the host and just run the generator inside a
PHP 7.4 container:

```bash
composer install --no-dev          # installs the OLD locked parser stack (skips phpunit ^7)
bin/generate-golden-docker.sh      # runs bin/generate-golden.php inside php:7.4-cli
```

The helper reuses the host's `vendor/` and writes files as your user (no Docker
root-owned files). It is equivalent to:

```bash
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app \
  php:7.4-cli php bin/generate-golden.php
```

Then commit `tests/golden/snapshots/`. **Never regenerate these against the new
parser** — that would erase the oracle.

## 2. Run the test (any time, on whatever stack is installed)

```bash
composer install
./vendor/bin/phpunit -c phpunit-golden.xml.dist
```

- On the **old** stack the test passes trivially (parser vs. its own snapshot) —
  this proves the harness is wired correctly before any code changes.
- During the **rewrite** every dropped field, changed type, or reordered entry
  becomes a hard failure naming the exact fixture.

## 3. Intentional changes

If the rewrite *intentionally* changes output (e.g. adding class-constant
visibility, issue #224), regenerate the affected snapshot, **review the JSON diff
in the commit**, and call it out in the PR. The snapshots are reviewed artifacts,
not throwaway fixtures.
