<?php
/**
 * WooCommerce single product JSON viewer.
 *
 * @package ThreeDFootprintCadViewer\Frontend
 */

namespace ThreeDFootprint\CadViewer\Frontend;

use ThreeDFootprint\CadViewer\Services\ProductJsonService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the default WooCommerce product gallery with the product JSON viewer.
 */
class ProductViewer {

	/**
	 * Product JSON service.
	 *
	 * @var ProductJsonService
	 */
	private $json_service;

	/**
	 * Create the frontend viewer controller.
	 *
	 * @param ProductJsonService $json_service Product JSON service.
	 */
	public function __construct( ProductJsonService $json_service ) {
		$this->json_service = $json_service;
	}

	/**
	 * Register frontend hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_before_single_product', array( $this, 'replace_gallery_hook' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Remove WooCommerce's gallery action and add the custom viewer action.
	 *
	 * @return void
	 */
	public function replace_gallery_hook() {
		$product_id = $this->get_current_product_id();

		if ( ! $product_id || ! $this->should_replace_gallery( $product_id ) ) {
			return;
		}

		remove_action(
			'woocommerce_before_single_product_summary',
			'woocommerce_show_product_images',
			20
		);

		if ( ! has_action( 'woocommerce_before_single_product_summary', array( $this, 'render_viewer' ) ) ) {
			add_action(
				'woocommerce_before_single_product_summary',
				array( $this, 'render_viewer' ),
				20
			);
		}
	}

