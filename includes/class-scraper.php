<?php
namespace CTS_EKG;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Scraper {
	public function analyze( string $url ): array {
		$url = \esc_url_raw( $url );
		if ( empty( $url ) ) {
			return [ 'colors' => [], 'fonts' => [] ];
		}

		$headers = [
			'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0 Safari/537.36 CTS-EKG/1.1 ' . \home_url( '/' ),
			'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Language' => 'en-US,en;q=0.9',
		];
		$response = \wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 3, 'headers' => $headers ] );
		if ( \is_wp_error( $response ) ) {
			return [ 'colors' => [], 'fonts' => [], 'error' => $response->get_error_message() ];
		}
		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return [ 'colors' => [], 'fonts' => [], 'error' => 'HTTP ' . $code ];
		}
		$body = \wp_remote_retrieve_body( $response );
		$ct = (string) ( \wp_remote_retrieve_header( $response, 'content-type' ) ?: '' );
		if ( '' !== $ct && false === stripos( $ct, 'text/html' ) && false === stripos( $ct, 'application/xhtml+xml' ) ) {
			return [ 'colors' => [], 'fonts' => [], 'error' => 'Unsupported content-type' ];
		}
		// Truncate very large bodies to 1MB to avoid excessive processing.
		if ( strlen( $body ) > 1024 * 1024 ) {
			$body = substr( $body, 0, 1024 * 1024 );
		}

		$base = $url;
		$css_links = $this->extract_stylesheet_links( $body, $base );
		$inline_css = $this->extract_inline_css( $body );

		$css_contents = $inline_css;
		foreach ( array_slice( $css_links, 0, 5 ) as $css_url ) {
			$css_res = \wp_remote_get( $css_url, [ 'timeout' => 12, 'redirection' => 2, 'headers' => $headers ] );
			if ( ! \is_wp_error( $css_res ) ) {
				$css_code = (int) \wp_remote_retrieve_response_code( $css_res );
				if ( $css_code >= 200 && $css_code < 300 ) {
					$css_ct = (string) ( \wp_remote_retrieve_header( $css_res, 'content-type' ) ?: '' );
					if ( false !== stripos( $css_ct, 'text/css' ) || '' === $css_ct ) {
						$chunk = (string) \wp_remote_retrieve_body( $css_res );
						if ( strlen( $chunk ) > 512 * 1024 ) { // 512KB cap per CSS file
							$chunk = substr( $chunk, 0, 512 * 1024 );
						}
						$css_contents .= "\n" . $chunk;
					}
				}
			}
		}

		$colors = $this->extract_colors( $body . "\n" . $css_contents );
		$fonts  = $this->extract_fonts( $body . "\n" . $css_contents );

		$colors = $this->rank_and_limit_colors( $colors, 12 );
		$fonts  = $this->rank_and_limit_fonts( $fonts, 4 );

		return [
			'colors' => array_slice( array_values( array_unique( $colors ) ), 0, 8 ),
			'fonts'  => array_slice( array_values( array_unique( $fonts ) ), 0, 3 ),
		];
	}

	private function extract_colors( string $html ): array {
		$colors = [];
		// HEX colors
		if ( \preg_match_all( '/#(?:[0-9a-fA-F]{3}){1,2}\b/', $html, $m ) ) {
			$colors = array_merge( $colors, $m[0] );
		}
		// rgb(a)
		if ( \preg_match_all( '/rgba?\\(\\s*\\d+\\s*,\\s*\\d+\\s*,\\s*\\d+(?:\\s*,\\s*(?:0|1|0?\\.\\d+))?\\s*\\)/', $html, $m2 ) ) {
			$colors = array_merge( $colors, array_map( [ $this, 'normalize_rgb' ], $m2[0] ) );
		}
		return array_map( 'strtolower', $colors );
	}

	private function normalize_rgb( string $rgb ): string {
		return $rgb; // Keep as-is; Elementor supports rgba.
	}

	private function extract_fonts( string $html ): array {
		$fonts = [];
		if ( \preg_match_all( '/font-family\\s*:\\s*([^;}{]+);/i', $html, $m ) ) {
			foreach ( $m[1] as $fam ) {
				// Take first family name, strip quotes.
				$first = trim( explode( ',', $fam )[0] );
				$first = trim( $first, " \"'" );
				if ( ! empty( $first ) ) {
					$fonts[] = $first;
				}
			}
		}
		// Also parse Google Fonts link tags quickly.
		if ( \preg_match_all( '/https?:\\/\\/fonts\\.googleapis\\.com\\/css2?\\?family=([^"&\s]+)/i', $html, $gm ) ) {
			foreach ( $gm[1] as $famstr ) {
				$parts = explode( ':', $famstr );
				$fonts[] = str_replace( '+', ' ', $parts[0] );
			}
		}
		return $fonts;
	}

	private function extract_stylesheet_links( string $html, string $base ): array {
		$links = [];
		if ( \preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				$links[] = $this->resolve_url( $href, $base );
			}
		}
		return array_values( array_unique( array_filter( $links ) ) );
	}

	private function extract_inline_css( string $html ): string {
		$css = '';
		if ( \preg_match_all( '/<style[^>]*>([\s\S]*?)<\\/style>/i', $html, $m ) ) {
			$css = implode( "\n", $m[1] );
		}
		return $css;
	}

	private function resolve_url( string $href, string $base ): string {
		if ( \preg_match( '#^https?://#i', $href ) ) { return $href; }
		// Basic relative resolution.
		$parts = \wp_parse_url( $base );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return $href; }
		$prefix = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $href, '/' ) ) { return $prefix . $href; }
		$path = isset( $parts['path'] ) ? rtrim( dirname( $parts['path'] ), '/\\' ) : '';
		return $prefix . $path . '/' . ltrim( $href, '/\\' );
	}

	private function rank_and_limit_colors( array $colors, int $limit ): array {
		$counts = [];
		foreach ( $colors as $c ) { $counts[ $c ] = ( $counts[ $c ] ?? 0 ) + 1; }
		arsort( $counts );
		return array_slice( array_keys( $counts ), 0, $limit );
	}

	private function rank_and_limit_fonts( array $fonts, int $limit ): array {
		$counts = [];
		foreach ( $fonts as $f ) { $counts[ $f ] = ( $counts[ $f ] ?? 0 ) + 1; }
		arsort( $counts );
		return array_slice( array_keys( $counts ), 0, $limit );
	}
}
