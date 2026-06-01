# WordPress integration test suite

These tests exercise the **WordPress side** of the plugin — the importer turning
parsed data into posts/taxonomies/meta, and the related templates — so they need
a real WordPress install. They complement the [golden-master parser
harness](../golden/README.md), which is WordPress-free and only covers
`parse_files()`.

- `tests/phpunit/tests/export/**` — parser output assertions
- `tests/phpunit/tests/import/file.php` — end-to-end importer test (posts, terms,
  `_wp-parser_*` meta)

## Baseline status

On the **old parser stack** (PHP 7.4, `phpdocumentor/reflection ~3.0`) the suite
is green:

```
PHPUnit 7.5.20 — OK (22 tests, 125 assertions)
```

This is the regression baseline. Every migration stage must keep it green (run on
PHP 7.4 until Stage 2 moves the floor to PHP 8.2 + PHPUnit 9).

## Running locally (wp-env / Docker)

Requires Docker and Node. The environment is defined in `.wp-env.json` (PHP 7.4,
plugin + Posts-to-Posts).

```bash
npm install                 # installs @wordpress/env
npm run test:phpunit:setup  # wp-env start + composer install (in the container)
npm run test:phpunit        # runs phpunit -c phpunit.xml.dist in tests-wordpress
```

Or drive `@wordpress/env` directly without the npm scripts:

```bash
npx @wordpress/env start
npx @wordpress/env run --env-cwd='wp-content/plugins/phpdoc-parser' tests-wordpress composer install
npx @wordpress/env run --env-cwd='wp-content/plugins/phpdoc-parser' tests-wordpress \
  vendor/phpunit/phpunit/phpunit -c phpunit.xml.dist
```

### Gotcha: GitHub HTTPS→SSH git rewrite

`wp-env start` clones `WordPress/wordpress-develop` over **HTTPS**. If your global
git config rewrites GitHub HTTPS to SSH (e.g.
`url."git@github.com:".insteadOf "https://github.com/"`) and outbound SSH port 22
is blocked, the clone fails with `Connection to ... port 22: Operation timed out`.

Neutralize the rewrite for that one command — your config files stay untouched:

```bash
GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null npx @wordpress/env start
```
