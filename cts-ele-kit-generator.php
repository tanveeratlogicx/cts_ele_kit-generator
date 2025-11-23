<?php
/**
 * Plugin Name: CTS Elementor Kit Generator
 * Description: Elementor extension to analyze a website URL and generate an Elementor Template Kit with matching colors and typography.
 * Version: 1.1.0
 * Author: CTS
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: cts-ele-kit
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants.
define( 'CTS_EKG_VERSION', '1.1.0' );
define( 'CTS_EKG_FILE', __FILE__ );
define( 'CTS_EKG_DIR', \plugin_dir_path( __FILE__ ) );
define( 'CTS_EKG_URL', \plugin_dir_url( __FILE__ ) );

// Autoload includes.
require_once CTS_EKG_DIR . 'includes/class-plugin.php';

/**
 * Bootstrap plugin.
 */
function cts_ekg_boot() {
	// Check Elementor is active.
	if ( ! \did_action( 'elementor/loaded' ) ) {
		\add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . \esc_html__( 'CTS Elementor Kit Generator requires Elementor to be installed and active.', 'cts-ele-kit' ) . '</p></div>';
		} );
		return;
	}

	// Init plugin core.
	try {
		\CTS_EKG\Plugin::instance();
	} catch ( \Throwable $e ) {
		if ( \current_user_can( 'activate_plugins' ) ) {
			\add_action( 'admin_notices', function () use ( $e ) {
				echo '<div class="notice notice-error"><p>' . \esc_html__( 'CTS Elementor Kit Generator failed to initialize.', 'cts-ele-kit' ) . ' ' . \esc_html( $e->getMessage() ) . '</p></div>';
			} );
		}
	}
}
\add_action( 'plugins_loaded', 'cts_ekg_boot' );

/**
 * Activation tasks.
 */
function cts_ekg_activate() {
	// Store current version for future upgrades.
	if ( \function_exists( 'update_option' ) ) {
		\update_option( 'cts_ekg_version', CTS_EKG_VERSION );
	}
}
\register_activation_hook( __FILE__, 'cts_ekg_activate' );

/**
 * Deactivation tasks.
 */
function cts_ekg_deactivate() {
	// Nothing for now.
}
\register_deactivation_hook( __FILE__, 'cts_ekg_deactivate' );
