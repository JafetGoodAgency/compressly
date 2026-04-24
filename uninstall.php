<?php
/**
 * Uninstall handler.
 *
 * WordPress runs this file when the user deletes the plugin. We only
 * remove plugin data if the user opted in via the
 * `remove_data_on_uninstall` setting; otherwise everything is left
 * intact so a reinstall picks up where it left off.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$compressly_option_name = 'compressly_settings';
$compressly_settings    = get_option( $compressly_option_name, [] );

if ( ! is_array( $compressly_settings ) ) {
    $compressly_settings = [];
}

if ( empty( $compressly_settings['remove_data_on_uninstall'] ) ) {
    return;
}

global $wpdb;

$compressly_table = $wpdb->prefix . 'compressly_log';
$wpdb->query( "DROP TABLE IF EXISTS {$compressly_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( $compressly_option_name );

$compressly_transient_prefixes = [
    '_transient_compressly_',
    '_transient_timeout_compressly_',
    '_site_transient_compressly_',
    '_site_transient_timeout_compressly_',
];
foreach ( $compressly_transient_prefixes as $compressly_prefix ) {
    $compressly_like = $wpdb->esc_like( $compressly_prefix ) . '%';
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $compressly_like
        )
    );
}

$compressly_meta_keys = [
    '_compressly_optimized',
    '_compressly_version',
    '_compressly_original_size',
    '_compressly_optimized_size',
    '_compressly_webp_path',
    '_compressly_backup_path',
];
foreach ( $compressly_meta_keys as $compressly_meta_key ) {
    $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $compressly_meta_key ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}
