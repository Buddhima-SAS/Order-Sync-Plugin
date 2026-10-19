<?php
/**
 * Per-variation Ewand Style No (Verify looks it up in Ewand and stores the style ID),
 * plus the style data used to build the Production Order sent to the ERP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Codemas_Ewand_Product_Fields {

	const STYLE_NO       = '_ce_style_no';
	const STYLE_ID       = '_ce_ewand_style_id';
	const STYLE_DESC     = '_ce_style_desc';
	const ITEM_NOTE      = '_ce_item_note';
	const COMPONENTS     = '_ce_components';
	const EMBELLISHMENTS = '_ce_embellishments';

	/* Same codes as po-extractor config/pdf.php "embellishment_codes" */
	const EMBELLISHMENT_CODES = array(
		'EMBROIDERY'          => 'EMB',
		'SUBLIMATION'         => 'SUB',
		'SCREEN PRINT'        => 'SP',
		'SCREEN PRINTING'     => 'SP',
		'HEAT TRANSFER'       => 'HT',
		'HEAT TRANSFER PRINT' => 'HT',
		'VINYL PRINT'         => 'VY',
		'VINYL PRINTING'      => 'VY',
		'VINYL'               => 'VY',
		'DIGITAL PRINT'       => 'DP',
		'REFLECTIVE PRINT'    => 'RP',
		'RUBBER PRINT'        => 'RP',
		'TPU'                 => 'TPU',
		'PUFF PRINT'          => 'PUFF PRINT',
		'APPLIQUE'            => 'APPLIQUE',
	);

	/* Types offered in the dropdown */
	const EMBELLISHMENT_TYPES = array( 'EMBROIDERY', 'SUBLIMATION', 'SCREEN PRINT', 'HEAT TRANSFER', 'VINYL', 'DIGITAL PRINT', 'REFLECTIVE PRINT', 'RUBBER PRINT', 'TPU', 'PUFF PRINT', 'APPLIQUE' );

	public static function init() {
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_field' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );

		add_action( 'wp_ajax_codemas_ewand_verify_style', array( __CLASS__, 'ajax_verify_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Production data for a product (variation Style No overrides the parent's).
	 *
	 * @return array{style_no:string,style_no_source:string,description:string,item_note:string,components:array,embellishments:array}
	 */
	public static function get_style_data( $product_id, $variation_id = 0 ) {
		$product = wc_get_product( $product_id );

		$style_no = '';
		$source   = 'style_no';

		if ( $variation_id ) {
			$style_no = (string) get_post_meta( $variation_id, self::STYLE_NO, true );
		}
		if ( '' === $style_no ) {
			$style_no = (string) get_post_meta( $product_id, self::STYLE_NO, true );
		}
		if ( '' === $style_no && $product ) {
			$variation = $variation_id ? wc_get_product( $variation_id ) : null;
			$style_no  = $variation && $variation->get_sku() ? $variation->get_sku() : $product->get_sku();
			$source    = 'sku';
		}
		if ( '' === $style_no ) {
			$style_no = $product ? $product->get_name() : (string) $product_id;
			$source   = 'name';
		}

		$description = (string) get_post_meta( $product_id, self::STYLE_DESC, true );
		if ( '' === $description && $product ) {
			$description = $product->get_short_description();
		}
		$description = html_entity_decode( wp_strip_all_tags( $description ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = trim( preg_replace( "/\n{3,}/", "\n\n", str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $description ) ) );

		$data = array(
			'style_no'        => $style_no,
			'style_no_source' => $source,
			'description'     => $description,
			'item_note'       => (string) get_post_meta( $product_id, self::ITEM_NOTE, true ),
			'components'      => array_values( (array) get_post_meta( $product_id, self::COMPONENTS, true ) ),
			'embellishments'  => array_values( (array) get_post_meta( $product_id, self::EMBELLISHMENTS, true ) ),
		);

		$data['components']     = array_values( array_filter( $data['components'], 'is_array' ) );
		$data['embellishments'] = array_values( array_filter( $data['embellishments'], 'is_array' ) );

		return apply_filters( 'codemas_ewand_style_data', $data, $product_id, $variation_id );
	}

	/**
	 * Ewand style record ID (variation overrides the parent), or null when not set.
	 */
	public static function get_ewand_style_id( $product_id, $variation_id = 0 ) {
		$id = $variation_id ? (int) get_post_meta( $variation_id, self::STYLE_ID, true ) : 0;
		if ( ! $id ) {
			$id = (int) get_post_meta( $product_id, self::STYLE_ID, true );
		}
		$id = (int) apply_filters( 'codemas_ewand_style_id', $id, $product_id, $variation_id );
		// TEMP: until every product has its Ewand Style ID, send style 1. Remove before go-live.
		return $id ?: 1;
	}

	/**
	 * "EMBROIDERY, EMBROIDERY, SUBLIMATION" => "EMB(2)/SUB(1)", nothing => "NO".
	 */
	public static function embellishment_summary( array $embellishments ) {
		$counts = array();
		foreach ( $embellishments as $e ) {
			$type = strtoupper( trim( $e['type'] ?? '' ) );
			if ( '' === $type ) {
				continue;
			}
			$code            = self::EMBELLISHMENT_CODES[ $type ] ?? $type;
			$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + 1;
		}
		if ( ! $counts ) {
			return 'NO';
		}
		return implode( '/', array_map( fn( $code, $n ) => "$code($n)", array_keys( $counts ), $counts ) );
	}

	private static function sanitize_id( $value ) {
		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		return '' === $value ? '' : (string) absint( $value );
	}

	public static function render_variation_field( $loop, $variation_data, $variation ) {
		$style_id = get_post_meta( $variation->ID, self::STYLE_ID, true );
		woocommerce_wp_text_input( array(
			'id'            => 'ce_variation_style_no_' . $loop,
			'name'          => 'ce_variation_style_no[' . $loop . ']',
			'value'         => get_post_meta( $variation->ID, self::STYLE_NO, true ),
			'label'         => __( 'Ewand Style No', 'codemas-ewand-ordersync' ),
			'placeholder'   => 'PSHOOD 33 CL KKHS',
			'class'         => 'short ce-style-no',
			'wrapper_class' => 'form-row form-row-first',
			'desc_tip'      => true,
			'description'   => __( 'Style number on the Production Order. Verify links it to the Ewand style sent with orders.', 'codemas-ewand-ordersync' ),
		) );
		?>
		<p class="form-row form-row-last ce-verify-row">
			<label><?php esc_html_e( 'Ewand verification', 'codemas-ewand-ordersync' ); ?></label>
			<input type="hidden" class="ce-style-id" name="ce_variation_style_id[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $style_id ); ?>">
			<button type="button" class="button ce-verify-style<?php echo $style_id ? ' is-ok' : ''; ?>"><?php echo $style_id ? esc_html__( '✓ Verified', 'codemas-ewand-ordersync' ) : esc_html__( 'Verify with Ewand', 'codemas-ewand-ordersync' ); ?></button>
		</p>
		<div class="form-row form-row-full ce-verify-status<?php echo $style_id ? ' is-ok' : ''; ?>"<?php echo $style_id ? '' : ' hidden'; ?>>
			<?php
			if ( $style_id ) {
				/* translators: %s: Ewand style ID */
				printf( esc_html__( 'Linked to Ewand style #%s', 'codemas-ewand-ordersync' ), esc_html( $style_id ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Looks up the Style No of a variation in Ewand and, when found, saves the Style No
	 * and the matching Ewand style ID on the variation straight away.
	 */
	public static function ajax_verify_style() {
		check_ajax_referer( 'ce_verify_style', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'codemas-ewand-ordersync' ) ), 403 );
		}

		$style_no     = trim( sanitize_text_field( wp_unslash( $_POST['style_no'] ?? '' ) ) );
		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		$url          = (string) Codemas_Ewand_Settings::get( 'verify_url' );

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Set the Style verify URL in WooCommerce → ERP Sync.', 'codemas-ewand-ordersync' ) ) );
		}
		if ( '' === $style_no ) {
			wp_send_json_error( array( 'message' => __( 'Enter the Ewand Style No first.', 'codemas-ewand-ordersync' ) ) );
		}

		$matches = self::find_ewand_styles( str_replace( '{style_no}', rawurlencode( $style_no ), $url ), $style_no );
		if ( is_wp_error( $matches ) ) {
			wp_send_json_error( array( 'message' => $matches->get_error_message() ) );
		}
		if ( ! $matches ) {
			/* translators: %s: style number */
			wp_send_json_error( array( 'message' => sprintf( __( 'Style No "%s" not found in Ewand.', 'codemas-ewand-ordersync' ), $style_no ) ) );
		}

		// Same code can exist more than once in Ewand: prefer active, then the newest.
		usort( $matches, function ( $a, $b ) {
			$active = (int) ( 'active' === ( $b['status'] ?? '' ) ) - (int) ( 'active' === ( $a['status'] ?? '' ) );
			return $active ?: (int) $b['id'] - (int) $a['id'];
		} );
		$style = $matches[0];

		/* translators: 1: style number, 2: Ewand style ID, 3: style name */
		$message = sprintf( __( 'Verified: %1$s = Ewand style #%2$s (%3$s)', 'codemas-ewand-ordersync' ), $style['code'], $style['id'], $style['name'] ?? '' );
		if ( 'active' !== ( $style['status'] ?? '' ) ) {
			/* translators: %s: Ewand style status */
			$message .= ' – ' . sprintf( __( 'status: %s', 'codemas-ewand-ordersync' ), $style['status'] ?? '?' );
		}
		if ( count( $matches ) > 1 ) {
			/* translators: %d: number of Ewand styles with this code */
			$message .= ' – ' . sprintf( __( '%d styles have this code', 'codemas-ewand-ordersync' ), count( $matches ) );
		}

		$variation = $variation_id && current_user_can( 'edit_post', $variation_id ) ? wc_get_product( $variation_id ) : null;
		if ( $variation && $variation->is_type( 'variation' ) ) {
			$variation->update_meta_data( self::STYLE_NO, $style_no );
			$variation->update_meta_data( self::STYLE_ID, (string) absint( $style['id'] ) );
			$variation->save_meta_data();
		}

		wp_send_json_success( array(
			'id'      => (int) $style['id'],
			'message' => $message,
		) );
	}

	/**
	 * Ewand styles whose code equals $style_no exactly (case-insensitive), following result pages.
	 *
	 * @return array[]|WP_Error
	 */
	private static function find_ewand_styles( $url, $style_no ) {
		$matches = array();

		for ( $page = 0; $url && $page < 10; $page++ ) {
			$result = Codemas_Ewand_Sender::get( $url );
			if ( is_wp_error( $result ) ) {
				/* translators: %s: error message */
				return new WP_Error( 'ce_verify', sprintf( __( 'Could not reach Ewand: %s', 'codemas-ewand-ordersync' ), $result->get_error_message() ) );
			}
			if ( 404 === $result['code'] ) {
				return array();
			}
			if ( in_array( $result['code'], array( 401, 403 ), true ) ) {
				/* translators: %d: HTTP status code */
				return new WP_Error( 'ce_verify', sprintf( __( 'Ewand returned HTTP %d – check the Bearer token in ERP Sync.', 'codemas-ewand-ordersync' ), $result['code'] ) );
			}
			if ( $result['code'] < 200 || $result['code'] >= 300 ) {
				/* translators: %d: HTTP status code */
				return new WP_Error( 'ce_verify', sprintf( __( 'Ewand returned HTTP %d.', 'codemas-ewand-ordersync' ), $result['code'] ) );
			}

			$json  = json_decode( $result['body'], true );
			$items = $json['data'] ?? array();
			if ( isset( $items['id'] ) ) {
				$items = array( $items ); // Single style (e.g. /styles/{id}).
			}
			foreach ( (array) $items as $item ) {
				if ( is_array( $item ) && isset( $item['id'], $item['code'] ) && 0 === strcasecmp( trim( (string) $item['code'] ), $style_no ) ) {
					$matches[] = $item;
				}
			}

			$url = $json['links']['next'] ?? null;
		}

		return $matches;
	}

	public static function save_variation( $variation_id, $loop ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before this hook.
		$variation = wc_get_product( $variation_id );
		if ( $variation ) {
			$variation->update_meta_data( self::STYLE_NO, sanitize_text_field( wp_unslash( $_POST['ce_variation_style_no'][ $loop ] ?? '' ) ) );
			$variation->update_meta_data( self::STYLE_ID, self::sanitize_id( $_POST['ce_variation_style_id'][ $loop ] ?? '' ) );
			$variation->save_meta_data();
		}
		// phpcs:enable
	}

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
			return;
		}
		wp_register_style( 'codemas-ewand-admin', false, array(), CODEMAS_EWAND_VERSION );
		wp_enqueue_style( 'codemas-ewand-admin' );
		wp_add_inline_style( 'codemas-ewand-admin', '
			.woocommerce_variation .ce-verify-row label{display:block}
			.woocommerce_variation .ce-verify-row .ce-verify-style{width:100%;min-height:40px;text-align:center}
			.woocommerce_variation .ce-verify-style.is-ok{color:#fff;background:#00a32a;border-color:#00a32a}
			.woocommerce_variation .ce-verify-style.is-ok:hover,.woocommerce_variation .ce-verify-style.is-ok:focus{color:#fff;background:#008a20;border-color:#008a20}
			.woocommerce_variation .ce-verify-style.is-bad{color:#fff;background:#d63638;border-color:#d63638}
			.woocommerce_variation .ce-verify-style.is-bad:hover,.woocommerce_variation .ce-verify-style.is-bad:focus{color:#fff;background:#b32d2e;border-color:#b32d2e}
			.woocommerce_variation .ce-verify-status{clear:both;box-sizing:border-box;margin:0 0 12px;padding:8px 12px;border-left:4px solid #c3c4c7;background:#f6f7f7;color:#50575e}
			.woocommerce_variation .ce-verify-status[hidden]{display:none}
			.woocommerce_variation .ce-verify-status.is-ok{border-left-color:#00a32a;background:#edfaef;color:#00691a}
			.woocommerce_variation .ce-verify-status.is-bad{border-left-color:#d63638;background:#fcf0f1;color:#8a2424}
		' );
		wp_add_inline_script( 'wc-admin-meta-boxes', "
			jQuery(function($){
				var box = $('#woocommerce-product-data');
				var txt = {
					verify: '" . esc_js( __( 'Verify with Ewand', 'codemas-ewand-ordersync' ) ) . "',
					checking: '" . esc_js( __( 'Checking…', 'codemas-ewand-ordersync' ) ) . "',
					ok: '" . esc_js( __( '✓ Verified', 'codemas-ewand-ordersync' ) ) . "',
					bad: '" . esc_js( __( '✗ Not verified – try again', 'codemas-ewand-ordersync' ) ) . "',
					unexpected: '" . esc_js( __( 'Unexpected response.', 'codemas-ewand-ordersync' ) ) . "',
					failed: '" . esc_js( __( 'Request failed.', 'codemas-ewand-ordersync' ) ) . "'
				};
				function show(row, state, msg){
					row.find('.ce-verify-style').removeClass('is-ok is-bad').addClass(state ? 'is-' + state : '').prop('disabled', false)
						.text(state === 'ok' ? txt.ok : state === 'bad' ? txt.bad : txt.verify);
					row.find('.ce-verify-status').removeClass('is-ok is-bad').addClass(state ? 'is-' + state : '').prop('hidden', !msg).text(msg || '');
				}
				box.on('click', '.ce-verify-style', function(){
					var row = $(this).closest('.woocommerce_variation');
					$(this).removeClass('is-ok is-bad').prop('disabled', true).text(txt.checking);
					$.post(ajaxurl, {
						action: 'codemas_ewand_verify_style',
						nonce: '" . esc_js( wp_create_nonce( 'ce_verify_style' ) ) . "',
						variation_id: row.find('.variable_post_id').val(),
						style_no: row.find('.ce-style-no').val()
					}).done(function(r){
						if (r && r.success) {
							row.find('.ce-style-id').val(r.data.id);
							show(row, 'ok', r.data.message);
						} else {
							show(row, 'bad', r && r.data && r.data.message ? r.data.message : txt.unexpected);
						}
					}).fail(function(){
						show(row, 'bad', txt.failed);
					});
				});
				box.on('input', '.ce-style-no', function(){
					var row = $(this).closest('.woocommerce_variation');
					row.find('.ce-style-id').val('');
					show(row, '', '');
				});
			});
		" );
	}
}
