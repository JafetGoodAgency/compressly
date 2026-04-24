# Compressly — WordPress Image Optimization Plugin

## Project Overview

Build a lightweight, production-grade WordPress plugin called **Compressly** that optimizes images using the ShortPixel API. This plugin will be deployed across 80+ client sites hosted on Kinsta and must support centralized updates without manually logging into each site.

**Plugin name**: Compressly
**Slug**: `compressly`
**Text domain**: `compressly`
**Minimum PHP**: 7.4
**Minimum WordPress**: 6.0
**License**: Proprietary (internal use)
**Author**: GoodAgency

---

## Core Functionality

### 1. Automatic Image Optimization on Upload

When a user uploads an image through the WordPress media library or any editor:

- Hook into `wp_generate_attachment_metadata` with priority 100 (so WordPress generates thumbnails first, then we compress everything)
- Send the original image and all generated thumbnail sizes to the ShortPixel API
- Replace local files with optimized versions
- Generate WebP versions alongside originals (enabled by default, toggleable in settings)
- Store optimization metadata in post_meta so we never re-process the same image
- Must handle JPEG, PNG, GIF, and WebP uploads gracefully

### 2. Bulk Optimization Panel

Admin page under **Media → Compressly** with:

- Stats dashboard: total images, optimized count, unoptimized count, total bytes saved
- "Start Bulk Optimization" button
- Real-time progress bar (AJAX-based, processes in batches of 5 images at a time to avoid timeouts)
- Pause / Resume / Cancel controls
- List of failed images with retry button
- Ability to restore originals from backup

### 3. Settings Panel

Admin page under **Settings → Compressly** with these options:

**Compression Level** (radio buttons):
- Lossless (pixel-perfect, minimal reduction)
- Lossy (default, best balance)
- Glossy (lossy but better for photography)

**WebP Generation**:
- Enabled by default (toggle)
- Serve WebP via `<picture>` tag replacement in `the_content` filter (safer than htaccess across unknown server configs)

**Automatic Resize**:
- Enabled by default
- Configurable max width (default: 2560px)
- Configurable max height (default: 2560px)
- Preserves aspect ratio, only downsizes (never upsizes)

**Lazy Loading**:
- Enabled by default (toggle)
- Uses native `loading="lazy"` attribute on img tags
- Skips first image on the page (LCP optimization) via a configurable threshold
- Can be disabled entirely via toggle

**Backup Originals**:
- Enabled by default (toggle, but warn strongly if disabled)
- Stores originals in `/wp-content/uploads/compressly-backup/` mirroring the original folder structure

**Skip Threshold**:
- Skip files smaller than X KB (default: 10KB)
- Prevents wasting API credits on tiny images

**Exclusions**:
- Text area to exclude file patterns (e.g., `/uploads/do-not-compress/*`)
- Checkbox to exclude specific thumbnail sizes from optimization

**Kill Switch**:
- Master toggle: "Pause all optimization"
- When active, plugin behaves as if not installed (no hooks fire)
- Critical for emergency rollback across fleet

