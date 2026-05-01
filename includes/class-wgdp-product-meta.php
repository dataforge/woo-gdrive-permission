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
		add_action( 'wp_ajax_wgdp_get_file_info', array( $this, 'ajax_get_file_info' ) );
		add_action( 'wp_ajax_wgdp_browse_drive_files', array( $this, 'ajax_browse_drive_files' ) );

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
	 * Get Drive resources for a product/variation.
	 *
	 * Returns array of [{id, type, name}, ...]. Checks _wgdp_drive_resources JSON
	 * first, then falls back to legacy single-resource meta keys.
	 *
	 * @param int $product_id   The product ID.
	 * @param int $variation_id The variation ID (0 for simple products).
	 * @return array Array of resource objects with id, type, name keys.
	 */
	public static function get_drive_resources( $product_id, $variation_id = 0 ) {
		// Check variation first, then product.
		$check_ids = array();
		if ( $variation_id ) {
			$check_ids[] = $variation_id;
		}
		$check_ids[] = $product_id;

		foreach ( $check_ids as $check_id ) {
			$json = get_post_meta( $check_id, '_wgdp_drive_resources', true );
			if ( ! empty( $json ) ) {
				$resources = json_decode( $json, true );
				if ( is_array( $resources ) && ! empty( $resources ) ) {
					return $resources;
				}
			}
		}

		// Legacy fallback: single-resource meta keys.
		foreach ( $check_ids as $check_id ) {
			$resource_id = get_post_meta( $check_id, '_wgdp_drive_resource_id', true );
			if ( ! empty( $resource_id ) ) {
				return array( array(
					'id'   => $resource_id,
					'type' => get_post_meta( $check_id, '_wgdp_drive_resource_type', true ) ?: 'file',
					'name' => get_post_meta( $check_id, '_wgdp_drive_resource_name', true ) ?: '',
				) );
			}
		}

		return array();
	}

	/**
	 * Render the simple product panel.
	 */
	public function render_product_panel() {
		global $post;
		if ( ! $post ) {
			return;
		}
		$product_id = $post->ID;
		$resources  = self::get_drive_resources( $product_id );
		$account_id = get_post_meta( $product_id, '_wgdp_account_id', true );

		$ent_trigger    = get_post_meta( $product_id, '_wgdp_entitlement_trigger', true ) ?: 'default';
		$release_mode  = get_post_meta( $product_id, '_wgdp_release_mode', true ) ?: 'immediate';
		$threshold_qty = (int) get_post_meta( $product_id, '_wgdp_threshold_qty', true );
		$paid_qty      = (int) get_post_meta( $product_id, '_wgdp_paid_qty_total', true );
		$is_released   = '1' === get_post_meta( $product_id, '_wgdp_is_released', true );
		$released_at   = get_post_meta( $product_id, '_wgdp_released_at', true );
		?>
		<div id="wgdp_drive_data" class="panel woocommerce_options_panel">
			<div class="options_group show_if_simple show_if_external hide_if_variable">
				<?php $this->render_drive_fields( '', $resources, $account_id ); ?>
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
					<span class="description"><?php esc_html_e( 'Controls when buyers receive Google Drive access. Variations inherit this setting unless overridden.', 'woo-gdrive-permission' ); ?></span>
				</p>

				<p class="form-field">
					<label for="_wgdp_entitlement_trigger"><?php esc_html_e( 'Entitlement Trigger', 'woo-gdrive-permission' ); ?></label>
					<?php
					$global_trigger       = get_option( 'wgdp_entitlement_trigger', 'on_payment' );
					$global_trigger_label = 'on_completion' === $global_trigger
						? __( 'On Completion', 'woo-gdrive-permission' )
						: __( 'On Payment', 'woo-gdrive-permission' );
					?>
					<select id="_wgdp_entitlement_trigger" name="_wgdp_entitlement_trigger" class="short">
						<?php /* translators: %s: current global trigger setting name */ ?>
						<option value="default" <?php selected( $ent_trigger, 'default' ); ?>><?php printf( esc_html__( 'System Default, Currently: %s', 'woo-gdrive-permission' ), esc_html( $global_trigger_label ) ); ?></option>
						<option value="on_payment" <?php selected( $ent_trigger, 'on_payment' ); ?>><?php esc_html_e( 'On Payment', 'woo-gdrive-permission' ); ?></option>
						<option value="on_completion" <?php selected( $ent_trigger, 'on_completion' ); ?>><?php esc_html_e( 'On Completion', 'woo-gdrive-permission' ); ?></option>
					</select>
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
	 *
	 * @param string $name_prefix Form field name prefix (empty for simple products).
	 * @param array  $resources   Array of [{id, type, name}, ...].
	 * @param string $account_id  Selected Google account ID.
	 */
	private function render_drive_fields( $name_prefix, $resources, $account_id = '' ) {
		$auth     = WGDP_Google_Auth::instance();
		$accounts = $auth->get_accounts();
		$has_accounts = $auth->has_accounts();

		$account_field_name   = $name_prefix ? $name_prefix . '[_wgdp_account_id]' : '_wgdp_account_id';
		$resources_field_name = $name_prefix ? $name_prefix . '[_wgdp_drive_resources]' : '_wgdp_drive_resources';
		$resources_submitted_field_name = $name_prefix ? $name_prefix . '[_wgdp_drive_resources_submitted]' : '_wgdp_drive_resources_submitted';
		$unique_id            = $name_prefix ? 'wgdp-' . esc_attr( $name_prefix ) : 'wgdp-simple';

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

			<p class="form-field">
				<label><?php esc_html_e( 'Drive Files', 'woo-gdrive-permission' ); ?></label>
				<span class="wgdp-resources-list-wrap">
					<input type="hidden" name="<?php echo esc_attr( $resources_submitted_field_name ); ?>" value="1" />
					<span class="wgdp-resources-list" data-name-prefix="<?php echo esc_attr( $resources_field_name ); ?>">
					<?php foreach ( $resources as $i => $res ) :
						$res_status = $res['status'] ?? 'active';
						$is_retired = in_array( $res_status, array( 'retired_manual', 'retired_missing' ), true );
						$row_class  = 'wgdp-resource-row' . ( $is_retired ? ' wgdp-resource-row--retired' : '' );
					?>
						<span class="<?php echo esc_attr( $row_class ); ?>">
							<input type="hidden" name="<?php echo esc_attr( $resources_field_name ); ?>[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $res['id'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $resources_field_name ); ?>[<?php echo (int) $i; ?>][type]" value="<?php echo esc_attr( $res['type'] ?? 'file' ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $resources_field_name ); ?>[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $res['name'] ?? '' ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $resources_field_name ); ?>[<?php echo (int) $i; ?>][status]" value="<?php echo esc_attr( $res_status ); ?>" class="wgdp-resource-status" />
							<span class="wgdp-resource-row-info">
								<strong><?php echo esc_html( $res['name'] ?? $res['id'] ); ?></strong>
								<a href="<?php echo esc_url( WGDP_Google_Drive::build_web_link( $res['id'], $res['type'] ?? '' ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'woo-gdrive-permission' ); ?></a>
								<?php if ( $is_retired ) : ?>
									<span class="wgdp-badge--retired"><?php esc_html_e( 'Retired', 'woo-gdrive-permission' ); ?></span>
									<?php if ( 'retired_missing' === $res_status ) : ?>
										<em class="wgdp-retired-note"><?php esc_html_e( 'Auto-retired: file not found on Google Drive', 'woo-gdrive-permission' ); ?></em>
									<?php endif; ?>
								<?php endif; ?>
							</span>
							<?php if ( $is_retired ) : ?>
								<a href="#" class="wgdp-resource-row-restore" title="<?php esc_attr_e( 'Restore', 'woo-gdrive-permission' ); ?>"><?php esc_html_e( 'Restore', 'woo-gdrive-permission' ); ?></a>
							<?php endif; ?>
							<a href="#" class="wgdp-resource-row-remove" title="<?php esc_attr_e( 'Remove', 'woo-gdrive-permission' ); ?>">&times;</a>
						</span>
					<?php endforeach; ?>
				</span>
			</span>
		</p>

		<div class="wgdp-resource-input-wrap">
			<p class="form-field">
				<label for="<?php echo esc_attr( $unique_id ); ?>-resource-url">
					<?php esc_html_e( 'Add File', 'woo-gdrive-permission' ); ?>
				</label>
				<input type="text"
					class="short wgdp-resource-url-input"
					id="<?php echo esc_attr( $unique_id ); ?>-resource-url"
					placeholder="<?php esc_attr_e( 'Paste a GDrive URL or click Browse', 'woo-gdrive-permission' ); ?>"
				/>
			</p>
			<p class="form-field">
				<label>&nbsp;</label>
				<span class="wgdp-drive-actions">
					<button type="button" class="button wgdp-browse-drive"><?php esc_html_e( 'Browse GDrive', 'woo-gdrive-permission' ); ?></button>
					<button type="button" class="button wgdp-google-picker-drive"><?php esc_html_e( 'Google Picker', 'woo-gdrive-permission' ); ?></button>
				</span>
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

		// Verify WooCommerce product meta nonce (defense-in-depth; WC checks this upstream).
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		// Account ID.
		if ( isset( $_POST['_wgdp_account_id'] ) ) {
			update_post_meta( $post_id, '_wgdp_account_id', sanitize_text_field( wp_unslash( $_POST['_wgdp_account_id'] ) ) );
		}

		// Multi-file resources.
		$old_resources  = self::get_drive_resources( $post_id );
		$old_active_ids = self::extract_active_resource_ids( $old_resources );

		$resources_submitted = isset( $_POST['_wgdp_drive_resources_submitted'] );

		if ( isset( $_POST['_wgdp_drive_resources'] ) && is_array( $_POST['_wgdp_drive_resources'] ) ) {
			$raw       = wp_unslash( $_POST['_wgdp_drive_resources'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$resources = self::sanitize_resources_array( $raw );
			update_post_meta( $post_id, '_wgdp_drive_resources', wp_json_encode( $resources ) );
			// Clean up legacy keys.
			delete_post_meta( $post_id, '_wgdp_drive_resource_id' );
			delete_post_meta( $post_id, '_wgdp_drive_resource_type' );
			delete_post_meta( $post_id, '_wgdp_drive_resource_name' );

			// Detect new active resources and queue backfill.
			$new_active_ids = self::extract_active_resource_ids( $resources );
			$added_ids      = array_diff( $new_active_ids, $old_active_ids );
			if ( ! empty( $added_ids ) ) {
				$account_id = self::get_account_for_item( $post_id, 0 );
				if ( $account_id ) {
					self::queue_backfill( $post_id, 0, array_values( $added_ids ), $account_id );
				}
			}

			// Detect removed resources and auto-revoke.
			$removed_ids = array_diff( $old_active_ids, $new_active_ids );
			if ( ! empty( $removed_ids ) ) {
				self::revoke_removed_assets( $post_id, 0, array_values( $removed_ids ), $old_resources );
			}
		} elseif ( $resources_submitted ) {
			// Resources UI was submitted with an intentionally empty list.
			update_post_meta( $post_id, '_wgdp_drive_resources', wp_json_encode( array() ) );
			delete_post_meta( $post_id, '_wgdp_drive_resource_id' );
			delete_post_meta( $post_id, '_wgdp_drive_resource_type' );
			delete_post_meta( $post_id, '_wgdp_drive_resource_name' );

			// All resources removed — revoke all.
			if ( ! empty( $old_active_ids ) ) {
				self::revoke_removed_assets( $post_id, 0, array_values( $old_active_ids ), $old_resources );
			}
		}

		// Entitlement trigger.
		if ( isset( $_POST['_wgdp_entitlement_trigger'] ) ) {
			$trigger = sanitize_text_field( wp_unslash( $_POST['_wgdp_entitlement_trigger'] ) );
			if ( in_array( $trigger, array( 'default', 'on_payment', 'on_completion' ), true ) ) {
				update_post_meta( $post_id, '_wgdp_entitlement_trigger', $trigger );
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
		$variation_id      = $variation->ID;
		$product_id        = wp_get_post_parent_id( $variation_id );
		$resources         = self::get_drive_resources( $product_id, $variation_id );
		$account_id        = get_post_meta( $variation_id, '_wgdp_account_id', true );
		$includes_digital  = get_post_meta( $variation_id, '_wgdp_includes_digital', true ) ?: 'yes';
		$requires_shipping = get_post_meta( $variation_id, '_wgdp_requires_shipping', true );

		// Release override fields.
		$var_release_mode    = get_post_meta( $variation_id, '_wgdp_release_mode', true ) ?: 'inherit_from_product';
		$var_threshold_qty   = (int) get_post_meta( $variation_id, '_wgdp_threshold_qty', true );
		$var_threshold_scope = get_post_meta( $variation_id, '_wgdp_threshold_scope', true ) ?: 'entire_product';
		$var_counts_toward   = get_post_meta( $variation_id, '_wgdp_counts_toward_product_threshold', true );
		$var_counts_toward   = '' === $var_counts_toward || 'yes' === $var_counts_toward ? 'yes' : 'no';
		$var_is_released     = '1' === get_post_meta( $variation_id, '_wgdp_is_released', true );
		$var_released_at     = get_post_meta( $variation_id, '_wgdp_released_at', true );
		$var_paid_qty        = (int) get_post_meta( $variation_id, '_wgdp_variation_paid_qty_total', true );

		// Product-level mode for display in "Inherit" label.
		$product_mode       = get_post_meta( $product_id, '_wgdp_release_mode', true ) ?: 'immediate';
		$product_mode_label = self::release_mode_label( $product_mode );

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
			. esc_html__( 'Grants the buyer Google Drive access when they purchase this variation.', 'woo-gdrive-permission' )
			. '</span>';
		echo '</p>';

		// Requires shipping checkbox.
		echo '<p class="form-row form-row-full">';
		echo '<label>';
		echo '<input type="hidden" name="' . esc_attr( $name_prefix . '[_wgdp_requires_shipping]' ) . '" value="no" />';
		echo '<input type="checkbox" name="' . esc_attr( $name_prefix . '[_wgdp_requires_shipping]' ) . '" value="yes"'
			. checked( '' === $requires_shipping || 'yes' === $requires_shipping, true, false ) . ' /> ';
		echo esc_html__( 'Requires Shipping', 'woo-gdrive-permission' );
		echo '</label>';
		echo '<br><span class="description">'
			. esc_html__( 'This variation includes a physical item (e.g. DVD, Blu-ray). Uncheck for digital-only variations — orders with only digital items will auto-complete once access is granted.', 'woo-gdrive-permission' )
			. '</span>';
		echo '</p>';

		$this->render_drive_fields( $name_prefix, $resources, $account_id );

		// Release override section.
		echo '<p class="form-row form-row-full" style="margin-top:12px;"><strong>' . esc_html__( 'Release Gate Override', 'woo-gdrive-permission' ) . '</strong></p>';

		// Release Mode Override.
		echo '<p class="form-row form-row-full">';
		echo '<label>' . esc_html__( 'Release Mode', 'woo-gdrive-permission' ) . '</label>';
		echo '<select class="wgdp-var-release-mode" name="' . esc_attr( $name_prefix . '[_wgdp_release_mode]' ) . '" style="width:100%;">';
		/* translators: %s: current product-level release mode */
		echo '<option value="inherit_from_product"' . selected( $var_release_mode, 'inherit_from_product', false ) . '>'
			. sprintf( esc_html__( 'Inherit from product (%s)', 'woo-gdrive-permission' ), esc_html( $product_mode_label ) )
			. '</option>';
		echo '<option value="immediate"' . selected( $var_release_mode, 'immediate', false ) . '>' . esc_html__( 'Immediate', 'woo-gdrive-permission' ) . '</option>';
		echo '<option value="manual_release"' . selected( $var_release_mode, 'manual_release', false ) . '>' . esc_html__( 'Manual Release', 'woo-gdrive-permission' ) . '</option>';
		echo '<option value="min_sales_qty"' . selected( $var_release_mode, 'min_sales_qty', false ) . '>' . esc_html__( 'Min Sales Qty', 'woo-gdrive-permission' ) . '</option>';
		echo '</select>';
		echo '</p>';

		// Threshold Qty (shown when min_sales_qty).
		echo '<p class="form-row form-row-full wgdp-var-show-if-min-sales"' . ( 'min_sales_qty' !== $var_release_mode ? ' style="display:none;"' : '' ) . '>';
		echo '<label>' . esc_html__( 'Threshold Qty', 'woo-gdrive-permission' ) . '</label>';
		echo '<input type="number" name="' . esc_attr( $name_prefix . '[_wgdp_threshold_qty]' ) . '" min="0" step="1" value="' . esc_attr( $var_threshold_qty ) . '" style="width:100%;" />';
		echo '</p>';

		// Count Sales From (shown when min_sales_qty).
		echo '<p class="form-row form-row-full wgdp-var-show-if-min-sales"' . ( 'min_sales_qty' !== $var_release_mode ? ' style="display:none;"' : '' ) . '>';
		echo '<label>' . esc_html__( 'Count Sales From', 'woo-gdrive-permission' ) . '</label>';
		echo '<select name="' . esc_attr( $name_prefix . '[_wgdp_threshold_scope]' ) . '" style="width:100%;">';
		echo '<option value="entire_product"' . selected( $var_threshold_scope, 'entire_product', false ) . '>' . esc_html__( 'Entire product', 'woo-gdrive-permission' ) . '</option>';
		echo '<option value="this_variation_only"' . selected( $var_threshold_scope, 'this_variation_only', false ) . '>' . esc_html__( 'This variation only', 'woo-gdrive-permission' ) . '</option>';
		echo '</select>';
		echo '</p>';

		// Variation Sales Count (shown when min_sales_qty with this_variation_only scope).
		$show_var_counter = 'min_sales_qty' === $var_release_mode && 'this_variation_only' === $var_threshold_scope;
		echo '<p class="form-row form-row-full wgdp-var-show-if-var-counter"' . ( ! $show_var_counter ? ' style="display:none;"' : '' ) . '>';
		echo '<label>' . esc_html__( 'Variation Sales Count', 'woo-gdrive-permission' ) . '</label>';
		echo '<span class="wgdp-var-sales-count-value">' . esc_html( $var_paid_qty ) . '</span> ';
		echo '<button type="button" class="button button-small wgdp-recalculate-var-sales-btn" data-variation-id="' . esc_attr( $variation_id ) . '">' . esc_html__( 'Recalculate', 'woo-gdrive-permission' ) . '</button>';
		echo '</p>';

		// Counts toward product threshold (always shown).
		echo '<p class="form-row form-row-full">';
		echo '<label>';
		echo '<input type="hidden" name="' . esc_attr( $name_prefix . '[_wgdp_counts_toward_product_threshold]' ) . '" value="no" />';
		echo '<input type="checkbox" name="' . esc_attr( $name_prefix . '[_wgdp_counts_toward_product_threshold]' ) . '" value="yes"'
			. checked( $var_counts_toward, 'yes', false ) . ' /> ';
		echo esc_html__( 'Counts toward product threshold', 'woo-gdrive-permission' );
		echo '</label>';
		echo '<br><span class="description">'
			. esc_html__( 'Sales of this variation count toward the product-level min sales threshold. Uncheck for free/test variations.', 'woo-gdrive-permission' )
			. '</span>';
		echo '</p>';

		// Variation release status (shown when variation has its own gate).
		$has_own_gate = in_array( $var_release_mode, array( 'manual_release', 'min_sales_qty' ), true );
		if ( $has_own_gate ) {
			echo '<p class="form-row form-row-full wgdp-var-release-status">';
			echo '<label>' . esc_html__( 'Release Status', 'woo-gdrive-permission' ) . '</label>';
			if ( $var_is_released ) {
				echo '<span class="wgdp-release-gate-status wgdp-release-gate-status--released">' . esc_html__( 'Released', 'woo-gdrive-permission' ) . '</span>';
				if ( $var_released_at ) {
					echo ' <span class="description">' . esc_html( $var_released_at ) . '</span>';
				}
			} else {
				echo '<span class="wgdp-release-gate-status wgdp-release-gate-status--pending">' . esc_html__( 'Pending', 'woo-gdrive-permission' ) . '</span> ';
				echo '<button type="button" class="button button-small wgdp-release-var-now-btn" data-variation-id="' . esc_attr( $variation_id ) . '">'
					. esc_html__( 'Release Now', 'woo-gdrive-permission' ) . '</button>';
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Get a human-readable label for a release mode.
	 */
	private static function release_mode_label( $mode ) {
		switch ( $mode ) {
			case 'immediate':
				return __( 'Immediate', 'woo-gdrive-permission' );
			case 'manual_release':
				return __( 'Manual Release', 'woo-gdrive-permission' );
			case 'min_sales_qty':
				return __( 'Min Sales Qty', 'woo-gdrive-permission' );
			default:
				return __( 'Immediate', 'woo-gdrive-permission' );
		}
	}

	/**
	 * Save variation meta.
	 */
	public function save_variation_meta( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		// WooCommerce verifies nonces upstream before firing this hook
		// (woocommerce_meta_nonce for full saves, security nonce for AJAX saves).

		if ( ! isset( $_POST['wgdp_var'][ $loop ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual fields sanitized below.
		$data = wp_unslash( $_POST['wgdp_var'][ $loop ] );

		// Account ID.
		if ( isset( $data['_wgdp_account_id'] ) ) {
			$acct = sanitize_text_field( $data['_wgdp_account_id'] );
			if ( '' === $acct ) {
				delete_post_meta( $variation_id, '_wgdp_account_id' );
			} else {
				update_post_meta( $variation_id, '_wgdp_account_id', $acct );
			}
		}

		// Multi-file resources.
		$product_id     = wp_get_post_parent_id( $variation_id );
		$old_resources  = self::get_drive_resources( $product_id, $variation_id );
		$old_active_ids = self::extract_active_resource_ids( $old_resources );

		$resources_submitted = isset( $data['_wgdp_drive_resources_submitted'] );

		if ( isset( $data['_wgdp_drive_resources'] ) && is_array( $data['_wgdp_drive_resources'] ) ) {
			$resources = self::sanitize_resources_array( $data['_wgdp_drive_resources'] );
			update_post_meta( $variation_id, '_wgdp_drive_resources', wp_json_encode( $resources ) );
			delete_post_meta( $variation_id, '_wgdp_drive_resource_id' );
			delete_post_meta( $variation_id, '_wgdp_drive_resource_type' );
			delete_post_meta( $variation_id, '_wgdp_drive_resource_name' );

			// Detect new active resources and queue backfill.
			$new_active_ids = self::extract_active_resource_ids( $resources );
			$added_ids      = array_diff( $new_active_ids, $old_active_ids );
			if ( ! empty( $added_ids ) ) {
				$account_id = self::get_account_for_item( $product_id, $variation_id );
				if ( $account_id ) {
					self::queue_backfill( $product_id, $variation_id, array_values( $added_ids ), $account_id );
				}
			}

			// Detect removed resources and auto-revoke.
			$removed_ids = array_diff( $old_active_ids, $new_active_ids );
			if ( ! empty( $removed_ids ) ) {
				self::revoke_removed_assets( $product_id, $variation_id, array_values( $removed_ids ), $old_resources );
			}
		} elseif ( $resources_submitted ) {
			update_post_meta( $variation_id, '_wgdp_drive_resources', wp_json_encode( array() ) );
			delete_post_meta( $variation_id, '_wgdp_drive_resource_id' );
			delete_post_meta( $variation_id, '_wgdp_drive_resource_type' );
			delete_post_meta( $variation_id, '_wgdp_drive_resource_name' );

			// All resources removed — revoke all.
			if ( ! empty( $old_active_ids ) ) {
				self::revoke_removed_assets( $product_id, $variation_id, array_values( $old_active_ids ), $old_resources );
			}
		}

		// Includes digital.
		if ( isset( $data['_wgdp_includes_digital'] ) ) {
			$val = sanitize_text_field( $data['_wgdp_includes_digital'] );
			update_post_meta( $variation_id, '_wgdp_includes_digital', 'yes' === $val ? 'yes' : 'no' );
		}

		// Requires shipping.
		if ( isset( $data['_wgdp_requires_shipping'] ) ) {
			$val = sanitize_text_field( $data['_wgdp_requires_shipping'] );
			update_post_meta( $variation_id, '_wgdp_requires_shipping', 'yes' === $val ? 'yes' : 'no' );
		}

		// Release mode override.
		if ( isset( $data['_wgdp_release_mode'] ) ) {
			$mode = sanitize_text_field( $data['_wgdp_release_mode'] );
			if ( in_array( $mode, array( 'inherit_from_product', 'immediate', 'manual_release', 'min_sales_qty' ), true ) ) {
				update_post_meta( $variation_id, '_wgdp_release_mode', $mode );
			}
		}

		// Threshold qty (variation-level).
		if ( isset( $data['_wgdp_threshold_qty'] ) ) {
			update_post_meta( $variation_id, '_wgdp_threshold_qty', absint( $data['_wgdp_threshold_qty'] ) );
		}

		// Threshold scope.
		if ( isset( $data['_wgdp_threshold_scope'] ) ) {
			$scope = sanitize_text_field( $data['_wgdp_threshold_scope'] );
			if ( in_array( $scope, array( 'entire_product', 'this_variation_only' ), true ) ) {
				update_post_meta( $variation_id, '_wgdp_threshold_scope', $scope );
			}
		}

		// Counts toward product threshold.
		if ( isset( $data['_wgdp_counts_toward_product_threshold'] ) ) {
			$val = sanitize_text_field( $data['_wgdp_counts_toward_product_threshold'] );
			update_post_meta( $variation_id, '_wgdp_counts_toward_product_threshold', 'yes' === $val ? 'yes' : 'no' );
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
		$resources = self::get_drive_resources( $product_id, $variation_id );
		if ( empty( $resources ) ) {
			return false;
		}

		// Simple product (no variation) — qualifies if resources exist.
		if ( ! $variation_id ) {
			return true;
		}

		// Check includes_digital flag (defaults to yes if never set).
		$includes_digital = get_post_meta( $variation_id, '_wgdp_includes_digital', true );
		return '' === $includes_digital || 'yes' === $includes_digital;
	}

	/**
	 * Get cart items that qualify for digital access.
	 *
	 * @return array[] Each element has: cart_key, product_name, quantity, product_id, variation_id, key.
	 */
	public static function get_qualifying_cart_items() {
		if ( ! WC()->cart ) {
			return array();
		}

		$items = array();

		foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
			$product_id   = $cart_item['product_id'] ?? 0;
			$variation_id = $cart_item['variation_id'] ?? 0;
			$quantity     = $cart_item['quantity'] ?? 1;

			if ( ! self::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				continue;
			}

			$product = $cart_item['data'] ?? null;

			$items[] = array(
				'cart_key'     => $cart_key,
				'product_name' => $product ? $product->get_name() : '',
				'quantity'     => (int) $quantity,
				'product_id'   => (int) $product_id,
				'variation_id' => (int) $variation_id,
				'key'          => $product_id . '_' . ( $variation_id ?: 0 ),
			);
		}

		return $items;
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
	 * Get the effective entitlement trigger for a product.
	 * Returns 'on_payment' or 'on_completion'.
	 */
	public static function get_entitlement_trigger( $product_id ) {
		$product_setting = get_post_meta( $product_id, '_wgdp_entitlement_trigger', true );
		if ( ! empty( $product_setting ) && 'default' !== $product_setting ) {
			return $product_setting;
		}
		return get_option( 'wgdp_entitlement_trigger', 'on_payment' );
	}

	/**
	 * Check if an order item requires physical shipping.
	 *
	 * Simple products with a Drive resource are treated as digital-only.
	 * Variations default to requiring shipping unless explicitly set to 'no'.
	 */
	public static function item_requires_shipping( $product_id, $variation_id = 0 ) {
		if ( ! $variation_id ) {
			// Simple product with a Drive resource is digital-only.
			return false;
		}
		$val = get_post_meta( $variation_id, '_wgdp_requires_shipping', true );
		// Default to yes (requires shipping) if never set.
		return '' === $val || 'yes' === $val;
	}

	/**
	 * Get only active (non-retired) Drive resources for a product/variation.
	 *
	 * @param int $product_id   The product ID.
	 * @param int $variation_id The variation ID (0 for simple products).
	 * @return array Array of active resource objects.
	 */
	public static function get_active_drive_resources( $product_id, $variation_id = 0 ) {
		$resources = self::get_drive_resources( $product_id, $variation_id );
		return array_values( array_filter( $resources, function( $r ) {
			return empty( $r['status'] ) || 'active' === $r['status'];
		} ) );
	}

	/**
	 * Extract active resource IDs from a resources array.
	 *
	 * @param array $resources Resources array.
	 * @return array Array of active resource IDs.
	 */
	private static function extract_active_resource_ids( $resources ) {
		$ids = array();
		foreach ( $resources as $r ) {
			if ( empty( $r['status'] ) || 'active' === $r['status'] ) {
				$ids[] = $r['id'];
			}
		}
		return $ids;
	}

	/**
	 * Retire a resource on a product/variation.
	 *
	 * @param int    $product_id   The product ID.
	 * @param int    $variation_id The variation ID (0 for simple products).
	 * @param string $asset_id     The resource ID to retire.
	 * @param string $reason       The retirement reason: 'retired_manual' or 'retired_missing'.
	 * @return bool True if modified.
	 */
	public static function maybe_retire_resource( $product_id, $variation_id, $asset_id, $reason = 'retired_manual' ) {
		$check_id = $variation_id ?: $product_id;
		$json     = get_post_meta( $check_id, '_wgdp_drive_resources', true );
		if ( empty( $json ) ) {
			return false;
		}
		$resources = json_decode( $json, true );
		if ( ! is_array( $resources ) ) {
			return false;
		}

		$modified = false;
		foreach ( $resources as &$r ) {
			if ( $r['id'] === $asset_id && ( empty( $r['status'] ) || 'active' === $r['status'] ) ) {
				$r['status'] = $reason;
				$modified     = true;
				break;
			}
		}
		unset( $r );

		if ( $modified ) {
			update_post_meta( $check_id, '_wgdp_drive_resources', wp_json_encode( $resources ) );
		}

		return $modified;
	}

	/**
	 * Revoke Drive permissions for assets removed from a product/variation.
	 *
	 * @param int   $product_id    The product ID.
	 * @param int   $variation_id  The variation ID (0 for simple products).
	 * @param array $removed_ids   Array of removed asset IDs.
	 * @param array $old_resources The previous resource list (to look up file names).
	 */
	public static function revoke_removed_assets( $product_id, $variation_id, $removed_ids, $old_resources = array() ) {
		if ( empty( $removed_ids ) ) {
			return;
		}

		// Build asset ID → name map from old resources.
		$asset_names = array();
		foreach ( $old_resources as $r ) {
			if ( ! empty( $r['id'] ) && ! empty( $r['name'] ) ) {
				$asset_names[ $r['id'] ] = $r['name'];
			}
		}

		global $wpdb;
		$ent   = WGDP_Entitlements::instance();
		$table = $wpdb->prefix . 'wgdp_entitlements';

		// Find all non-revoked entitlements for these assets. Parent product
		// removals also affect variation rows that inherit parent resources.
		$placeholders = implode( ',', array_fill( 0, count( $removed_ids ), '%s' ) );
		if ( $variation_id ) {
			$where = "product_id = %d AND variation_id = %d AND cloud_asset_id IN ({$placeholders})";
			$args  = array_merge( array( $product_id, $variation_id ), $removed_ids );
		} else {
			$where = "product_id = %d AND cloud_asset_id IN ({$placeholders})";
			$args  = array_merge( array( $product_id ), $removed_ids );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE {$where}
			   AND grant_status != 'revoked'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$args
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return;
		}

		// Group by recipient: email => [ 'rows' => [...], 'file_names' => [...] ].
		$by_recipient = array();
		foreach ( $rows as $row ) {
			if ( ! self::removed_asset_row_applies( (int) $variation_id, (int) ( $row['variation_id'] ?? 0 ) ) ) {
				continue;
			}

			$result = $ent->revoke_with_drive_delete( $row, WGDP_Entitlements::REVOCATION_REASON_ASSET_REMOVED );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			if ( ! empty( $row['recipient_email'] ) ) {
				$email = $row['recipient_email'];
				if ( ! isset( $by_recipient[ $email ] ) ) {
					$by_recipient[ $email ] = array( 'row' => $row, 'file_names' => array() );
				}
				$file_name = $asset_names[ $row['cloud_asset_id'] ] ?? '';
				if ( $file_name && ! in_array( $file_name, $by_recipient[ $email ]['file_names'], true ) ) {
					$by_recipient[ $email ]['file_names'][] = $file_name;
				}
			}
		}

		// Send revocation emails with file-level detail.
		foreach ( $by_recipient as $email => $info ) {
			$product_name = WGDP_Entitlements::get_product_name( $info['row'] );
			$order_id     = $info['row']['order_id'] ?? 0;
			if ( ! empty( $info['file_names'] ) ) {
				WGDP_Notification_Email::send_file_access_revoked( $email, $product_name, $info['file_names'], $order_id );
			} else {
				WGDP_Notification_Email::send_access_revoked( $email, $product_name, $order_id );
			}
		}

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Check whether a removed product/variation asset applies to an entitlement row.
	 */
	private static function removed_asset_row_applies( $removed_variation_id, $row_variation_id ) {
		if ( $removed_variation_id ) {
			return $row_variation_id === $removed_variation_id;
		}

		if ( ! $row_variation_id ) {
			return true;
		}

		return ! self::variation_has_own_resources( $row_variation_id );
	}

	/**
	 * Check whether a variation defines its own resource set instead of inheriting.
	 */
	public static function variation_has_own_resources( $variation_id ) {
		$json = get_post_meta( $variation_id, '_wgdp_drive_resources', true );
		if ( ! empty( $json ) ) {
			$resources = json_decode( $json, true );
			if ( is_array( $resources ) && ! empty( $resources ) ) {
				return true;
			}
		}

		return (bool) get_post_meta( $variation_id, '_wgdp_drive_resource_id', true );
	}

	/**
	 * Queue a backfill job for newly added resources.
	 *
	 * @param int    $product_id   The product ID.
	 * @param int    $variation_id The variation ID (0 for simple products).
	 * @param array  $added_ids    Array of newly added resource IDs.
	 * @param string $account_id   The Google account ID.
	 */
	public static function queue_backfill( $product_id, $variation_id, $added_ids, $account_id ) {
		global $wpdb;

		if ( empty( $added_ids ) || empty( $account_id ) ) {
			return;
		}

		sort( $added_ids );
		$table = WGDP_DB::get_backfill_table_name();

		// Check for existing pending/processing job for same product+variation.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, asset_ids FROM {$table} WHERE product_id = %d AND variation_id = %d AND status IN ('pending', 'processing') LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product_id,
			$variation_id
		), ARRAY_A );

		if ( $existing ) {
			// Merge new asset IDs into existing job and reset cursor to re-scan
			// from the beginning. The unique constraint on entitlements makes
			// re-scanning safe — already-created rows are skipped.
			// Also reset status to 'pending' so the cursor reset is authoritative
			// and any in-flight batch completion won't overwrite it.
			$old_ids  = json_decode( $existing['asset_ids'], true ) ?: array();
			$merged   = array_values( array_unique( array_merge( $old_ids, $added_ids ) ) );
			sort( $merged );
			$wpdb->update( $table, array(
				'asset_ids'      => wp_json_encode( $merged ),
				'account_id'     => $account_id,
				'cursor_item_id' => 0,
				'cursor_email'   => '',
				'status'         => 'pending',
				'started_at'     => null,
			), array( 'id' => $existing['id'] ) );
		} else {
			$wpdb->insert( $table, array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'account_id'   => $account_id,
				'asset_ids'    => wp_json_encode( $added_ids ),
			) );
		}

		wp_schedule_single_event( time(), 'wgdp_process_backfill' );
	}

	/**
	 * Sanitize the resources array from POST data.
	 *
	 * @param array $raw Raw resources array from $_POST.
	 * @return array Sanitized array of [{id, type, name, status?}, ...].
	 */
	private static function sanitize_resources_array( $raw ) {
		$resources = array();
		$seen_ids  = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$id = sanitize_text_field( $entry['id'] );
			$id = WGDP_Google_Drive::extract_id_from_url( $id );
			if ( empty( $id ) || isset( $seen_ids[ $id ] ) ) {
				continue;
			}
			$seen_ids[ $id ] = true;
			$res = array(
				'id'   => $id,
				'type' => sanitize_text_field( $entry['type'] ?? 'file' ),
				'name' => sanitize_text_field( $entry['name'] ?? '' ),
			);
			if ( ! empty( $entry['status'] ) && in_array( $entry['status'], array( 'active', 'retired_manual', 'retired_missing' ), true ) ) {
				$res['status'] = $entry['status'];
			}
			$resources[] = $res;
		}
		return $resources;
	}

	/**
	 * AJAX: Get file info by ID or URL.
	 */
	public function ajax_get_file_info() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
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
	 * AJAX: List accessible Drive files for the product editor browser.
	 */
	public function ajax_browse_drive_files() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page_token = isset( $_POST['page_token'] ) ? sanitize_text_field( wp_unslash( $_POST['page_token'] ) ) : '';
		$folder_id  = isset( $_POST['folder_id'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_id'] ) ) : 'root';

		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			wp_send_json_error( 'Please select a connected Google account.' );
		}

		$result = WGDP_Google_Drive::instance()->list_files( $account_id, $search, $page_token, $folder_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

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

		wp_enqueue_script(
			'wgdp-admin',
			WGDP_PLUGIN_URL . 'admin/js/wgdp-admin.js',
			array( 'jquery' ),
			WGDP_VERSION,
			true
		);
		$can_manage_settings = WGDP_Google_Auth::current_user_can_manage_credentials();
		wp_localize_script( 'wgdp-admin', 'wgdp', array(
			'ajax_url'             => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'wgdp_admin_nonce' ),
			'oauth_client_id'      => $can_manage_settings ? get_option( 'wgdp_oauth_client_id', '' ) : '',
			'picker_api_key'       => $can_manage_settings ? get_option( 'wgdp_picker_api_key', '' ) : '',
			'cloud_project_number' => $can_manage_settings ? get_option( 'wgdp_cloud_project_number', '' ) : '',
			'can_manage_settings'  => $can_manage_settings,
		) );
	}
}
