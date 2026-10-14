# Extroverting

WordPress theme for the Extroverting multi-city local events calendar: `theme.json` (v3) with a custom palette, raw-PHP templates under `templates/`, template parts under `parts/`. Templates render through `template-shell.php`, which wraps each fragment in a full document.

Designed to be used with [extroverting-core](https://github.com/smcnally/extroverting-core), which registers the CPTs these templates render; dashboard/account templates assume it is active.

## Install

The repo root **is** the theme directory — clone it straight into `wp-content/themes/` (e.g. `extroverting-theme/`) and activate "Extroverting". Subsequent updates are a plain `git pull`.

## Development

```bash
composer install
composer phpstan   # PHPStan level 5 with WordPress stubs
composer test      # PHPUnit via wp-phpunit (needs a local MySQL; see tests/wp-config.php)
```

CI (`.github/workflows/ci.yml`) runs PHPStan + PHPUnit on PHP 8.1 and 8.3 for every push to `main` and PR.

## License

GPL-2.0-or-later
