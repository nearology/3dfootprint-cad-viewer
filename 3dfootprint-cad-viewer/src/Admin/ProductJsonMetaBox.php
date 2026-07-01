<?php
/**
 * WooCommerce product JSON admin meta box.
 *
 * @package ThreeDFootprintCadViewer\Admin
 */

namespace ThreeDFootprint\CadViewer\Admin;

use ThreeDFootprint\CadViewer\Services\ProductJsonService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds and handles the Product JSON meta box on WooCommerce product screens.
 */
class ProductJsonMetaBox {

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'product_json_save';

	/**
	 * Nonce field.
	 */
	private const NONCE_FIELD = 'product_json_nonce';

	/**
	 * File input field.
	 */
	private const FILE_FIELD = 'product_json_file';

	/**
	 * Delete submit field.
	 */
	private const DELETE_FIELD = 'product_json_delete';

	/**
	 * Product JSON service.
	 *
	 * @var ProductJsonService
	 */
	private $json_service;

	/**
	 * Create the meta box controller.
	 *
	 * @param ProductJsonService $json_service Product JSON service.
	 */
	public function __construct( ProductJsonService $json_service ) {
		$this->json_service = $json_service;
	}

	/**
	 * Register WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
		add_action( 'post_edit_form_tag', array( $this, 'add_form_encoding' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Add the Product JSON meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'product-json',
			__( 'Product JSON', '3dfootprint-cad-viewer' ),
			array( $this, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Add multipart encoding to the product edit form.
	 *
	 * @return void
	 */
	public function add_form_encoding() {
		$screen = get_current_screen();

		if ( $screen && 'product' === $screen->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/**
	 * Enqueue admin styles and scripts for product edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'3dfootprint-cad-viewer-admin',
			THREE_D_FOOTPRINT_CAD_VIEWER_URL . 'assets/css/admin.css',
			array(),
			THREE_D_FOOTPRINT_CAD_VIEWER_VERSION
		);

		wp_enqueue_script(
			'3dfootprint-cad-viewer-admin',
			THREE_D_FOOTPRINT_CAD_VIEWER_URL . 'assets/js/admin.js',
			array(),
			THREE_D_FOOTPRINT_CAD_VIEWER_VERSION,
			true
		);

		wp_localize_script(
			'3dfootprint-cad-viewer-admin',
			'ProductJsonAdmin',
			array(
				'i18n' => array(
					'noFileSelected' => __( 'No file selected.', '3dfootprint-cad-viewer' ),
				),
			)
		);
	}

	/**
	 * Render the Product JSON meta box.
	 *
	 * @param \WP_Post $post Product post object.
	 * @return void
	 */
	public function render( $post ) {
		$info      = $this->json_service->get_file_info( $post->ID );
		$max_size  = $this->json_service->get_max_upload_size();
		$has_file  = ! empty( $info['exists'] );
		$file_text = $has_file ? __( 'Replace file', '3dfootprint-cad-viewer' ) : __( 'Upload JSON file', '3dfootprint-cad-viewer' );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="product-json-box">
			<input
				type="file"
				id="product-json-file"
				class="product-json-box__input"
				name="<?php echo esc_attr( self::FILE_FIELD ); ?>"
				accept=".json,application/json"
			/>

			<div class="product-json-box__actions">
				<label class="button product-json-box__upload-button" for="product-json-file">
					<?php echo esc_html( $file_text ); ?>
				</label>

				<?php if ( $has_file ) : ?>
					<button
						type="submit"
						class="button product-json-box__delete-button"
						name="<?php echo esc_attr( self::DELETE_FIELD ); ?>"
						value="1"
						onclick="return window.confirm('<?php echo esc_js( __( 'Delete this product JSON file?', '3dfootprint-cad-viewer' ) ); ?>');"
					>
						<?php esc_html_e( 'Delete file', '3dfootprint-cad-viewer' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<p class="description product-json-box__selected" data-product-json-selected>
				<?php esc_html_e( 'No file selected.', '3dfootprint-cad-viewer' ); ?>
			</p>

			<p class="description">
				<?php
				printf(
					/* translators: %s: maximum upload size. */
					esc_html__( 'Maximum upload size: %s.', '3dfootprint-cad-viewer' ),
					esc_html( size_format( $max_size ) )
				);
				?>
			</p>

			<?php if ( $has_file ) : ?>
				<table class="widefat striped product-json-box__details">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Filename', '3dfootprint-cad-viewer' ); ?></th>
							<td><?php echo esc_html( $info['filename'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Upload date', '3dfootprint-cad-viewer' ); ?></th>
							<td>
								<?php
								echo esc_html(
									$info['uploaded_at']
										? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $info['uploaded_at'] ) )
										: __( 'Unknown', '3dfootprint-cad-viewer' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'File size', '3dfootprint-cad-viewer' ); ?></th>
							<td><?php echo esc_html( size_format( absint( $info['size'] ) ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Validation', '3dfootprint-cad-viewer' ); ?></th>
							<td>
								<?php if ( ! empty( $info['is_valid'] ) ) : ?>
									<span class="product-json-box__status product-json-box__status--valid">
										<?php esc_html_e( 'Valid JSON', '3dfootprint-cad-viewer' ); ?>
									</span>
								<?php else : ?>
									<span class="product-json-box__status product-json-box__status--invalid">
										<?php echo esc_html( $info['json_error'] ? $info['json_error'] : __( 'Invalid JSON', '3dfootprint-cad-viewer' ) ); ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php if ( ! empty( $info['preview'] ) ) : ?>
					<label class="product-json-box__preview-label" for="product-json-preview">
						<?php esc_html_e( 'Preview', '3dfootprint-cad-viewer' ); ?>
					</label>
					<pre id="product-json-preview" class="product-json-box__preview"><?php echo esc_html( $info['preview'] ); ?></pre>
				<?php endif; ?>
			<?php else : ?>
				<p class="product-json-box__empty">
					<?php esc_html_e( 'No JSON file has been uploaded for this product.', '3dfootprint-cad-viewer' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save, replace, or delete the product JSON file.
	 *
	 * @param int      $post_id Product ID.
	 * @param \WP_Post $post    Product post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		unset( $post );

		if ( ! $this->can_save( $post_id ) ) {
			return;
		}

		if ( $this->is_delete_requested() ) {
			$result = $this->json_service->delete( $post_id );

			if ( is_wp_error( $result ) ) {
				$this->set_notice( 'error', $result->get_error_message() );
				return;
			}

			$this->set_notice( 'success', __( 'Product JSON file deleted.', '3dfootprint-cad-viewer' ) );
			return;
		}

		if ( ! $this->has_upload() ) {
			return;
		}

		$result = $this->json_service->upload( $post_id, $_FILES[ self::FILE_FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
			return;
		}

		$this->set_notice( 'success', __( 'Product JSON file uploaded and validated.', '3dfootprint-cad-viewer' ) );
	}

	/**
	 * Render a persisted admin notice after product save.
	 *
	 * @return void
	 */
	public function render_notice() {
		$notice = get_transient( $this->get_notice_key() );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) || empty( $notice['type'] ) ) {
			return;
		}

		delete_transient( $this->get_notice_key() );

		$type = 'success' === $notice['type'] ? 'success' : 'error';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Determine whether the current product save request can be handled.
	 *
	 * @param int $post_id Product ID.
	 * @return bool
	 */
	private function can_save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return false;
		}

		return current_user_can( 'edit_product', $post_id ) || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Determine whether the delete button was submitted.
	 *
	 * @return bool
	 */
	private function is_delete_requested() {
		return isset( $_POST[ self::DELETE_FIELD ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ self::DELETE_FIELD ] ) );
	}

	/**
	 * Determine whether a file upload was provided.
	 *
	 * @return bool
	 */
	private function has_upload() {
		return isset( $_FILES[ self::FILE_FIELD ] )
			&& isset( $_FILES[ self::FILE_FIELD ]['error'] )
			&& UPLOAD_ERR_NO_FILE !== absint( $_FILES[ self::FILE_FIELD ]['error'] );
	}

	/**
	 * Persist an admin notice for display after the save redirect.
	 *
	 * @param string $type    Notice type: success or error.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			$this->get_notice_key(),
			array(
				'type'    => sanitize_key( $type ),
				'message' => wp_strip_all_tags( $message ),
			),
			60
		);
	}

	/**
	 * Return the current user's transient key for product JSON notices.
	 *
	 * @return string Transient key.
	 */
	private function get_notice_key() {
		return 'product_json_admin_notice_' . get_current_user_id();
	}
}
