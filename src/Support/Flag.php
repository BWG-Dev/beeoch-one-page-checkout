<?php
/**
 * The parallel-running switch.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for "is the new checkout active for this request?".
 *
 * Everything else asks this class. Scattering the condition would guarantee that some
 * code path eventually disagrees with the rest — which on a checkout page means a
 * half-old, half-new render.
 *
 * Three states, per docs/PLUGIN-ARCHITECTURE.md §6:
 *
 *   off     — the plugin adds nothing; checkout is byte-identical to before
 *   flagged — the new checkout renders for testers only
 *   on      — the new checkout renders for everyone
 */
class Flag {

	public const MODE_OFF     = 'off';
	public const MODE_FLAGGED = 'flagged';
	public const MODE_ON      = 'on';

	/**
	 * Site option holding the current mode.
	 */
	private const OPTION = 'beeoch_opc_mode';

	/**
	 * Query argument that opts a request into the new checkout while in flagged mode.
	 */
	private const QUERY_ARG = 'beeoch_new';

	/**
	 * Memoised per request — this is consulted from render paths that run many times.
	 *
	 * @var bool|null
	 */
	private static ?bool $active = null;

	/**
	 * Current mode.
	 */
	public static function mode(): string {
		$mode = (string) get_option( self::OPTION, self::MODE_OFF );

		return in_array( $mode, array( self::MODE_OFF, self::MODE_FLAGGED, self::MODE_ON ), true )
			? $mode
			: self::MODE_OFF;
	}

	/**
	 * Whether the new checkout should render for this request.
	 */
	public static function is_active(): bool {
		if ( null !== self::$active ) {
			return self::$active;
		}

		self::$active = self::evaluate();

		/**
		 * Filter whether the new one-page checkout is active for this request.
		 *
		 * @param bool $active Whether the new checkout renders.
		 */
		self::$active = (bool) apply_filters( 'beeoch_opc_is_active', self::$active );

		return self::$active;
	}

	/**
	 * Uncached evaluation.
	 */
	private static function evaluate(): bool {
		switch ( self::mode() ) {
			case self::MODE_ON:
				return true;

			case self::MODE_FLAGGED:
				return self::request_opted_in();

			case self::MODE_OFF:
			default:
				return false;
		}
	}

	/**
	 * Whether this specific request asked for the new checkout.
	 *
	 * Requires the capability as well as the query argument, so the in-progress checkout
	 * is never exposed to a customer who happens to land on a shared link.
	 *
	 * The AJAX branch matters: the refresh cycle posts to wc-ajax=update_order_review,
	 * a separate request that must reach the same verdict as the page render that
	 * produced the form. The client echoes the argument back for exactly this reason.
	 */
	private static function request_opted_in(): bool {
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch, capability-gated above.
		return ! empty( $_REQUEST[ self::QUERY_ARG ] );
	}

	/**
	 * Query argument name, for the client script and for building test URLs.
	 */
	public static function query_arg(): string {
		return self::QUERY_ARG;
	}

	/**
	 * Reset the memoised verdict. Test-support only.
	 */
	public static function flush(): void {
		self::$active = null;
	}
}
