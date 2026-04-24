<?php
/**
 * Plugin Name:       Compressly
 * Plugin URI:        https://github.com/JafetGoodAgency/compressly
 * Description:       Lightweight image optimization powered by ShortPixel.
 * Version:           1.0.0
 * Author:            GoodAgency
 * Author URI:        https://github.com/JafetGoodAgency
 * License:           Proprietary
 * Text Domain:       compressly
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'COMPRESSLY_VERSION', '1.0.0' );
define( 'COMPRESSLY_PLUGIN_FILE', __FILE__ );
define( 'COMPRESSLY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COMPRESSLY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$compressly_autoload = COMPRESSLY_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! is_readable( $compressly_autoload ) ) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Compressly: Composer dependencies are missing. Run `composer install` in the plugin directory, or install the plugin from an official release zip.',
                'compressly'
            );
            echo '</p></div>';
        }
    );
    return;
}

require_once $compressly_autoload;

register_activation_hook( __FILE__, [ \GoodAgency\Compressly\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \GoodAgency\Compressly\Deactivator::class, 'deactivate' ] );

add_action(
    'plugins_loaded',
    static function (): void {
        \GoodAgency\Compressly\Plugin::instance()->boot();
    }
);
