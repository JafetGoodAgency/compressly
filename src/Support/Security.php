<?php
/**
 * Shared security helpers (capabilities, nonces, sanitization).
 *
 * Phase 1 only exposes the capability constants used by the settings
 * page. Nonce and AJAX helpers will be fleshed out in Phase 2+ when
 * custom form endpoints and the bulk processor come online.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Support;

final class Security {

    public const MANAGE_CAPABILITY = 'manage_options';
    public const BULK_CAPABILITY   = 'upload_files';

    public static function user_can_manage(): bool {
        return current_user_can( self::MANAGE_CAPABILITY );
    }

    public static function verify_admin_nonce( string $action, string $nonce_name = '_wpnonce' ): bool {
        if ( ! isset( $_REQUEST[ $nonce_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return false;
        }
        $nonce = sanitize_text_field( wp_unslash( (string) $_REQUEST[ $nonce_name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return (bool) wp_verify_nonce( $nonce, $action );
    }
}
