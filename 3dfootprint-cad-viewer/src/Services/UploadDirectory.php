<?php
/**
 * Upload directory management for product JSON files.
 *
 * @package ThreeDFootprintCadViewer\Services
 */

namespace ThreeDFootprint\CadViewer\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides safe paths for the product JSON upload directory.
 */
class UploadDirectory {

	/**
	 * Relative directory under wp-content.
	 *
	 * @var string
	 */
	private const RELATIVE_DIRECTORY = 'uploads/product-json';

	/**
	 * Return the relative storage directory.
	 *
	 * @return string Relative path from wp-content.
	 */
	public function get_relative_directory() {
		/**
		 * Filters the relative product JSON storage directory under wp-content.
		 *
		 * @param string $directory Relative directory.
		 */
		$directory = (string) apply_filters( 'product_json_relative_directory', self::RELATIVE_DIRECTORY );

		return trim( str_replace( array( '\\', '..' ), array( '/', '' ), $directory ), '/' );
	}

	/**
	 * Return the absolute storage directory path.
	 *
	 * @return string Absolute directory path.
	 */
	public function get_directory() {
		return trailingslashit( WP_CONTENT_DIR ) . $this->get_relative_directory();
	}

	/**
	 * Return the relative path for a product JSON file.
	 *
	 * @param int $product_id Product ID.
	 * @return string Relative path from wp-content.
	 */
	public function get_relative_file_path( $product_id ) {
		return $this->get_relative_directory() . '/' . absint( $product_id ) . '.json';
	}

	/**
	 * Return the absolute path for a product JSON file.
	 *
	 * @param int $product_id Product ID.
	 * @return string Absolute file path.
	 */
	public function get_file_path( $product_id ) {
		return trailingslashit( WP_CONTENT_DIR ) . $this->get_relative_file_path( $product_id );
	}

	/**
	 * Ensure the storage directory exists and contains basic hardening files.
	 *
	 * @return true|\WP_Error True on success, error on failure.
	 */
	public function ensure() {
		$directory = $this->get_directory();

		if ( ! wp_mkdir_p( $directory ) ) {
			return new \WP_Error(
				'product_json_directory_failed',
				__( 'Could not create the product JSON upload directory.', '3dfootprint-cad-viewer' )
			);
		}

		$this->write_hardening_file( trailingslashit( $directory ) . 'index.php', "<?php\n// Silence is golden.\n" );
		$this->write_hardening_file(
			trailingslashit( $directory ) . '.htaccess',
			"Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh|shtml)$\">\nRequire all denied\n</FilesMatch>\n"
		);

		return true;
	}

	/**
	 * Temporarily route wp_handle_upload() into the product JSON directory.
	 *
	 * @param array<string,string> $uploads WordPress upload directory data.
	 * @return array<string,string>
	 */
	public function filter_upload_dir( $uploads ) {
		$relative_directory = $this->get_relative_directory();
		$subdir             = preg_replace( '#^uploads/?#', '', $relative_directory );
		$base               = trailingslashit( WP_CONTENT_DIR ) . 'uploads';

		$uploads['path']    = $this->get_directory();
		$uploads['url']     = content_url( $relative_directory );
		$uploads['subdir']  = '/' . trim( (string) $subdir, '/' );
		$uploads['basedir'] = $base;
		$uploads['baseurl'] = content_url( 'uploads' );

		return $uploads;
	}

	/**
	 * Write a hardening file when it does not exist.
	 *
	 * @param string $file    Absolute file path.
	 * @param string $content File contents.
	 * @return void
	 */
	private function write_hardening_file( $file, $content ) {
		if ( file_exists( $file ) ) {
			return;
		}

		file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
