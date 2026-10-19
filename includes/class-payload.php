<?php
/**
 * Builds the JSON body for the Ewand create-order API: header fields plus
 * items grouped by Ewand style, with quantities (and players) per size.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Codemas_Ewand_Payload {

	/* Hidden line item meta written by the club shop for bulk (player) orders */
	const BULK_META      = '_cmt_bulk_data';
	const BULK_TEXT_META = 'Bulk Order Details';

	/**
	 * Full request body.
	 */
	public static function build( WC_Order $order ) {
		$problems = array();
		return apply_filters( 'codemas_ewand_order_payload', self::collect( $order, $problems ), $order );
	}

	/**
	 * Reasons the ERP would reject this order (missing IDs). Empty when it can be sent.
	 *
	 * @return string[]
	 */
	public static function problems( WC_Order $order ) {
		$problems = array();
		self::collect( $order, $problems );
		return array_values( array_unique( $problems ) );
	}

	private static function collect( WC_Order $order, array &$problems ) {
		$s        = Codemas_Ewand_Settings::get();
		$created  = $order->get_date_created();
		$ordered  = $created ? $created->date_i18n( 'Y-m-d' ) : current_time( 'Y-m-d' );
		$delivery = null;
		if ( '' !== $s['lead_time_days'] && null !== $s['lead_time_days'] ) {
			$delivery = gmdate( 'Y-m-d', strtotime( $ordered . ' +' . (int) $s['lead_time_days'] . ' days' ) );
		}

		$billing_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$shipping_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$agent_id      = self::int_or_null( $s['agent_id'] );

		foreach ( array(
			'Factory ID'         => $s['factory_id'],
			'AI Agent user ID'   => $agent_id,
		) as $label => $value ) {
			if ( null === $value || '' === $value ) {
				$problems[] = sprintf( '%s is not set.', $label );
			}
		}

		return array(
			'factory_id'          => self::int_or_null( $s['factory_id'] ),
			'customer_email'      => $order->get_billing_email(),
			'delivery_date'       => $delivery,
			'production_type'     => $s['production_type'],
			'team_club_school'    => self::team_name( $order, $s['team_name'] ),
			'delivery_address'    => self::delivery_address( $order ),
			'attention_person'    => $shipping_name ?: $billing_name,
			'sales_agent_id'      => $agent_id,
			'customer_service_id' => $agent_id,
			'account_manager_id'  => $agent_id,
			'shipping_region'     => self::region( $order ),
			'shipping_type'       => strtolower( $s['ship_mode'] ),
			'no_of_boxes'         => max( 1, (int) $s['no_of_boxes'] ),
			'customer_order_ref'  => (string) $order->get_order_number(),
			'items'               => self::items( $order, $s['size_attribute'], $problems ),
		);
	}

	private static function int_or_null( $value ) {
		return '' === trim( (string) $value ) ? null : (int) $value;
	}

	/**
	 * Team Name entered at checkout (club shop "_billing_team_name"), then the
	 * team name on a bulk order, then the default from settings, then the billing company.
	 */
	private static function team_name( WC_Order $order, $default ) {
		$team = trim( (string) $order->get_meta( '_billing_team_name' ) );
		if ( '' === $team ) {
			foreach ( $order->get_items() as $item ) {
				foreach ( self::bulk_players( $item ) as $player ) {
					if ( '' !== $player['team_name'] ) {
						return $player['team_name'];
					}
				}
			}
		}
		return $team ?: ( $default ?: $order->get_billing_company() );
	}

	/**
	 * One item per Ewand style and unit price; quantities summed per size.
	 * Bulk order lines are split into their players, so each player's size,
	 * number and name reach the ERP.
	 */
	private static function items( WC_Order $order, $size_tax, array &$problems ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			foreach ( self::units( $order, $item, $size_tax ) as $unit ) {
				$style_id = Codemas_Ewand_Product_Fields::get_ewand_style_id( $item->get_product_id(), $unit['variation_id'] );
				if ( ! $style_id ) {
					$problems[] = sprintf( 'Ewand Style ID not set for "%s" (product %d).', $item->get_name(), $item->get_product_id() );
				}

				$price = round( (float) $unit['unit_price'], 2 );
				$key   = $style_id . '|' . $price;
				if ( ! isset( $items[ $key ] ) ) {
					$items[ $key ] = array(
						'style_id'   => $style_id,
						'unit_price' => $price,
						'sizes'      => array(),
					);
				}

				$size = $unit['size'];
				if ( ! isset( $items[ $key ]['sizes'][ $size ] ) ) {
					$items[ $key ]['sizes'][ $size ] = array( 'size' => $size, 'quantity' => 0, 'players' => array() );
				}
				$items[ $key ]['sizes'][ $size ]['quantity'] += $unit['quantity'];
				if ( $unit['player'] ) {
					$items[ $key ]['sizes'][ $size ]['players'][] = $unit['player'];
				}
			}
		}

		foreach ( $items as &$entry ) {
			uasort( $entry['sizes'], fn( $a, $b ) => self::size_rank( $a['size'] ) <=> self::size_rank( $b['size'] ) );
			$entry['sizes'] = array_values( $entry['sizes'] );
		}

		return array_values( $items );
	}

	/**
	 * What one order line stands for: a single size, or one entry per player on a bulk order.
	 *
	 * @return array<array{variation_id:int,size:string,quantity:int,unit_price:float,player:?array}>
	 */
	private static function units( WC_Order $order, WC_Order_Item_Product $item, $size_tax ) {
		$players = self::bulk_players( $item );

		if ( ! $players ) {
			return array( array(
				'variation_id' => $item->get_variation_id(),
				'size'         => self::size_name( (string) $item->get_meta( $size_tax, true ), $size_tax ),
				'quantity'     => (int) $item->get_quantity(),
				'unit_price'   => $order->get_item_subtotal( $item, wc_prices_include_tax(), false ),
				'player'       => null,
			) );
		}

		// Bulk lines are one item priced at the sum of all players. Older orders have
		// no per-player price, so spread the line subtotal over the players.
		$total_qty = array_sum( array_column( $players, 'quantity' ) );
		$fallback  = $total_qty ? $order->get_line_subtotal( $item, wc_prices_include_tax(), false ) / $total_qty : 0;

		$units = array();
		foreach ( $players as $player ) {
			$slug = $player['variation_id'] ? (string) get_post_meta( $player['variation_id'], 'attribute_' . $size_tax, true ) : '';
			$name = trim( $player['player_name'] . ' ' . $player['surname'] );
			$no   = '' !== $player['player_no'] ? $player['player_no'] : $player['input_no'];

			$units[] = array(
				'variation_id' => $player['variation_id'],
				'size'         => self::size_name( '' !== $slug ? $slug : $player['size'], $size_tax ),
				'quantity'     => $player['quantity'],
				'unit_price'   => null !== $player['unit_price'] ? $player['unit_price'] : $fallback,
				'player'       => ( '' !== $no || '' !== $name ) ? array( 'number' => $no, 'name' => $name ) : null,
			);
		}
		return $units;
	}

	/**
	 * Players on a club shop bulk order line. Reads the structured meta, or
	 * parses the "Bulk Order Details" table on orders placed before it existed.
	 */
	private static function bulk_players( WC_Order_Item_Product $item ) {
		$rows = $item->get_meta( self::BULK_META, true );
		if ( ! is_array( $rows ) || ! $rows ) {
			$rows = self::parse_bulk_text( (string) $item->get_meta( self::BULK_TEXT_META, true ) );
		}

		$players = array();
		foreach ( $rows as $row ) {
			$players[] = array(
				'player_name'  => trim( (string) ( $row['player_name'] ?? '' ) ),
				'surname'      => trim( (string) ( $row['surname'] ?? '' ) ),
				'player_no'    => trim( (string) ( $row['player_no'] ?? '' ) ),
				'input_no'     => trim( (string) ( $row['input_no'] ?? '' ) ),
				'size'         => trim( (string) ( $row['size'] ?? '' ) ),
				'team_name'    => trim( (string) ( $row['team_name'] ?? '' ) ),
				'variation_id' => (int) ( $row['variation_id'] ?? 0 ),
				'quantity'     => max( 1, (int) ( $row['quantity'] ?? 0 ) ), // Same rule as the club shop cart.
				'unit_price'   => isset( $row['unit_price'] ) && '' !== $row['unit_price'] ? (float) $row['unit_price'] : null,
			);
		}
		return $players;
	}

	/**
	 * "Name | Number | Size\n------\nSMITH | 7 | m" => rows keyed like the club shop bulk_data.
	 */
	private static function parse_bulk_text( $text ) {
		$map   = array(
			'Name'         => 'player_name',
			'Surname'      => 'surname',
			'Number'       => 'player_no',
			'Input Number' => 'input_no',
			'Size'         => 'size',
			'Team Name'    => 'team_name',
			'Quantity'     => 'quantity',
		);
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", trim( $text ) ) ), 'strlen' ) );
		if ( count( $lines ) < 3 ) {
			return array();
		}

		$keys = array_map( fn( $h ) => $map[ trim( $h ) ] ?? null, explode( '|', $lines[0] ) );
		$rows = array();
		foreach ( array_slice( $lines, 2 ) as $line ) {
			$cells = explode( '|', $line );
			if ( count( $cells ) !== count( $keys ) ) {
				continue; // A "|" inside a name; skip rather than shift columns.
			}
			$row = array();
			foreach ( $keys as $i => $key ) {
				if ( $key ) {
					$row[ $key ] = trim( $cells[ $i ] );
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Ewand size for a size attribute value: the display name ("m" => "M", "OSFA"
	 * when there is none), swapped for its Size mapping entry when it has one.
	 */
	private static function size_name( $slug, $size_tax ) {
		static $map = null;
		if ( null === $map ) {
			$map = Codemas_Ewand_Settings::size_map();
		}

		if ( '' === $slug ) {
			$name = 'OSFA';
		} else {
			$term = taxonomy_exists( $size_tax ) ? get_term_by( 'slug', $slug, $size_tax ) : false;
			$name = $term ? $term->name : strtoupper( $slug );
		}

		foreach ( array( $name, $slug ) as $key ) {
			$key = strtolower( trim( $key ) );
			if ( '' !== $key && isset( $map[ $key ] ) ) {
				return $map[ $key ];
			}
		}
		return $name;
	}

	/**
	 * Sort position of a size, same order as po-extractor SizeBreakdownSheet::ORDER.
	 * Womens sizes like "10/L" sort by their number.
	 */
	private static function size_rank( $size ) {
		static $rank = null;
		if ( null === $rank ) {
			$rank = array_flip( array(
				'2K', '4K', '6K', '8K', '10K', '12K', '14K', '16K',
				'4', '6', '8', '10', '12', '14', '16', '18', '20', '22', '24', '26',
				'XXS', '2XS', 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', '6XL', '7XL', '8XL',
				'S/M', 'M/L', 'L/XL', 'XL/2XL', 'OSFA',
			) );
			// Women's "2/XXS" sits before "4".
			$rank = array( '2' => -1 ) + $rank;
		}

		$size = strtoupper( trim( $size ) );
		if ( isset( $rank[ $size ] ) ) {
			return $rank[ $size ];
		}
		$first = strtok( $size, '/' );
		return $rank[ $first ] ?? PHP_INT_MAX;
	}

	private static function delivery_address( WC_Order $order ) {
		$type  = $order->has_shipping_address() ? 'shipping' : 'billing';
		$parts = array(
			$order->{"get_{$type}_company"}(),
			$order->{"get_{$type}_address_1"}(),
			$order->{"get_{$type}_address_2"}(),
			$order->{"get_{$type}_city"}(),
			trim( $order->{"get_{$type}_state"}() . ' ' . $order->{"get_{$type}_postcode"}() ),
			$order->{"get_{$type}_country"}(),
		);
		return implode( ', ', array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * Region picked at checkout ("Auckland"), from the same address as the
	 * delivery address. Falls back to the raw value for countries without a region list.
	 */
	private static function region( WC_Order $order ) {
		$type    = $order->has_shipping_address() ? 'shipping' : 'billing';
		$code    = $order->{"get_{$type}_state"}();
		$country = $order->{"get_{$type}_country"}();
		if ( '' === (string) $code ) {
			return '';
		}
		$states = WC()->countries->get_states( $country );
		$name   = is_array( $states ) && isset( $states[ $code ] ) ? $states[ $code ] : $code;
		return html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
	}
}