**API Key**:
- Single text input for ShortPixel API key
- **Use this fake key for development**: `JiuZJdc11GgL1RuW1777`
- Validate key by calling ShortPixel's account status endpoint on save
- Show remaining credits / quota on dashboard
- Support for API KEY aliases (ShortPixel's agency feature for multi-site use)

---

## ShortPixel API Integration

### Approach

Use the **official ShortPixel PHP SDK** via Composer:

```
composer require shortpixel/shortpixel-php
```

Bundle the `vendor/` folder in the plugin release (standard practice for WP plugins that use Composer libs).

### API Details

- **Endpoint base**: `https://api.shortpixel.com/v2/`
- **Authentication**: API key passed with each request
- **Compression levels**: 0 = lossless, 1 = lossy, 2 = glossy
- **Output formats**: Request WebP generation in the same API call (saves credits)

### Basic Usage Pattern

```php
use ShortPixel\ShortPixel;

ShortPixel::setKey( $api_key );

$result = ShortPixel::fromFile( $file_path )
    ->optimize( $compression_level )
    ->wait( 60 )
    ->toFiles( dirname( $file_path ), null, $backup_dir );
```

### Error Handling Requirements

Wrap every API call in try/catch. Handle these specific cases:

- **API key invalid**: Log error, notify admin via dashboard notice, stop processing
- **Quota exceeded**: Pause bulk processing, notify admin, resume when quota resets
- **Network timeout**: Retry up to 3 times with exponential backoff (2s, 4s, 8s)
- **Image too large (>100MB)**: Skip and log, don't fail the whole batch
- **Compressed file larger than original**: Keep original, mark as "already optimized"
- **Compressed file smaller than 5% of original**: Reject as corrupt, keep original

### Credit Tracking

- Query `/api-status.php` on plugin load (cached for 1 hour via transient) to show remaining credits
- Display warning banner when credits drop below 10% remaining
- Auto-pause bulk processing when credits hit zero

---

## Safety & Data Integrity

### Non-Negotiable Requirements

1. **Always preserve originals** (unless user explicitly disables backup in settings)
2. **Atomic writes**: Write optimized file to temp location first, verify it opened correctly, then rename over original. Never write directly over source files.
3. **Size sanity checks**:
   - If compressed output > original size, keep original
   - If compressed output < 5% of original, reject as corrupt, keep original
4. **Try/catch all external operations**: API calls, file I/O, database writes
5. **Idempotent operations**: Re-running optimization on an already-optimized image must be a no-op
6. **Metadata versioning**: Store a version number with the optimization flag so settings changes can trigger re-optimization without breaking existing optimized images

### Custom DB Table

Create one custom table on activation:

**Table: `{prefix}_compressly_log`**
- `id` bigint unsigned auto_increment primary key
- `attachment_id` bigint unsigned, indexed
- `status` enum: 'pending', 'success', 'failed', 'skipped'
- `original_size` int (bytes)
- `optimized_size` int (bytes)
- `webp_size` int nullable
- `error_message` text nullable
- `processed_at` datetime
- `plugin_version` varchar(20)

This provides a full audit log and powers the dashboard stats.

### Post Meta Keys

- `_compressly_optimized` (bool)
- `_compressly_version` (string, matches plugin version at time of optimization)
- `_compressly_original_size` (int)
- `_compressly_optimized_size` (int)
- `_compressly_webp_path` (string, relative path)
- `_compressly_backup_path` (string, relative path)

---

## Architecture & File Structure

```
compressly/
├── compressly.php                    # Main plugin file, headers, bootstrap only
├── uninstall.php                     # Clean removal (with opt-in data preservation)
├── composer.json
├── composer.lock
├── vendor/                           # Composer dependencies (bundled in release)
├── readme.txt                        # WP plugin readme
├── CHANGELOG.md
├── LICENSE
├── assets/
│   ├── css/
│   │   └── admin.css
│   ├── js/
│   │   ├── admin.js
│   │   └── bulk-processor.js
│   └── images/
│       └── logo.svg
├── languages/
│   └── compressly.pot
├── src/
│   ├── Plugin.php                    # Main plugin class, singleton
│   ├── Activator.php                 # Activation hook logic (create table, defaults)
│   ├── Deactivator.php               # Deactivation hook (clean transients)
│   ├── Settings/
│   │   ├── SettingsPage.php          # Renders Settings → Compressly
│   │   ├── OptionsManager.php        # Get/set wrapper for wp_options
│   │   └── Defaults.php              # Default values
│   ├── Optimization/
│   │   ├── UploadHandler.php         # Hooks into wp_generate_attachment_metadata
│   │   ├── Optimizer.php             # Core optimization logic
│   │   ├── ShortPixelClient.php      # Wrapper around SDK with error handling
│   │   ├── FileValidator.php         # Size/type/sanity checks
│   │   └── BackupManager.php         # Handles originals backup/restore
│   ├── Bulk/
│   │   ├── BulkPage.php              # Renders Media → Compressly
│   │   ├── BulkProcessor.php         # AJAX handlers for batch processing
│   │   └── QueueManager.php          # Tracks pending/processing/done state
│   ├── Frontend/
│   │   ├── LazyLoad.php              # Adds loading="lazy" via the_content filter
│   │   └── WebPServer.php            # Picture tag replacement for WebP delivery
│   ├── Admin/
│   │   ├── Dashboard.php             # Stats widget
│   │   ├── Notices.php               # Admin notices (low credits, errors, etc.)
│   │   └── AssetManager.php          # Enqueue admin CSS/JS
│   ├── Database/
│   │   ├── Schema.php                # Table creation/migration
│   │   └── LogRepository.php         # Queries against compressly_log table
│   ├── Updater/
│   │   └── GitHubUpdater.php         # Integration with Plugin Update Checker
│   ├── Integrations/
│   │   └── WooCommerce.php           # WC-specific hooks (only loads if WC active)
│   └── Support/
│       ├── Logger.php                # Central logging (to custom table + WP debug log)
│       ├── Security.php              # Nonce, capability, sanitization helpers
│       └── Container.php             # Simple dependency container
└── tests/
    ├── phpunit.xml
    └── unit/
        ├── OptimizerTest.php
        └── FileValidatorTest.php
```

### Coding Standards

- **PSR-4 autoloading** via Composer (namespace: `GoodAgency\Compressly`)
- Follow **WordPress Coding Standards (WPCS)** for WP-specific code (hooks, filters, DB)
- Follow **PSR-12** for pure PHP classes
- Type declarations on all method signatures (PHP 7.4 syntax)
- Return type declarations where possible
- No direct `$_POST`, `$_GET`, `$_REQUEST` access, always through sanitization helpers
- No `eval()`, no `extract()`, no dynamic class instantiation from user input
- All strings through `__()` / `_e()` with text domain `compressly`
- Use `wp_enqueue_script` / `wp_enqueue_style` properly with versioning
- Defer to WP's HTTP API (`wp_remote_post`) for any raw HTTP calls not handled by SDK

### Security Checklist

- All form submissions protected by nonces (`wp_nonce_field`, `check_admin_referer`)
- All AJAX endpoints verify nonce and capability
- Capability check: `manage_options` for settings, `upload_files` for bulk operations
- All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)
- All input sanitized (`sanitize_text_field`, `absint`, `sanitize_key`)
- Prepared statements for all custom DB queries (`$wpdb->prepare`)
- File paths validated against `wp_upload_dir()` to prevent directory traversal
- API key stored in `wp_options` (not constants) but never output in HTML after save (show `••••••••` placeholder)
- No file operations outside the WordPress uploads directory

