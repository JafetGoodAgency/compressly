<?php
/**
 * Format and live-endpoint validation for ShortPixel API keys.
 *
 * Used at save time on the settings page. Does its own format check
 * first (cheap, no network), then hits ShortPixel's api-status.php
 * endpoint via wp_remote_get for a definitive verdict. Network or
 * unexpected-response errors are intentionally non-blocking — they
 * return an "unverified" result so the user can still save the key
 * when ShortPixel itself is having a bad day.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

final class ApiKeyValidator {

    public const RESULT_VALID          = 'valid';
    public const RESULT_INVALID        = 'invalid';
    public const RESULT_INVALID_FORMAT = 'invalid_format';
    public const RESULT_UNVERIFIED     = 'unverified';

    private const FORMAT_PATTERN = '/^[A-Za-z0-9]{20}$/';
    private const STATUS_URL     = 'https://api.shortpixel.com/v2/api-status.php';
    private const TIMEOUT_SEC    = 10;

    /**
     * @return array{result: string, message: string}
     */
    public static function validate( string $key ): array {
        if ( $key === '' ) {
            return [ 'result' => self::RESULT_VALID, 'message' => '' ];
        }

        if ( preg_match( self::FORMAT_PATTERN, $key ) !== 1 ) {
            return [
                'result'  => self::RESULT_INVALID_FORMAT,
                'message' => __( 'API key must be exactly 20 alphanumeric characters.', 'compressly' ),
            ];
        }

        $url = add_query_arg( [ 'key' => $key ], self::STATUS_URL );

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => self::TIMEOUT_SEC,
                'redirection' => 2,
                'user-agent'  => 'Compressly/' . ( defined( 'COMPRESSLY_VERSION' ) ? COMPRESSLY_VERSION : '1.0.0' ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'result'  => self::RESULT_UNVERIFIED,
                'message' => sprintf(
                    /* translators: %s: error message from the HTTP request. */
                    __( 'Could not reach ShortPixel to verify the key (%s). The key was saved; verification will be retried automatically.', 'compressly' ),
                    $response->get_error_message()
                ),
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );

        if ( $http_code === 401 || $http_code === 403 ) {
            return [
                'result'  => self::RESULT_INVALID,
                'message' => __( 'ShortPixel rejected the API key.', 'compressly' ),
            ];
        }

        if ( $http_code !== 200 ) {
            return [
                'result'  => self::RESULT_UNVERIFIED,
                'message' => sprintf(
                    /* translators: %d: HTTP status code returned by ShortPixel. */
                    __( 'Unexpected response from ShortPixel (HTTP %d). The key was saved; verification will be retried automatically.', 'compressly' ),
                    $http_code
                ),
            ];
        }

        $body = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( is_array( $data ) && isset( $data['Status']['Code'] ) ) {
            $status_code = (int) $data['Status']['Code'];
            if ( $status_code < 0 ) {
                $api_message = isset( $data['Status']['Message'] ) ? (string) $data['Status']['Message'] : __( 'Invalid API key.', 'compressly' );
                return [
                    'result'  => self::RESULT_INVALID,
                    'message' => sprintf(
                        /* translators: %s: error message from the ShortPixel API. */
                        __( 'ShortPixel rejected the API key: %s', 'compressly' ),
                        $api_message
                    ),
                ];
            }
        }

        return [ 'result' => self::RESULT_VALID, 'message' => '' ];
    }

    /**
     * Convenience: did `validate()` reject the key outright?
     */
    public static function rejects( string $result_code ): bool {
        return in_array( $result_code, [ self::RESULT_INVALID, self::RESULT_INVALID_FORMAT ], true );
    }
}
