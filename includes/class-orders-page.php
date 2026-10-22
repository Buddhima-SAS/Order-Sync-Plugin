<?php
/**
 * Ewand Sync → Orders (top-level menu): orders with their Ewand sync state, filters and
 * "Send to Ewand" actions. Orders are only sent from here (or the order action), never automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Codemas_Ewand_Orders_Page {

	const PAGE = 'codemas-ewand-orders';
	const CAP  = 'edit_shop_orders';

	/* Sync filters shown as views above the table */
	const VIEWS = array( 'all', 'not_sent', 'failed', 'sent' );

	/** @var string[] Screen IDs of the Ewand Sync menu pages */
	private static $screens = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 59 );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		add_action( 'wp_ajax_ce_ewand_send', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_ce_ewand_unsent_ids', array( __CLASS__, 'ajax_unsent_ids' ) );
	}

	/* Loads WooCommerce admin styles (order status badges) on the Ewand Sync pages */
	public static function screen_ids( $ids ) {
		return array_merge( $ids, self::$screens );
	}

	/**
	 * Adds a page under the Ewand Sync menu.
	 */
	public static function add_page( $title, $menu_title, $cap, $slug, $callback ) {
		$hook              = add_submenu_page( self::PAGE, $title, $menu_title, $cap, $slug, $callback );
		self::$screens[]   = $hook;
		return $hook;
	}

	public static function add_menu() {
		// Top-level menu (like CPT UI); its first submenu renames the duplicate entry to "Orders".
		$hook            = add_menu_page(
			__( 'Ewand Orders', 'codemas-ewand-ordersync' ),
			__( 'Ewand Sync', 'codemas-ewand-ordersync' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-update',
			57
		);
		self::add_page( __( 'Ewand Orders', 'codemas-ewand-ordersync' ), __( 'Orders', 'codemas-ewand-ordersync' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
		add_action( 'load-' . $hook, array( __CLASS__, 'handle_actions' ) );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * Row "Send now"/"Retry" and the "Send to Ewand" bulk action. Redirects back with a result notice.
	 */
	public static function handle_actions() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$back = remove_query_arg( array( 'action', 'action2', 'order', 'orders', '_wpnonce', '_wp_http_referer', 'ce_sent', 'ce_failed', 'ce_skipped', 'ce_error' ), wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

		// Single order: send right away so the result can be shown.
		if ( isset( $_GET['ce_send'] ) ) {
			$order_id = absint( $_GET['ce_send'] );
			check_admin_referer( 'ce_send_' . $order_id );
			$back = remove_query_arg( 'ce_send', $back );

			if ( ! Codemas_Ewand_Settings::is_active() ) {
				wp_safe_redirect( add_query_arg( 'ce_error', 'inactive', $back ) );
				exit;
			}

			$order  = wc_get_order( $order_id );
			$result = $order ? Codemas_Ewand_Sender::send( $order ) : new WP_Error( 'ce_missing', __( 'Order not found.', 'codemas-ewand-ordersync' ) );
			if ( is_wp_error( $result ) ) {
				if ( $order ) {
					/* translators: %s: error */
					$order->add_order_note( sprintf( __( 'Manual ERP send failed: %s', 'codemas-ewand-ordersync' ), $result->get_error_message() ) );
				}
				set_transient( 'ce_orders_error_' . get_current_user_id(), sprintf( '#%s: %s', $order ? $order->get_order_number() : $order_id, $result->get_error_message() ), 60 );
				wp_safe_redirect( add_query_arg( 'ce_failed', 1, $back ) );
			} else {
				wp_safe_redirect( add_query_arg( null === $result ? array( 'ce_sent' => 0, 'ce_skipped' => 1 ) : array( 'ce_sent' => 1 ), $back ) );
			}
			exit;
		}

		// Bulk without JavaScript (the page script normally sends one by one in place).
		$action = sanitize_key( $_GET['action'] ?? '' );
		if ( '-1' === $action || '' === $action ) {
			$action = sanitize_key( $_GET['action2'] ?? '' );
		}
		if ( 'ewand_send' !== $action || empty( $_GET['orders'] ) ) {
			if ( ! empty( $_GET['_wp_http_referer'] ) ) {
				wp_safe_redirect( $back ); // Filter/search submit: drop the form's nonce fields from the URL.
				exit;
			}
			return;
		}
		check_admin_referer( 'bulk-ewand-orders' );

		if ( ! Codemas_Ewand_Settings::is_active() ) {
			wp_safe_redirect( add_query_arg( 'ce_error', 'inactive', $back ) );
			exit;
		}

		$sent    = 0;
		$failed  = 0;
		$skipped = 0;
		foreach ( array_map( 'absint', (array) $_GET['orders'] ) as $order_id ) {
			$order  = wc_get_order( $order_id );
			$result = $order ? Codemas_Ewand_Sender::send( $order ) : null;
			if ( true === $result ) {
				++$sent;
			} elseif ( is_wp_error( $result ) ) {
				++$failed;
			} else {
				++$skipped;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'ce_sent' => $sent, 'ce_failed' => $failed, 'ce_skipped' => $skipped ), $back ) );
		exit;
	}

	private static function ajax_check() {
		check_ajax_referer( 'ce_ewand_orders', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'codemas-ewand-ordersync' ) ), 403 );
		}
		if ( ! Codemas_Ewand_Settings::is_active() ) {
			wp_send_json_error( array( 'message' => __( 'ERP sync is disabled or has no endpoint set.', 'codemas-ewand-ordersync' ) ) );
		}
	}

	/**
	 * Sends one order now; returns its refreshed Ewand sync and button cells.
	 */
	public static function ajax_send() {
		self::ajax_check();

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'codemas-ewand-ordersync' ) ) );
		}

		$result = Codemas_Ewand_Sender::send( $order );
		if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'codemas_ewand_invalid', 'codemas_ewand_busy' ), true ) ) {
			/* translators: %s: error */
			$order->add_order_note( sprintf( __( 'Manual ERP send failed: %s', 'codemas-ewand-ordersync' ), $result->get_error_message() ) );
		}

		$order = wc_get_order( $order_id );
		wp_send_json_success( array(
			'ok'      => ! is_wp_error( $result ),
			'state'   => Codemas_Ewand_Orders_Table::state( $order, true )[0],
			/* translators: 1: order number 2: error */
			'message' => is_wp_error( $result ) ? sprintf( __( '#%1$s: %2$s', 'codemas-ewand-ordersync' ), $order->get_order_number(), $result->get_error_message() ) : '',
			'ewand'   => Codemas_Ewand_Orders_Table::ewand_cell( $order ),
			'send'    => Codemas_Ewand_Orders_Table::send_cell( $order ),
		) );
	}

	/**
	 * IDs of all orders matching the current filters that were never sent (every page).
	 */
	public static function ajax_unsent_ids() {
		self::ajax_check();
		$ids = wc_get_orders( Codemas_Ewand_Orders_Table::filter_args() + array(
			'meta_query' => Codemas_Ewand_Orders_Table::sync_meta_query( 'unsent' ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'limit'      => -1,
			'return'     => 'ids',
		) );
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$table = new Codemas_Ewand_Orders_Table();
		$table->prepare_items();
		?>
		<div class="wrap ce-orders">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Ewand Orders', 'codemas-ewand-ordersync' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Codemas_Ewand_Settings::PAGE ) ); ?>"><?php esc_html_e( 'ERP Sync settings', 'codemas-ewand-ordersync' ); ?></a>
			<hr class="wp-header-end">
			<?php self::notices(); ?>
			<div id="ce-orders-notice"></div>
			<style>
				.ce-orders .column-order{width:22%}
				.ce-orders .column-date,.ce-orders .column-total{width:10%}
				.ce-orders .column-status{width:12%}
				.ce-orders .column-ewand{width:30%}
				.ce-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;line-height:20px}
				.ce-badge.is-sent{background:#edfaef;color:#00691a}
				.ce-badge.is-failed{background:#fcf0f1;color:#8a2424}
				.ce-badge.is-not-ready{background:#fcf9e8;color:#614200}
				.ce-badge.is-not-sent{background:#f0f0f1;color:#50575e}
				.ce-orders .ce-detail{display:block;margin-top:4px;color:#646970;font-size:12px;word-break:break-word}
				.ce-orders .ce-filters{display:inline-flex;gap:4px;align-items:center;flex-wrap:wrap}
				.ce-orders .column-send{width:120px;text-align:right}
				.ce-orders .ce-send{min-width:96px;text-align:center}
				.ce-orders .button.ce-send.is-sent,.ce-orders .button.ce-send.is-sent:hover,.ce-orders .button.ce-send.is-sent:focus{color:#fff!important;background:#00a32a;border-color:#00a32a;cursor:default;box-shadow:none}
				.ce-orders .button.ce-send.is-busy{pointer-events:none;opacity:.7}
				.ce-orders .ce-send-filtered{margin-left:8px}
			</style>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="sync" value="<?php echo esc_attr( $table->view ); ?>">
				<?php
				$table->search_box( __( 'Search orders', 'codemas-ewand-ordersync' ), 'ce-order' );
				$table->display();
				?>
			</form>
		</div>
		<?php
		self::script();
	}

	/**
	 * Sends from the table without reloading: row buttons, the bulk action and "Send filtered".
	 */
	private static function script() {
		$config = array(
			'nonce' => wp_create_nonce( 'ce_ewand_orders' ),
			'txt'   => array(
				'sending'  => __( 'Sending…', 'codemas-ewand-ordersync' ),
				/* translators: 1: current 2: total */
				'progress' => __( 'Sending %1$d of %2$d…', 'codemas-ewand-ordersync' ),
				/* translators: %d: number of orders */
				'confirm'  => __( 'Send %d order(s) to Ewand?', 'codemas-ewand-ordersync' ),
				/* translators: 1: count 2: from date 3: to date */
				'confirmRange' => __( 'Send %1$d unsent order(s) from %2$s to %3$s to Ewand?', 'codemas-ewand-ordersync' ),
				'start'    => __( 'the first order', 'codemas-ewand-ordersync' ),
				'today'    => __( 'today', 'codemas-ewand-ordersync' ),
				'none'     => __( 'No unsent orders match these filters.', 'codemas-ewand-ordersync' ),
				'select'   => __( 'Select the orders to send first.', 'codemas-ewand-ordersync' ),
				'sent'     => __( 'Order sent to Ewand.', 'codemas-ewand-ordersync' ),
				/* translators: 1: sent 2: failed 3: not ready */
				'summary'  => __( 'Done: %1$d sent, %2$d failed, %3$d not ready.', 'codemas-ewand-ordersync' ),
				'failed'   => __( 'Request failed.', 'codemas-ewand-ordersync' ),
				'refresh'  => __( 'Refresh list', 'codemas-ewand-ordersync' ),
			),
		);
		?>
		<script>
		jQuery(function($){
			var cfg = <?php echo wp_json_encode( $config ); ?>, txt = cfg.txt, busy = false;
			function fmt(s){ var a = [].slice.call(arguments, 1), i = 0; return s.replace(/%(\d)\$[ds]|%d/g, function(m, n){ return a[n ? n - 1 : i++]; }); }
			function notice(type, msg, refresh){
				var p = $('<p>').text(msg);
				if (refresh) { p.append(' ', $('<a href="#">').text(txt.refresh).on('click', function(e){ e.preventDefault(); location.reload(); })); }
				$('#ce-orders-notice').html($('<div class="notice is-dismissible">').addClass('notice-' + type).append(p));
			}
			function sendOne(id){
				var row = $('tr[data-order-id="' + id + '"]');
				row.find('.ce-send').addClass('is-busy').text(txt.sending);
				return $.post(ajaxurl, { action: 'ce_ewand_send', nonce: cfg.nonce, order_id: id }).then(function(r){
					if (!r || !r.success) { return { ok: false, state: 'failed', message: r && r.data && r.data.message ? r.data.message : txt.failed }; }
					row.find('td.column-ewand').html(r.data.ewand);
					row.find('td.column-send').html(r.data.send);
					return r.data;
				}, function(){
					row.find('.ce-send').removeClass('is-busy');
					return $.Deferred().resolve({ ok: false, state: 'failed', message: txt.failed });
				});
			}
			function sendMany(ids, button){
				var i = 0, sent = 0, failed = 0, notReady = 0, label = button ? button.text() : '';
				busy = true;
				if (button) { button.prop('disabled', true); }
				(function next(){
					if (i >= ids.length) {
						busy = false;
						if (button) { button.prop('disabled', false).text(label); }
						notice(failed ? 'warning' : 'success', fmt(txt.summary, sent, failed, notReady), true);
						return;
					}
					if (button) { button.text(fmt(txt.progress, i + 1, ids.length)); }
					sendOne(ids[i++]).then(function(d){
						if (d.ok) { sent++; } else if (d.state === 'not_ready') { notReady++; } else { failed++; }
						next();
					});
				})();
			}
			$('.ce-orders').on('click', 'a.ce-send', function(e){
				e.preventDefault();
				if (busy || $(this).hasClass('is-busy')) { return; }
				sendOne($(this).closest('tr').data('order-id')).then(function(d){
					d.ok ? notice('success', txt.sent, true) : notice('error', d.message);
				});
			});
			$('.ce-orders').on('click', '#doaction, #doaction2', function(e){
				if ($(this).prev('select').val() !== 'ewand_send') { return; }
				e.preventDefault();
				var ids = $('.ce-orders input[name="orders[]"]:checked').map(function(){ return +this.value; }).get();
				if (!ids.length) { notice('warning', txt.select); return; }
				if (!busy && confirm(fmt(txt.confirm, ids.length))) { sendMany(ids, $(this)); }
			});
			$('.ce-orders').on('click', '.ce-send-filtered', function(){
				// Use the filter fields as they are now (even if Filter wasn't clicked), so the dates shown are the dates sent.
				var button = $(this), form = button.closest('form'), data = { action: 'ce_ewand_unsent_ids', nonce: cfg.nonce };
				if (busy) { return; }
				['order_status', 'date_from', 'date_to', 's'].forEach(function(k){ var v = form.find('[name="' + k + '"]').first().val(); if (v) { data[k] = v; } });
				$.get(ajaxurl, data).done(function(r){
					var ids = r && r.success ? r.data.ids : [];
					if (!r || !r.success) { notice('error', r && r.data && r.data.message ? r.data.message : txt.failed); return; }
					if (!ids.length) { notice('info', txt.none); return; }
					var question = data.date_from || data.date_to ? fmt(txt.confirmRange, ids.length, data.date_from || txt.start, data.date_to || txt.today) : fmt(txt.confirm, ids.length);
					if (confirm(question)) { sendMany(ids, button); }
				}).fail(function(){ notice('error', txt.failed); });
			});
		});
		</script>
		<?php
	}

	private static function notices() {
		if ( ! Codemas_Ewand_Settings::is_active() || 'inactive' === ( $_GET['ce_error'] ?? '' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'ERP sync is disabled or has no endpoint set – orders cannot be sent until it is enabled in ERP Sync settings.', 'codemas-ewand-ordersync' ) . '</p></div>';
		}

		$error = get_transient( 'ce_orders_error_' . get_current_user_id() );
		if ( isset( $_GET['ce_failed'] ) ) {
			delete_transient( 'ce_orders_error_' . get_current_user_id() );
		}
		if ( isset( $_GET['ce_skipped'] ) ) { // Bulk result.
			/* translators: 1: sent 2: failed 3: skipped */
			$message = sprintf( __( 'Done: %1$d sent, %2$d failed, %3$d skipped (already sent).', 'codemas-ewand-ordersync' ), absint( $_GET['ce_sent'] ?? 0 ), absint( $_GET['ce_failed'] ?? 0 ), absint( $_GET['ce_skipped'] ) );
			echo '<div class="notice ' . ( absint( $_GET['ce_failed'] ?? 0 ) ? 'notice-warning' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( isset( $_GET['ce_sent'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Order sent to Ewand.', 'codemas-ewand-ordersync' ) . '</p></div>';
		} elseif ( isset( $_GET['ce_failed'] ) ) {
			/* translators: %s: error */
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Not sent to Ewand – %s', 'codemas-ewand-ordersync' ), $error ?: __( 'unknown error.', 'codemas-ewand-ordersync' ) ) ) . '</p></div>';
		}
	}
}

class Codemas_Ewand_Orders_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/** @var string Current sync view */
	public $view = 'all';

	public function __construct() {
		parent::__construct( array(
			'singular' => 'ewand-order',
			'plural'   => 'ewand-orders',
			'ajax'     => false,
		) );
		$view       = sanitize_key( $_GET['sync'] ?? 'all' );
		$this->view = in_array( $view, Codemas_Ewand_Orders_Page::VIEWS, true ) ? $view : 'all';
	}

	public function get_columns() {
		return array(
			'cb'     => '<input type="checkbox">',
			'order'  => __( 'Order', 'codemas-ewand-ordersync' ),
			'date'   => __( 'Date', 'codemas-ewand-ordersync' ),
			'status' => __( 'Status', 'codemas-ewand-ordersync' ),
			'total'  => __( 'Total', 'codemas-ewand-ordersync' ),
			'ewand'  => __( 'Ewand sync', 'codemas-ewand-ordersync' ),
			'send'   => '',
		);
	}

	protected function get_bulk_actions() {
		return array( 'ewand_send' => __( 'Send to Ewand', 'codemas-ewand-ordersync' ) );
	}

	/**
	 * wc_get_orders() meta_query for a sync view.
	 */
	public static function sync_meta_query( $view ) {
		$synced = array( 'key' => Codemas_Ewand_Sender::META_SYNCED_AT, 'compare' => 'NOT EXISTS' );
		$tried  = array( 'key' => Codemas_Ewand_Sender::META_TRIED_AT, 'compare' => 'NOT EXISTS' );

		switch ( $view ) {
			case 'sent':
				return array( array( 'key' => Codemas_Ewand_Sender::META_SYNCED_AT, 'compare' => 'EXISTS' ) );
			case 'failed':
				return array( 'relation' => 'AND', $synced, array( 'key' => Codemas_Ewand_Sender::META_TRIED_AT, 'compare' => 'EXISTS' ) );
			case 'not_sent':
				return array( 'relation' => 'AND', $synced, $tried );
			case 'unsent': // Not sent + failed.
				return array( $synced );
		}
		return array();
	}

	/**
	 * Query args from the status, date and search filters (without the sync view).
	 */
	public static function filter_args() {
		$args = array(
			'type'    => 'shop_order',
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		$status = sanitize_key( $_GET['order_status'] ?? '' );
		if ( $status && array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
			$args['status'] = array( 'wc-' . $status );
		}

		$from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		$to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '';
		if ( $from && $to ) {
			$args['date_created'] = $from . '...' . $to;
		} elseif ( $from ) {
			$args['date_created'] = '>=' . $from;
		} elseif ( $to ) {
			$args['date_created'] = '<=' . $to;
		}

		$search = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
		if ( '' !== $search ) {
			$ids               = wc_order_search( $search );
			$args['post__in']  = $ids ? array_map( 'absint', $ids ) : array( 0 );
		}

		return $args;
	}

	private static function count( $view ) {
		$result = wc_get_orders( self::filter_args() + array(
			'meta_query' => self::sync_meta_query( $view ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'limit'      => 1,
			'paginate'   => true,
			'return'     => 'ids',
		) );
		return (int) $result->total;
	}

	protected function get_views() {
		$labels = array(
			'all'      => __( 'All', 'codemas-ewand-ordersync' ),
			'not_sent' => __( 'Not sent', 'codemas-ewand-ordersync' ),
			'failed'   => __( 'Failed', 'codemas-ewand-ordersync' ),
			'sent'     => __( 'Sent', 'codemas-ewand-ordersync' ),
		);
		$keep  = array_filter( array(
			'order_status' => sanitize_key( $_GET['order_status'] ?? '' ),
			'date_from'    => sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ),
			'date_to'      => sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ),
			's'            => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
		) );
		$views = array();
		foreach ( $labels as $view => $label ) {
			$views[ $view ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( Codemas_Ewand_Orders_Page::url( $keep + array( 'sync' => $view ) ) ),
				$view === $this->view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				self::count( $view )
			);
		}
		return $views;
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$status = sanitize_key( $_GET['order_status'] ?? '' );
		?>
		<div class="alignleft actions ce-filters">
			<select name="order_status">
				<option value=""><?php esc_html_e( 'All statuses', 'codemas-ewand-ordersync' ); ?></option>
				<?php foreach ( wc_get_order_statuses() as $key => $label ) :
					$slug = str_replace( 'wc-', '', $key ); ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="ce-date-from"><?php esc_html_e( 'From', 'codemas-ewand-ordersync' ); ?></label>
			<input type="date" id="ce-date-from" name="date_from" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ) ); ?>" title="<?php esc_attr_e( 'From', 'codemas-ewand-ordersync' ); ?>">
			<label class="screen-reader-text" for="ce-date-to"><?php esc_html_e( 'To', 'codemas-ewand-ordersync' ); ?></label>
			<input type="date" id="ce-date-to" name="date_to" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ) ); ?>" title="<?php esc_attr_e( 'To', 'codemas-ewand-ordersync' ); ?>">
			<?php submit_button( __( 'Filter', 'codemas-ewand-ordersync' ), '', 'filter_action', false ); ?>
			<?php if ( $status || ! empty( $_GET['date_from'] ) || ! empty( $_GET['date_to'] ) || ! empty( $_GET['s'] ) ) : ?>
				<a class="button-link" href="<?php echo esc_url( Codemas_Ewand_Orders_Page::url( array( 'sync' => $this->view ) ) ); ?>"><?php esc_html_e( 'Clear', 'codemas-ewand-ordersync' ); ?></a>
			<?php endif; ?>
			<?php if ( Codemas_Ewand_Settings::is_active() ) :
				$unsent = self::count( 'unsent' ); ?>
				<button type="button" class="button button-primary ce-send-filtered" <?php disabled( 0, $unsent ); ?> title="<?php esc_attr_e( 'Sends every not-yet-sent order in the From–To dates (and status/search) entered, across all pages. Each order is sent only once.', 'codemas-ewand-ordersync' ); ?>">
					<?php
					/* translators: %d: number of orders */
					echo esc_html( sprintf( __( 'Send unsent in date range to Ewand (%d)', 'codemas-ewand-ordersync' ), $unsent ) );
					?>
				</button>
			<?php endif; ?>
		</div>
		<?php
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$result = wc_get_orders( self::filter_args() + array(
			'meta_query' => self::sync_meta_query( $this->view ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'limit'      => self::PER_PAGE,
			'paged'      => $this->get_pagenum(),
			'paginate'   => true,
		) );

		$this->items = $result->orders;
		$this->set_pagination_args( array(
			'total_items' => (int) $result->total,
			'per_page'    => self::PER_PAGE,
		) );
	}

	public function no_items() {
		esc_html_e( 'No orders found.', 'codemas-ewand-ordersync' );
	}

	/**
	 * Sync state of an order: sent, failed, not_ready or not_sent, plus a detail line.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function state( WC_Order $order, $fresh = false ) {
		static $cache = array();
		if ( $fresh ) {
			unset( $cache[ $order->get_id() ] );
		}
		return $cache[ $order->get_id() ] ??= self::compute_state( $order );
	}

	private static function compute_state( WC_Order $order ) {
		$synced = $order->get_meta( Codemas_Ewand_Sender::META_SYNCED_AT );
		if ( $synced ) {
			/* translators: %s: date */
			return array( 'sent', sprintf( __( 'Sent %s', 'codemas-ewand-ordersync' ), $synced ) );
		}
		$problems = Codemas_Ewand_Payload::problems( $order );
		if ( $problems ) {
			return array( 'not_ready', implode( ' ', $problems ) );
		}
		$tried = $order->get_meta( Codemas_Ewand_Sender::META_TRIED_AT );
		if ( $tried ) {
			$code     = $order->get_meta( Codemas_Ewand_Sender::META_CODE );
			$response = (string) $order->get_meta( Codemas_Ewand_Sender::META_RESPONSE );
			$response = Codemas_Ewand_Sender::error_detail( $response ) ?: $response;
			$detail   = trim( ( $code ? 'HTTP ' . $code . ' – ' : '' ) . wp_html_excerpt( wp_strip_all_tags( $response ), 200, '…' ), ' –' );
			/* translators: 1: date 2: error */
			$detail = sprintf( __( 'Last try %1$s: %2$s', 'codemas-ewand-ordersync' ), $tried, $detail );
			return array( 'failed', $detail );
		}
		return array( 'not_sent', '' );
	}

	public function single_row( $order ) {
		echo '<tr data-order-id="' . esc_attr( $order->get_id() ) . '">';
		$this->single_row_columns( $order );
		echo '</tr>';
	}

	protected function column_cb( $order ) {
		return '<input type="checkbox" name="orders[]" value="' . esc_attr( $order->get_id() ) . '">';
	}

	protected function column_order( $order ) {
		$name = trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_company() ?: $order->get_billing_email();
		return sprintf(
			'<a href="%s"><strong>#%s %s</strong></a>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() ),
			esc_html( $name )
		);
	}

	protected function column_date( $order ) {
		$date = $order->get_date_created();
		return $date ? esc_html( $date->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '—';
	}

	protected function column_status( $order ) {
		return sprintf(
			'<mark class="order-status status-%s"><span>%s</span></mark>',
			esc_attr( $order->get_status() ),
			esc_html( wc_get_order_status_name( $order->get_status() ) )
		);
	}

	protected function column_total( $order ) {
		return wp_kses_post( $order->get_formatted_order_total() );
	}

	protected function column_ewand( $order ) {
		return self::ewand_cell( $order );
	}

	protected function column_send( $order ) {
		return self::send_cell( $order );
	}

	public static function ewand_cell( WC_Order $order ) {
		list( $state, $detail ) = self::state( $order );
		$labels = array(
			'sent'      => __( 'Sent', 'codemas-ewand-ordersync' ),
			'failed'    => __( 'Failed', 'codemas-ewand-ordersync' ),
			'not_ready' => __( 'Not ready', 'codemas-ewand-ordersync' ),
			'not_sent'  => __( 'Not sent', 'codemas-ewand-ordersync' ),
		);
		$html = '<span class="ce-badge is-' . esc_attr( str_replace( '_', '-', $state ) ) . '">' . esc_html( $labels[ $state ] ) . '</span>';
		if ( '' !== $detail ) {
			$html .= '<span class="ce-detail">' . esc_html( $detail ) . '</span>';
		}
		return $html;
	}

	/**
	 * "Send now" / "Retry" button, or a green "✓ Sent". A sent order can't be sent again.
	 * The links work without JavaScript; the page script sends them in place.
	 */
	public static function send_cell( WC_Order $order ) {
		list( $state ) = self::state( $order );
		if ( 'sent' === $state ) {
			$html = '<span class="button ce-send is-sent">' . esc_html__( '✓ Sent', 'codemas-ewand-ordersync' ) . '</span>';
		} else {
			$html = '';
		}
		if ( ! Codemas_Ewand_Settings::is_active() || 'not_ready' === $state ) {
			return $html;
		}

		$url = wp_nonce_url( Codemas_Ewand_Orders_Page::url( array( 'ce_send' => $order->get_id() ) ), 'ce_send_' . $order->get_id() );
		if ( 'sent' === $state ) {
			return $html;
		}
		return sprintf(
			'<a class="button button-primary ce-send" href="%s">%s</a>',
			esc_url( $url ),
			'failed' === $state ? esc_html__( 'Retry', 'codemas-ewand-ordersync' ) : esc_html__( 'Send now', 'codemas-ewand-ordersync' )
		);
	}
}
