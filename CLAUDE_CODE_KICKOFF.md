# Claude Code Kickoff Prompt

Paste this as your first message to Claude Code in the repo folder.

---

You are building a WordPress plugin called **Compressly** following the full specification in `COMPRESSLY_SPEC.md` (already in this repo root).

Read the spec in full before writing any code. Then execute **Phase 1 only**. Do not start Phase 2 until I verify Phase 1 works.

## Phase 1 Deliverables

1. Main plugin file `compressly.php` with proper WordPress plugin headers:
   - Plugin Name: Compressly
   - Description: Lightweight image optimization powered by ShortPixel
   - Version: 1.0.0
   - Author: GoodAgency
   - Author URI: https://github.com/JafetGoodAgency
   - Text Domain: compressly
   - Requires PHP: 7.4
   - Requires at least: 6.0

2. `composer.json` with PSR-4 autoloading under namespace `GoodAgency\Compressly` and these dependencies:
   - `shortpixel/shortpixel-php`
   - `yahnis-elsts/plugin-update-checker`

3. Activation hook that:
   - Creates the `{prefix}_compressly_log` custom table per the spec schema
   - Seeds default options in `wp_options` using the defaults defined in `src/Settings/Defaults.php`
   - Logs activation failure gracefully (no WSOD) if table creation fails

4. Deactivation hook that clears plugin transients (but leaves options and the log table intact)

5. `uninstall.php` that removes all plugin data ONLY if user opted in via a setting (`compressly_remove_data_on_uninstall`), otherwise preserves everything

6. Basic settings page at **Settings → Compressly** with a single field: API Key (text input, nonce-protected, sanitized, validated format). Use placeholder value `JiuZJdc11GgL1RuW1777` documented as dev key, never commit real keys.

7. `README.md` at repo root with installation instructions and development setup

8. `.gitignore` excluding `vendor/`, `.DS_Store`, `node_modules/`, `.env`

9. `.github/workflows/release.yml` that builds `compressly.zip` on release tag push (runs `composer install --no-dev`, excludes dev files, attaches zip to release)

## Constraints

- **Do not build Phase 2 functionality yet** (no optimization logic, no SDK integration beyond the Composer requirement)
- Follow the file structure in the spec exactly
- Every class file must have a file-level docblock explaining its purpose
- Use type declarations on all method signatures
- Use WordPress's Settings API for the settings page (not custom form handling)
- Run `composer install` at the end so `vendor/` exists locally (but gitignored)

## Verification Before Phase 2

After Phase 1, I will test:
- Plugin activates on a clean WordPress install without errors
- `{prefix}_compressly_log` table exists in the database
- Settings → Compressly page loads and saves API key
- No PHP notices/warnings with `WP_DEBUG=true`
- GitHub Actions workflow builds a valid zip on release tag push

Confirm you understand the phase gating before you begin. Ask clarifying questions about anything in the spec that is ambiguous before writing code.