---

## Self-Hosted Update System (CRITICAL)

This is the most important part for agency use. The plugin must support centralized updates across 80+ sites.

### Implementation: Plugin Update Checker Library

Use the battle-tested **Plugin Update Checker** library by YahnisElsts:

```
composer require yahnis-elsts/plugin-update-checker
```

### Setup

Point the updater at the **GitHub repository**:

**Repository**: `https://github.com/JafetGoodAgency/compressly`

```php
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/JafetGoodAgency/compressly/',
    __FILE__,
    'compressly'
);

$updateChecker->setBranch( 'main' );
$updateChecker->getVcsApi()->enableReleaseAssets();

// If the repo is private, add authentication via a constant in wp-config.php:
// define( 'COMPRESSLY_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' );
if ( defined( 'COMPRESSLY_GITHUB_TOKEN' ) ) {
    $updateChecker->setAuthentication( COMPRESSLY_GITHUB_TOKEN );
}
```

**Decision point**: if the repo stays public, no token needed. If private, you'll need to add a GitHub personal access token to each site's `wp-config.php`. For 80+ sites, **public repo is significantly easier to manage**. The plugin code contains no secrets (API keys live in `wp_options` per-site), so public is safe.

### Release Workflow

1. Developer pushes changes to `main` branch
2. Developer creates a GitHub release with a semantic version tag (e.g., `v1.2.3`)
3. GitHub Actions workflow (included in repo) builds the plugin zip with `vendor/` included and attaches it to the release
4. Within 12 hours, all 80 sites see the update in their WP admin
5. Kinsta's auto-update feature (or WP core auto-updates) applies the update during low-traffic windows

