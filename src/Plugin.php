<?php
/**
 * Wiring.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC;

use Beeoch\OPC\Cart\ItemPolicy;
use Beeoch\OPC\Diagnostics\Comparator;
use Beeoch\OPC\Diagnostics\FragmentAssert;
use Beeoch\OPC\Orchestration\Layout;
use Beeoch\OPC\Support\Flag;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root. Nothing else instantiates a subsystem directly.
 *
 * Boot order is intentional: diagnostics come up before orchestration, so that when
 * orchestration is added it is already under observation rather than being retrofitted
 * with checks after a bug appears.
 */
class Plugin {

	/**
	 * Register everything this plugin does.
	 */
	public function boot(): void {
		load_plugin_textdomain( 'beeoch-opc', false, dirname( plugin_basename( BEEOCH_OPC_FILE ) ) . '/languages' );

		/*
		 * Diagnostics are development instrumentation and never run on a production
		 * request, regardless of flag state.
		 */
		if ( $this->diagnostics_enabled() ) {
			( new FragmentAssert() )->register();
			( new Comparator() )->register();
		}

		/*
		 * Orchestration is gated on the flag. With the flag off nothing below registers,
		 * which is what makes "deactivate to roll back" true rather than aspirational.
		 */
		if ( ! Flag::is_active() ) {
			return;
		}

		( new Layout( new ItemPolicy() ) )->register();
	}

	/**
	 * Whether to run development instrumentation.
	 *
	 * Local environment or explicit debug only. The comparator dumps cart and order
	 * internals to disk, which must never happen on a live store.
	 */
	private function diagnostics_enabled(): bool {
		$enabled = ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		/**
		 * Filter whether One Page Checkout diagnostics run.
		 *
		 * @param bool $enabled Whether diagnostics are active.
		 */
		return (bool) apply_filters( 'beeoch_opc_diagnostics_enabled', $enabled );
	}
}
