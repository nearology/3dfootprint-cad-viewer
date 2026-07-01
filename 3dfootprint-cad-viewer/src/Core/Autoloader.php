<?php
/**
 * PSR-4 autoloader for the plugin source tree.
 *
 * @package ThreeDFootprintCadViewer\Core
 */

namespace ThreeDFootprint\CadViewer\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and resolves plugin classes using a PSR-4 style mapping.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 *
	 * @var string
	 */
	private const PREFIX = 'ThreeDFootprint\\CadViewer\\';

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load a class file when it belongs to the plugin namespace.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( self::PREFIX ) );
		$relative_path  = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		$file           = THREE_D_FOOTPRINT_CAD_VIEWER_PATH . 'src/' . $relative_path;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
