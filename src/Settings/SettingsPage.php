<?php
/**
 * Renders and handles the `Settings → Compressly` admin page.
 *
 * Uses the WordPress Settings API end-to-end: register_setting handles
 * nonces, capability checks, and persistence on submission to
 * options.php, while sanitize() validates the incoming values. Phase 1
 * exposes only the API key field; later phases extend the tabbed UI.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Settings;

use GoodAgency\Compressly\Support\Security;

final class SettingsPage {

    public const MENU_SLUG     = 'compressly';
    private const OPTION_GROUP = 'compressly_settings_group';
    private const SECTION_API  = 'compressly_section_api';

    private const API_KEY_PATTERN = '/^[A-Za-z0-9]{20}$/';

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

        add_settings_section(
            self::SECTION_API,
            __( 'ShortPixel API', 'compressly' ),
            [ $this, 'render_section_intro' ],
            self::MENU_SLUG
        );

        add_settings_field(
            'api_key',
            __( 'API Key', 'compressly' ),
            [ $this, 'render_api_key_field' ],
            self::MENU_SLUG,
            self::SECTION_API,
            [ 'label_for' => 'compressly_api_key' ]
        );
    }

    public function render_section_intro(): void {
        echo '<p>' . esc_html__(
            'Enter your ShortPixel API key. You can get one from shortpixel.com.',
            'compressly'
        ) . '</p>';
    }

    public function render_api_key_field(): void {
        $value = (string) $this->options->get( 'api_key', '' );
        printf(
            '<input type="text" id="compressly_api_key" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="off" spellcheck="false" pattern="[A-Za-z0-9]{20}" maxlength="20" />',
            esc_attr( Defaults::OPTION_NAME ),
            esc_attr( $value )
        );
        echo '<p class="description">' . esc_html__(
            '20-character alphanumeric key. Use JiuZJdc11GgL1RuW1777 for local development only — never in production.',
            'compressly'
        ) . '</p>';
    }

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

        if ( array_key_exists( 'api_key', $input ) ) {
            $api_key = sanitize_text_field( (string) $input['api_key'] );

            if ( $api_key === '' || preg_match( self::API_KEY_PATTERN, $api_key ) === 1 ) {
                $clean['api_key'] = $api_key;
            } else {
                add_settings_error(
                    Defaults::OPTION_NAME,
                    'compressly_api_key_invalid',
                    __( 'API key must be exactly 20 alphanumeric characters.', 'compressly' ),
                    'error'
                );
            }
        }

        return $clean;
    }

    public function render(): void {
        if ( ! Security::user_can_manage() ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Compressly', 'compressly' ) . '</h1>';
        settings_errors( Defaults::OPTION_NAME );
        echo '<form action="options.php" method="post">';
        settings_fields( self::OPTION_GROUP );
        do_settings_sections( self::MENU_SLUG );
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