	/**
	 * Enqueue frontend assets only for WooCommerce product pages.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = $this->get_current_product_id();

		if ( ! $product_id || ! $this->should_replace_gallery( $product_id ) ) {
			return;
		}

		wp_enqueue_style(
			'3dfootprint-cad-viewer-product-viewer',
			THREE_D_FOOTPRINT_CAD_VIEWER_URL . 'assets/frontend/product-viewer.css',
			array(),
			THREE_D_FOOTPRINT_CAD_VIEWER_VERSION
		);

		wp_enqueue_script(
			'3dfootprint-cad-viewer-konva',
			THREE_D_FOOTPRINT_CAD_VIEWER_URL . 'assets/frontend/konva.min.js',
			array(),
			'10.2.5',
			true
		);

		wp_enqueue_script(
			'3dfootprint-cad-viewer-product-viewer',
			THREE_D_FOOTPRINT_CAD_VIEWER_URL . 'assets/frontend/product-viewer.js',
			array( '3dfootprint-cad-viewer-konva' ),
			THREE_D_FOOTPRINT_CAD_VIEWER_VERSION,
			true
		);

		wp_add_inline_script(
			'3dfootprint-cad-viewer-product-viewer',
			'window.ProductJSONViewerData = ' . $this->encode_script_data( $product_id ) . ';',
			'before'
		);
	}

	/**
	 * Render the custom product JSON viewer.
	 *
	 * @return void
	 */
	public function render_viewer() {
		$product_id = $this->get_current_product_id();

		if ( ! $product_id ) {
			return;
		}

		$json = $this->json_service->get_json( $product_id );

		if ( '' === $json ) {
			$this->render_empty_message( $product_id );
			return;
		}

		$json_array = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json_array ) ) {
			$this->render_empty_message( $product_id );
			return;
		}

		/**
		 * Filters the decoded product JSON used by the frontend viewer.
		 *
		 * @param array<mixed> $json_array Decoded product JSON.
		 * @param int          $product_id Product ID.
		 */
		$json_array = apply_filters( 'product_json_viewer_json', $json_array, $product_id );
		$safe_json  = $this->safe_json_encode( $json_array );

		if ( false === $safe_json ) {
			$this->render_empty_message( $product_id );
			return;
		}

		$html_template = $this->get_html_template( $product_id );
		$html          = str_replace(
			array( '(($JSON))', '(($PRODUCT_ID))' ),
			array( $safe_json, esc_attr( (string) $product_id ) ),
			$html_template
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template is plugin-owned/filterable and JSON is safely encoded for its script context.
	}

	/**
	 * Render the no-JSON fallback.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function render_empty_message( $product_id ) {
		/**
		 * Filters the frontend message shown when a product has no usable JSON.
		 *
		 * @param string $message    Empty JSON message.
		 * @param int    $product_id Product ID.
		 */
		$message = apply_filters(
			'product_json_viewer_empty_message',
			__( 'No product JSON file is available for this product.', '3dfootprint-cad-viewer' ),
			$product_id
		);

		printf(
			'<div class="woocommerce-product-gallery product-json-viewer-gallery product-json-viewer__empty"><p>%s</p></div>',
			wp_kses_post( $message )
		);
	}

	/**
	 * Return the frontend viewer HTML template.
	 *
	 * @param int $product_id Product ID.
	 * @return string HTML template.
	 */
	private function get_html_template( $product_id ) {
		$template_file = THREE_D_FOOTPRINT_CAD_VIEWER_PATH . 'templates/product-viewer.html';
		$html_template = is_readable( $template_file )
			? file_get_contents( $template_file ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			: '';

		if ( false === $html_template || '' === $html_template ) {
			$html_template = '<div class="woocommerce-product-gallery product-json-viewer-gallery" data-product-json-viewer data-product-id="(($PRODUCT_ID))"><script type="application/json" data-product-json-viewer-payload>(($JSON))</script></div>';
		}

		/**
		 * Filters the HTML template used by the frontend product JSON viewer.
		 *
		 * Include the (($JSON)) placeholder where the safely encoded product JSON
		 * should be inserted.
		 *
		 * @param string $html_template HTML template.
		 * @param int    $product_id    Product ID.
		 */
		return (string) apply_filters( 'product_json_viewer_html_template', $html_template, $product_id );
	}

	/**
	 * Encode script data for window.ProductJSONViewerData.
	 *
	 * @param int $product_id Product ID.
	 * @return string JSON object literal.
	 */
	private function encode_script_data( $product_id ) {
		$json_array = null;
		$json       = $this->json_service->get_json( $product_id );

		if ( '' !== $json ) {
			$decoded = json_decode( $json, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				/**
				 * Filters the decoded product JSON exposed to frontend scripts.
				 *
				 * @param array<mixed> $decoded    Decoded product JSON.
				 * @param int          $product_id Product ID.
				 */
				$json_array = apply_filters( 'product_json_viewer_json', $decoded, $product_id );
			}
		}

		$encoded = $this->safe_json_encode(
			array(
				'productId' => $product_id,
				'json'      => $json_array,
			)
		);

		return false === $encoded ? '{"productId":0,"json":null}' : $encoded;
	}

	/**
	 * Safely encode data for JavaScript or application/json script content.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false Encoded JSON, or false on failure.
	 */
	private function safe_json_encode( $data ) {
		return wp_json_encode(
			$data,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
	}

	/**
	 * Determine whether the gallery should be replaced for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True when the viewer should replace the gallery.
	 */
	private function should_replace_gallery( $product_id ) {
		$enabled = 'no' !== get_option( 'product_json_viewer_replace_gallery', 'yes' );

		/**
		 * Filters whether the viewer should replace the WooCommerce gallery.
		 *
		 * @param bool $enabled    Whether replacement is enabled.
		 * @param int  $product_id Product ID.
		 */
		return (bool) apply_filters( 'product_json_viewer_should_replace_gallery', $enabled, $product_id );
	}

	/**
	 * Return the current WooCommerce product ID.
	 *
	 * @return int Product ID, or 0.
	 */
	private function get_current_product_id() {
		$product_id = get_queried_object_id();

		if ( ! $product_id ) {
			global $product;

			if ( $product && is_a( $product, 'WC_Product' ) ) {
				$product_id = $product->get_id();
			}
		}

		return absint( $product_id );
	}
}
