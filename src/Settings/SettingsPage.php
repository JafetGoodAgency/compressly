<?php
/**
 * Renders and handles the `Settings → Compressly` admin page.
 *
 * Tabbed UI (General / Compression / Delivery / Advanced) backed by
 * the WordPress Settings API. All four tabs render inside a single
 * form so one Save click commits every changed value. Each tab is a
 * distinct "page" slug in Settings API terms; do_settings_sections()
 * is called once per tab inside its own panel div, and the JS in
 * assets/js/admin.js toggles which panel is visible.
 *
 * Sanitize is the single source of truth — it dispatches per known
 * key, validates each field's type/range, and runs the live API key
 * check. Unknown keys are dropped; missing keys keep their stored
 * value (so a tampered POST cannot wipe a setting).
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Settings;

use GoodAgency\Compressly\Optimization\ApiKeyValidator;
use GoodAgency\Compressly\Support\Security;

final class SettingsPage {

    public const MENU_SLUG     = 'compressly';
    private const OPTION_GROUP = 'compressly_settings_group';

    public const TAB_GENERAL     = 'general';
    public const TAB_COMPRESSION = 'compression';
    public const TAB_DELIVERY    = 'delivery';
    public const TAB_ADVANCED    = 'advanced';

    /** Boolean settings that toggle behaviour. */
    private const BOOL_KEYS = [
        'webp_enabled',
        'resize_enabled',
        'lazy_load_enabled',
        'backup_originals',
        'kill_switch',
        'remove_data_on_uninstall',
    ];

    /** Numeric settings: key => [min, max]. */
    private const NUMERIC_RANGES = [
        'resize_max_width'     => [ 1, 10000 ],
        'resize_max_height'    => [ 1, 10000 ],
        'lazy_load_skip_count' => [ 0, 20 ],
        'skip_threshold_kb'    => [ 0, 10000 ],
    ];

    private OptionsManager $options;

    public function __construct( OptionsManager $options ) {
        $this->options = $options;
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_menu(): void {
        add_options_page(
            __( 'Compressly', 'compressly' ),
            __( 'Compressly', 'compressly' ),
            Security::MANAGE_CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            Defaults::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => Defaults::all(),
            ]
        );

        $this->register_general_tab();
        $this->register_compression_tab();
        $this->register_delivery_tab();
        $this->register_advanced_tab();
    }

    private function register_general_tab(): void {
        $page = $this->page_slug( self::TAB_GENERAL );

        add_settings_section(
            'compressly_section_api',
            __( 'ShortPixel API', 'compressly' ),
            [ $this, 'render_section_api_intro' ],
            $page
        );
        add_settings_field( 'api_key', __( 'API Key', 'compressly' ), [ $this, 'render_field_api_key' ], $page, 'compressly_section_api', [ 'label_for' => 'compressly_api_key' ] );

        add_settings_section(
            'compressly_section_status',
            __( 'Plugin Status', 'compressly' ),
            '__return_false',
            $page
        );
        add_settings_field( 'kill_switch', __( 'Kill Switch', 'compressly' ), [ $this, 'render_field_kill_switch' ], $page, 'compressly_section_status', [ 'label_for' => 'compressly_kill_switch' ] );
    }

    private function register_compression_tab(): void {
        $page = $this->page_slug( self::TAB_COMPRESSION );

        add_settings_section( 'compressly_section_quality', __( 'Quality', 'compressly' ), '__return_false', $page );
        add_settings_field( 'compression_level', __( 'Compression Level', 'compressly' ), [ $this, 'render_field_compression_level' ], $page, 'compressly_section_quality' );

        add_settings_section( 'compressly_section_output', __( 'Output Formats', 'compressly' ), '__return_false', $page );
        add_settings_field( 'webp_enabled', __( 'WebP Generation', 'compressly' ), [ $this, 'render_field_webp_enabled' ], $page, 'compressly_section_output', [ 'label_for' => 'compressly_webp_enabled' ] );

        add_settings_section( 'compressly_section_resize', __( 'Automatic Resize', 'compressly' ), [ $this, 'render_section_resize_intro' ], $page );
        add_settings_field( 'resize_enabled', __( 'Resize Large Uploads', 'compressly' ), [ $this, 'render_field_resize_enabled' ], $page, 'compressly_section_resize', [ 'label_for' => 'compressly_resize_enabled' ] );
        add_settings_field( 'resize_max_width', __( 'Max Width', 'compressly' ), [ $this, 'render_field_resize_max_width' ], $page, 'compressly_section_resize', [ 'label_for' => 'compressly_resize_max_width' ] );
        add_settings_field( 'resize_max_height', __( 'Max Height', 'compressly' ), [ $this, 'render_field_resize_max_height' ], $page, 'compressly_section_resize', [ 'label_for' => 'compressly_resize_max_height' ] );

        add_settings_section( 'compressly_section_threshold', __( 'Skip Threshold', 'compressly' ), '__return_false', $page );
        add_settings_field( 'skip_threshold_kb', __( 'Minimum File Size (KB)', 'compressly' ), [ $this, 'render_field_skip_threshold_kb' ], $page, 'compressly_section_threshold', [ 'label_for' => 'compressly_skip_threshold_kb' ] );
    }

    private function register_delivery_tab(): void {
        $page = $this->page_slug( self::TAB_DELIVERY );

        add_settings_section( 'compressly_section_lazy', __( 'Lazy Loading', 'compressly' ), [ $this, 'render_section_lazy_intro' ], $page );
        add_settings_field( 'lazy_load_enabled', __( 'Enable Lazy Loading', 'compressly' ), [ $this, 'render_field_lazy_load_enabled' ], $page, 'compressly_section_lazy', [ 'label_for' => 'compressly_lazy_load_enabled' ] );
        add_settings_field( 'lazy_load_skip_count', __( 'Eager-Load First N Images', 'compressly' ), [ $this, 'render_field_lazy_load_skip_count' ], $page, 'compressly_section_lazy', [ 'label_for' => 'compressly_lazy_load_skip_count' ] );
    }

    private function register_advanced_tab(): void {
        $page = $this->page_slug( self::TAB_ADVANCED );

        add_settings_section( 'compressly_section_backup', __( 'Backup Originals', 'compressly' ), '__return_false', $page );
        add_settings_field( 'backup_originals', __( 'Keep Original Files', 'compressly' ), [ $this, 'render_field_backup_originals' ], $page, 'compressly_section_backup', [ 'label_for' => 'compressly_backup_originals' ] );

        add_settings_section( 'compressly_section_exclusions', __( 'Exclusions', 'compressly' ), '__return_false', $page );
        add_settings_field( 'exclusion_patterns', __( 'Exclude File Patterns', 'compressly' ), [ $this, 'render_field_exclusion_patterns' ], $page, 'compressly_section_exclusions', [ 'label_for' => 'compressly_exclusion_patterns' ] );
        add_settings_field( 'excluded_thumbnail_sizes', __( 'Skip Thumbnail Sizes', 'compressly' ), [ $this, 'render_field_excluded_thumbnail_sizes' ], $page, 'compressly_section_exclusions' );

        add_settings_section( 'compressly_section_uninstall', __( 'Uninstall Behaviour', 'compressly' ), '__return_false', $page );
        add_settings_field( 'remove_data_on_uninstall', __( 'Remove All Data On Uninstall', 'compressly' ), [ $this, 'render_field_remove_data_on_uninstall' ], $page, 'compressly_section_uninstall', [ 'label_for' => 'compressly_remove_data_on_uninstall' ] );
    }

    public function render(): void {
        if ( ! Security::user_can_manage() ) {
            return;
        }

        $labels = [
            self::TAB_GENERAL     => __( 'General', 'compressly' ),
            self::TAB_COMPRESSION => __( 'Compression', 'compressly' ),
            self::TAB_DELIVERY    => __( 'Delivery', 'compressly' ),
            self::TAB_ADVANCED    => __( 'Advanced', 'compressly' ),
        ];

        echo '<div class="wrap compressly-settings">';
        echo '<h1>' . esc_html__( 'Compressly', 'compressly' ) . '</h1>';
        settings_errors( Defaults::OPTION_NAME );

        echo '<nav class="nav-tab-wrapper" role="tablist" aria-label="' . esc_attr__( 'Compressly settings tabs', 'compressly' ) . '">';
        $first = true;
        foreach ( $labels as $slug => $label ) {
            $classes = 'nav-tab' . ( $first ? ' nav-tab-active' : '' );
            printf(
                '<a href="#%1$s" class="%2$s" role="tab" data-compressly-tab="%1$s" aria-selected="%3$s" aria-controls="compressly-tab-%1$s" tabindex="%4$s">%5$s</a>',
                esc_attr( $slug ),
                esc_attr( $classes ),
                $first ? 'true' : 'false',
                $first ? '0' : '-1',
                esc_html( $label )
            );
            $first = false;
        }
        echo '</nav>';

        echo '<form action="options.php" method="post">';
        settings_fields( self::OPTION_GROUP );

        $first = true;
        foreach ( $labels as $slug => $label ) {
            $classes = 'compressly-tab-panel' . ( $first ? ' is-active' : '' );
            printf(
                '<div id="compressly-tab-%1$s" class="%2$s" role="tabpanel" aria-labelledby="compressly-tab-%1$s">',
                esc_attr( $slug ),
                esc_attr( $classes )
            );
            do_settings_sections( $this->page_slug( $slug ) );
            echo '</div>';
            $first = false;
        }

        echo '<div class="compressly-save-bar">';
        submit_button( __( 'Save changes', 'compressly' ), 'primary', 'submit', false );
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    // ---------- Section intros ----------

    public function render_section_api_intro(): void {
        echo '<p>' . esc_html__( 'Enter your ShortPixel API key. The key is verified against ShortPixel on save.', 'compressly' ) . '</p>';
    }

    public function render_section_resize_intro(): void {
        echo '<p>' . esc_html__( 'Resize uploads larger than the limits below. Aspect ratio is always preserved and images are never upscaled.', 'compressly' ) . '</p>';
    }

    public function render_section_lazy_intro(): void {
        echo '<p>' . esc_html__( 'Native loading="lazy" is added to img tags in post content. The first N images are left eager so the LCP is not delayed.', 'compressly' ) . '</p>';
    }

    // ---------- Field renderers ----------

    public function render_field_api_key(): void {
        $value = (string) $this->options->get( 'api_key', '' );
        printf(
            '<input type="password" id="compressly_api_key" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="off" spellcheck="false" pattern="[A-Za-z0-9]{20}" maxlength="20" />',
            esc_attr( Defaults::OPTION_NAME ),
            esc_attr( $value )
        );
        echo '<p class="description">' . esc_html__( '20-character alphanumeric key. Use JiuZJdc11GgL1RuW1777 for local development only — never in production.', 'compressly' ) . '</p>';
    }

    public function render_field_kill_switch(): void {
        $checked = (bool) $this->options->get( 'kill_switch', false );
        $this->render_checkbox( 'kill_switch', $checked, __( 'Pause all optimization', 'compressly' ) );
        echo '<p class="description">' . esc_html__( 'When enabled, Compressly behaves as if the plugin is not installed: no upload hooks fire, no cron events run. Use for emergency rollback across the fleet.', 'compressly' ) . '</p>';
        if ( $checked ) {
            echo '<p class="compressly-warning">' . esc_html__( 'The kill switch is currently active. Optimization is paused on this site.', 'compressly' ) . '</p>';
        }
    }

    public function render_field_compression_level(): void {
        $current = (string) $this->options->get( 'compression_level', Defaults::COMPRESSION_GLOSSY );
        $levels  = [
            Defaults::COMPRESSION_LOSSLESS => [ __( 'Lossless', 'compressly' ), __( 'Pixel-perfect, smallest savings.', 'compressly' ) ],
            Defaults::COMPRESSION_LOSSY    => [ __( 'Lossy', 'compressly' ), __( 'Aggressive, biggest savings; may soften photographic detail.', 'compressly' ) ],
            Defaults::COMPRESSION_GLOSSY   => [ __( 'Glossy', 'compressly' ), __( 'Tuned for photography — preserves detail with strong savings (recommended default).', 'compressly' ) ],
        ];
        echo '<fieldset class="compressly-radio-group">';
        foreach ( $levels as $value => [ $label, $hint ] ) {
            printf(
                '<label><input type="radio" name="%1$s[compression_level]" value="%2$s" %3$s /> <strong>%4$s</strong> — <span class="description">%5$s</span></label>',
                esc_attr( Defaults::OPTION_NAME ),
                esc_attr( $value ),
                checked( $current, $value, false ),
                esc_html( $label ),
                esc_html( $hint )
            );
        }
        echo '</fieldset>';
    }

    public function render_field_webp_enabled(): void {
        $this->render_checkbox(
            'webp_enabled',
            (bool) $this->options->get( 'webp_enabled', true ),
            __( 'Generate a .webp alongside every optimized image', 'compressly' )
        );
        echo '<p class="description">' . esc_html__( 'Browser delivery via <picture> tag replacement is configured separately on the Delivery tab.', 'compressly' ) . '</p>';
    }

    public function render_field_resize_enabled(): void {
        $this->render_checkbox(
            'resize_enabled',
            (bool) $this->options->get( 'resize_enabled', true ),
            __( 'Downscale uploads larger than the limits below', 'compressly' )
        );
    }

    public function render_field_resize_max_width(): void {
        $this->render_number( 'resize_max_width', (int) $this->options->get( 'resize_max_width', 2560 ), 1, 10000 );
        echo ' ' . esc_html__( 'pixels', 'compressly' );
    }

    public function render_field_resize_max_height(): void {
        $this->render_number( 'resize_max_height', (int) $this->options->get( 'resize_max_height', 2560 ), 1, 10000 );
        echo ' ' . esc_html__( 'pixels', 'compressly' );
    }

    public function render_field_skip_threshold_kb(): void {
        $this->render_number( 'skip_threshold_kb', (int) $this->options->get( 'skip_threshold_kb', 10 ), 0, 10000 );
        echo ' ' . esc_html__( 'KB', 'compressly' );
        echo '<p class="description">' . esc_html__( 'Files smaller than this are skipped entirely. Prevents wasting API credits on tiny images.', 'compressly' ) . '</p>';
    }

    public function render_field_lazy_load_enabled(): void {
        $this->render_checkbox(
            'lazy_load_enabled',
            (bool) $this->options->get( 'lazy_load_enabled', true ),
            __( 'Add loading="lazy" to images in post content', 'compressly' )
        );
    }

    public function render_field_lazy_load_skip_count(): void {
        $this->render_number( 'lazy_load_skip_count', (int) $this->options->get( 'lazy_load_skip_count', 1 ), 0, 20 );
        echo '<p class="description">' . esc_html__( 'How many images at the top of each page to leave eager-loaded so the largest contentful paint is not delayed.', 'compressly' ) . '</p>';
    }

    public function render_field_backup_originals(): void {
        $checked = (bool) $this->options->get( 'backup_originals', true );
        $this->render_checkbox( 'backup_originals', $checked, __( 'Keep a copy of every original under /wp-content/uploads/compressly-backup/', 'compressly' ) );
        if ( ! $checked ) {
            echo '<p class="compressly-danger">' . esc_html__( 'Backups are disabled. Compressed files will permanently overwrite originals — there is no way to restore them. Strongly recommended to keep this enabled.', 'compressly' ) . '</p>';
        }
    }

    public function render_field_exclusion_patterns(): void {
        $value = (string) $this->options->get( 'exclusion_patterns', '' );
        printf(
            '<textarea id="compressly_exclusion_patterns" name="%1$s[exclusion_patterns]" rows="6" maxlength="5000" spellcheck="false">%2$s</textarea>',
            esc_attr( Defaults::OPTION_NAME ),
            esc_textarea( $value )
        );
        echo '<p class="description">' . wp_kses(
            __( 'One <code>fnmatch</code> pattern per line. Matched against both the absolute path and the path relative to the uploads root. Example: <code>/uploads/do-not-compress/*</code>.', 'compressly' ),
            [ 'code' => [] ]
        ) . '</p>';
    }

    public function render_field_excluded_thumbnail_sizes(): void {
        $current  = (array) $this->options->get( 'excluded_thumbnail_sizes', [] );
        $current  = array_map( 'strval', $current );
        $sizes    = $this->registered_image_sizes();
        $name     = sprintf( '%s[excluded_thumbnail_sizes][]', Defaults::OPTION_NAME );

        echo '<fieldset class="compressly-checkbox-list">';
        // Sentinel: ensures the key is always present in the POST so an
        // empty selection is "no exclusions" rather than "field absent".
        printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name ) );

        if ( $sizes === [] ) {
            echo '<p>' . esc_html__( 'No registered thumbnail sizes were detected.', 'compressly' ) . '</p>';
        } else {
            foreach ( $sizes as $size ) {
                printf(
                    '<label><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
                    esc_attr( $name ),
                    esc_attr( $size ),
                    checked( in_array( $size, $current, true ), true, false ),
                    esc_html( $size )
                );
            }
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__( 'Selected sizes are skipped during optimization. The original and -scaled variants are always processed.', 'compressly' ) . '</p>';
    }

    public function render_field_remove_data_on_uninstall(): void {
        $this->render_checkbox(
            'remove_data_on_uninstall',
            (bool) $this->options->get( 'remove_data_on_uninstall', false ),
            __( 'When the plugin is deleted, also drop the log table, options, post meta, and transients', 'compressly' )
        );
        echo '<p class="description">' . esc_html__( 'Off by default. Settings and the audit log persist through deactivation; turning this on opts in to a full purge when the plugin is uninstalled.', 'compressly' ) . '</p>';
    }

    // ---------- Render primitives ----------

    private function render_checkbox( string $key, bool $checked, string $label ): void {
        $name = sprintf( '%s[%s]', Defaults::OPTION_NAME, $key );
        $id   = 'compressly_' . $key;
        // Hidden 0 lands first so an unchecked checkbox still posts a falsey value.
        printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $name ) );
        printf(
            '<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
            esc_attr( $id ),
            esc_attr( $name ),
            checked( $checked, true, false ),
            esc_html( $label )
        );
    }

    private function render_number( string $key, int $value, int $min, int $max ): void {
        $name = sprintf( '%s[%s]', Defaults::OPTION_NAME, $key );
        $id   = 'compressly_' . $key;
        printf(
            '<input type="number" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1" />',
            esc_attr( $id ),
            esc_attr( $name ),
            (int) $value,
            (int) $min,
            (int) $max
        );
    }

    // ---------- Sanitize ----------

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize( $input ): array {
        $current = $this->options->all();

        if ( ! is_array( $input ) ) {
            return $current;
        }

        $clean = $current;

        $this->sanitize_api_key( $input, $clean );
        $this->sanitize_compression_level( $input, $clean );
        $this->sanitize_booleans( $input, $clean );
        $this->sanitize_numerics( $input, $clean );
        $this->sanitize_exclusion_patterns( $input, $clean );
        $this->sanitize_excluded_thumbnail_sizes( $input, $clean );

        return $clean;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     */
    private function sanitize_api_key( array $input, array &$clean ): void {
        if ( ! array_key_exists( 'api_key', $input ) ) {
            return;
        }

        $api_key = sanitize_text_field( (string) $input['api_key'] );

        if ( $api_key === '' ) {
            $clean['api_key'] = '';
            return;
        }

        $verdict = ApiKeyValidator::validate( $api_key );

        switch ( $verdict['result'] ) {
            case ApiKeyValidator::RESULT_VALID:
                $clean['api_key'] = $api_key;
                break;

            case ApiKeyValidator::RESULT_UNVERIFIED:
                $clean['api_key'] = $api_key;
                add_settings_error(
                    Defaults::OPTION_NAME,
                    'compressly_api_key_unverified',
                    $verdict['message'],
                    'warning'
                );
                break;

            case ApiKeyValidator::RESULT_INVALID:
            case ApiKeyValidator::RESULT_INVALID_FORMAT:
            default:
                add_settings_error(
                    Defaults::OPTION_NAME,
                    'compressly_api_key_invalid',
                    $verdict['message'],
                    'error'
                );
                break;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     */
    private function sanitize_compression_level( array $input, array &$clean ): void {
        if ( ! array_key_exists( 'compression_level', $input ) ) {
            return;
        }
        $allowed = [ Defaults::COMPRESSION_LOSSLESS, Defaults::COMPRESSION_LOSSY, Defaults::COMPRESSION_GLOSSY ];
        $value   = sanitize_key( (string) $input['compression_level'] );
        if ( in_array( $value, $allowed, true ) ) {
            $clean['compression_level'] = $value;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     */
    private function sanitize_booleans( array $input, array &$clean ): void {
        foreach ( self::BOOL_KEYS as $key ) {
            if ( array_key_exists( $key, $input ) ) {
                $clean[ $key ] = ! empty( $input[ $key ] );
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     */
    private function sanitize_numerics( array $input, array &$clean ): void {
        foreach ( self::NUMERIC_RANGES as $key => [ $min, $max ] ) {
            if ( ! array_key_exists( $key, $input ) ) {
                continue;
            }
            $value         = (int) $input[ $key ];
            $clean[ $key ] = max( $min, min( $max, $value ) );
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     */
    private function sanitize_exclusion_patterns( array $input, array &$clean ): void {
        if ( ! array_key_exists( 'exclusion_patterns', $input ) ) {
            return;
        }
        $value = sanitize_textarea_field( (string) $input['exclusion_patterns'] );
        if ( strlen( $value ) > 5000 ) {
            $value = substr( $value, 0, 5000 );
        }
        $clean['exclusion_patterns'] = $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $clean
     */
    private function sanitize_excluded_thumbnail_sizes( array $input, array &$clean ): void {
        if ( ! array_key_exists( 'excluded_thumbnail_sizes', $input ) ) {
            return;
        }
        $raw = is_array( $input['excluded_thumbnail_sizes'] ) ? $input['excluded_thumbnail_sizes'] : [];

        $registered = $this->registered_image_sizes();
        $cleaned    = [];
        foreach ( $raw as $candidate ) {
            $size = sanitize_key( (string) $candidate );
            if ( $size === '' ) {
                continue;
            }
            if ( in_array( $size, $registered, true ) ) {
                $cleaned[ $size ] = true;
            }
        }

        $clean['excluded_thumbnail_sizes'] = array_keys( $cleaned );
    }

    // ---------- Helpers ----------

    private function page_slug( string $tab ): string {
        return self::MENU_SLUG . '_tab_' . $tab;
    }

    /**
     * @return array<int, string>
     */
    private function registered_image_sizes(): array {
        if ( ! function_exists( 'get_intermediate_image_sizes' ) ) {
            return [];
        }
        $sizes = get_intermediate_image_sizes();
        if ( ! is_array( $sizes ) ) {
            return [];
        }
        $sizes = array_values( array_unique( array_map( 'strval', $sizes ) ) );
        sort( $sizes );
        return $sizes;
    }
}
