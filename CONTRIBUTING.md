# Contributing

## Setup

```bash
composer install
pnpm install
```

Query Monitor is installed as a WordPress plugin, in `wp-content/plugins/`: the tests load it from there, and PHPStan scans its directories as it isn't autoloaded.

## Tests

```bash
composer test
```

Tests run on [Mantle Testkit](https://mantle.alley.com/docs/testing) with SQLite: WordPress is downloaded and installed on the first run. The installation runs `php` from your `PATH`, so it must be the version Composer installed the dependencies with.

## Inspector script

`assets/inspector.js` is bundled with its dependencies (Floating UI) into `assets/dist/inspector.js`, with [esbuild](https://esbuild.github.io/). The bundle is committed, as the package is installed with Composer, which doesn't build it: rebuild it with every change to the script.

```bash
pnpm build
pnpm watch # Rebuild on changes
```

## Coding standards and static analysis

```bash
composer cs      # ECS, without changing anything
composer cs:fix  # Apply ECS's fixes
composer phpstan # PHPStan, at max level
composer rector  # Apply Rector's changes
composer lint    # ECS, Rector and PHPStan, without changing anything
```

Run `composer lint` before submitting a change: it should report nothing.

[GrumPHP](https://github.com/phpro/grumphp) runs these checks, plus `composer normalize` and a PHP syntax check, before each commit. Its Git hooks are installed by `composer install`; run `composer grump` to run them by hand.

## Conventions

Every PHP file declares `strict_types=1`, and classes are final (or abstract). PHPStan reports the ones that don't, with [ergebnis/phpstan-rules](https://github.com/ergebnis/phpstan-rules).