### GitHub Actions Workflow

Include `.github/workflows/release.yml` that:

1. Triggers on new release tag push
2. Runs `composer install --no-dev --optimize-autoloader`
3. Creates a clean zip (excludes `tests/`, `.git`, `node_modules`, `.github`, `composer.json`, `composer.lock`)
4. Names the zip `compressly.zip`
5. Attaches the zip to the GitHub release as a release asset

### Update Channels

Support two update channels via a constant in `wp-config.php`:

- `define('COMPRESSLY_UPDATE_CHANNEL', 'stable');` (default, tracks `main` branch releases)
- `define('COMPRESSLY_UPDATE_CHANNEL', 'beta');` (tracks `beta` branch for staged rollouts)

This enables the pattern of deploying to 2-3 test sites first (set to beta), verifying for a week, then promoting to stable for the fleet.

### Rollback Safety

Store the last 3 plugin versions in `/wp-content/compressly-backups/` on update. Provide an admin-only button to roll back to a previous version if a deployment breaks something.

---

## Admin UI Design

### Design Principles

- **Modern, minimal, fast**. No jQuery UI, no Bootstrap, no bloat.
- **Native WordPress admin styling** as the base (uses existing CSS variables from WP 6.x)
- **Custom accent color** matching ShortPixel/Compressly branding (use a distinctive blue/teal, NOT WordPress default blue)
- **Large, clear typography**, generous whitespace
- **Mobile-friendly** (responsive down to 360px, since some agency work happens from phones)

### Framework

- **Vanilla JavaScript** for admin interactions (no React, no Vue, keeps plugin lightweight)
- Use WordPress's built-in `wp.ajax` or `fetch` API
- CSS written by hand, using CSS custom properties for theming
- Total JS + CSS footprint target: **under 50KB combined** (minified)

### Specific Screens

**Dashboard widget** (shown on WP Dashboard):
- Total images optimized
- Total bytes saved
- Credits remaining
- Link to bulk optimization

**Bulk Optimization page** (`Media → Compressly`):
- Large progress ring/circle visualization
- Stats cards: total / optimized / pending / failed
- Primary action button: "Start Bulk Optimization"
- Live-updating batch status
- Collapsible "Recent errors" section

**Settings page** (`Settings → Compressly`):
- Tabbed interface: **General**, **Compression**, **Delivery**, **Advanced**
- Save button sticky at bottom of viewport
- Inline validation (API key format, numeric fields)
- Tooltips on hover for technical options

### Performance Requirements

- Admin JS loads **only on Compressly admin pages**, not globally
- Frontend code (lazy load, WebP serving) must add **zero additional HTTP requests**
- No jQuery dependency on frontend
- All admin AJAX responses under 500ms for non-batch operations

---

## Frontend Behavior

### Zero Impact When Idle

When no settings require frontend intervention (lazy load disabled, WebP disabled), the plugin must add **zero bytes** to the frontend output. Hook registration should be conditional.

### Lazy Loading Implementation

When enabled:
- Filter `the_content`, `post_thumbnail_html`, and `wp_get_attachment_image_attributes`
- Add `loading="lazy"` to `<img>` tags
- Skip the first N images on the page (configurable, default 1) to preserve LCP
- Skip images already marked with `loading="eager"` or `loading="lazy"`
- Skip images inside `<picture>` tags already handled (avoid double-processing)

### WebP Delivery Implementation

When enabled:
- Filter `the_content` to wrap `<img>` tags in `<picture>` elements:

```html
<picture>
    <source srcset="image.webp" type="image/webp">
    <img src="image.jpg" alt="...">
</picture>
```

