<?php
namespace CTS_EKG;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/** @var Plugin */
	private static $instance;
	/** @var string */
	private $option_key = 'cts_ekg_version';

	/**
	 * Singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	private function includes() {
		require_once CTS_EKG_DIR . 'includes/class-admin.php';
		require_once CTS_EKG_DIR . 'includes/class-scraper.php';
		require_once CTS_EKG_DIR . 'includes/class-kit-generator.php';
		require_once CTS_EKG_DIR . 'includes/class-rest-controller.php';
	}

	private function init_hooks() {
		// I18n.
		\add_action( 'init', [ $this, 'load_textdomain' ] );

		// Upgrades.
		\add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );

		// Admin.
		if ( \is_admin() ) {
			Admin::instance();
		}

		// REST.
		( new REST_Controller( new Scraper(), new Kit_Generator() ) )->register_routes();
	}

	public function load_textdomain() {
		\load_plugin_textdomain( 'cts-ele-kit', false, \dirname( \plugin_basename( CTS_EKG_FILE ) ) . '/languages' );
	}

	public function maybe_upgrade() {
		$current = '';
		if ( \is_callable( '\\get_option' ) ) {
			$current = (string) \call_user_func( '\\get_option', $this->option_key, '' );
		}
		if ( $current === CTS_EKG_VERSION ) {
			return;
		}
		// Place future migrations here based on $current version.
		if ( \is_callable( '\\update_option' ) ) {
			\call_user_func( '\\update_option', $this->option_key, CTS_EKG_VERSION );
		}
	}
}
