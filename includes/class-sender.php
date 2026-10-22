<?php
/**
 * Sends orders to the ERP endpoint. Sending is always manual (Ewand Sync → Orders,
 * or the "Send to ERP" order action) and each order is sent at most once.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Codemas_Ewand_Sender {

	const LOG_SOURCE = 'codemas-ewand';

	/* Background queue used by earlier versions (auto-send); leftover actions are removed */
	const HOOK  = 'codemas_ewand_send_order';
	const GROUP = 'codemas-ewand';

	const META_SYNCED_AT = '_codemas_ewand_synced_at';
	const META_EVENT     = '_codemas_ewand_last_event';
	const META_CODE      = '_codemas_ewand_response_code';
	const META_ATTEMPTS  = '_codemas_ewand_attempts';
	const META_PAYLOAD   = '_codemas_ewand_last_payload';
	const META_RESPONSE  = '_codemas_ewand_last_response';
	const META_TRIED_AT  = '_codemas_ewand_last_attempt_at';

	/* Seconds after which a send lock is treated as abandoned (longer than the max HTTP timeout) */
	const LOCK_TTL = 300;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'clear_old_queue' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_codemas_ewand_send', array( __CLASS__, 'manual_send' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
	}

	/**
	 * Once: cancel automatic sends/retries still queued by the old auto-send.
	 */
	public static function clear_old_queue() {
		if ( get_option( 'codemas_ewand_queue_cleared' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		update_option( 'codemas_ewand_queue_cleared', 1, false );
	}

	/**
	 * POST the order to the ERP – once. The ERP creates a new order on every POST,
	 * so an order that was sent successfully is never sent again, and a lock stops
	 * two sends of the same order (queue + button, double click) running together.
	 *
	 * @return true|null|WP_Error True on success, null if already sent, WP_Error on failure.
	 */
	public static function send( WC_Order $order ) {
		$order_id = $order->get_id();
		if ( ! self::lock( $order_id ) ) {
			return new WP_Error( 'codemas_ewand_busy', __( 'This order is being sent right now – check again in a minute.', 'codemas-ewand-ordersync' ) );
		}

		try {
			// Re-read: another request may have sent it since this order object was loaded.
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh || $fresh->get_meta( self::META_SYNCED_AT ) ) {
				return null;
			}
			return self::do_send( $fresh );
		} finally {
			self::unlock( $order_id );
		}
	}

	private static function lock_key( $order_id ) {
		return 'codemas_ewand_lock_' . (int) $order_id;
	}

	/**
	 * add_option() only succeeds for one request (unique option name), so it works as a lock.
	 */
	private static function lock( $order_id ) {
		$key = self::lock_key( $order_id );
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		wp_cache_delete( $key, 'options' );
		if ( (int) get_option( $key ) < time() - self::LOCK_TTL ) {
			delete_option( $key );
			return add_option( $key, time(), '', 'no' );
		}
		return false;
	}

	private static function unlock( $order_id ) {
		delete_option( self::lock_key( $order_id ) );
	}

	private static function do_send( WC_Order $order ) {
		$problems = Codemas_Ewand_Payload::problems( $order );
		if ( $problems ) {
			return new WP_Error( 'codemas_ewand_invalid', implode( ' ', $problems ) );
		}

		$event   = 'order.created';
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
			$order->update_meta_data( self::META_ATTEMPTS, (int) $order->get_meta( self::META_ATTEMPTS ) + 1 );
			$order->save_meta_data();
			$logger->error( sprintf( 'Order #%d %s failed: %s', $order->get_id(), $event, $result->get_error_message() ), $context );
			return $result;
		}

		$code = $result['code'];
		$body = $result['body'];

		$order->update_meta_data( self::META_CODE, $code );
		$order->update_meta_data( self::META_RESPONSE, substr( $body, 0, 2048 ) );

		if ( $code < 200 || $code >= 300 ) {
			$order->update_meta_data( self::META_ATTEMPTS, (int) $order->get_meta( self::META_ATTEMPTS ) + 1 );
			$order->save_meta_data();
			$logger->error( sprintf( 'Order #%d %s failed: HTTP %d %s', $order->get_id(), $event, $code, substr( $body, 0, 500 ) ), $context );
			$message = in_array( $code, array( 401, 403 ), true )
				? sprintf( 'HTTP %d – check Bearer token', $code )
				: sprintf( 'HTTP %d', $code );
			$detail  = self::error_detail( $body );
			if ( '' !== $detail ) {
				$message .= ' – ' . $detail;
			}
			// Validation errors (422 etc.) fail the same way on every retry.
			$rejected = $code >= 400 && $code < 500 && ! in_array( $code, array( 401, 403, 408, 429 ), true );
			return new WP_Error( $rejected ? 'codemas_ewand_rejected' : 'codemas_ewand_http', $message );
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
	 * Readable error from an ERP JSON error body: every validation error, else its message.
	 */
	public static function error_detail( $body ) {
		$json = json_decode( (string) $body, true );
		if ( ! is_array( $json ) ) {
			return '';
		}
		if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
			$errors = array();
			array_walk_recursive( $json['errors'], function ( $e ) use ( &$errors ) {
				$errors[] = (string) $e;
			} );
			return implode( ' ', array_unique( $errors ) );
		}
		return is_string( $json['message'] ?? null ) ? $json['message'] : '';
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
		if ( Codemas_Ewand_Settings::is_active() && ! ( $order instanceof WC_Order && $order->get_meta( self::META_SYNCED_AT ) ) ) {
			$actions['codemas_ewand_send'] = __( 'Send to ERP', 'codemas-ewand-ordersync' );
		}
		return $actions;
	}

	public static function manual_send( $order ) {
		$result = self::send( $order );
		if ( null === $result ) {
			$order->add_order_note( __( 'Already sent to ERP – not sent again (each send creates a new ERP order).', 'codemas-ewand-ordersync' ) );
		} elseif ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error */
				__( 'Manual ERP send failed: %s', 'codemas-ewand-ordersync' ),
				$result->get_error_message()
			) );
		}
	}
}
