<?php
namespace CTS_EKG;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Kit_Generator {
	/**
	 * Build kit settings from analysis without saving (for preview).
	 */
	public function build_settings( array $analysis ): array {
		$settings = [];

		$colors = $analysis['colors'] ?? [];
        // Prefer labeled colors if provided by scraper.
        $labeled = $analysis['labeled_colors'] ?? [];
        $map = [ 'primary', 'secondary', 'text', 'accent' ];
        if ( is_array( $labeled ) && array_filter( $labeled ) ) {
            foreach ( $map as $key ) {
                if ( ! empty( $labeled[ $key ] ) ) {
                    $settings[ "global_colors-$key" ] = [ 'color' => $labeled[ $key ] ];
                }
            }
        } else {
            foreach ( $map as $i => $key ) {
                if ( isset( $colors[ $i ] ) ) {
                    $settings[ "global_colors-$key" ] = [ 'color' => $colors[ $i ] ];
                }
            }
        }

		// Build custom colors from non-labeled colors
		$labeled_vals = [];
		if ( ! empty( $labeled ) && is_array( $labeled ) ) {
			foreach ( $labeled as $v ) { if ( $v ) { $labeled_vals[ strtolower( (string) $v ) ] = true; } }
		}
		$extra_colors = [];
		foreach ( $colors as $c ) {
			$lc = strtolower( (string) $c );
			if ( isset( $labeled_vals[ $lc ] ) ) { continue; }
			$extra_colors[] = $c;
		}
		if ( ! empty( $extra_colors ) ) {
			$preview_custom = [];
			$added = 0;
			foreach ( $extra_colors as $col ) {
                $added++;
                $preview_custom[] = [
                    '_id'   => substr( md5( strtolower( $col ) ), 0, 8 ),
                    'title' => sprintf( /* translators: 1: guessed color name, 2: hex */ \__( 'Global Custom Color: %1$s (%2$s)', 'cts-ele-kit' ), $this->guess_color_name( $col ), $col ),
                    'color' => $col,
                ];
                if ( $added >= 6 ) { break; }
            }
            $settings['custom_colors'] = $preview_custom;
        }

		$fonts = $analysis['fonts'] ?? [];
		$families = $analysis['font_families'] ?? [];
		$primary_family = $families['primary'] ?? ( $fonts[0] ?? null );
		$body_family    = $families['body'] ?? ( $fonts[0] ?? null );
		$secondary_family = $families['secondary'] ?? ( $fonts[1] ?? null );
		if ( $primary_family ) {
			$settings['global_typography-primary_typography_font_family'] = $primary_family;
		}
		if ( $body_family ) {
			$settings['global_typography-text_typography_font_family'] = $body_family;
		}
		if ( $secondary_family ) {
			$settings['global_typography-secondary_typography_font_family'] = $secondary_family;
		}

		return $settings;
	}
	public function apply_to_active_kit( array $analysis ): array {
        if ( ! \did_action( 'elementor/loaded' ) || ! \class_exists( '\\Elementor\\Plugin' ) ) {
            return [ 'ok' => false, 'message' => \__( 'Elementor is not loaded.', 'cts-ele-kit' ) ];
        }

        $plugin = \Elementor\Plugin::$instance;
        if ( empty( $plugin->kits_manager ) ) {
            return [ 'ok' => false, 'message' => \__( 'Elementor Kits Manager unavailable.', 'cts-ele-kit' ) ];
        }

        $kit = $plugin->kits_manager->get_active_kit();
        if ( ! $kit ) {
            return [ 'ok' => false, 'message' => \__( 'No active Elementor Kit found.', 'cts-ele-kit' ) ];
        }

        // Backup current kit settings before applying changes.
        $this->backup_active_kit();

        $settings = $this->build_settings( $analysis );

		// Append non-labeled colors as Global Custom Colors in the kit (best-effort, de-duplicated).
		$all_colors = $analysis['colors'] ?? [];
		$labeled = $analysis['labeled_colors'] ?? [];
		$labeled_vals = [];
		if ( is_array( $labeled ) ) {
			foreach ( $labeled as $v ) { if ( $v ) { $labeled_vals[ strtolower( (string) $v ) ] = true; } }
		}
		$extra_colors = [];
		foreach ( $all_colors as $c ) {
			$lc = strtolower( (string) $c );
			if ( isset( $labeled_vals[ $lc ] ) ) { continue; }
			$extra_colors[] = $c;
		}
		if ( ! empty( $extra_colors ) ) {
			$existing_custom = $kit->get_settings( 'custom_colors' );
			if ( ! is_array( $existing_custom ) ) {
				$existing_custom = [];
			}
			// Build a set of existing color values to avoid duplicates.
			$existing_values = [];
			foreach ( $existing_custom as $c ) {
				if ( isset( $c['color'] ) ) {
					$existing_values[ strtolower( $c['color'] ) ] = true;
				}
			}
			$added = 0;
			foreach ( $extra_colors as $idx => $col ) {
                $lc = strtolower( $col );
                if ( isset( $existing_values[ $lc ] ) ) {
                    continue;
                }
                $existing_values[ $lc ] = true;
                $added++;
                $existing_custom[] = [
                    '_id'   => function_exists( '\\wp_generate_uuid4' ) ? \wp_generate_uuid4() : substr( md5( $lc . microtime( true ) ), 0, 8 ),
                    'title' => sprintf( /* translators: 1: guessed color name, 2: hex */ \__( 'Global Custom Color: %1$s (%2$s)', 'cts-ele-kit' ), $this->guess_color_name( $col ), $col ),
                    'color' => $col,
                ];
                if ( $added >= 6 ) { // cap
                    break;
                }
            }
            $settings['custom_colors'] = $existing_custom;
        }

		// Persist.
		$kit->update_settings( $settings );

		// Apply font sizes via custom CSS (body, h1–h6, buttons/CTAs).
		$css = $this->build_css_from_sizes( $analysis['font_sizes'] ?? [] );
		if ( ! empty( $css ) ) {
			$existing_css = (string) $kit->get_settings( 'custom_css' );
			$marker_start = '/* cts-ekg:font-sizes:start */';
			$marker_end   = '/* cts-ekg:font-sizes:end */';
			// Remove previous block if present.
			$existing_css = preg_replace( '/\/\* cts-ekg:font-sizes:start \*\/[\s\S]*?\/\* cts-ekg:font-sizes:end \*\//', '', $existing_css );
			$existing_css = trim( $existing_css );
			$combined = trim( $existing_css . "\n\n" . $marker_start . "\n" . $css . "\n" . $marker_end );
			$kit->update_settings( [ 'custom_css' => $combined ] );
		}

		$kit->save();

		return [ 'ok' => true, 'message' => \__( 'Active kit updated.', 'cts-ele-kit' ), 'applied' => $settings ];
	}

