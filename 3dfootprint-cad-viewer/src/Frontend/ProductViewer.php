<?php
/**
 * Frontend WooCommerce product JSON viewer.
 *
 * @package ThreeDFootprintCadViewer\Frontend
 */

namespace ThreeDFootprint\CadViewer\Frontend;

use ThreeDFootprint\CadViewer\Services\ProductJsonService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the WooCommerce product gallery with a JSON-powered custom viewer.
 */
class ProductViewer {

	/**
	 * Placeholder token used in the HTML template.
	 */
	private const JSON_PLACEHOLDER = '(($JSON))';

	/**
	 * Product JSON service.
	 *
	 * @var ProductJsonService
	 */
	private $json_service;

	/**
	 * Current product ID.
	 *
	 * @var int
	 */
	private $product_id = 0;

	/**
	 * Whether the viewer is active for the current product request.
	 *
	 * @var bool
	 */
	private $is_active = false;

	/**
	 * Create the frontend viewer.
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
		add_action( 'wp', array( $this, 'configure_product_gallery' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Remove the default WooCommerce gallery and add the custom viewer.
	 *
	 * @return void
	 */
	public function configure_product_gallery() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$this->product_id = absint( get_queried_object_id() );

		if ( ! $this->product_id ) {
			return;
		}

		/**
		 * Filters whether the Product JSON viewer should replace the WooCommerce gallery.
		 *
		 * @param bool $should_replace Whether to replace the default gallery.
		 * @param int  $product_id     Product ID.
		 */
		$this->is_active = (bool) apply_filters( 'product_json_viewer_should_replace_gallery', true, $this->product_id );

		if ( ! $this->is_active ) {
			return;
		}

		remove_action(
			'woocommerce_before_single_product_summary',
			'woocommerce_show_product_images',
			20
		);

