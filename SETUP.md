# Pressable Cache Management - Developer Notes

## GitHub Auto-Updates

This plugin uses [YahnisElsts/plugin-update-checker v5.6](https://github.com/YahnisElsts/plugin-update-checker)
to deliver automatic updates directly from GitHub to WordPress sites running the plugin.

The library is already bundled at `includes/plugin-update-checker/` for plugin runtime.
Composer is only needed for local development tooling such as linting.

## Development Tooling

Install the local QA tools from the plugin root:

```bash
composer install
```

Run WordPress coding standards and PHP compatibility checks:

```bash
composer lint
```

Auto-fix the issues PHPCS can safely rewrite:

```bash
composer lint:fix
```

Run PHPStan with WordPress-aware analysis, including WooCommerce and WP-CLI stubs:

```bash
composer stan
```

The ruleset lives in `phpcs.xml.dist` and currently runs:

- `WordPress-Extra`
- `PHPCompatibilityWP` with `testVersion` set to `8.1-`

The static-analysis config lives in `phpstan.neon.dist` and analyzes the plugin code at PHPStan level 5 while excluding the bundled third-party updater library under `includes/plugin-update-checker/`.

Third-party bundled code in `includes/plugin-update-checker/` is excluded from linting.

## How to Release an Update

1. Bump `Version:` in `pressable-cache-management.php`, for example `5.2.4`.
2. Update the `readme.txt` changelog.
3. Commit and push to `main`.
4. In GitHub, open Releases and draft a new release.
5. Tag it as `v5.2.4` with the `v` prefix matching the plugin version.
6. Attach the plugin `.zip` as a release asset.
7. Publish the release.

WordPress sites detect the new version within about 12 hours and show the standard
update notice. Clicking it installs exactly like a WordPress.org plugin.
