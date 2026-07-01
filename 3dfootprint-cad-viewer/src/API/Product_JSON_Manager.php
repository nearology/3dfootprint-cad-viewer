<?php
/**
 * Public product JSON API facade.
 *
 * @package ThreeDFootprintCadViewer\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Product_JSON_Manager' ) ) {
	/**
	 * Public API for accessing WooCommerce product JSON files.
	 *
	 * This global facade is intentionally provided for themes, plugins, and
	 * custom frontend integrations that need a stable static API.
	 */
	final class Product_JSON_Manager {

		/**
		 * Determine whether a product JSON file exists.
		 *
		 * @param int $product_id WooCommerce product ID.
		 * @return bool True when the JSON file exists and is readable.
		 */
		public static function exists( $product_id ) {
			return \ThreeDFootprint\CadViewer\Core\Plugin::instance()->product_json_service()->exists( $product_id );
		}

		/**
		 * Return the absolute filesystem path for a product JSON file.
		 *
		 * @param int $product_id WooCommerce product ID.
		 * @return string Absolute filesystem path, or an empty string when unavailable.
		 */
		public static function get_file( $product_id ) {
			return \ThreeDFootprint\CadViewer\Core\Plugin::instance()->product_json_service()->get_file( $product_id );
		}

		/**
		 * Return the raw JSON string for a product.
		 *
		 * @param int $product_id WooCommerce product ID.
		 * @return string JSON string, or an empty string when unavailable.
		 */
		public static function get_json( $product_id ) {
			return \ThreeDFootprint\CadViewer\Core\Plugin::instance()->product_json_service()->get_json( $product_id );
		}

		/**
		 * Return the decoded associative array for a product JSON file.
		 *
		 * @param int $product_id WooCommerce product ID.
		 * @return array<mixed> Decoded JSON as an associative array, or an empty array.
		 */
		public static function get_array( $product_id ) {
			return \ThreeDFootprint\CadViewer\Core\Plugin::instance()->product_json_service()->get_array( $product_id );
		}
	}
}