    /**
     * Backup current active kit settings and custom CSS into an option.
     */
    public function backup_active_kit(): array {
        if ( ! \did_action( 'elementor/loaded' ) || ! \class_exists( '\\Elementor\\Plugin' ) ) {
            return [ 'ok' => false, 'message' => \__( 'Elementor is not loaded.', 'cts-ele-kit' ) ];
        }
        $plugin = \Elementor\Plugin::$instance;
        $kit = $plugin->kits_manager ? $plugin->kits_manager->get_active_kit() : null;
        if ( ! $kit ) {
            return [ 'ok' => false, 'message' => \__( 'No active Elementor Kit found.', 'cts-ele-kit' ) ];
        }
        $all = $kit->get_settings();
        $css = (string) $kit->get_settings( 'custom_css' );
        $payload = [
            'time'     => time(),
            'settings' => is_array( $all ) ? $all : [],
            'custom_css' => $css,
        ];
        \update_option( 'cts_ekg_last_kit_backup', $payload, false );
        return [ 'ok' => true, 'message' => \__( 'Backup saved.', 'cts-ele-kit' ) ];
    }

    /**
     * Restore the last backup saved via backup_active_kit().
     */
    public function restore_last_backup(): array {
        if ( ! \did_action( 'elementor/loaded' ) || ! \class_exists( '\\Elementor\\Plugin' ) ) {
            return [ 'ok' => false, 'message' => \__( 'Elementor is not loaded.', 'cts-ele-kit' ) ];
        }
        $payload = \get_option( 'cts_ekg_last_kit_backup' );
        if ( empty( $payload ) || ! is_array( $payload ) ) {
            return [ 'ok' => false, 'message' => \__( 'No backup found to restore.', 'cts-ele-kit' ) ];
        }
        $plugin = \Elementor\Plugin::$instance;
        if ( empty( $plugin->kits_manager ) ) {
            return [ 'ok' => false, 'message' => \__( 'Elementor Kits Manager unavailable.', 'cts-ele-kit' ) ];
        }
        $kit = $plugin->kits_manager->get_active_kit();
        if ( ! $kit ) {
            return [ 'ok' => false, 'message' => \__( 'No active Elementor Kit found.', 'cts-ele-kit' ) ];
        }
        $to = [ 'custom_css' => (string) ( $payload['custom_css'] ?? '' ) ];
        if ( ! empty( $payload['settings'] ) && is_array( $payload['settings'] ) ) {
            // We restore the full settings array for accuracy.
            $to = array_merge( $payload['settings'], $to );
        }
        $kit->update_settings( $to );
        $kit->save();
        return [ 'ok' => true, 'restored_at' => (int) ( $payload['time'] ?? 0 ) ];
    }

