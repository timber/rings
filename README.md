# Timber Rings

[![Latest Version](https://img.shields.io/packagist/v/timber/rings.svg)](https://packagist.org/packages/timber/rings)
[![PHP unit tests](https://github.com/timber/rings/actions/workflows/php-unit-tests.yml/badge.svg)](https://github.com/timber/rings/actions/workflows/php-unit-tests.yml)
[![Coverage](https://codecov.io/gh/timber/rings/graph/badge.svg)](https://codecov.io/gh/timber/rings)

A [Query Monitor](https://querymonitor.com/) panel that shows you what's happening in your Timber templates.

## What it does

- Shows which templates are rendered and how long each takes
- Displays block-level profiling so you can spot slow spots
- Provides a DevTools-like inspector to find the template behind any element of the page

## Requirements

- PHP 8.3+
- [Query Monitor](https://wordpress.org/plugins/query-monitor/) 4.x, active
- [Timber](https://github.com/timber/timber) 2.x, with Twig 3.15+

## Installation

```bash
composer require --dev timber/rings
```

The package loads itself through Composer's autoloader: there's no plugin to activate.

## Usage

Just activate Query Monitor and look for the "Twig" panel. Click on the "inspector" icon at the top right of QM panel to inspect your templates.

The inspector works like browsers' ones, but for templates: hovering the page highlights the output of the template or block rendered there, with its size and the template line of the hovered tag. Clicking selects its row in the panel, which links to the template in your editor.

Hover a template or block row in the panel to highlight its output in the page, click it to scroll there.

Twig is only instrumented (profiled, annotated, marked) for users who can see Query Monitor, and not in AJAX, REST, cron and feed requests. Timber's cache (`Timber::render()`/`Timber::compile()` expiration, `{% cache %}` tag) is bypassed meanwhile, so instrumented output is never cached and served to others.

The inspector points to the exact template line of each element: when templates are compiled, the HTML tags written in them get a `data-twig="template.twig:line"` attribute. Only actual HTML/SVG elements and custom elements are annotated, never what's in `<script>`, `<style>`, comments or attribute values, nor HTML coming from variables.

It also relies on HTML comments (`<!--twig:start ...-->` / `<!--twig:end ...-->`) added around the output of each template and block. Only output starting with a tag gets them (not text, nor JavaScript in a `<script>`), and not templates named like `*.txt.twig`, `*.json.twig`, `*.xml.twig`, etc. Markers are not added when Twig's `use_yield` option is on, as they rely on output buffering.

If you use Twig's cache, clear it after updating this package: the cache isn't invalidated when the generated marker code or annotations change.

## Configuration

Rows are highlighted in the panel depending on their duration: the time cell above `QM_TWIG_WARN` (50 ms by default), the whole row above `QM_TWIG_EXPENSIVE` (100 ms by default). To change them, define the constants before the package is loaded, e.g. in `wp-config.php`:

```php
define('QM_TWIG_WARN', 20); // ms
define('QM_TWIG_EXPENSIVE', 50); // ms
```

## Filters

- `timber/rings/separate_twig_roots` (`bool $separate`): whether to show one root per Twig environment in the panel. Environments rendered from another one (e.g. `Timber::compile()` called while rendering a template) are nested under the template or block they were called from, so there's usually a single root, and no environment rows.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