- Only wrap images that have a corresponding `.webp` file (check via the stored meta)
- Preserve all existing attributes on the `<img>` tag
- Never modify images from external domains (only local `/wp-content/uploads/` URLs)
- Handle srcset correctly, generate matching webp srcset when applicable

---

## WooCommerce Compatibility

**Scope**: ~5% of the 80+ site fleet runs WooCommerce. The plugin must work correctly on those stores without breaking product images, but WooCommerce-specific features should not bloat the core plugin.

### Requirements

- **Detect WooCommerce**: check `class_exists( 'WooCommerce' )` on init. If absent, skip all WC-specific code entirely (zero overhead for the 95% of non-WC sites).
- **WC thumbnail sizes**: automatically include `woocommerce_thumbnail`, `woocommerce_single`, and `woocommerce_gallery_thumbnail` in the optimization pipeline when WC is active.
- **Product gallery**: ensure variation images and gallery images hit the same optimization path as regular attachments. WooCommerce uses standard `wp_generate_attachment_metadata`, so this should work out of the box, but must be explicitly tested.
- **Regenerate Thumbnails compatibility**: when another plugin regenerates thumbnails, Compressly must re-optimize them (use the `_compressly_version` meta to detect when re-optimization is needed).
- **No WC admin UI changes**: do not add columns to the Products list, do not modify WC settings screens. Keep the admin surface area unchanged.

### Out of scope for v1.0

- WC-specific bulk actions ("Optimize all product images" button on Products list)
- Integration with WC's own image regeneration
- Variable product image optimization settings per variation

These can come in v1.1 if real-world usage shows they're needed.

### Isolation

All WC-specific code must live in a single file (`src/Integrations/WooCommerce.php`) and only load when WC is detected. The rest of the plugin must remain WC-agnostic.

---

## Testing Requirements

### Manual QA Checklist (Must Pass Before v1.0 Release)

Upload and verify:
- [ ] 5MB JPEG photograph → compresses to under 1MB, WebP generated, thumbnails all optimized
- [ ] 10MB PNG with transparency → transparency preserved, WebP generated
- [ ] 500KB JPEG (already small) → skipped per threshold setting
- [ ] Animated GIF → handled per settings (skip by default, API handles if enabled)
- [ ] Corrupt/truncated image → gracefully fails, original preserved, logged
- [ ] Upload with plugin Kill Switch enabled → no processing happens
- [ ] Bulk optimize 100 images → completes without timeout, progress bar accurate
- [ ] Bulk optimize with API quota exhausted mid-batch → pauses gracefully

Activate/deactivate:
- [ ] Activate on fresh WP install → no errors, table created, defaults set
- [ ] Deactivate → no orphaned data, transients cleared
- [ ] Uninstall → all plugin data removed (with opt-in preservation checkbox)

Frontend:
- [ ] Lazy load adds `loading="lazy"` to images (skipping first image)
- [ ] WebP served via `<picture>` tag on supported browsers
- [ ] Disabling lazy load removes all frontend hooks
- [ ] No PHP warnings/notices with `WP_DEBUG` enabled

Updates:
- [ ] Plugin Update Checker detects new GitHub release within update check window
- [ ] Update applies cleanly, preserves settings and optimized image metadata
- [ ] Rollback button restores previous version

WooCommerce compatibility (only ~5% of fleet, but must work reliably on those sites):
- [ ] Product images optimize on upload
- [ ] Product variation images optimize on upload
- [ ] Product gallery images optimize
- [ ] Bulk optimize includes all WooCommerce-registered image sizes (`woocommerce_thumbnail`, `woocommerce_single`, `woocommerce_gallery_thumbnail`)
- [ ] Works alongside Regenerate Thumbnails plugin without double-processing
- [ ] No conflict with common WC performance plugins (Perfmatters, WP Rocket)

Multisite:
- [ ] Network activate works across all subsites
- [ ] Settings per-site (not network-wide) unless explicitly configured

### Automated Tests

Minimum PHPUnit coverage for:
- `FileValidator` (all sanity check edge cases)
- `OptionsManager` (defaults, get/set, sanitization)
- `LogRepository` (CRUD operations)

