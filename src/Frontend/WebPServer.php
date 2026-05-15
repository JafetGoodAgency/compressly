<?php
/**
 * Wraps local <img> tags in a <picture> block so browsers that accept
 * image/webp pull the .webp variant while everyone else still gets the
 * original.
 *
 * Conservative on purpose: only touches images served from this site's
 * uploads directory, only wraps when the attachment was actually
 * optimized by Compressly (so a coincidental same-named .webp on a
 * non-managed image doesn't get hijacked), and only emits a webp
 * srcset when every candidate has a matching .webp on disk.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Frontend;

use GoodAgency\Compressly\Optimization\Optimizer;

final class WebPServer {

    private string $uploads_basedir;
    private string $uploads_baseurl;

    /**
     * Per-request cache of url => attachment_id (or 0 when no match).
     * Same image can appear many times in a single page (gallery,
     * thumbnails) and attachment_url_to_postid() is a real DB hit.
     *
     * @var array<string,int>
     */
    private array $url_to_attachment = [];

    /**
     * Per-request cache of attachment_id => bool, true iff the
     * attachment carries Compressly's optimized flag.
     *
     * @var array<int,bool>
     */
    private array $attachment_optimized = [];

    public function __construct() {
        $upload_dir            = wp_get_upload_dir();
        $this->uploads_basedir = isset( $upload_dir['basedir'] )
            ? rtrim( str_replace( '\\', '/', (string) $upload_dir['basedir'] ), '/' )
            : '';
        $this->uploads_baseurl = isset( $upload_dir['baseurl'] )
            ? rtrim( (string) $upload_dir['baseurl'], '/' )
            : '';
    }

    public function register(): void {
        add_filter( 'the_content', [ $this, 'filter_content' ], 10 );
    }

    public function filter_content( $content ) {
        if ( ! is_string( $content ) || $content === '' ) {
            return $content;
        }
        if ( $this->uploads_basedir === '' || $this->uploads_baseurl === '' ) {
            return $content;
        }
        if ( stripos( $content, '<img' ) === false ) {
            return $content;
        }

        [ $masked, $placeholders ] = $this->mask_picture_blocks( $content );

        $processed = preg_replace_callback(
            '#<img\b[^>]*>#i',
            function ( array $m ): string {
                return $this->maybe_wrap_img( (string) $m[0] );
            },
            $masked
        );

        return is_string( $processed ) ? strtr( $processed, $placeholders ) : $content;
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function mask_picture_blocks( string $content ): array {
        if ( stripos( $content, '<picture' ) === false ) {
            return [ $content, [] ];
        }

        $placeholders = [];
        $index        = 0;
        $masked       = preg_replace_callback(
            '#<picture\b[^>]*>.*?</picture>#is',
            static function ( array $m ) use ( &$placeholders, &$index ): string {
                $token                  = "\0CPL_WPIC_{$index}\0";
                $placeholders[ $token ] = (string) $m[0];
                $index++;
                return $token;
            },
            $content
        );

        if ( ! is_string( $masked ) ) {
            return [ $content, [] ];
        }
        return [ $masked, $placeholders ];
    }

    private function maybe_wrap_img( string $img_tag ): string {
        $src = $this->attr_value( $img_tag, 'src' );
        if ( $src === null ) {
            return $img_tag;
        }

        $local_path = $this->local_path_for_url( $src );
        if ( $local_path === null ) {
            return $img_tag;
        }

        if ( ! $this->is_attachment_optimized_by_url( $src ) ) {
            return $img_tag;
        }

        $primary_webp = $this->webp_sibling_path( $local_path );
        if ( $primary_webp === null || ! is_file( $primary_webp ) ) {
            return $img_tag;
        }
        $primary_webp_url = $this->webp_sibling_url( $src );
        if ( $primary_webp_url === null ) {
            return $img_tag;
        }

        $srcset = $this->attr_value( $img_tag, 'srcset' );
        if ( is_string( $srcset ) && $srcset !== '' ) {
            $webp_srcset = $this->convert_srcset_to_webp( $srcset );
            if ( $webp_srcset === null ) {
                return $img_tag;
            }
            $source_tag = sprintf(
                '<source srcset="%s" type="image/webp">',
                esc_attr( $webp_srcset )
            );
        } else {
            $source_tag = sprintf(
                '<source srcset="%s" type="image/webp">',
                esc_attr( $primary_webp_url )
            );
        }

        return '<picture>' . $source_tag . $img_tag . '</picture>';
    }

    private function attr_value( string $tag, string $name ): ?string {
        $pattern = '#\s' . preg_quote( $name, '#' ) . '\s*=\s*("|\')([^"\']*)\1#i';
        if ( preg_match( $pattern, $tag, $m ) !== 1 ) {
            return null;
        }
        return (string) $m[2];
    }

    private function local_path_for_url( string $url ): ?string {
        $normalized = $this->normalize_url( $url );
        if ( $normalized === null ) {
            return null;
        }

        if ( strpos( $normalized, $this->uploads_baseurl . '/' ) !== 0 ) {
            return null;
        }

        $relative = ltrim( substr( $normalized, strlen( $this->uploads_baseurl ) ), '/' );
        if ( $relative === '' ) {
            return null;
        }

        $relative = $this->strip_query( $relative );
        if ( strpos( $relative, '..' ) !== false ) {
            return null;
        }

        return $this->uploads_basedir . '/' . $relative;
    }

    private function normalize_url( string $url ): ?string {
        $url = trim( $url );
        if ( $url === '' ) {
            return null;
        }

        // Protocol-relative -> https.
        if ( strpos( $url, '//' ) === 0 ) {
            $url = 'https:' . $url;
        }

        // Root-relative -> prepend home.
        if ( strpos( $url, '/' ) === 0 ) {
            $home = home_url();
            $url  = rtrim( $home, '/' ) . $url;
        }

        // Compare host-agnostic: normalize scheme of the candidate to
        // match uploads_baseurl's scheme so the simple prefix check
        // works in mixed http/https setups (proxies, CDNs).
        $base_scheme = parse_url( $this->uploads_baseurl, PHP_URL_SCHEME );
        $url_scheme  = parse_url( $url, PHP_URL_SCHEME );
        if ( $base_scheme !== null && $url_scheme !== null && $base_scheme !== $url_scheme ) {
            $url = preg_replace( '#^' . preg_quote( (string) $url_scheme, '#' ) . ':#', $base_scheme . ':', $url, 1 );
            if ( ! is_string( $url ) ) {
                return null;
            }
        }

        return $url;
    }

    private function strip_query( string $relative ): string {
        $q = strpos( $relative, '?' );
        if ( $q !== false ) {
            $relative = substr( $relative, 0, $q );
        }
        $h = strpos( $relative, '#' );
        if ( $h !== false ) {
            $relative = substr( $relative, 0, $h );
        }
        return $relative;
    }

    private function webp_sibling_path( string $absolute_path ): ?string {
        $info = pathinfo( $absolute_path );
        if ( empty( $info['dirname'] ) || empty( $info['filename'] ) ) {
            return null;
        }
        return $info['dirname'] . '/' . $info['filename'] . '.webp';
    }

    private function webp_sibling_url( string $url ): ?string {
        $normalized = $this->normalize_url( $url );
        if ( $normalized === null ) {
            return null;
        }
        $clean = $this->strip_query( $normalized );

        $dot = strrpos( $clean, '.' );
        $slash = strrpos( $clean, '/' );
        if ( $dot === false || ( $slash !== false && $dot < $slash ) ) {
            return null;
        }

        return substr( $clean, 0, $dot ) . '.webp';
    }

    private function is_attachment_optimized_by_url( string $url ): bool {
        $attachment_id = $this->resolve_attachment_id( $url );
        if ( $attachment_id <= 0 ) {
            return false;
        }
        if ( isset( $this->attachment_optimized[ $attachment_id ] ) ) {
            return $this->attachment_optimized[ $attachment_id ];
        }
        $optimized = (bool) get_post_meta( $attachment_id, Optimizer::META_OPTIMIZED, true );
        $this->attachment_optimized[ $attachment_id ] = $optimized;
        return $optimized;
    }

    private function resolve_attachment_id( string $url ): int {
        $normalized = $this->normalize_url( $url );
        if ( $normalized === null ) {
            return 0;
        }
        $clean = $this->strip_query( $normalized );

        if ( isset( $this->url_to_attachment[ $clean ] ) ) {
            return $this->url_to_attachment[ $clean ];
        }

        $id = (int) attachment_url_to_postid( $clean );
        if ( $id === 0 ) {
            $original = $this->strip_size_suffix( $clean );
            if ( $original !== $clean ) {
                $id = (int) attachment_url_to_postid( $original );
            }
        }

        $this->url_to_attachment[ $clean ] = $id;
        return $id;
    }

    private function strip_size_suffix( string $url ): string {
        return (string) preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $url );
    }

    private function convert_srcset_to_webp( string $srcset ): ?string {
        $entries = array_filter( array_map( 'trim', explode( ',', $srcset ) ) );
        if ( $entries === [] ) {
            return null;
        }

        $webp_entries = [];
        foreach ( $entries as $entry ) {
            // "url descriptor" — descriptor is optional (e.g. "1x", "320w").
            if ( preg_match( '/^(\S+)(\s+\S+)?$/', $entry, $m ) !== 1 ) {
                return null;
            }
            $url        = (string) $m[1];
            $descriptor = isset( $m[2] ) ? (string) $m[2] : '';

            $local = $this->local_path_for_url( $url );
            if ( $local === null ) {
                return null;
            }
            $webp = $this->webp_sibling_path( $local );
            if ( $webp === null || ! is_file( $webp ) ) {
                return null;
            }
            $webp_url = $this->webp_sibling_url( $url );
            if ( $webp_url === null ) {
                return null;
            }

            $webp_entries[] = trim( $webp_url . $descriptor );
        }

        if ( $webp_entries === [] ) {
            return null;
        }
        return implode( ', ', $webp_entries );
    }
}