    /**
     * Get all active kit settings for diagnostics.
     */
    public function get_active_kit_settings(): array {
        if ( ! \did_action( 'elementor/loaded' ) || ! \class_exists( '\\Elementor\\Plugin' ) ) {
            return [ 'ok' => false, 'message' => \__( 'Elementor is not loaded.', 'cts-ele-kit' ) ];
        }
        $plugin = \Elementor\Plugin::$instance;
        if ( empty( $plugin->kits_manager ) ) {
            return [ 'ok' => false, 'message' => \__( 'Elementor Kits Manager unavailable.', 'cts-ele-kit' ) ];
        }
        $kit = $plugin->kits_manager->get_active_kit();
        if ( ! $kit ) {
            return [ 'ok' => false, 'message' => \__( 'No active Elementor Kit found.', 'cts-ele-kit' ) ];
        }
        $all = $kit->get_settings();
        return [ 'ok' => true, 'settings' => is_array( $all ) ? $all : [] ];
    }

    private function guess_color_name( string $color ): string {
        $rgb = $this->parse_color_to_rgb( $color );
        if ( ! $rgb ) { return \__( 'Color', 'cts-ele-kit' ); }
        // Minimal palette of common names for good UX and speed
        $palette = [
            'White' => [255,255,255], 'Black' => [0,0,0], 'Red' => [255,0,0], 'Lime' => [0,255,0], 'Blue' => [0,0,255],
            'Yellow' => [255,255,0], 'Cyan' => [0,255,255], 'Magenta' => [255,0,255], 'Silver' => [192,192,192], 'Gray' => [128,128,128],
            'Maroon' => [128,0,0], 'Olive' => [128,128,0], 'Green' => [0,128,0], 'Purple' => [128,0,128], 'Teal' => [0,128,128], 'Navy' => [0,0,128],
            'Orange' => [255,165,0], 'Pink' => [255,192,203], 'Brown' => [165,42,42], 'Beige' => [245,245,220], 'Ivory' => [255,255,240],
            'Coral' => [255,127,80], 'Salmon' => [250,128,114], 'Gold' => [255,215,0], 'Khaki' => [240,230,140], 'Lavender' => [230,230,250],
            'Indigo' => [75,0,130], 'Violet' => [238,130,238], 'Turquoise' => [64,224,208], 'Slate' => [112,128,144], 'Charcoal' => [54,69,79]
        ];
        $best = null; $bestName = 'Color';
        foreach ( $palette as $name => $p ) {
            $d = ($rgb[0]-$p[0])**2 + ($rgb[1]-$p[1])**2 + ($rgb[2]-$p[2])**2;
            if ( $best === null || $d < $best ) { $best = $d; $bestName = $name; }
        }
        return $bestName;
    }

    private function parse_color_to_rgb( string $color ) {
        $c = trim( strtolower( $color ) );
        // #rgb or #rrggbb
        if ( preg_match( '/^#([0-9a-f]{3})$/i', $c, $m ) ) {
            $h = $m[1];
            return [ hexdec($h[0].$h[0]), hexdec($h[1].$h[1]), hexdec($h[2].$h[2]) ];
        }
        if ( preg_match( '/^#([0-9a-f]{6})$/i', $c, $m ) ) {
            $h = $m[1];
            return [ hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2)) ];
        }
        // rgb/rgba
        if ( preg_match( '/^rgba?\(([^)]+)\)$/', $c, $m ) ) {
            $parts = array_map( 'trim', explode( ',', $m[1] ) );
            if ( count($parts) >= 3 ) {
                $toInt = function($v){
                    if ( substr($v,-1) === '%' ) { return (int) round( 255 * max(0,min(100,(float)$v))/100 ); }
                    return (int) max(0, min(255, (float) $v));
                };
                return [ $toInt($parts[0]), $toInt($parts[1]), $toInt($parts[2]) ];
            }
        }
        return null;
    }

    private function build_css_from_sizes( array $sizes ): string {
		if ( empty( $sizes ) ) { return ''; }
		$val = function( $k ) use ( $sizes ) {
			if ( empty( $sizes[ $k ] ) || ! is_string( $sizes[ $k ] ) ) { return null; }
			$v = trim( $sizes[ $k ] );
			// Allow px, rem, em, %, clamp(), calc(), var().
			if ( preg_match( '/^(?:\d+(?:\.\d+)?(px|rem|em|%)|clamp\([^)]*\)|calc\([^)]*\)|var\([^)]*\))$/i', $v ) ) {
				return $v;
			}
			return null;
		};
		$lines = [];
		if ( $v = $val('base') ) { $lines[] = "body{font-size:$v;}"; }
		foreach ( ['h1','h2','h3','h4','h5','h6'] as $h ) {
			if ( $v = $val($h) ) { $lines[] = "$h{font-size:$v;}"; }
		}
		// CTA buttons: Elementor button and common buttons.
		$cta = $val('cta');
		if ( $cta ) {
			$lines[] = ".elementor-button, .button, button, input[type=button], input[type=submit]{font-size:$cta;}";
		}
		return implode( "\n", $lines );
	}
}