---

## Development Workflow for Claude Code

### Build in Phases, Verify Each Phase

**Phase 1**: Scaffold + activation
- Main plugin file with proper headers
- Activation hook creates custom table
- Basic settings page (just API key input)
- Verify: plugin activates without errors on fresh WP install

**Phase 2**: ShortPixel integration
- Wire up the SDK
- Implement `Optimizer` class
- Hook into `wp_generate_attachment_metadata`
- Verify: upload an image, see it compressed, WebP generated, original backed up

**Phase 3**: Settings page (full)
- All settings options
- Tabbed interface
- Proper sanitization/validation
- Verify: settings save, persist, and actually change behavior

**Phase 4**: Bulk optimization
- Bulk page UI
- AJAX batch processor
- Progress tracking
- Verify: bulk optimize 50 images without timeout

**Phase 5**: Frontend features
- Lazy loading
- WebP serving via picture tags
- Verify: inspect page HTML, confirm attributes and tags

**Phase 6**: Plugin Update Checker
- Wire up PUC library
- Set up GitHub repo
- Create GitHub Actions workflow
- Verify: push a test release, see update notification in WP admin

**Phase 7**: Polish
- Admin dashboard widget
- Credit tracking display
- Admin notices for errors
- CSS refinement
- Kill switch

### Constraints for Claude Code

- **Never commit the real API key**. Use `JiuZJdc11GgL1RuW1777` for development, plugin should read from `wp_options` in production.
- **Every file should have a header comment** explaining its purpose.
- **No "god classes"**. Keep classes single-responsibility, under 300 lines ideally.
- **Use dependency injection**, not static/singleton patterns (except for the main Plugin class).
- **Write self-explanatory code**. Minimal inline comments. Good naming > comments.
- **When in doubt about a WordPress API, check the official docs, don't guess.**

---

## Deliverables

At completion, the repository should contain:

1. Working plugin code following the structure above
2. `README.md` with installation instructions, development setup, release process
3. `CHANGELOG.md` with initial `v1.0.0` entry
4. `.github/workflows/release.yml` for automated releases
5. `composer.json` with all dependencies properly declared
6. A GitHub release for `v1.0.0` with `compressly.zip` attached
7. Manual QA checklist results documented in `docs/qa-v1.0.md`

---

## Out of Scope for v1.0

Do NOT build these in the initial version, keep scope tight:

- CDN integration (ShortPixel has CDN but we'll add that in v1.1 if needed)
- AVIF generation (v1.1)
- Image metadata (EXIF) stripping options (rely on API defaults)
- Custom thumbnail size management
- Image optimization for non-media-library images (theme images, etc.)
- Multi-language support beyond `.pot` file generation (translation is future work)
- Central dashboard across multiple sites (that's a separate product)

---

## API Key for Development

**Development API Key (safe to use, will be replaced before production):**

```
JiuZJdc11GgL1RuW1777
```

Do not commit any real API keys to the repository. The settings page should handle this via `wp_options` and the plugin should fetch it at runtime.

---

## Success Criteria

The plugin is considered complete when:

1. All 7 development phases are complete and verified
2. Manual QA checklist passes 100%
3. Plugin installs cleanly on a fresh Kinsta-hosted WordPress site
4. GitHub Actions successfully builds and releases `v1.0.0`
5. Update mechanism verified end-to-end (push `v1.0.1` test release, see it appear in WP admin)
6. Plugin runs with zero PHP warnings/notices on `WP_DEBUG=true`
7. Frontend Lighthouse score unaffected vs plugin not installed (target: ±2 points tolerance)

---

## Questions to Resolve During Build

The repo is confirmed: `https://github.com/JafetGoodAgency/compressly`

Leave TODOs in the code for these decisions that still require input:

- Branding colors/logo (pending design, use placeholder teal `#0EA5A4` for now)
- Final pricing tier / API key alias configuration for production rollout
- Whether to publish to private Composer registry or keep GitHub-only (recommend GitHub-only for simplicity)
