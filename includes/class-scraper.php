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
        // Normalize colors and drop very transparent entries, collapse near-duplicates.
        $colors = $this->normalize_colors_list( $colors );
		$fonts  = $this->extract_fonts( $body . "\n" . $css_contents );
		$sizes  = $this->extract_font_sizes( $css_contents );
        $vars   = $this->extract_css_vars( $css_contents );

		$colors = $this->rank_and_limit_colors( $colors, 12 );
		$fonts  = $this->rank_and_limit_fonts( $fonts, 4 );
        $bg = $this->extract_background_color( $css_contents );
        $labeled = $this->label_colors( $colors, $bg );
        // Merge CSS variables into labels/sizes when meaningful.
        if ( ! empty( $vars['colors'] ) ) {
            foreach ( $vars['colors'] as $name => $val ) {
                $lname = strtolower( $name );
                if ( false !== strpos( $lname, 'primary' ) ) { $labeled['primary'] = $val; }
                if ( false !== strpos( $lname, 'secondary' ) ) { $labeled['secondary'] = $val; }
                if ( false !== strpos( $lname, 'text' ) ) { $labeled['text'] = $val; }
                if ( false !== strpos( $lname, 'accent' ) || false !== strpos( $lname, 'brand' ) ) { $labeled['accent'] = $val; }
            }
        }
        if ( ! empty( $vars['font_sizes'] ) ) {
            $map = [ 'base' => ['base','body','root'], 'h1'=>['h1'], 'h2'=>['h2'], 'h3'=>['h3'], 'h4'=>['h4'], 'h5'=>['h5'], 'h6'=>['h6'] ];
            foreach ( $vars['font_sizes'] as $name => $val ) {
                $lname = strtolower( $name );
                foreach ( $map as $key => $needles ) {
                    foreach ( $needles as $needle ) {
                        if ( false !== strpos( $lname, $needle ) ) { $sizes[ $key ] = $val; break 2; }
                    }
                }
            }
        }

		return [
			'colors'          => array_slice( array_values( array_unique( $colors ) ), 0, 8 ),
			'labeled_colors'  => $labeled,
			'fonts'           => array_slice( array_values( array_unique( $fonts ) ), 0, 3 ),
			'font_sizes'      => $sizes,
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

	private function extract_font_sizes( string $css ): array {
		$result = [
			'base' => null,
			'h1' => null,
			'h2' => null,
			'h3' => null,
			'h4' => null,
			'h5' => null,
			'h6' => null,
		];
		$search = [
			'base' => '(?:html|:root|body)\s*\{[^}]*font-size\s*:\s*([^;}{]+);',
			'h1' => 'h1\s*\{[^}]*font-size\s*:\s*([^;}{]+);',
			'h2' => 'h2\s*\{[^}]*font-size\s*:\s*([^;}{]+);',
			'h3' => 'h3\s*\{[^}]*font-size\s*:\s*([^;}{]+);',
			'h4' => 'h4\s*\{[^}]*font-size\s*:\s*([^;}{]+);',
			'h5' => 'h5\s*\{[^}]*font-size\s*:\s*([^;}{]+);',
			'h6' => 'h6\s*\{[^}]*font-size\s*:\s*([^;}{]+);',
		];
		foreach ( $search as $key => $pattern ) {
			if ( \preg_match( '/' . $pattern . '/i', $css, $m ) ) {
				$result[ $key ] = trim( $m[1] );
			}
		}
		return $result;
	}

	private function extract_css_vars( string $css ): array {
        $vars = [ 'colors' => [], 'font_sizes' => [] ];
        if ( \preg_match_all( '/--([a-z0-9\-_]+)\s*:\s*([^;}{]+);/i', $css, $m ) ) {
            foreach ( $m[1] as $i => $name ) {
                $val = trim( $m[2][ $i ] );
                // Strip !important etc.
                $val = preg_replace( '/!\s*important/i', '', $val );
                if ( \preg_match( '/^(#|rgb)/i', $val ) ) {
                    $vars['colors'][ '--' . $name ] = $val;
                } elseif ( \preg_match( '/^(?:\d+(?:\.\d+)?(px|rem|em|%)|clamp\(|calc\(|var\()/i', $val ) ) {
                    $vars['font_sizes'][ '--' . $name ] = $val;
                }
            }
        }
        return $vars;
    }

	private function label_colors( array $colors, ?string $bg = null ): array {
        $labels = [ 'primary' => null, 'secondary' => null, 'text' => null, 'accent' => null ];
        if ( empty( $colors ) ) { return $labels; }
        $metrics = [];
        foreach ( $colors as $c ) {
            $rgb = $this->to_rgb( $c );
            if ( ! $rgb ) { continue; }
            list( $r,$g,$b,$a ) = $rgb;
            $lum = 0.2126 * ($r/255) + 0.7152 * ($g/255) + 0.0722 * ($b/255);
            $max = max($r,$g,$b); $min = min($r,$g,$b); $sat = ($max===0)?0:(($max-$min)/$max);
            $metrics[] = [ 'c'=>$c, 'lum'=>$lum, 'sat'=>$sat, 'a'=>$a ];
        }
        if ( empty( $metrics ) ) { return $labels; }
        // Background
        $bg_rgb = $bg ? $this->to_rgb( $bg ) : null;
        // Text: prefer highest contrast vs background, else darkest opaque.
        $opaque = array_values( array_filter( $metrics, function($m){ return $m['a'] === null || $m['a'] >= 0.8; }) );
        if ( $bg_rgb ) {
            usort( $opaque, function($x,$y) use ($bg_rgb) {
                $cx = $this->contrast_ratio_rgb( $this->to_rgb_components($x['c']), $bg_rgb );
                $cy = $this->contrast_ratio_rgb( $this->to_rgb_components($y['c']), $bg_rgb );
                return $cy <=> $cx; // desc
            });
        } else {
            usort( $opaque, function($x,$y){ return $x['lum'] <=> $y['lum']; });
        }
        $labels['text'] = $opaque[0]['c'] ?? $metrics[0]['c'];
        // Accent: highest saturation.
        $cand = array_values( array_filter( $metrics, function($m){ return $m['sat'] >= 0.2; } ) );
        if ( empty( $cand ) ) { $cand = $metrics; }
        usort( $cand, function($x,$y){ return ($y['sat'] <=> $x['sat']) ?: ($x['lum'] <=> $y['lum']); });
        $labels['accent'] = $cand[0]['c'];
        // Primary/Secondary: first remaining distinct colors.
        $used = [ strtolower($labels['text']), strtolower($labels['accent']) ];
        $prim = null; $sec = null;
        foreach ( $colors as $c ) {
            $lc = strtolower($c);
            if ( in_array( $lc, $used, true ) ) { continue; }
            if ( ! $prim ) { $prim = $c; $used[] = $lc; continue; }
            $sec = $c; break;
        }
        $labels['primary'] = $prim ?? $colors[0];
        $labels['secondary'] = $sec ?? ($colors[1] ?? $colors[0]);
        return $labels;
    }

	private function to_rgb( string $c ) {
        $c = trim( strtolower( $c ) );
        if ( 0 === strpos( $c, '#' ) ) {
            $h = substr( $c, 1 );
            if ( strlen( $h ) === 3 ) {
                $r = hexdec( str_repeat( $h[0], 2 ) );
                $g = hexdec( str_repeat( $h[1], 2 ) );
                $b = hexdec( str_repeat( $h[2], 2 ) );
                return [ $r,$g,$b,null ];
            }
            if ( strlen( $h ) === 6 ) {
                $r = hexdec( substr( $h,0,2 ) );
                $g = hexdec( substr( $h,2,2 ) );
                $b = hexdec( substr( $h,4,2 ) );
                return [ $r,$g,$b,null ];
            }
        }
        if ( 0 === strpos( $c, 'rgb' ) ) {
            if ( \preg_match( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*(\d*\.?\d+))?\s*\)/', $c, $m ) ) {
                $r = (int) $m[1]; $g = (int) $m[2]; $b = (int) $m[3];
                $a = isset($m[4]) ? (float) $m[4] : null;
                return [ $r,$g,$b,$a ];
            }
        }
        return null;
    }

    private function to_rgb_components( string $c ): array {
        $rgb = $this->to_rgb( $c );
        if ( ! $rgb ) { return [0,0,0]; }
        return [ $rgb[0], $rgb[1], $rgb[2] ];
    }

    private function contrast_ratio_rgb( array $rgb1, array $rgb2 ): float {
        $L = function($c){ list($r,$g,$b)=$c; $toLin=function($v){$v=$v/255; return $v<=0.03928? $v/12.92 : pow(($v+0.055)/1.055,2.4);}; $R=$toLin($r);$G=$toLin($g);$B=$toLin($b); return 0.2126*$R+0.7152*$G+0.0722*$B; };
        $L1 = $L($rgb1); $L2 = $L($rgb2); if ($L1<$L2){ list($L1,$L2)=[$L2,$L1]; }
        return ($L1+0.05)/($L2+0.05);
    }

    private function normalize_colors_list( array $colors ): array {
        $norm = [];
        foreach ( $colors as $c ) {
            $rgb = $this->to_rgb( $c );
            if ( ! $rgb ) { continue; }
            // Drop very transparent
            if ( isset($rgb[3]) && $rgb[3] !== null && $rgb[3] < 0.5 ) { continue; }
            $hex = sprintf( '#%02x%02x%02x', max(0,min(255,$rgb[0])), max(0,min(255,$rgb[1])), max(0,min(255,$rgb[2])) );
            $norm[] = $hex;
        }
        // De-duplicate near-equals (RGB distance < 20)
        $out = [];
        foreach ( $norm as $hex ) {
            $rgb = $this->to_rgb( $hex );
            $keep = true;
            foreach ( $out as $existing ) {
                $e = $this->to_rgb( $existing );
                $dist = sqrt( pow($rgb[0]-$e[0],2) + pow($rgb[1]-$e[1],2) + pow($rgb[2]-$e[2],2) );
                if ( $dist < 20 ) { $keep = false; break; }
            }
            if ( $keep ) { $out[] = strtolower($hex); }
        }
        return $out;
    }

    private function extract_background_color( string $css ): ?string {
        if ( \preg_match( '/body\s*\{[^}]*background(?:-color)?\s*:\s*([^;}{]+);/i', $css, $m ) ) {
            $val = trim( $m[1] );
            // Resolve var(--x) if present
            if ( \preg_match( '/var\((--[a-z0-9\-_]+)\)/i', $val, $vm ) ) {
                // Try to find the var value in CSS
                if ( \preg_match( '/'.preg_quote($vm[1],'/' ).'\s*:\s*([^;}{]+);/i', $css, $def ) ) {
                    $val = trim( $def[1] );
                }
            }
            return $val;
        }
        return null;
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
