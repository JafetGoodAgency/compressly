<?php
/**
 * Admin-notice surface for Compressly errors.
 *
 * Reads transients set by the optimizer when an authorization or quota
 * failure occurs and renders WP admin notices so the administrator is
 * alerted at the next page load. Phase 2 only emits the critical ones
 * (invalid API key, quota exhausted); dashboard notices (low credits,
 * etc.) are layered on in Phase 7.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Admin;

use GoodAgency\Compressly\Support\Security;

final class Notices {

    public const TRANSIENT_API_KEY_INVALID = 'compressly_error_api_key_invalid';
    public const TRANSIENT_QUOTA_EXCEEDED  = 'compressly_error_quota_exceeded';

    public function register(): void {
        add_action( 'admin_notices', [ $this, 'render' ] );
    }

    public function render(): void {
        if ( ! Security::user_can_manage() ) {
            return;
        }

        if ( get_transient( self::TRANSIENT_API_KEY_INVALID ) ) {
            $this->render_notice(
                'error',
                __( 'Compressly: your ShortPixel API key was rejected. Please verify it under Settings → Compressly.', 'compressly' )
            );
        }

        if ( get_transient( self::TRANSIENT_QUOTA_EXCEEDED ) ) {
            $this->render_notice(
                'warning',
                __( 'Compressly: your ShortPixel quota has been exceeded. Optimization is paused until the quota resets.', 'compressly' )
            );
        }
    }

    private function render_notice( string $severity, string $message ): void {
        $class = 'notice notice-' . ( $severity === 'error' ? 'error' : 'warning' ) . ' is-dismissible';
        printf(
            '<div class="%1$s"><p><strong>%2$s</strong> %3$s</p></div>',
            esc_attr( $class ),
            esc_html__( 'Compressly', 'compressly' ),
            esc_html( $message )
        );
    }
}
