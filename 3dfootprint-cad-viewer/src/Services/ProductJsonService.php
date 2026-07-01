<?php
/**
 * Product JSON storage and retrieval service.
 *
 * @package ThreeDFootprintCadViewer\Services
 */

namespace ThreeDFootprint\CadViewer\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles validation, storage, deletion, and retrieval of product JSON files.
 */
class ProductJsonService {

	/**
	 * Product meta key containing the relative JSON file path.
	 */
	public const META_KEY = '_product_json_file';

	/**
	 * Default maximum upload size in bytes.
	 */
	private const DEFAULT_MAX_UPLOAD_SIZE = 5242880;

	/**
	 * Upload directory helper.
	 *
	 * @var UploadDirectory
	 */
	private $upload_directory;

	/**
	 * Create the service.
	 *
	 * @param UploadDirectory $upload_directory Upload directory helper.
	 */
	public function __construct( UploadDirectory $upload_directory ) {
		$this->upload_directory = $upload_directory;
	}

	/**
	 * Determine whether a product JSON file exists.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True when the product has a readable JSON file.
	 */
	public function exists( $product_id ) {
		$file = $this->get_file( $product_id );

		return '' !== $file && is_readable( $file );
	}

	/**
	 * Return the absolute filesystem path for a product JSON file.
	 *
	 * @param int $product_id Product ID.
	 * @return string Absolute path, or an empty string when no meta path exists.
	 */
	public function get_file( $product_id ) {
		$relative_path = $this->get_relative_path( $product_id );

		if ( '' === $relative_path ) {
			return '';
		}

		return $this->relative_path_to_absolute_path( $relative_path );
	}

