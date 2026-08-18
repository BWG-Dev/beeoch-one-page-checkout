<?php
/**
 * Hook surgery helpers.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Detach third-party callbacks so they can be re-rendered somewhere else.
 *
 * `remove_action()` needs the identical callable, which for an object method means holding
 * the very instance the plugin registered. Plugins rarely expose it. This walks
 * `$GLOBALS['wp_filter']` and matches on class name plus method name instead, which works
 * for static calls, instance methods and singletons alike.
 *
 * Nothing here reimplements plugin behaviour: the callable is captured and invoked
 * verbatim, only from a different place in the page. That is the "relocate an existing
 * callback rather than rewrite it" rule from PROJECT.md §2.
 */
class Hooks {

	/**
	 * Find a callback by class and method, remove it, and hand it back.
	 *
	 * @param string      $hook     Hook name.
	 * @param string      $class    Fully-qualified class name, or '' for plain functions.
	 * @param string      $method   Method or function name.
	 * @param int|null    $priority Restrict to one priority, or null for any.
	 * @return callable|null The detached callable, or null if it was not registered.
	 */
	public static function detach( string $hook, string $class, string $method, ?int $priority = null ): ?callable {
		$found = self::locate( $hook, $class, $method, $priority );

		if ( null === $found ) {
			return null;
		}

		remove_action( $hook, $found['callback'], $found['priority'] );

		return is_callable( $found['callback'] ) ? $found['callback'] : null;
	}

	/**
	 * Find a callback by class and method and hand it back, leaving it registered.
	 *
	 * The counterpart to `detach()`, for borrowing a plugin's renderer rather than relocating
	 * it. Detaching would be wrong when the callback still has a job to do where it is — the
	 * free-gift picker, for instance, is wanted at checkout AND left in place for the cart.
	 *
	 * @param string   $hook     Hook name.
	 * @param string   $class    Fully-qualified class name, or '' for plain functions.
	 * @param string   $method   Method or function name.
	 * @param int|null $priority Restrict to one priority, or null for any.
	 * @return callable|null The registered callable, or null if it was not found.
	 */
	public static function find( string $hook, string $class, string $method, ?int $priority = null ): ?callable {
		$found = self::locate( $hook, $class, $method, $priority );

		if ( null === $found || ! is_callable( $found['callback'] ) ) {
			return null;
		}

		return $found['callback'];
	}

	/**
	 * Locate a registered callback without touching it.
	 *
	 * @param string   $hook     Hook name.
	 * @param string   $class    Fully-qualified class name, or '' for plain functions.
	 * @param string   $method   Method or function name.
	 * @param int|null $priority Restrict to one priority, or null for any.
	 * @return array{callback:mixed, priority:int}|null
	 */
	private static function locate( string $hook, string $class, string $method, ?int $priority = null ): ?array {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return null;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $registered_priority => $group ) {
			if ( null !== $priority && (int) $registered_priority !== $priority ) {
				continue;
			}

			foreach ( $group as $entry ) {
				if ( self::matches( $entry['function'], $class, $method ) ) {
					return array(
						'callback' => $entry['function'],
						'priority' => (int) $registered_priority,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Whether a registered callback is the one we are looking for.
	 *
	 * @param mixed  $callback Registered callback.
	 * @param string $class    Class name, or '' for a plain function.
	 * @param string $method   Method or function name.
	 */
	private static function matches( $callback, string $class, string $method ): bool {
		// Plain function, e.g. woocommerce_checkout_coupon_form.
		if ( '' === $class ) {
			return is_string( $callback ) && $callback === $method;
		}

		// 'Class::method' string form.
		if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			return $callback === $class . '::' . $method;
		}

		if ( ! is_array( $callback ) || 2 !== count( $callback ) ) {
			return false;
		}

		$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

		return $target === $class && (string) $callback[1] === $method;
	}

	/**
	 * Run a detached callback and return what it printed.
	 *
	 * These callbacks echo rather than return, so the output is buffered. A callback that
	 * fatals must not take the whole checkout with it — hence the try/finally, which
	 * guarantees the buffer is closed even if something throws.
	 *
	 * @param callable $callback Detached callback.
	 * @return string Captured markup.
	 */
	public static function capture( callable $callback ): string {
		ob_start();

		try {
			$callback();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'BEEOCH-OPC [relocate] callback threw: ' . $e->getMessage() );
			}
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}
}
