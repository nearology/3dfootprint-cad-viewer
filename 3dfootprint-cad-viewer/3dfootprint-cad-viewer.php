<?php
/**
 * Plugin Name: 3DFootprint CAD Viewer
 * Plugin URI: https://aminx.me
 * Description: Upload, validate, store, retrieve, and render one JSON viewer for each WooCommerce product.
 * Version: 1.1.1
 * Author: thenearology@gmail.com
 * Author URI: https://aminx.me
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: 3dfootprint-cad-viewer
 * Domain Path: /languages
 *
 * @package ThreeDFootprintCadViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'THREE_D_FOOTPRINT_CAD_VIEWER_VERSION', '1.1.1' );
define( 'THREE_D_FOOTPRINT_CAD_VIEWER_FILE', __FILE__ );
define( 'THREE_D_FOOTPRINT_CAD_VIEWER_PATH', plugin_dir_path( __FILE__ ) );
define( 'THREE_D_FOOTPRINT_CAD_VIEWER_URL', plugin_dir_url( __FILE__ ) );
define( 'THREE_D_FOOTPRINT_CAD_VIEWER_BASENAME', plugin_basename( __FILE__ ) );

require_once THREE_D_FOOTPRINT_CAD_VIEWER_PATH . 'src/Core/Autoloader.php';

\ThreeDFootprint\CadViewer\Core\Autoloader::register();

require_once THREE_D_FOOTPRINT_CAD_VIEWER_PATH . 'src/API/Product_JSON_Manager.php';

register_activation_hook(
	__FILE__,
	static function () {
		$upload_directory = new \ThreeDFootprint\CadViewer\Services\UploadDirectory();
		$upload_directory->ensure();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		\ThreeDFootprint\CadViewer\Core\Plugin::instance()->init();
	}
);
