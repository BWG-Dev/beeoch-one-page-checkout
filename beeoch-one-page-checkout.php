<?php
/**
 * Plugin Name:       BEE-OCH One Page Checkout
 * Description:       Merges the Cart experience into Checkout. Orchestration only — all business rules stay with WooCommerce and the existing plugins.
 * Version:           1.39.14
 * Requires PHP:      8.1
 * Requires at least: 6.5
 * Author:            Web Bennet Group
 * Text Domain:       beeoch-opc
 *
 * @package Beeoch\OPC
 *
 * With the flag off this plugin registers nothing that alters output — deactivating it
 * returns the checkout to its previous state exactly. See docs/PLUGIN-ARCHITECTURE.md §6.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'BEEOCH_OPC_VERSION', '1.39.14' );
define( 'BEEOCH_OPC_FILE', __FILE__ );
define( 'BEEOCH_OPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BEEOCH_OPC_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 autoloader for Beeoch\OPC.
 *
 * Hand-rolled rather than Composer: this plugin ships to a client site and has no
 * third-party dependencies, so a vendor directory would be cost without benefit.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Beeoch\\OPC\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = BEEOCH_OPC_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Boot after all plugins are loaded, so dependency checks see the final plugin set.
 *
 * Priority 20 is deliberate: it runs after WooCommerce (10) has defined its constants
 * and after the BWG conditional loader has filtered the active plugin list, so
 * Environment inspects what is actually running on this request.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		require_once BEEOCH_OPC_DIR . 'src/Support/Environment.php';

		$environment = new Beeoch\OPC\Support\Environment();

		if ( ! $environment->is_satisfied() ) {
			$environment->handle_unsatisfied();

			return;
		}

		( new Beeoch\OPC\Plugin() )->boot();
	},
	20
);
