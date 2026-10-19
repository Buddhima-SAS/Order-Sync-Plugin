<?php
/**
 * Queues and sends orders to the ERP endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Codemas_Ewand_Sender {

	const HOOK        = 'codemas_ewand_send_order';
	const GROUP       = 'codemas-ewand';
	const LOG_SOURCE  = 'codemas-ewand';
	const MAX_RETRIES = 5;
	const KICK_ACTION = 'codemas_ewand_kick';

	/**
	 * Delay before the first send. CODEMAS switches half-payment orders from
	 * "processing" to "partially-paid" on the thank-you page, so wait for the
	 * status to settle and send the final state once. Kept short: the queue
	 * itself only runs about once a minute.
	 */
	const SEND_DELAY = 10;

	/* Seconds to wait before retry 1..5 */
	const BACKOFF = array( 60, 300, 1800, 7200, 21600 );

	const META_SYNCED_AT = '_codemas_ewand_synced_at';
	const META_EVENT     = '_codemas_ewand_last_event';
	const META_CODE      = '_codemas_ewand_response_code';
	const META_ATTEMPTS  = '_codemas_ewand_attempts';
	const META_PAYLOAD   = '_codemas_ewand_last_payload';
	const META_RESPONSE  = '_codemas_ewand_last_response';
	const META_TRIED_AT  = '_codemas_ewand_last_attempt_at';

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( self::HOOK, array( __CLASS__, 'process' ), 10, 1 );
		add_action( 'wp_ajax_' . self::KICK_ACTION, array( __CLASS__, 'kick' ) );
		add_action( 'wp_ajax_nopriv_' . self::KICK_ACTION, array( __CLASS__, 'kick' ) );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_codemas_ewand_send', array( __CLASS__, 'manual_send' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
	}

	public static function on_status_changed( $order_id, $old_status, $new_status, $order ) {
		if ( ! Codemas_Ewand_Settings::is_active() ) {
			return;
		}
		if ( ! in_array( $new_status, (array) Codemas_Ewand_Settings::get( 'statuses' ), true ) ) {
			return;
		}
		if ( $order instanceof WC_Order && 'shop_order' !== $order->get_type() ) {
			return; // Skip refunds etc.
		}

		self::schedule( $order_id, self::SEND_DELAY );
		self::request_kick( $order_id );
	}

	/**
	 * The queue only runs when WP-Cron does, which on a quiet site can be
	 * minutes away. Fire a background request to this site that runs the queued
	 * send as soon as it is due. If the request fails, the queue still sends it.
	 */
	private static function request_kick( $order_id ) {
		static $requested = array();
		if ( isset( $requested[ $order_id ] ) ) {
			return;
		}
		$requested[ $order_id ] = true;

		add_action( 'shutdown', function () use ( $order_id ) {
			wp_remote_post( admin_url( 'admin-ajax.php' ), array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action'   => self::KICK_ACTION,
					'order_id' => (int) $order_id,
					'key'      => self::kick_key( $order_id ),
				),
			) );
		} );
	}

	private static function kick_key( $order_id ) {
		return wp_hash( self::KICK_ACTION . '|' . (int) $order_id );
	}

	/**
	 * Background request from request_kick(): wait out the send delay, then run
	 * this order's pending send.
	 */
	public static function kick() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$key      = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		if ( ! $order_id || ! hash_equals( self::kick_key( $order_id ), $key ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		sleep( self::SEND_DELAY + 1 );

		$pending = as_get_scheduled_actions( array(
			'hook'     => self::HOOK,
			'args'     => array( $order_id ),
			'group'    => self::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		), 'ids' );
		foreach ( $pending as $action_id ) {
			ActionScheduler_QueueRunner::instance()->process_action( $action_id, 'Codemas Ewand' );
		}
		wp_die();
	}

	/**
	 * Queue a send unless one is already pending for this order (the pending one
	 * will pick up the latest order state when it runs).
	 */
	public static function schedule( $order_id, $delay ) {
		$args    = array( (int) $order_id );
		$pending = as_get_scheduled_actions( array(
			'hook'     => self::HOOK,
			'args'     => $args,
			'group'    => self::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		), 'ids' );
		if ( $pending ) {
			return;
		}
		as_schedule_single_action( time() + (int) $delay, self::HOOK, $args, self::GROUP );
	}

	/**
	 * Action Scheduler callback.
	 */
	public static function process( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! Codemas_Ewand_Settings::is_active() ) {
			return;
		}

		$result = self::send( $order, false );

		if ( is_wp_error( $result ) && 'codemas_ewand_invalid' === $result->get_error_code() ) {
			// Retrying won't help until the missing IDs are filled in.
			$order->add_order_note( sprintf(
				/* translators: %s: reasons */
				__( 'Not sent to ERP: %s Fix this, then use "Send to ERP" in Order actions.', 'codemas-ewand-ordersync' ),
				$result->get_error_message()
			) );
		} elseif ( is_wp_error( $result ) ) {
			$attempts = (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
			$order->update_meta_data( self::META_ATTEMPTS, $attempts );
			$order->save_meta_data();

			if ( $attempts <= self::MAX_RETRIES ) {
				$delay = self::BACKOFF[ $attempts - 1 ];
				self::schedule( $order_id, $delay );
				$order->add_order_note( sprintf(
					/* translators: 1: error 2: attempt 3: max 4: minutes */
					__( 'ERP sync failed: %1$s. Retry %2$d of %3$d in %4$d min.', 'codemas-ewand-ordersync' ),
					$result->get_error_message(), $attempts, self::MAX_RETRIES, $delay / 60
				) );
			} else {
				$order->add_order_note( sprintf(
					/* translators: %s: error */
					__( 'ERP sync failed permanently: %s. Use "Send to ERP" in Order actions to retry.', 'codemas-ewand-ordersync' ),
					$result->get_error_message()
				) );
			}
		}
	}

	/**
	 * POST the order to the ERP.
	 *
	 * The ERP creates a new order on every POST, so an order is only sent
	 * automatically once; later status changes don't send it again.
	 *
	 * @param bool $force Send even if it was sent before ("Send to ERP" order action).
	 * @return true|null|WP_Error True on success, null if skipped, WP_Error on failure.
	 */
	public static function send( WC_Order $order, $force = false ) {
		if ( ! $force && $order->get_meta( self::META_SYNCED_AT ) ) {
			return null;
		}

		$problems = Codemas_Ewand_Payload::problems( $order );
		if ( $problems ) {
			return new WP_Error( 'codemas_ewand_invalid', implode( ' ', $problems ) );
		}

		$event   = $order->get_meta( self::META_SYNCED_AT ) ? 'order.resent' : 'order.created';
		$payload = Codemas_Ewand_Payload::build( $order );
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$logger  = wc_get_logger();
		$context = array( 'source' => self::LOG_SOURCE );
		$logger->debug( sprintf( 'Order #%d %s payload: %s', $order->get_id(), $event, $json ), $context );

		$result = self::post( $payload );

		$order->update_meta_data( self::META_PAYLOAD, $json );
		$order->update_meta_data( self::META_TRIED_AT, current_time( 'mysql' ) );

		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( self::META_RESPONSE, $result->get_error_message() );
			$order->save_meta_data();
			$logger->error( sprintf( 'Order #%d %s failed: %s', $order->get_id(), $event, $result->get_error_message() ), $context );
			return $result;
		}

		$code = $result['code'];
		$body = $result['body'];

		$order->update_meta_data( self::META_CODE, $code );
		$order->update_meta_data( self::META_RESPONSE, substr( $body, 0, 2048 ) );

		if ( $code < 200 || $code >= 300 ) {
			$order->save_meta_data();
			$logger->error( sprintf( 'Order #%d %s failed: HTTP %d %s', $order->get_id(), $event, $code, substr( $body, 0, 500 ) ), $context );
			$message = in_array( $code, array( 401, 403 ), true )
				? sprintf( 'HTTP %d – check Bearer token', $code )
				: sprintf( 'HTTP %d', $code );
			return new WP_Error( 'codemas_ewand_http', $message );
		}

		$order->update_meta_data( self::META_SYNCED_AT, current_time( 'mysql' ) );
		$order->update_meta_data( self::META_EVENT, $event );
		$order->update_meta_data( self::META_ATTEMPTS, 0 );
		$order->save_meta_data();

		$order->add_order_note( sprintf(
			/* translators: 1: event 2: HTTP code */
			__( 'Sent to ERP (%1$s) – HTTP %2$d.', 'codemas-ewand-ordersync' ),
			$event, $code
		) );
		$logger->info( sprintf( 'Order #%d %s sent: HTTP %d', $order->get_id(), $event, $code ), $context );

		do_action( 'codemas_ewand_order_sent', $order, $payload, $result );

		return true;
	}

	/**
	 * Raw HTTP POST of a payload to the ERP endpoint.
	 *
	 * @return array{code:int,body:string}|WP_Error
	 */
	public static function post( array $payload ) {
		$response = wp_remote_post( Codemas_Ewand_Settings::get( 'endpoint' ), array(
			'headers'     => self::headers() + array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'timeout'     => (int) Codemas_Ewand_Settings::get( 'timeout' ),
			'data_format' => 'body',
		) );

		return self::result( $response );
	}

	/**
	 * Raw HTTP GET from the ERP (same auth headers as post()).
	 *
	 * @return array{code:int,body:string}|WP_Error
	 */
	public static function get( $url ) {
		$response = wp_remote_get( $url, array(
			'headers' => self::headers(),
			'timeout' => (int) Codemas_Ewand_Settings::get( 'timeout' ),
		) );

		return self::result( $response );
	}

	private static function headers() {
		$headers = array( 'Accept' => 'application/json' );

		$token = Codemas_Ewand_Settings::get( 'bearer_token' );
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$header_name = Codemas_Ewand_Settings::get( 'header_name' );
		if ( $header_name ) {
			$headers[ $header_name ] = Codemas_Ewand_Settings::get( 'header_value' );
		}

		return $headers;
	}

	private static function result( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * "ERP Sync" box on the order edit screen (HPOS and legacy).
	 */
	public static function add_meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'codemas-ewand-sync', __( 'ERP Sync', 'codemas-ewand-ordersync' ), array( __CLASS__, 'render_meta_box' ), $screen, 'normal', 'low' );
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$synced   = $order->get_meta( self::META_SYNCED_AT );
		$tried    = $order->get_meta( self::META_TRIED_AT );
		$payload  = $order->get_meta( self::META_PAYLOAD );
		$response = $order->get_meta( self::META_RESPONSE );

		if ( ! $tried ) {
			echo '<p>' . esc_html__( 'Not sent to the ERP yet.', 'codemas-ewand-ordersync' ) . '</p>';
			return;
		}

		$rows = array(
			__( 'Last successful sync', 'codemas-ewand-ordersync' ) => $synced ?: '—',
			__( 'Last event', 'codemas-ewand-ordersync' )           => $order->get_meta( self::META_EVENT ) ?: '—',
			__( 'Last attempt', 'codemas-ewand-ordersync' )         => $tried,
			__( 'HTTP code', 'codemas-ewand-ordersync' )            => $order->get_meta( self::META_CODE ) ?: '—',
			__( 'Failed attempts', 'codemas-ewand-ordersync' )      => (int) $order->get_meta( self::META_ATTEMPTS ),
		);

		echo '<table class="widefat striped" style="margin-bottom:10px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:180px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$pretty = json_decode( $payload );
		$pretty = null !== $pretty ? wp_json_encode( $pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : $payload;

		$pre = 'background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;';
		echo '<details><summary><strong>' . esc_html__( 'Last sent JSON', 'codemas-ewand-ordersync' ) . '</strong></summary>';
		echo '<pre style="' . esc_attr( $pre ) . 'max-height:500px">' . esc_html( $pretty ) . '</pre></details>';
		echo '<details><summary><strong>' . esc_html__( 'ERP response', 'codemas-ewand-ordersync' ) . '</strong></summary>';
		echo '<pre style="' . esc_attr( $pre ) . 'max-height:200px">' . esc_html( $response ) . '</pre></details>';
	}

	public static function add_order_action( $actions, $order = null ) {
		if ( Codemas_Ewand_Settings::is_active() ) {
			$actions['codemas_ewand_send'] = __( 'Send to ERP', 'codemas-ewand-ordersync' );
		}
		return $actions;
	}

	public static function manual_send( $order ) {
		$result = self::send( $order, true );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error */
				__( 'Manual ERP send failed: %s', 'codemas-ewand-ordersync' ),
				$result->get_error_message()
			) );
		}
	}
}
