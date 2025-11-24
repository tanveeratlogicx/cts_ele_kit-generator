<?php
namespace CTS_EKG;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class REST_Controller {
	private $scraper;
	private $kit_generator;

	public function __construct( Scraper $scraper, Kit_Generator $kit_generator ) {
		$this->scraper = $scraper;
		$this->kit_generator = $kit_generator;
	}

	public function register_routes() {
		\add_action( 'rest_api_init', function () {
			\register_rest_route( 'cts-ekg/v1', '/generate', [
				'methods'             => 'POST',
				'permission_callback' => function () {
					return \current_user_can( 'manage_options' );
				},
				'args'                => [
					'url'   => [ 'type' => 'string', 'required' => true ],
					'apply' => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
					'overrides' => [ 'type' => 'object', 'required' => false ],
				],
				'callback'            => [ $this, 'handle_generate' ],
			] );
			\register_rest_route( 'cts-ekg/v1', '/restore', [
				'methods'             => 'POST',
				'permission_callback' => function () {
					return \current_user_can( 'manage_options' );
				},
				'callback'            => [ $this, 'handle_restore' ],
			] );
			\register_rest_route( 'cts-ekg/v1', '/diagnostics', [
				'methods'             => 'POST',
				'permission_callback' => function () {
					return \current_user_can( 'manage_options' );
				},
				'callback'            => [ $this, 'handle_diagnostics' ],
			] );
		} );
	}

	public function handle_generate( $request ) {
		$url = '';
		if ( is_array( $request ) ) {
			$url = isset( $request['url'] ) ? (string) $request['url'] : '';
		} elseif ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$url = (string) $request->get_param( 'url' );
		}
		$url = \esc_url_raw( $url );
		if ( empty( $url ) ) {
			return [ 'ok' => false, 'message' => \__( 'Invalid URL.', 'cts-ele-kit' ) ];
		}
		// Only allow http/https to avoid SSRF on internal schemes.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return [ 'ok' => false, 'message' => \__( 'Only http/https URLs are allowed.', 'cts-ele-kit' ) ];
		}

		// Apply flag.
		$apply = false;
		if ( is_array( $request ) ) {
			$apply = ! empty( $request['apply'] ) && ( $request['apply'] === true || $request['apply'] === 'true' || $request['apply'] === 1 || $request['apply'] === '1' );
		} elseif ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$raw = $request->get_param( 'apply' );
			$apply = ! empty( $raw ) && ( $raw === true || $raw === 'true' || $raw === 1 || $raw === '1' );
		}

		try {
			$analysis = $this->scraper->analyze( $url );
			// Merge overrides if provided.
			$overrides = [];
			if ( is_array( $request ) ) {
				$overrides = isset( $request['overrides'] ) && is_array( $request['overrides'] ) ? $request['overrides'] : [];
			} elseif ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
				$ov = $request->get_param( 'overrides' );
				if ( is_array( $ov ) ) { $overrides = $ov; }
			}
			if ( ! empty( $overrides ) ) {
				if ( ! empty( $overrides['labeled_colors'] ) && is_array( $overrides['labeled_colors'] ) ) {
					$analysis['labeled_colors'] = array_merge( $analysis['labeled_colors'] ?? [], $overrides['labeled_colors'] );
				}
				if ( ! empty( $overrides['font_sizes'] ) && is_array( $overrides['font_sizes'] ) ) {
					$analysis['font_sizes'] = array_merge( $analysis['font_sizes'] ?? [], $overrides['font_sizes'] );
				}
				if ( ! empty( $overrides['font_families'] ) && is_array( $overrides['font_families'] ) ) {
					$analysis['font_families'] = array_merge( $analysis['font_families'] ?? [], $overrides['font_families'] );
				}
			}
			if ( isset( $analysis['error'] ) ) {
				return [ 'ok' => false, 'message' => $analysis['error'] ];
			}
			if ( ! $apply ) {
				$preview = $this->kit_generator->build_settings( $analysis );
				return [ 'ok' => true, 'analysis' => $analysis, 'preview' => $preview ];
			}
			$result = $this->kit_generator->apply_to_active_kit( $analysis );
			return array_merge( [ 'analysis' => $analysis ], $result );
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => \__( 'Unexpected error while generating kit.', 'cts-ele-kit' ) ];
		}
	}

	public function handle_restore( $request ) {
		try {
			$result = $this->kit_generator->restore_last_backup();
			return $result;
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => \__( 'Unexpected error while restoring.', 'cts-ele-kit' ) ];
		}
	}

	public function handle_diagnostics( $request ) {
		try {
			$result = $this->kit_generator->get_active_kit_settings();
			return $result;
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'message' => \__( 'Unexpected error while reading diagnostics.', 'cts-ele-kit' ) ];
		}
	}
}
