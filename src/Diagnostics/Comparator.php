<?php
/**
 * Old-vs-new checkout differ.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Diagnostics;

use Beeoch\OPC\Support\Flag;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Captures the computed outcome of a checkout request so old and new can be compared.
 *
 * The comparison is deliberately on **computed outcomes, not markup** — markup differs by
 * design, so diffing HTML would produce noise indistinguishable from signal. What must not
 * differ is what the customer is charged, which promotions applied, and which gateways are
 * offered. See docs/CHECKOUT-COMPATIBILITY.md §10.
 *
 * Usage: load checkout with ?beeoch_capture=old, then with the new checkout flag plus
 * ?beeoch_capture=new, then compare the two snapshots:
 *
 *     wp eval '(new Beeoch\OPC\Diagnostics\Comparator())->render_diff();'
 *
 * Development only.
 */
class Comparator {

	/**
	 * Query argument that triggers a capture, and names the slot.
	 */
	private const QUERY_ARG = 'beeoch_capture';

	/**
	 * Hook in.
	 */
	public function register(): void {
		// Late, so totals are final: everything on this site settles by priority 10000.
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'maybe_capture' ), PHP_INT_MAX );
	}

	/**
	 * Capture when asked to.
	 */
	public function maybe_capture(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only dev instrumentation.
		$slot = isset( $_REQUEST[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( (string) $_REQUEST[ self::QUERY_ARG ] ) ) : '';

		if ( '' === $slot ) {
			return;
		}

		$this->write( $slot, $this->snapshot() );
	}

	/**
	 * Everything that must be identical between the two checkouts.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;

		if ( null === $cart ) {
			return array( 'error' => 'no cart' );
		}

		return array(
			'flag_active' => Flag::is_active(),
			'totals'      => $this->totals( $cart ),
			'items'       => $this->items( $cart ),
			'coupons'     => $this->coupons( $cart ),
			'fees'        => $this->fees( $cart ),
			'shipping'    => $this->shipping( $cart ),
			'gateways'    => $this->gateways(),
			'fragments'   => array_keys( FragmentAssert::expected() ),
		);
	}

	/**
	 * Money. Strings, not floats — these are compared for exact equality.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string, string>
	 */
	private function totals( $cart ): array {
		return array(
			'subtotal'       => (string) $cart->get_subtotal(),
			'subtotal_tax'   => (string) $cart->get_subtotal_tax(),
			'discount_total' => (string) $cart->get_discount_total(),
			'discount_tax'   => (string) $cart->get_discount_tax(),
			'shipping_total' => (string) $cart->get_shipping_total(),
			'shipping_tax'   => (string) $cart->get_shipping_tax(),
			'fee_total'      => (string) $cart->get_fee_total(),
			'total_tax'      => (string) $cart->get_total_tax(),
			'total'          => (string) $cart->get_total( 'edit' ),
		);
	}

	/**
	 * Line items, keyed by cart item key so ordering differences do not register as changes.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string, array<string, mixed>>
	 */
	private function items( $cart ): array {
		$items = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;

			$items[ $key ] = array(
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
				'variation'  => (int) ( $item['variation_id'] ?? 0 ),
				'quantity'   => (int) ( $item['quantity'] ?? 0 ),
				'sku'        => $product instanceof WC_Product ? $product->get_sku() : '',
				'line_total' => (string) ( $item['line_total'] ?? '' ),
				'line_tax'   => (string) ( $item['line_tax'] ?? '' ),
			);
		}

		ksort( $items );

		return $items;
	}

	/**
	 * Applied coupons and their discount.
	 *
	 * These matter more than they look: three callbacks auto-apply coupons during
	 * after_calculate_totals, so a coupon set that differs between old and new is a real
	 * behavioural change even when the total happens to match.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string, string>
	 */
	private function coupons( $cart ): array {
		$coupons = array();

		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupons[ (string) $code ] = (string) $cart->get_coupon_discount_amount( $code );
		}

		ksort( $coupons );

		return $coupons;
	}

	/**
	 * Fees — where points discounts and shipping overrides land on this site.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string, string>
	 */
	private function fees( $cart ): array {
		$fees = array();

		foreach ( $cart->get_fees() as $fee ) {
			$fees[ (string) $fee->name ] = (string) $fee->amount;
		}

		ksort( $fees );

		return $fees;
	}

	/**
	 * Chosen shipping methods and offered rates.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string, mixed>
	 */
	private function shipping( $cart ): array {
		$chosen = (array) WC()->session?->get( 'chosen_shipping_methods', array() );
		$rates  = array();

		foreach ( WC()->shipping()->get_packages() as $index => $package ) {
			foreach ( (array) ( $package['rates'] ?? array() ) as $rate_id => $rate ) {
				$rates[ $index . ':' . $rate_id ] = (string) $rate->get_cost();
			}
		}

		ksort( $rates );

		return array(
			'chosen'       => $chosen,
			'rates'        => $rates,
			'needs_shipping' => $cart->needs_shipping(),
		);
	}

	/**
	 * Payment gateways offered.
	 *
	 * Subscriptions filters this six ways oncart contents, so it is genuinely
	 * cart-dependent and worth comparing.
	 *
	 * @return array<int, string>
	 */
	private function gateways(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$ids = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
		sort( $ids );

		return array_map( 'strval', $ids );
	}

	/**
	 * Persist a snapshot.
	 *
	 * @param string               $slot     Slot name, e.g. 'old' or 'new'.
	 * @param array<string, mixed> $snapshot Snapshot.
	 */
	private function write( string $slot, array $snapshot ): void {
		$dir = $this->directory();

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			trailingslashit( $dir ) . 'snapshot-' . sanitize_file_name( $slot ) . '.json',
			(string) wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Read a snapshot back.
	 *
	 * @param string $slot Slot name.
	 * @return array<string, mixed>|null
	 */
	public function read( string $slot ): ?array {
		$path = trailingslashit( $this->directory() ) . 'snapshot-' . sanitize_file_name( $slot ) . '.json';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Compare two snapshots and print the differences.
	 *
	 * @param string $left  Baseline slot.
	 * @param string $right Comparison slot.
	 */
	public function render_diff( string $left = 'old', string $right = 'new' ): void {
		$a = $this->read( $left );
		$b = $this->read( $right );

		if ( null === $a || null === $b ) {
			echo "Missing snapshot — capture both slots first.\n";

			return;
		}

		$differences = $this->diff( $a, $b );

		if ( array() === $differences ) {
			echo "IDENTICAL — no computed difference between '{$left}' and '{$right}'.\n"; // phpcs:ignore

			return;
		}

		printf( "%d difference(s):\n", count( $differences ) );

		foreach ( $differences as $path => $pair ) {
			printf( "  %-46s %s  ->  %s\n", $path, $pair[0], $pair[1] );
		}
	}

	/**
	 * Recursive scalar diff.
	 *
	 * @param array<mixed> $a      Baseline.
	 * @param array<mixed> $b      Comparison.
	 * @param string       $prefix Key path.
	 * @return array<string, array{0:string,1:string}>
	 */
	private function diff( array $a, array $b, string $prefix = '' ): array {
		$differences = array();

		foreach ( array_keys( $a + $b ) as $key ) {
			$path  = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$left  = $a[ $key ] ?? null;
			$right = $b[ $key ] ?? null;

			if ( is_array( $left ) && is_array( $right ) ) {
				$differences += $this->diff( $left, $right, $path );

				continue;
			}

			if ( $left !== $right ) {
				$differences[ $path ] = array(
					null === $left ? '(absent)' : var_export( $left, true ),   // phpcs:ignore
					null === $right ? '(absent)' : var_export( $right, true ), // phpcs:ignore
				);
			}
		}

		return $differences;
	}

	/**
	 * Where snapshots live — outside the web root's served content by intent.
	 */
	private function directory(): string {
		return WP_CONTENT_DIR . '/uploads/beeoch-opc-diagnostics';
	}
}
