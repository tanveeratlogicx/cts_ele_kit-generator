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
		$color_keys = [ 'primary', 'secondary', 'text', 'accent' ];
		foreach ( $color_keys as $i => $key ) {
			if ( isset( $colors[ $i ] ) ) {
				$settings[ "global_colors-$key" ] = [ 'color' => $colors[ $i ] ];
			}
		}

		$extra_colors = array_slice( $colors, 4 );
		if ( ! empty( $extra_colors ) ) {
			$preview_custom = [];
			$added = 0;
			foreach ( $extra_colors as $col ) {
				$added++;
				$preview_custom[] = [
					'_id'   => substr( md5( strtolower( $col ) ), 0, 8 ),
					'title' => sprintf( /* translators: %d: index */ \__( 'Custom Color %d', 'cts-ele-kit' ), $added ),
					'color' => $col,
				];
				if ( $added >= 6 ) { break; }
			}
			$settings['custom_colors'] = $preview_custom;
		}

		$fonts = $analysis['fonts'] ?? [];
		if ( isset( $fonts[0] ) ) {
			$settings['global_typography-primary_typography_font_family'] = $fonts[0];
			$settings['global_typography-text_typography_font_family']    = $fonts[0];
		}
		if ( isset( $fonts[1] ) ) {
			$settings['global_typography-secondary_typography_font_family'] = $fonts[1];
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

		$settings = $this->build_settings( $analysis );

		// Append extra colors as Custom colors in the kit (best-effort, de-duplicated against existing).
		$extra_colors = array_slice( $analysis['colors'] ?? [], 4 );
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
					'title' => sprintf( /* translators: %d: index */ \__( 'Custom Color %d', 'cts-ele-kit' ), $added ),
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
		$kit->save();

		return [ 'ok' => true, 'message' => \__( 'Active kit updated.', 'cts-ele-kit' ), 'applied' => $settings ];
	}
}