	/**
	 * Return the raw JSON string for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return string JSON string, or an empty string when unavailable.
	 */
	public function get_json( $product_id ) {
		$file = $this->get_file( $product_id );

		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}

		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return false === $json ? '' : $json;
	}

	/**
	 * Return the decoded associative array for a product JSON file.
	 *
	 * @param int $product_id Product ID.
	 * @return array<mixed> Decoded JSON data, or an empty array when unavailable or invalid.
	 */
	public function get_array( $product_id ) {
		$json = $this->get_json( $product_id );

		if ( '' === $json ) {
			return array();
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Upload, validate, and store a product JSON file.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string,mixed>  $file       One item from the $_FILES superglobal.
	 * @return array<string,mixed>|\WP_Error Upload result, or error on failure.
	 */
	public function upload( $product_id, array $file ) {
		$product_id = absint( $product_id );

		if ( ! $this->is_valid_product( $product_id ) ) {
			return new \WP_Error(
				'product_json_invalid_product',
				__( 'Invalid WooCommerce product.', '3dfootprint-cad-viewer' )
			);
		}

		$validation = $this->validate_uploaded_file( $file );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$json_array       = $validation['array'];
		$file['name']     = $product_id . '.json';
		$file['type']     = 'application/json';
		$directory_result = $this->upload_directory->ensure();

		if ( is_wp_error( $directory_result ) ) {
			return $directory_result;
		}

		add_filter( 'upload_dir', array( $this->upload_directory, 'filter_upload_dir' ) );

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'test_type' => false,
				'mimes'     => array(
					'json' => 'application/json',
				),
			)
		);

		remove_filter( 'upload_dir', array( $this->upload_directory, 'filter_upload_dir' ) );

		if ( isset( $upload['error'] ) ) {
			return new \WP_Error( 'product_json_upload_failed', sanitize_text_field( $upload['error'] ) );
		}

		$stored_file = isset( $upload['file'] ) ? (string) $upload['file'] : '';

		if ( '' === $stored_file || ! is_readable( $stored_file ) ) {
			return new \WP_Error(
				'product_json_upload_missing',
				__( 'The uploaded JSON file could not be stored.', '3dfootprint-cad-viewer' )
			);
		}

		$expected_file = $this->upload_directory->get_file_path( $product_id );

		if ( wp_normalize_path( $stored_file ) !== wp_normalize_path( $expected_file ) ) {
			$backup_file = '';

			if ( file_exists( $expected_file ) ) {
				$backup_file = $expected_file . '.backup-' . wp_generate_uuid4();

				if ( ! rename( $expected_file, $backup_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					wp_delete_file( $stored_file );

					return new \WP_Error(
						'product_json_backup_failed',
						__( 'Could not prepare the existing product JSON file for replacement.', '3dfootprint-cad-viewer' )
					);
				}
			}

			if ( ! rename( $stored_file, $expected_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				if ( '' !== $backup_file && file_exists( $backup_file ) ) {
					rename( $backup_file, $expected_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}

				wp_delete_file( $stored_file );

				return new \WP_Error(
					'product_json_rename_failed',
					__( 'Could not move the uploaded JSON file into place.', '3dfootprint-cad-viewer' )
				);
			}

			if ( '' !== $backup_file && file_exists( $backup_file ) ) {
				wp_delete_file( $backup_file );
			}
		}

		$relative_path = $this->upload_directory->get_relative_file_path( $product_id );

		update_post_meta( $product_id, self::META_KEY, $relative_path );

		/**
		 * Fires after a product JSON file has been successfully uploaded.
		 *
		 * @param int          $product_id Product ID.
		 * @param array<mixed> $json_array Decoded JSON data.
		 */
		do_action( 'product_json_updated', $product_id, $json_array );

		return array(
			'file'          => $expected_file,
			'relative_path' => $relative_path,
			'json_array'    => $json_array,
		);
	}

	/**
	 * Delete a product JSON file and its stored meta path.
	 *
	 * @param int $product_id Product ID.
	 * @return true|\WP_Error True on success, or error on failure.
	 */
	public function delete( $product_id ) {
		$product_id = absint( $product_id );
		$file       = $this->get_file( $product_id );

		if ( '' !== $file && file_exists( $file ) && ! wp_delete_file( $file ) ) {
			return new \WP_Error(
				'product_json_delete_failed',
				__( 'Could not delete the product JSON file.', '3dfootprint-cad-viewer' )
			);
		}

		delete_post_meta( $product_id, self::META_KEY );

		return true;
	}

	/**
	 * Return metadata about the stored product JSON file.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string,mixed>
	 */
	public function get_file_info( $product_id ) {
		$file          = $this->get_file( $product_id );
		$exists        = '' !== $file && is_readable( $file );
		$relative_path = $this->get_relative_path( $product_id );
		$json          = $exists ? $this->get_json( $product_id ) : '';
		$json_array    = array();
		$json_error    = '';
		$is_valid      = false;

		if ( '' !== $json ) {
			$json_array = json_decode( $json, true );
			$is_valid   = JSON_ERROR_NONE === json_last_error();
			$json_error = $is_valid ? '' : json_last_error_msg();
		}

		return array(
			'exists'        => $exists,
			'file'          => $file,
			'relative_path' => $relative_path,
			'filename'      => $exists ? basename( $file ) : '',
			'uploaded_at'   => $exists ? filemtime( $file ) : 0,
			'size'          => $exists ? filesize( $file ) : 0,
			'is_valid'      => $is_valid,
			'json_error'    => $json_error,
			'preview'       => $exists ? $this->get_preview( $json ) : '',
			'array'         => is_array( $json_array ) ? $json_array : array(),
		);
	}

	/**
	 * Return the configured maximum upload size.
	 *
	 * @return int Maximum upload size in bytes.
	 */
	public function get_max_upload_size() {
		$max_size = min( self::DEFAULT_MAX_UPLOAD_SIZE, wp_max_upload_size() );

		/**
		 * Filters the maximum product JSON upload size.
		 *
		 * @param int $max_size Maximum upload size in bytes.
		 */
		return max( 1, absint( apply_filters( 'product_json_max_upload_size', $max_size ) ) );
	}

	/**
	 * Return the stored relative product JSON path.
	 *
	 * @param int $product_id Product ID.
	 * @return string Relative path from wp-content.
	 */
	public function get_relative_path( $product_id ) {
		$relative_path = (string) get_post_meta( absint( $product_id ), self::META_KEY, true );
		$relative_path = trim( wp_normalize_path( $relative_path ), '/' );

		if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) ) {
			return '';
		}

		$expected_prefix = $this->upload_directory->get_relative_directory() . '/';

		if ( 0 !== strpos( $relative_path, $expected_prefix ) ) {
			return '';
		}

		return $relative_path;
	}

	/**
	 * Validate an uploaded JSON file before it is stored.
	 *
	 * @param array<string,mixed> $file One item from the $_FILES superglobal.
	 * @return array<string,mixed>|\WP_Error Parsed validation data, or error.
	 */
	private function validate_uploaded_file( array $file ) {
		$error = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'product_json_upload_error', $this->get_upload_error_message( $error ) );
		}

		$name     = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size     = isset( $file['size'] ) ? absint( $file['size'] ) : 0;

		if ( '' === $tmp_name || ! is_readable( $tmp_name ) ) {
			return new \WP_Error(
				'product_json_tmp_missing',
				__( 'The uploaded JSON file could not be read.', '3dfootprint-cad-viewer' )
			);
		}

		if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error(
				'product_json_invalid_extension',
				__( 'Only .json files are allowed.', '3dfootprint-cad-viewer' )
			);
		}

		if ( 0 === $size ) {
			return new \WP_Error(
				'product_json_empty_file',
				__( 'The uploaded JSON file is empty.', '3dfootprint-cad-viewer' )
			);
		}

		if ( $size > $this->get_max_upload_size() ) {
			return new \WP_Error(
				'product_json_file_too_large',
				sprintf(
					/* translators: %s: maximum upload size. */
					__( 'The JSON file is too large. Maximum allowed size is %s.', '3dfootprint-cad-viewer' ),
					size_format( $this->get_max_upload_size() )
				)
			);
		}

		$mime_error = $this->validate_mime_type( $tmp_name, $name );

		if ( is_wp_error( $mime_error ) ) {
			return $mime_error;
		}

		$json = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $json ) {
			return new \WP_Error(
				'product_json_read_failed',
				__( 'The uploaded JSON file could not be read.', '3dfootprint-cad-viewer' )
			);
		}

		$array = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'product_json_invalid_json',
				sprintf(
					/* translators: %s: JSON parser error message. */
					__( 'Invalid JSON: %s', '3dfootprint-cad-viewer' ),
					json_last_error_msg()
				)
			);
		}

		return array(
			'json'  => $json,
			'array' => is_array( $array ) ? $array : array(),
		);
	}

	/**
	 * Validate the uploaded file MIME type.
	 *
	 * @param string $tmp_name Temporary upload path.
	 * @param string $name     Original sanitized filename.
	 * @return true|\WP_Error True on success, error on failure.
	 */
	private function validate_mime_type( $tmp_name, $name ) {
		$filetype = wp_check_filetype_and_ext(
			$tmp_name,
			$name,
			array(
				'json' => 'application/json',
			)
		);
		$extension_check = wp_check_filetype(
			$name,
			array(
				'json' => 'application/json',
			)
		);

		if (
			( ! empty( $filetype['ext'] ) && 'json' !== $filetype['ext'] )
			|| 'json' !== $extension_check['ext']
		) {
			return new \WP_Error(
				'product_json_invalid_filetype',
				__( 'The uploaded file is not a valid JSON file.', '3dfootprint-cad-viewer' )
			);
		}

		$detected_mime = $this->detect_mime_type( $tmp_name );

		/**
		 * Filters the allowed MIME types for product JSON uploads.
		 *
		 * Some servers report JSON as text/plain, so that is allowed by default
		 * after extension and parser validation have both passed.
		 *
		 * @param string[] $mime_types Allowed MIME types.
		 */
		$allowed_mimes = (array) apply_filters(
			'product_json_allowed_mime_types',
			array(
				'application/json',
				'text/json',
				'text/plain',
				'application/octet-stream',
			)
		);

		if ( '' !== $detected_mime && ! in_array( $detected_mime, $allowed_mimes, true ) ) {
			return new \WP_Error(
				'product_json_invalid_mime',
				sprintf(
					/* translators: %s: detected MIME type. */
					__( 'The uploaded file MIME type is not allowed: %s', '3dfootprint-cad-viewer' ),
					$detected_mime
				)
			);
		}

		return true;
	}

	/**
	 * Detect a file MIME type using PHP's fileinfo extension when available.
	 *
	 * @param string $file Absolute or temporary file path.
	 * @return string MIME type, or empty string when unavailable.
	 */
	private function detect_mime_type( $file ) {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $finfo ) {
			return '';
		}

		$mime = finfo_file( $finfo, $file );
		finfo_close( $finfo );

		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * Convert a safe relative product JSON path into an absolute path.
	 *
	 * @param string $relative_path Relative path from wp-content.
	 * @return string Absolute path.
	 */
	private function relative_path_to_absolute_path( $relative_path ) {
		$absolute_path = trailingslashit( WP_CONTENT_DIR ) . trim( $relative_path, '/' );
		$normalized    = wp_normalize_path( $absolute_path );
		$base          = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . $this->upload_directory->get_relative_directory() );

		if ( 0 !== strpos( $normalized, trailingslashit( $base ) ) ) {
			return '';
		}

		return $absolute_path;
	}

	/**
	 * Return a short preview of the first JSON lines.
	 *
	 * @param string $json JSON string.
	 * @return string Preview text.
	 */
	private function get_preview( $json ) {
		$lines = preg_split( '/\r\n|\r|\n/', $json );

		if ( ! is_array( $lines ) ) {
			return '';
		}

		$lines = array_slice( $lines, 0, 12 );

		return implode( "\n", $lines );
	}

	/**
	 * Determine whether a post ID is a WooCommerce product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private function is_valid_product( $product_id ) {
		return $product_id > 0 && 'product' === get_post_type( $product_id );
	}

	/**
	 * Return a readable PHP upload error message.
	 *
	 * @param int $error PHP upload error code.
	 * @return string Error message.
	 */
	private function get_upload_error_message( $error ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the server upload limit.', '3dfootprint-cad-viewer' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the form upload limit.', '3dfootprint-cad-viewer' ),
			UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded.', '3dfootprint-cad-viewer' ),
			UPLOAD_ERR_NO_FILE    => __( 'No JSON file was uploaded.', '3dfootprint-cad-viewer' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'The server temporary upload directory is missing.', '3dfootprint-cad-viewer' ),
			UPLOAD_ERR_CANT_WRITE => __( 'The server could not write the uploaded file.', '3dfootprint-cad-viewer' ),
			UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the upload.', '3dfootprint-cad-viewer' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'Unknown upload error.', '3dfootprint-cad-viewer' );
	}
}