		add_action(
			'woocommerce_before_single_product_summary',
			array( $this, 'render_viewer' ),
			20
		);
	}

	/**
	 * Enqueue frontend viewer assets on single product pages only.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! $this->is_active ) {
			return;
		}

		$product_id = $this->get_product_id();

		if ( ! $product_id ) {
			return;
		}

		wp_enqueue_style(
			'3dfootprint-cad-viewer-product-viewer',
			THREE_D_FOOTPRINT_CAD_VIEWER_URL . 'assets/frontend/product-viewer.css',
			array(),
			THREE_D_FOOTPRINT_CAD_VIEWER_VERSION
		);

		if ( ! $this->json_service->exists( $product_id ) ) {
			return;
		}

		$json_array = $this->get_filtered_json_array( $product_id );
		$data       = array(
			'productId' => $product_id,
			'json'      => $json_array,
		);
		$encoded_data = wp_json_encode(
			$data,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( false === $encoded_data ) {
			$encoded_data = '{"productId":' . absint( $product_id ) . ',"json":{}}';
		}

		/**
		 * Filters the Konva script URL used by the frontend viewer.
		 *
		 * Return an empty string to skip loading Konva when your theme or another
		 * plugin already provides it.
		 *
		 * @param string $url        Konva script URL.
		 * @param int    $product_id Product ID.
		 */
		$konva_url = (string) apply_filters(
			'product_json_viewer_konva_url',
			'https://cdn.jsdelivr.net/npm/konva@9.3.20/konva.min.js',
			$product_id
		);

		if ( '' !== $konva_url ) {
			wp_enqueue_script(
				'konva',
				esc_url_raw( $konva_url ),
				array(),
				'9.3.20',
				true
			);
		}

		wp_enqueue_script(
			'3dfootprint-cad-viewer-product-viewer',
			THREE_D_FOOTPRINT_CAD_VIEWER_URL . 'assets/frontend/product-viewer.js',
			array_filter( array( '' !== $konva_url ? 'konva' : '' ) ),
			THREE_D_FOOTPRINT_CAD_VIEWER_VERSION,
			true
		);

		wp_add_inline_script(
			'3dfootprint-cad-viewer-product-viewer',
			'window.ProductJSONViewerData = ' . $encoded_data . ';',
			'before'
		);
	}

	/**
	 * Render the custom product viewer.
	 *
	 * @return void
	 */
	public function render_viewer() {
		$product_id = $this->get_product_id();

		if ( ! $product_id ) {
			return;
		}

		if ( ! $this->json_service->exists( $product_id ) ) {
			echo '<div class="product-json-viewer product-json-viewer--empty">';
			echo wp_kses_post( $this->get_empty_message( $product_id ) );
			echo '</div>';
			return;
		}

		$html_template = $this->get_html_template( $product_id );
		$json_array    = $this->get_filtered_json_array( $product_id );
		$safe_json     = wp_json_encode(
			$json_array,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( false === $safe_json ) {
			$safe_json = '{}';
		}

		$html = str_replace( self::JSON_PLACEHOLDER, $safe_json, $html_template );

		echo $this->sanitize_viewer_html( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Return the active product ID.
	 *
	 * @return int Product ID.
	 */
	private function get_product_id() {
		if ( $this->product_id > 0 ) {
			return $this->product_id;
		}

		return absint( get_queried_object_id() );
	}

	/**
	 * Return the filtered decoded product JSON array.
	 *
	 * @param int $product_id Product ID.
	 * @return array<mixed> JSON array.
	 */
	private function get_filtered_json_array( $product_id ) {
		$json_array = $this->json_service->get_array( $product_id );

		/**
		 * Filters the decoded product JSON used by the frontend viewer.
		 *
		 * @param array<mixed> $json_array Product JSON array.
		 * @param int          $product_id Product ID.
		 */
		$json_array = apply_filters( 'product_json_viewer_json', $json_array, $product_id );

		return is_array( $json_array ) ? $json_array : array();
	}

	/**
	 * Return the fallback message shown when a product has no JSON file.
	 *
	 * @param int $product_id Product ID.
	 * @return string Fallback message.
	 */
	private function get_empty_message( $product_id ) {
		/**
		 * Filters the frontend fallback message when no product JSON exists.
		 *
		 * @param string $message    Fallback message.
		 * @param int    $product_id Product ID.
		 */
		return (string) apply_filters(
			'product_json_viewer_empty_message',
			__( 'No product JSON file is available for this product.', '3dfootprint-cad-viewer' ),
			$product_id
		);
	}

	/**
	 * Return the custom HTML template with the JSON placeholder.
	 *
	 * @param int $product_id Product ID.
	 * @return string HTML template.
	 */
	private function get_html_template( $product_id ) {
		$html_template = '
<div class="product-json-viewer" data-product-json-viewer>
	<script type="application/json" data-product-json-payload>' . self::JSON_PLACEHOLDER . '</script>
	<div class="product-json-viewer__main">
		<div class="product-json-viewer__box" id="pcb-box">
			<div class="product-json-viewer__header">
				<span class="product-json-viewer__label product-json-viewer__label--pcb">PCB</span>
				<div class="product-json-viewer__controls">
					<button type="button" class="product-json-viewer__button" id="fit-pcb">Fit</button>
					<span class="product-json-viewer__zoom" id="pcb-zoom">100%</span>
				</div>
			</div>
			<div class="product-json-viewer__canvas-wrap">
				<div id="pcb-canvas"></div>
			</div>
		</div>

		<div class="product-json-viewer__box" id="sch-box">
			<div class="product-json-viewer__header">
				<span class="product-json-viewer__label product-json-viewer__label--schematic">SCHEMATIC</span>
				<div class="product-json-viewer__controls">
					<button type="button" class="product-json-viewer__button" id="fit-sch">Fit</button>
					<span class="product-json-viewer__zoom" id="sch-zoom">100%</span>
				</div>
			</div>
			<div class="product-json-viewer__canvas-wrap">
				<div id="sch-canvas"></div>
			</div>
		</div>
	</div>
	<div class="product-json-viewer__loading" id="product-json-viewer-loading"><span>' . esc_html__( 'PARSING...', '3dfootprint-cad-viewer' ) . '</span></div>
	<div class="product-json-viewer__tooltip" id="product-json-viewer-tooltip"></div>
</div>';

		/**
		 * Filters the frontend viewer HTML template before placeholder replacement.
		 *
		 * The template should include the (($JSON)) placeholder wherever the safely
		 * encoded product JSON should be inserted.
		 *
		 * @param string $html_template HTML template.
		 * @param int    $product_id    Product ID.
		 */
		return (string) apply_filters( 'product_json_viewer_html_template', $html_template, $product_id );
	}

	/**
	 * Sanitize the rendered viewer HTML while allowing the JSON script payload.
	 *
	 * @param string $html Rendered viewer HTML.
	 * @return string Sanitized HTML.
	 */
	private function sanitize_viewer_html( $html ) {
		$allowed_html = wp_kses_allowed_html( 'post' );

		$allowed_html['script'] = array(
			'type'                      => true,
			'data-product-json-payload' => true,
		);
		if ( ! isset( $allowed_html['div'] ) ) {
			$allowed_html['div'] = array();
		}
		if ( ! isset( $allowed_html['span'] ) ) {
			$allowed_html['span'] = array();
		}

		$allowed_html['div']['data-product-json-viewer'] = true;
		$allowed_html['span']['data-product-json-viewer'] = true;

		return wp_kses( $html, $allowed_html );
	}
}
