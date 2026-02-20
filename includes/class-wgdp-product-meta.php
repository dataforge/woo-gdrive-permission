<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Product_Meta {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Simple product tabs and panels.
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

		// Variation fields.
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_meta' ), 10, 2 );

		// AJAX endpoints.
		add_action( 'wp_ajax_wgdp_browse_drive', array( $this, 'ajax_browse_drive' ) );
		add_action( 'wp_ajax_wgdp_get_file_info', array( $this, 'ajax_get_file_info' ) );

		// Enqueue assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add "GDrive" product data tab.
	 */
	public function add_product_tab( $tabs ) {
		$tabs['wgdp_drive'] = array(
			'label'    => __( 'GDrive', 'woo-gdrive-permission' ),
			'target'   => 'wgdp_drive_data',
			'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_external' ),
			'priority' => 80,
		);
		return $tabs;
	}

	/**
	 * Render the simple product panel.
	 */
	public function render_product_panel() {
		global $post;
		$product_id    = $post->ID;
		$resource_id   = get_post_meta( $product_id, '_wgdp_drive_resource_id', true );
		$resource_type = get_post_meta( $product_id, '_wgdp_drive_resource_type', true );
		$resource_name = get_post_meta( $product_id, '_wgdp_drive_resource_name', true );
		$account_id    = get_post_meta( $product_id, '_wgdp_account_id', true );

		$release_mode  = get_post_meta( $product_id, '_wgdp_release_mode', true ) ?: 'immediate';
		$threshold_qty = (int) get_post_meta( $product_id, '_wgdp_threshold_qty', true );
		$paid_qty      = (int) get_post_meta( $product_id, '_wgdp_paid_qty_total', true );
		$is_released   = '1' === get_post_meta( $product_id, '_wgdp_is_released', true );
		$released_at   = get_post_meta( $product_id, '_wgdp_released_at', true );
		?>
		<div id="wgdp_drive_data" class="panel woocommerce_options_panel">
			<div class="options_group show_if_simple show_if_external hide_if_variable">
				<?php $this->render_drive_fields( '', $resource_id, $resource_type, $resource_name, $account_id ); ?>
			</div>

			<div class="options_group show_if_variable hide_if_simple hide_if_external" style="display:none;">
				<p class="form-field">
					<label>&nbsp;</label>
					<span class="description"><?php esc_html_e( 'Configure Drive resources on each variation in the Variations tab.', 'woo-gdrive-permission' ); ?></span>
				</p>
			</div>

			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Release Gate', 'woo-gdrive-permission' ); ?></strong></p>
				<p class="form-field">
					<label>&nbsp;</label>
					<span class="description"><?php esc_html_e( 'Controls when buyers receive Google Drive access. This applies across all variations.', 'woo-gdrive-permission' ); ?></span>
				</p>

				<p class="form-field">
					<label for="_wgdp_release_mode"><?php esc_html_e( 'Release Mode', 'woo-gdrive-permission' ); ?></label>
					<select id="_wgdp_release_mode" name="_wgdp_release_mode" class="short">
						<option value="immediate" <?php selected( $release_mode, 'immediate' ); ?>><?php esc_html_e( 'Immediate', 'woo-gdrive-permission' ); ?></option>
						<option value="manual_release" <?php selected( $release_mode, 'manual_release' ); ?>><?php esc_html_e( 'Manual Release', 'woo-gdrive-permission' ); ?></option>
						<option value="min_sales_qty" <?php selected( $release_mode, 'min_sales_qty' ); ?>><?php esc_html_e( 'Min Sales Qty', 'woo-gdrive-permission' ); ?></option>
					</select>
				</p>
				<p class="form-field">
					<label>&nbsp;</label>
					<span class="description"><?php
						echo '<strong>' . esc_html__( 'Immediate', 'woo-gdrive-permission' ) . '</strong> &mdash; ' . esc_html__( 'Drive access is granted as soon as the buyer verifies their email.', 'woo-gdrive-permission' ) . '<br>';
						echo '<strong>' . esc_html__( 'Manual Release', 'woo-gdrive-permission' ) . '</strong> &mdash; ' . esc_html__( 'Drive access is held until you manually click "Release Digital Now". Useful for pre-orders or unreleased content.', 'woo-gdrive-permission' ) . '<br>';
						echo '<strong>' . esc_html__( 'Min Sales Qty', 'woo-gdrive-permission' ) . '</strong> &mdash; ' . esc_html__( 'Drive access is held until total sales across all variations reach the threshold. Useful for crowdfunding-style releases.', 'woo-gdrive-permission' );
					?></span>
				</p>

				<p class="form-field wgdp-show-if-min-sales" <?php echo 'min_sales_qty' !== $release_mode ? 'style="display:none;"' : ''; ?>>
					<label for="_wgdp_threshold_qty"><?php esc_html_e( 'Sales Threshold', 'woo-gdrive-permission' ); ?></label>
					<input type="number" id="_wgdp_threshold_qty" name="_wgdp_threshold_qty" class="short" min="0" step="1" value="<?php echo esc_attr( $threshold_qty ); ?>" />
				</p>

				<p class="form-field">
					<label><?php esc_html_e( 'Sales Count', 'woo-gdrive-permission' ); ?></label>
					<span class="wgdp-sales-count-value"><?php echo esc_html( $paid_qty ); ?></span>
					<?php if ( 'immediate' !== $release_mode ) : ?>
						<button type="button" class="button button-small wgdp-recalculate-sales-btn" data-product-id="<?php echo esc_attr( $product_id ); ?>"><?php esc_html_e( 'Recalculate', 'woo-gdrive-permission' ); ?></button>
					<?php endif; ?>
				</p>

				<?php if ( 'immediate' !== $release_mode ) : ?>
				<p class="form-field">
					<label><?php esc_html_e( 'Release Status', 'woo-gdrive-permission' ); ?></label>
					<?php if ( $is_released ) : ?>
						<span class="wgdp-release-gate-status wgdp-release-gate-status--released"><?php esc_html_e( 'Released', 'woo-gdrive-permission' ); ?></span>
						<?php if ( $released_at ) : ?>
							<span class="description"><?php echo esc_html( $released_at ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<span class="wgdp-release-gate-status wgdp-release-gate-status--pending"><?php esc_html_e( 'Pending', 'woo-gdrive-permission' ); ?></span>
						<button type="button" class="button button-small wgdp-release-now-btn" data-product-id="<?php echo esc_attr( $product_id ); ?>"><?php esc_html_e( 'Release Digital Now', 'woo-gdrive-permission' ); ?></button>
					<?php endif; ?>
				</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Drive fields (reusable for simple and variations).
	 */
	private function render_drive_fields( $name_prefix, $resource_id, $resource_type, $resource_name, $account_id = '' ) {
		$auth     = WGDP_Google_Auth::instance();
		$accounts = $auth->get_accounts();
		$has_accounts = $auth->has_accounts();

		$id_field_name      = $name_prefix ? $name_prefix . '[_wgdp_drive_resource_id]' : '_wgdp_drive_resource_id';
		$type_field_name    = $name_prefix ? $name_prefix . '[_wgdp_drive_resource_type]' : '_wgdp_drive_resource_type';
		$name_field_name    = $name_prefix ? $name_prefix . '[_wgdp_drive_resource_name]' : '_wgdp_drive_resource_name';
		$account_field_name = $name_prefix ? $name_prefix . '[_wgdp_account_id]' : '_wgdp_account_id';
		$unique_id          = $name_prefix ? 'wgdp-' . esc_attr( $name_prefix ) : 'wgdp-simple';

		if ( ! $has_accounts ) : ?>
			<p class="form-field">
				<label>&nbsp;</label>
				<span class="description">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wgdp&tab=settings' ) ); ?>">Connect a Google account in settings first.</a>
				</span>
			</p>
		<?php endif; ?>

		<p class="form-field">
			<label for="<?php echo esc_attr( $unique_id ); ?>-account-id">
				<?php esc_html_e( 'Google Account', 'woo-gdrive-permission' ); ?>
			</label>
			<select class="short wgdp-account-select"
				id="<?php echo esc_attr( $unique_id ); ?>-account-id"
				name="<?php echo esc_attr( $account_field_name ); ?>">
				<option value=""><?php esc_html_e( '— Select Account —', 'woo-gdrive-permission' ); ?></option>
				<?php foreach ( $accounts as $acct_id => $acct ) : ?>
					<option value="<?php echo esc_attr( $acct_id ); ?>" <?php selected( $account_id, $acct_id ); ?>>
						<?php
						$acct_display = $acct['label'] ?: 'Google Account';
						if ( ! empty( $acct['email'] ) && $acct['email'] !== $acct['label'] ) {
							$acct_display .= ' (' . $acct['email'] . ')';
						}
						echo esc_html( $acct_display );
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<input type="hidden" class="wgdp-resource-id" name="<?php echo esc_attr( $id_field_name ); ?>" value="<?php echo esc_attr( $resource_id ); ?>" />
		<input type="hidden" class="wgdp-resource-type" name="<?php echo esc_attr( $type_field_name ); ?>" value="<?php echo esc_attr( $resource_type ); ?>" />
		<input type="hidden" class="wgdp-resource-name" name="<?php echo esc_attr( $name_field_name ); ?>" value="<?php echo esc_attr( $resource_name ); ?>" />

		<?php $has_resource = $resource_id && $resource_name; ?>

		<p class="form-field wgdp-resource-preview" <?php echo $has_resource ? '' : 'style="display:none;"'; ?>>
			<label><?php esc_html_e( 'Drive Resource', 'woo-gdrive-permission' ); ?></label>
			<span class="wgdp-resource-info">
				<?php if ( $has_resource ) : ?>
					<strong><?php echo esc_html( $resource_name ); ?></strong>
					(<?php echo esc_html( $resource_type ?: 'file' ); ?>)
					&mdash;
					<a href="<?php echo esc_url( WGDP_Google_Drive::build_web_link( $resource_id, $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' ) ); ?>" target="_blank">View in Drive</a>
				<?php endif; ?>
			</span>
			<a href="#" class="wgdp-resource-change"><?php esc_html_e( 'Change', 'woo-gdrive-permission' ); ?></a>
			<a href="#" class="wgdp-resource-clear" style="color:#b32d2e;margin-left:8px;"><?php esc_html_e( 'Remove', 'woo-gdrive-permission' ); ?></a>
		</p>

		<div class="wgdp-resource-input-wrap" <?php echo $has_resource ? 'style="display:none;"' : ''; ?>>
			<p class="form-field">
				<label for="<?php echo esc_attr( $unique_id ); ?>-resource-id">
					<?php esc_html_e( 'Drive Resource', 'woo-gdrive-permission' ); ?>
				</label>
				<input type="text"
					class="short wgdp-resource-url-input"
					id="<?php echo esc_attr( $unique_id ); ?>-resource-id"
					placeholder="<?php esc_attr_e( 'Paste a GDrive URL or click Browse', 'woo-gdrive-permission' ); ?>"
				/>
			</p>
			<p class="form-field">
				<label>&nbsp;</label>
				<button type="button" class="button wgdp-browse-drive"><?php esc_html_e( 'Browse GDrive', 'woo-gdrive-permission' ); ?></button>
				<?php if ( $has_resource ) : ?>
					<a href="#" class="wgdp-resource-cancel" style="margin-left:8px;"><?php esc_html_e( 'Cancel', 'woo-gdrive-permission' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save simple product meta.
	 */
	public function save_product_meta( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array( '_wgdp_drive_resource_id', '_wgdp_drive_resource_type', '_wgdp_drive_resource_name', '_wgdp_account_id' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
				if ( '_wgdp_drive_resource_id' === $field && ! empty( $value ) ) {
					$value = WGDP_Google_Drive::extract_id_from_url( $value );
				}
				update_post_meta( $post_id, $field, $value );
			}
		}

		// Release gate fields.
		if ( isset( $_POST['_wgdp_release_mode'] ) ) {
			$mode = sanitize_text_field( wp_unslash( $_POST['_wgdp_release_mode'] ) );
			if ( in_array( $mode, array( 'immediate', 'manual_release', 'min_sales_qty' ), true ) ) {
				update_post_meta( $post_id, '_wgdp_release_mode', $mode );
			}
		}
		if ( isset( $_POST['_wgdp_threshold_qty'] ) ) {
			update_post_meta( $post_id, '_wgdp_threshold_qty', absint( $_POST['_wgdp_threshold_qty'] ) );
		}
	}

	/**
	 * Render variation fields.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) {
		$variation_id    = $variation->ID;
		$resource_id     = get_post_meta( $variation_id, '_wgdp_drive_resource_id', true );
		$resource_type   = get_post_meta( $variation_id, '_wgdp_drive_resource_type', true );
		$resource_name   = get_post_meta( $variation_id, '_wgdp_drive_resource_name', true );
		$account_id      = get_post_meta( $variation_id, '_wgdp_account_id', true );
		$includes_digital = get_post_meta( $variation_id, '_wgdp_includes_digital', true );

		// Backward compat: if includes_digital was never set, infer from legacy format_type.
		if ( '' === $includes_digital ) {
			$format_type = get_post_meta( $variation_id, '_wgdp_format_type', true );
			$includes_digital = ( empty( $format_type ) || 'digital_only' === $format_type ) ? 'yes' : 'no';
		}

		$name_prefix = 'wgdp_var[' . $loop . ']';

		echo '<div class="wgdp-variation-fields">';
		echo '<p class="form-row form-row-full"><strong>' . esc_html__( 'GDrive Access', 'woo-gdrive-permission' ) . '</strong></p>';

		// Includes digital checkbox with explanation.
		echo '<p class="form-row form-row-full">';
		echo '<label>';
		echo '<input type="hidden" name="' . esc_attr( $name_prefix . '[_wgdp_includes_digital]' ) . '" value="no" />';
		echo '<input type="checkbox" class="wgdp-includes-digital-cb" name="' . esc_attr( $name_prefix . '[_wgdp_includes_digital]' ) . '" value="yes"'
			. checked( $includes_digital, 'yes', false ) . ' /> ';
		echo esc_html__( 'Includes Digital Access', 'woo-gdrive-permission' );
		echo '</label>';
		echo '<br><span class="description">'
			. esc_html__( 'Enable this if purchasing this variation should grant the buyer Google Drive access. Leave unchecked for physical-only variations that do not include digital content.', 'woo-gdrive-permission' )
			. '</span>';
		echo '</p>';

		$this->render_drive_fields( $name_prefix, $resource_id, $resource_type, $resource_name, $account_id );
		echo '</div>';
	}

	/**
	 * Save variation meta.
	 */
	public function save_variation_meta( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		if ( ! isset( $_POST['wgdp_var'][ $loop ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual fields sanitized below.
		$data   = wp_unslash( $_POST['wgdp_var'][ $loop ] );
		$fields = array( '_wgdp_drive_resource_id', '_wgdp_drive_resource_type', '_wgdp_drive_resource_name', '_wgdp_account_id' );

		foreach ( $fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$value = sanitize_text_field( $data[ $field ] );
				if ( '' === $value ) {
					delete_post_meta( $variation_id, $field );
				} else {
					if ( '_wgdp_drive_resource_id' === $field ) {
						$value = WGDP_Google_Drive::extract_id_from_url( $value );
					}
					update_post_meta( $variation_id, $field, $value );
				}
			}
		}

		// Includes digital.
		if ( isset( $data['_wgdp_includes_digital'] ) ) {
			$val = sanitize_text_field( $data['_wgdp_includes_digital'] );
			update_post_meta( $variation_id, '_wgdp_includes_digital', 'yes' === $val ? 'yes' : 'no' );
		}
	}

	/**
	 * Check if a variation (or simple product) qualifies for digital entitlement.
	 *
	 * Returns true if a Drive resource exists AND the item is digital_only
	 * or a physical format with includes_digital=yes.
	 * For simple products ($variation_id=0), returns true if resource exists (backward compat).
	 */
	public static function variation_qualifies_for_digital( $product_id, $variation_id = 0 ) {
		// Resolve Drive resource ID.
		$resource_id = '';
		if ( $variation_id ) {
			$resource_id = get_post_meta( $variation_id, '_wgdp_drive_resource_id', true );
		}
		if ( empty( $resource_id ) ) {
			$resource_id = get_post_meta( $product_id, '_wgdp_drive_resource_id', true );
		}
		if ( empty( $resource_id ) ) {
			return false;
		}

		// Simple product (no variation) — qualifies if resource exists.
		if ( ! $variation_id ) {
			return true;
		}

		// Check includes_digital flag.
		$includes_digital = get_post_meta( $variation_id, '_wgdp_includes_digital', true );

		// Backward compat: if never explicitly set, infer from legacy format_type.
		if ( '' === $includes_digital ) {
			$format_type = get_post_meta( $variation_id, '_wgdp_format_type', true );
			return empty( $format_type ) || 'digital_only' === $format_type;
		}

		return 'yes' === $includes_digital;
	}

	/**
	 * Resolve the account_id for a product/variation.
	 * Checks variation meta first, then falls back to parent product meta.
	 */
	public static function get_account_for_item( $product_id, $variation_id = 0 ) {
		$account_id = '';
		if ( $variation_id ) {
			$account_id = get_post_meta( $variation_id, '_wgdp_account_id', true );
		}
		if ( empty( $account_id ) ) {
			$account_id = get_post_meta( $product_id, '_wgdp_account_id', true );
		}
		return $account_id;
	}

	/**
	 * AJAX: Browse Drive folder contents.
	 */
	public function ajax_browse_drive() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			wp_send_json_error( 'Please select a connected Google account.' );
		}

		$folder_id  = isset( $_POST['folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_id'] ) ) : '';
		$page_token = isset( $_POST['page_token'] ) ? sanitize_text_field( wp_unslash( $_POST['page_token'] ) ) : '';
		$skip_root  = ! empty( $_POST['skip_root'] );

		// Use the account's configured root folder unless explicitly skipped (e.g. root folder picker).
		$root_folder_id = '';
		if ( ! $skip_root ) {
			$acct = WGDP_Google_Auth::instance()->get_account( $account_id );
			$root_folder_id = $acct ? ( $acct['root_folder_id'] ?? '' ) : '';
		}

		$result = WGDP_Google_Drive::instance()->list_files( $folder_id, $page_token, 50, $account_id, $root_folder_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Include root folder metadata so the client can show the correct breadcrumb name.
		if ( ! $skip_root && $root_folder_id ) {
			$root_folder_name = $acct ? ( $acct['root_folder_name'] ?? '' ) : '';
			$result['root_folder_id']   = $root_folder_id;
			$result['root_folder_name'] = $root_folder_name;
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Get file info by ID or URL.
	 */
	public function ajax_get_file_info() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$file_id    = isset( $_POST['file_id'] ) ? sanitize_text_field( wp_unslash( $_POST['file_id'] ) ) : '';
		$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';

		if ( empty( $file_id ) ) {
			wp_send_json_error( 'No file ID provided.' );
		}
		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			wp_send_json_error( 'Please select a connected Google account.' );
		}

		$file_id = WGDP_Google_Drive::extract_id_from_url( $file_id );
		$result  = WGDP_Google_Drive::instance()->get_file( $file_id, $account_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$result['resourceType'] = WGDP_Google_Drive::is_folder( $result['mimeType'] ?? '' ) ? 'folder' : 'file';
		wp_send_json_success( $result );
	}

	/**
	 * Enqueue assets on product edit screens.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'wgdp-admin',
			WGDP_PLUGIN_URL . 'admin/css/wgdp-admin.css',
			array(),
			WGDP_VERSION
		);

		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		wp_enqueue_script(
			'wgdp-admin',
			WGDP_PLUGIN_URL . 'admin/js/wgdp-admin.js',
			array( 'jquery', 'jquery-ui-dialog' ),
			WGDP_VERSION,
			true
		);
		wp_localize_script( 'wgdp-admin', 'wgdp', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wgdp_admin_nonce' ),
		) );
	}
}
