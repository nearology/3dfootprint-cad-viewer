<?php
/**
 * Main plugin container and hook registrar.
 *
 * @package ThreeDFootprintCadViewer\Core
 */

namespace ThreeDFootprint\CadViewer\Core;

use ThreeDFootprint\CadViewer\Admin\ProductJsonMetaBox;
use ThreeDFootprint\CadViewer\Frontend\ProductViewer;
use ThreeDFootprint\CadViewer\Services\ProductJsonService;
use ThreeDFootprint\CadViewer\Services\UploadDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin services and WordPress hook registration.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Product JSON service.
	 *
	 * @var ProductJsonService
	 */
	private $json_service;

	/**
	 * Product JSON admin meta box.
	 *
	 * @var ProductJsonMetaBox
	 */
	private $meta_box;

	/**
	 * Frontend product viewer.
	 *
	 * @var ProductViewer
	 */
	private $product_viewer;

	/**
	 * Return the plugin singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Create the plugin service graph.
	 */
	private function __construct() {
		$upload_directory     = new UploadDirectory();
		$this->json_service   = new ProductJsonService( $upload_directory );
		$this->meta_box       = new ProductJsonMetaBox( $this->json_service );
		$this->product_viewer = new ProductViewer( $this->json_service );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		load_plugin_textdomain(
			'3dfootprint-cad-viewer',
			false,
			dirname( THREE_D_FOOTPRINT_CAD_VIEWER_BASENAME ) . '/languages'
		);

		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );

		if ( is_admin() && $this->is_woocommerce_active() ) {
			$this->meta_box->register();
		}

		if ( ! is_admin() && $this->is_woocommerce_active() ) {
			$this->product_viewer->register();
		}
	}

	/**
	 * Get the product JSON service instance.
	 *
	 * @return ProductJsonService
	 */
	public function product_json_service() {
		return $this->json_service;
	}

	/**
	 * Render a WooCommerce dependency notice when needed.
	 *
	 * @return void
	 */
	public function render_dependency_notice() {
		if ( $this->is_woocommerce_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( '3DFootprint CAD Viewer requires WooCommerce to manage product JSON files.', '3dfootprint-cad-viewer' );
		echo '</p></div>';
	}

	/**
	 * Determine whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}
}
