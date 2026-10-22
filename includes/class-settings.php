<?php
/**
 * Settings page: Ewand Sync → Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Codemas_Ewand_Settings {

	const OPTION = 'codemas_ewand_settings';
	const PAGE   = 'codemas-ewand-ordersync';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 ); // After Codemas_Ewand_Orders_Page creates the menu.
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function defaults() {
		return array(
			'enabled'      => 'no',
			'endpoint'     => '',
			'verify_url'   => '',
			'bearer_token' => '',
			'header_name'  => '',
			'header_value' => '',
			'timeout'      => 15,
			// Production Order defaults for this website.
			'site_code'         => '',
			'factory_id'        => '',
			'agent_id'          => '',
			'customer_id'       => '',
			'region_ids'        => array(),
			'default_region_id' => '1', // Until regions are matched to Ewand IDs.
			'production_type'   => 'make',
			'ship_mode'         => 'SEA',
			'no_of_boxes'       => 1,
			'lead_time_days'    => '',
			'team_name'         => '',
			'size_attribute'    => 'pa_size',
			'size_map'          => "Small=S\nMedium=M\nLarge=L",
		);
	}

	/**
	 * "2XL - Adults=2XL" lines from the Size mapping setting as lowercase website size => Ewand size.
	 */
	public static function size_map() {
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'size_map' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$map[ strtolower( $parts[0] ) ] = $parts[1];
			}
		}
		return $map;
	}

	/**
	 * Ewand shipping region ID for a shipping method instance (the method chosen
	 * on the order), then the default. Null when none is set.
	 */
	public static function region_id( $instance_id ) {
		foreach ( (array) self::get( 'region_ids' ) as $row ) {
			if ( isset( $row['method'] ) && (int) $row['method'] === (int) $instance_id ) {
				return (int) $row['id'];
			}
		}
		$default = self::get( 'default_region_id' );
		return '' === (string) $default ? null : (int) $default;
	}

	public static function get( $key = null ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return null === $key ? $settings : ( $settings[ $key ] ?? null );
	}

	public static function is_active() {
		return 'yes' === self::get( 'enabled' ) && '' !== self::get( 'endpoint' );
	}

	public static function add_menu() {
		Codemas_Ewand_Orders_Page::add_page(
			__( 'ERP Sync', 'codemas-ewand-ordersync' ),
			__( 'Settings', 'codemas-ewand-ordersync' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting( self::PAGE, self::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => self::defaults(),
		) );
	}

	public static function sanitize( $input ) {
		$input      = (array) $input;

		// Blank field keeps the saved token (the field is never pre-filled); the checkbox clears it.
		$token = trim( sanitize_text_field( $input['bearer_token'] ?? '' ) );
		$token = trim( preg_replace( '/^Bearer\s+/i', '', $token ) );
		if ( '' === $token && empty( $input['clear_token'] ) ) {
			$token = self::get( 'bearer_token' );
		}

		return array(
			'enabled'      => ! empty( $input['enabled'] ) ? 'yes' : 'no',
			'endpoint'     => esc_url_raw( trim( $input['endpoint'] ?? '' ) ),
			'verify_url'   => self::url_template( $input['verify_url'] ?? '' ),
			'bearer_token' => $token,
			'header_name'  => preg_replace( '/[^A-Za-z0-9\-]/', '', $input['header_name'] ?? '' ),
			'header_value' => sanitize_text_field( $input['header_value'] ?? '' ),
			'timeout'      => max( 5, min( 60, absint( $input['timeout'] ?? 15 ) ) ),
			'site_code'         => strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', $input['site_code'] ?? '' ) ),
			'factory_id'        => self::id( $input['factory_id'] ?? '' ),
			'agent_id'          => self::id( $input['agent_id'] ?? '' ),
			'customer_id'       => self::id( $input['customer_id'] ?? '' ),
			'region_ids'        => self::sanitize_regions( $input['region_ids'] ?? array() ),
			'default_region_id' => self::id( $input['default_region_id'] ?? '' ),
			'production_type'   => sanitize_key( $input['production_type'] ?? 'make' ) ?: 'make',
			'ship_mode'         => in_array( $input['ship_mode'] ?? '', array( 'AIR', 'SEA' ), true ) ? $input['ship_mode'] : 'SEA',
			'no_of_boxes'       => max( 1, absint( $input['no_of_boxes'] ?? 1 ) ),
			'lead_time_days'    => '' === trim( (string) ( $input['lead_time_days'] ?? '' ) ) ? '' : absint( $input['lead_time_days'] ),
			'team_name'         => sanitize_text_field( $input['team_name'] ?? '' ),
			'size_attribute'    => sanitize_title( $input['size_attribute'] ?? 'pa_size' ),
			'size_map'          => sanitize_textarea_field( $input['size_map'] ?? '' ),
		);
	}

	/**
	 * Shipping method rows with an ID: [ {method, id} ], one per shipping method instance.
	 */
	private static function sanitize_regions( $rows ) {
		$clean = array();
		foreach ( (array) $rows as $row ) {
			$method = absint( $row['method'] ?? 0 );
			$id     = self::id( $row['id'] ?? '' );
			if ( $method && '' !== $id && '0' !== $id ) {
				$clean[ $method ] = array( 'method' => $method, 'id' => $id );
			}
		}
		return array_values( $clean );
	}

	/**
	 * URL that keeps its {style_no} placeholder (esc_url_raw strips braces).
	 */
	private static function url_template( $value ) {
		return str_replace( '%7Bstyle_no%7D', '{style_no}', esc_url_raw( str_replace( '{style_no}', '%7Bstyle_no%7D', trim( (string) $value ) ) ) );
	}

	/**
	 * Ewand record ID: digits, or blank when not set.
	 */
	private static function id( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? '' : (string) absint( $value );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = self::get();
		$o = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Codemas Ewand OrderSync – ERP Sync', 'codemas-ewand-ordersync' ); ?></h1>
			<p><?php esc_html_e( 'Orders are sent as JSON (HTTP POST) to the ERP endpoint below – only when you send them from Ewand Sync → Orders (single or bulk). Nothing is sent automatically, and each order is sent only once.', 'codemas-ewand-ordersync' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'codemas-ewand-ordersync' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled]" value="yes" <?php checked( $s['enabled'], 'yes' ); ?>> <?php esc_html_e( 'Allow sending orders to the ERP', 'codemas-ewand-ordersync' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-endpoint"><?php esc_html_e( 'ERP endpoint URL', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="url" id="ce-endpoint" class="large-text" name="<?php echo esc_attr( $o ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://erp.example.com/api/orders"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-verify-url"><?php esc_html_e( 'Style verify URL', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="text" id="ce-verify-url" class="large-text" name="<?php echo esc_attr( $o ); ?>[verify_url]" value="<?php echo esc_attr( $s['verify_url'] ); ?>" placeholder="https://test-ewand.it.sas.co.nz/api/v1/styles?search={style_no}">
							<p class="description"><?php esc_html_e( 'Used by the Verify button on product variations. GET request with the same Bearer token and extra header; {style_no} is replaced with the variation\'s Style No. The style whose code matches exactly is linked and its ID sent as style_id.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-token"><?php esc_html_e( 'Bearer token', 'codemas-ewand-ordersync' ); ?></label></th>
						<td>
							<input type="password" id="ce-token" class="large-text" name="<?php echo esc_attr( $o ); ?>[bearer_token]" value="" autocomplete="new-password"
								placeholder="<?php echo $s['bearer_token'] ? esc_attr__( '•••••••• saved – leave blank to keep', 'codemas-ewand-ordersync' ) : esc_attr__( 'Paste the token from the ERP team', 'codemas-ewand-ordersync' ); ?>">
							<?php if ( $s['bearer_token'] ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[clear_token]" value="1"> <?php esc_html_e( 'Remove saved token', 'codemas-ewand-ordersync' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Sent as "Authorization: Bearer <token>". Paste only the token.', 'codemas-ewand-ordersync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Extra header (optional)', 'codemas-ewand-ordersync' ); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr( $o ); ?>[header_name]" value="<?php echo esc_attr( $s['header_name'] ); ?>" placeholder="X-Api-Key">
							<input type="password" name="<?php echo esc_attr( $o ); ?>[header_value]" value="<?php echo esc_attr( $s['header_value'] ); ?>" placeholder="<?php esc_attr_e( 'value', 'codemas-ewand-ordersync' ); ?>" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Only if the ERP needs another header besides the Bearer token.', 'codemas-ewand-ordersync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-timeout"><?php esc_html_e( 'Timeout (seconds)', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="number" id="ce-timeout" min="5" max="60" name="<?php echo esc_attr( $o ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Production Order defaults (this website)', 'codemas-ewand-ordersync' ); ?></h2>
				<p><?php esc_html_e( 'Values sent on every order from this website. The Ewand Style No is set on each product variation.', 'codemas-ewand-ordersync' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ce-site"><?php esc_html_e( 'Site code', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="text" id="ce-site" name="<?php echo esc_attr( $o ); ?>[site_code]" value="<?php echo esc_attr( $s['site_code'] ); ?>" placeholder="SPIKE">
							<p class="description"><?php esc_html_e( 'Identifies this website in the ERP (same code as its web shop there). Not added to the order number.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-factory"><?php esc_html_e( 'Factory ID', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="number" id="ce-factory" min="1" name="<?php echo esc_attr( $o ); ?>[factory_id]" value="<?php echo esc_attr( $s['factory_id'] ); ?>" style="width:120px"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-agent"><?php esc_html_e( 'AI Agent user ID', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="number" id="ce-agent" min="1" name="<?php echo esc_attr( $o ); ?>[agent_id]" value="<?php echo esc_attr( $s['agent_id'] ); ?>" style="width:120px">
							<p class="description"><?php esc_html_e( 'ID of the "AI Agent" user in Ewand. Sent as Sales Agent, Customer Service and Account Manager.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-customer"><?php esc_html_e( 'Default Ewand customer ID', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="number" id="ce-customer" min="1" name="<?php echo esc_attr( $o ); ?>[customer_id]" value="<?php echo esc_attr( $s['customer_id'] ); ?>" style="width:120px">
							<p class="description"><?php esc_html_e( 'Sent as customer_id on every order, together with the customer\'s email (Ewand matches the real customer by email). Use a placeholder customer in Ewand such as "Web Orders". Leave blank to send none.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shipping region IDs', 'codemas-ewand-ordersync' ); ?></th>
						<td><?php self::render_regions( $s ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-team"><?php esc_html_e( 'Team/Club Name', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="text" id="ce-team" class="regular-text" name="<?php echo esc_attr( $o ); ?>[team_name]" value="<?php echo esc_attr( $s['team_name'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used when the order has no Team Name from checkout or the bulk order form. Leave blank to fall back to the customer\'s billing company.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-production"><?php esc_html_e( 'Production type', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="text" id="ce-production" name="<?php echo esc_attr( $o ); ?>[production_type]" value="<?php echo esc_attr( $s['production_type'] ); ?>" placeholder="make"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shipping Type', 'codemas-ewand-ordersync' ); ?></th>
						<td><select name="<?php echo esc_attr( $o ); ?>[ship_mode]">
							<option value="SEA" <?php selected( $s['ship_mode'], 'SEA' ); ?>>Sea</option>
							<option value="AIR" <?php selected( $s['ship_mode'], 'AIR' ); ?>>Air</option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-boxes"><?php esc_html_e( 'No. of boxes', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="number" id="ce-boxes" min="1" name="<?php echo esc_attr( $o ); ?>[no_of_boxes]" value="<?php echo esc_attr( $s['no_of_boxes'] ); ?>" style="width:90px">
							<p class="description"><?php esc_html_e( 'Sent on every order as the number of boxes.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-lead"><?php esc_html_e( 'Production lead time (days)', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><input type="number" id="ce-lead" min="0" name="<?php echo esc_attr( $o ); ?>[lead_time_days]" value="<?php echo esc_attr( $s['lead_time_days'] ); ?>" style="width:90px">
							<p class="description"><?php esc_html_e( 'Delivery date = date ordered + this many days. Leave blank to send no date.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Size attribute', 'codemas-ewand-ordersync' ); ?></th>
						<td><select name="<?php echo esc_attr( $o ); ?>[size_attribute]">
							<?php foreach ( wc_get_attribute_taxonomies() as $tax ) :
								$name = wc_attribute_taxonomy_name( $tax->attribute_name ); ?>
								<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $s['size_attribute'], $name ); ?>><?php echo esc_html( $tax->attribute_label ); ?></option>
							<?php endforeach; ?>
						</select>
							<p class="description"><?php esc_html_e( 'Variation attribute used for the Size | Total rows.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ce-sizes"><?php esc_html_e( 'Size mapping', 'codemas-ewand-ordersync' ); ?></label></th>
						<td><textarea id="ce-sizes" rows="6" cols="30" name="<?php echo esc_attr( $o ); ?>[size_map]" placeholder="2XL - Adults=2XL"><?php echo esc_textarea( $s['size_map'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line: website size = Ewand size (e.g. 2XL - Adults=2XL). Sizes not listed are sent as they are.', 'codemas-ewand-ordersync' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php self::render_tools(); ?>

			<p>
				<?php
				printf(
					/* translators: %s: logs link */
					esc_html__( 'Logs: %s (source "codemas-ewand"). Each order also gets an order note after every send.', 'codemas-ewand-ordersync' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=codemas-ewand' ) ) . '">' . esc_html__( 'WooCommerce Logs', 'codemas-ewand-ordersync' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Shipping method → Ewand shipping region ID table. Lists every method of
	 * every shipping zone (WooCommerce → Settings → Shipping).
	 */
	private static function render_regions( array $s ) {
		$o   = self::OPTION;
		$ids = array();
		foreach ( (array) $s['region_ids'] as $row ) {
			if ( isset( $row['method'] ) ) {
				$ids[ (int) $row['method'] ] = $row['id'];
			}
		}

		$zones = array();
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$zones[] = new WC_Shipping_Zone( $zone['id'] );
		}
		$zones[] = new WC_Shipping_Zone( 0 ); // Rest of the world.
		?>
		<table class="widefat striped ce-regions" style="max-width:720px">
			<thead><tr>
				<th><?php esc_html_e( 'Zone', 'codemas-ewand-ordersync' ); ?></th>
				<th><?php esc_html_e( 'Shipping method', 'codemas-ewand-ordersync' ); ?></th>
				<th style="width:170px"><?php esc_html_e( 'Ewand region ID', 'codemas-ewand-ordersync' ); ?></th>
			</tr></thead>
			<tbody>
				<?php
				$i = 0;
				foreach ( $zones as $zone ) :
					foreach ( $zone->get_shipping_methods() as $method ) :
						$n = $o . '[region_ids][' . $i++ . ']';
						?>
						<tr>
							<td><?php echo esc_html( $zone->get_zone_name() ); ?></td>
							<td><?php echo esc_html( $method->get_title() ); ?>
								<?php if ( ! $method->is_enabled() ) : ?><em>(<?php esc_html_e( 'disabled', 'codemas-ewand-ordersync' ); ?>)</em><?php endif; ?>
								<input type="hidden" name="<?php echo esc_attr( $n ); ?>[method]" value="<?php echo esc_attr( $method->get_instance_id() ); ?>"></td>
							<td><input type="number" min="1" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $ids[ $method->get_instance_id() ] ?? '' ); ?>" style="width:120px"></td>
						</tr>
					<?php endforeach;
				endforeach;
				if ( ! $i ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No shipping methods set up in WooCommerce → Settings → Shipping.', 'codemas-ewand-ordersync' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p>
			<label for="ce-default-region"><?php esc_html_e( 'Default region ID', 'codemas-ewand-ordersync' ); ?></label>
			<input type="number" id="ce-default-region" min="1" name="<?php echo esc_attr( $o ); ?>[default_region_id]" value="<?php echo esc_attr( $s['default_region_id'] ); ?>" style="width:120px">
		</p>
		<p class="description"><?php esc_html_e( 'Sent as shipping_region_id, picked from the shipping method chosen on the order (its title is sent as shipping_region), then the default. Orders with no match are held as "Not ready". Empty rows are ignored.', 'codemas-ewand-ordersync' ); ?></p>
		<?php
	}

	/**
	 * "Preview JSON" and "Send test" boxes.
	 */
	private static function render_tools() {
		$order_id = 0;
		$action   = '';

		if ( isset( $_POST['codemas_ewand_tool'] ) && check_admin_referer( 'codemas_ewand_tools' ) ) {
			$action   = sanitize_key( wp_unslash( $_POST['codemas_ewand_tool'] ) );
			$order_id = absint( $_POST['order_id'] ?? 0 );
		}
		if ( ! $order_id ) {
			$latest   = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order' ) );
			$order_id = $latest ? (int) $latest[0] : 0;
		}
		?>
		<hr>
		<h2><?php esc_html_e( 'Test & preview', 'codemas-ewand-ordersync' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'codemas_ewand_tools' ); ?>
			<label><?php esc_html_e( 'Order ID', 'codemas-ewand-ordersync' ); ?>
				<input type="number" name="order_id" value="<?php echo esc_attr( $order_id ); ?>" min="1" style="width:100px">
			</label>
			<button class="button" name="codemas_ewand_tool" value="preview"><?php esc_html_e( 'Preview JSON', 'codemas-ewand-ordersync' ); ?></button>
			<button class="button button-primary" name="codemas_ewand_tool" value="test" <?php disabled( '' === self::get( 'endpoint' ) ); ?>><?php esc_html_e( 'Send to ERP', 'codemas-ewand-ordersync' ); ?></button>
			<p class="description"><?php esc_html_e( 'Preview shows the JSON without sending. "Send to ERP" creates the order in the ERP and marks the WooCommerce order as sent – an order is only ever sent once.', 'codemas-ewand-ordersync' ); ?></p>
		</form>
		<?php
		if ( ! $action ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Order not found.', 'codemas-ewand-ordersync' ) . '</p></div>';
			return;
		}

		$payload  = Codemas_Ewand_Payload::build( $order );
		$problems = Codemas_Ewand_Payload::problems( $order );
		if ( $problems ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'The ERP will reject this order:', 'codemas-ewand-ordersync' ) . '</strong><br>' . implode( '<br>', array_map( 'esc_html', $problems ) ) . '</p></div>';
		}

		if ( 'test' === $action ) {
			$result = Codemas_Ewand_Sender::send( $order );
			if ( null === $result ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Already sent to the ERP – not sent again.', 'codemas-ewand-ordersync' ) . '</p></div>';
			} elseif ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Sent to the ERP.', 'codemas-ewand-ordersync' ) . '</p></div>';
			}
			$response = (string) wc_get_order( $order_id )->get_meta( Codemas_Ewand_Sender::META_RESPONSE );
			if ( null !== $result && '' !== $response ) {
				echo '<h3>' . esc_html__( 'ERP response', 'codemas-ewand-ordersync' ) . '</h3>';
				echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:200px;overflow:auto">' . esc_html( $response ) . '</pre>';
			}
		}

		echo '<h3>' . esc_html__( 'JSON', 'codemas-ewand-ordersync' ) . '</h3>';
		echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:500px;overflow:auto">' . esc_html( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}
}
