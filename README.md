# Compressly

Lightweight WordPress image optimization powered by ShortPixel. Built for agency fleet use across many client sites with centralized updates via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).

## Status

Phase 1 — scaffold and activation. Optimization logic ships in Phase 2. See `COMPRESSLY_SPEC.md` for the full roadmap.

## Requirements

- PHP 7.4 or newer
- WordPress 6.0 or newer
- A ShortPixel API key

## Installation

### From a release zip (production)

1. Download `compressly.zip` from the [latest release](https://github.com/JafetGoodAgency/compressly/releases).
2. In WP Admin, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin.
4. Go to **Settings → Compressly** and enter your ShortPixel API key.

The release zip includes a pre-built `vendor/` directory, so no Composer install is required on the target site.

### From source (development)

```bash
git clone https://github.com/JafetGoodAgency/compressly.git
cd compressly
composer install
```

Then symlink or copy the folder into a WordPress install's `wp-content/plugins/` directory and activate the plugin from WP Admin.

## Development

### Layout

- `compressly.php` — plugin bootstrap (headers, constants, hooks).
- `src/` — PSR-4 autoloaded source under the `GoodAgency\Compressly\` namespace.
- `uninstall.php` — data removal on delete, gated by the `remove_data_on_uninstall` setting.
- `vendor/` — Composer dependencies (gitignored locally, bundled in release zips).
- `.github/workflows/release.yml` — CI that builds and attaches `compressly.zip` on a published release.

### Development API key

Use this key for local development and testing only. **Do not commit real keys** and **do not deploy this key to production**:

```
JiuZJdc11GgL1RuW1777
```

The plugin reads the active key from `wp_options` at runtime; no constants or environment variables are required.

### Coding standards

- PSR-12 for pure PHP classes, WordPress Coding Standards for WP-specific glue.
- `declare(strict_types=1)` at the top of every PHP file.
- Type declarations on every method signature; return types where possible.
- Every class file carries a file-level docblock describing its purpose.

## Release process

Releases are automated via GitHub Actions.

1. Bump the `Version:` header in `compressly.php` and the `COMPRESSLY_VERSION` constant.
2. Commit and push to `main`.
3. Create a new GitHub Release with a semantic version tag (e.g. `v1.0.0`).
4. The `release.yml` workflow runs `composer install --no-dev --optimize-autoloader`, packages the plugin as `compressly.zip`, and attaches it to the triggering release.
5. Sites running the plugin detect the new version via Plugin Update Checker within the update-check window and offer the update in WP Admin.

## License

Proprietary — internal use by GoodAgency.
