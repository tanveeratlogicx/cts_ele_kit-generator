<?php
namespace CTS_EKG;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Admin {
	/** @var Admin */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		\add_action( 'admin_menu', [ $this, 'register_menu' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function register_menu() {
		\add_management_page(
			\__( 'Elementor Kit Generator', 'cts-ele-kit' ),
			\__( 'Elementor Kit Generator', 'cts-ele-kit' ),
			'manage_options',
			'cts-ekg',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( $hook ) {
		if ( 'tools_page_cts-ekg' !== $hook ) {
			return;
		}
		\wp_enqueue_style( 'cts-ekg-admin', CTS_EKG_URL . 'assets/admin.css', [], CTS_EKG_VERSION );
		\wp_enqueue_script( 'cts-ekg-admin', CTS_EKG_URL . 'assets/admin.js', [ 'wp-i18n', 'wp-api-fetch' ], CTS_EKG_VERSION, true );
		\wp_set_script_translations( 'cts-ekg-admin', 'cts-ele-kit', CTS_EKG_DIR . 'languages' );
		\wp_localize_script( 'cts-ekg-admin', 'ctsEkg', [
			'nonce' => \wp_create_nonce( 'wp_rest' ),
			'endpoint' => \esc_url_raw( \rest_url( 'cts-ekg/v1/generate' ) ),
		] );
	}

	public function render_page() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Elementor Template Kit Generator', 'cts-ele-kit' ); ?></h1>
			<p><?php \esc_html_e( 'Enter a website URL. The plugin will analyze colors and typography and update your active Elementor Kit.', 'cts-ele-kit' ); ?></p>
			<form id="cts-ekg-form" action="#" method="post" onsubmit="return false;">
				<label for="cts-ekg-url" class="screen-reader-text"><?php \esc_html_e( 'Website URL', 'cts-ele-kit' ); ?></label>
				<input type="url" id="cts-ekg-url" class="regular-text code" placeholder="https://example.com" required>
				<button class="button button-primary" id="cts-ekg-run"><?php \esc_html_e( 'Generate Kit', 'cts-ele-kit' ); ?></button>
			</form>
			<div id="cts-ekg-result" style="margin-top:1rem;"></div>
		</div>
		<?php
	}
}
