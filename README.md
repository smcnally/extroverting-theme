# Extroverting

WordPress block theme for Extroverting, a multi-city local events calendar product.

## What this is

Extroverting is a WordPress block theme that provides the front-end for the Extroverting multi-city local events calendar. It uses `theme.json` (schema v3) with a custom palette and ships templates under `templates/` (index, page, single city and event, dashboard, account login/register, legal pages, and age gate) plus template parts under `parts/`. `functions.php` enqueues the component styles and front-end script, localizes an `EXT` object for the REST API, and provides render helpers such as event rows and empty/loading/error states.

The theme is designed to be used together with [smcnally/extroverting-core](https://github.com/smcnally/extroverting-core), which registers the custom post types and templates the theme renders; it also works standalone as a block theme, though the dashboard and account templates assume the core plugin is active.

## Installation

Copy the `extroverting/` subdirectory into your site's `wp-content/themes/` directory, then activate the "Extroverting" theme from the WordPress appearance screen.

## Development

Install dependencies and run the checks:

```bash
composer install
composer phpstan
composer test
```

`composer phpstan` runs PHPStan at level 5 with the `szepeviktor/phpstan-wordpress` extension. `composer test` runs PHPUnit via wp-phpunit; the test bootstrap symlinks the theme into the test WordPress install and activates it as `WP_DEFAULT_THEME`.

Continuous integration is defined in `.github/workflows/ci.yml`. On push to `main` and on pull requests it runs PHPStan (level 5) and PHPUnit (wp-phpunit with a MySQL service) on PHP 8.1 and 8.3.

## License

GPL-2.0-or-later
