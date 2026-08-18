<?php
/**
 * Plan options as a dropdown instead of a radio list.
 *
 * Replaces All Products for Subscriptions' `cart/cart-item-subscription-options.php` for the
 * duration of one render, via the `wc_get_template` filter in `SubscriptionOptions`. Their
 * template is untouched on disk and still used everywhere else.
 *
 * The contract that matters is the field, not the control: the name stays
 * `cart[<key>][convert_to_sub]` and the values stay the scheme keys they generated, so
 * `WCS_ATT_Cart::update_cart_item_data()` reads a dropdown exactly as it read the radios.
 * Nothing downstream can tell the difference.
 *
 * @package Beeoch\OPC
 *
 * @var array<int, array<string, mixed>> $options       Option definitions, from WCS ATT.
 * @var string                           $cart_item_key Cart item key.
 * @var string                           $classes       Extra classes, from WCS ATT.
 */

defined( 'ABSPATH' ) || exit;

$beeoch_id = 'beeoch-opc-plan-' . substr( md5( (string) $cart_item_key ), 0, 8 );

/**
 * The label shown beside the purchase-option control.
 *
 * It was previously `screen-reader-text` — announced but invisible — which left a sighted
 * customer facing an unlabelled dropdown whose first option happens to read "One-time purchase".
 * That is guessable, not obvious, and it is the control that decides whether they are agreeing
 * to a recurring charge. It is worth a word.
 *
 * One `<label for>` serves both audiences: making it visible does not change what a screen
 * reader announces, so nothing is duplicated and nothing is lost.
 *
 * @param string $label         Label text.
 * @param string $cart_item_key Cart item key.
 */
$beeoch_label = (string) apply_filters(
	'beeoch_opc_plan_label',
	__( 'Purchase option', 'beeoch-opc' ),
	(string) $cart_item_key
);

?>
<!--beeoch-opc-plan-->
<span class="beeoch-opc-plan__field <?php echo esc_attr( $classes ); ?>">
	<?php if ( '' !== trim( $beeoch_label ) ) : ?>
		<label class="beeoch-opc-plan__label" for="<?php echo esc_attr( $beeoch_id ); ?>">
			<?php echo esc_html( $beeoch_label ); ?>
		</label>
	<?php endif; ?>
	<select
		id="<?php echo esc_attr( $beeoch_id ); ?>"
		class="beeoch-opc-plan__select"
		name="cart[<?php echo esc_attr( $cart_item_key ); ?>][convert_to_sub]"
	>
		<?php
		foreach ( $options as $beeoch_option ) {
			/*
			 * Descriptions arrive as HTML — a price in a <span>, sometimes <del>/<ins> for a
			 * sale. A browser renders none of that inside <option>, so it is reduced to text
			 * rather than left to be silently dropped. `html_entity_decode` first, or a price
			 * like "&#36;12.00" would show its entity.
			 */
			$beeoch_label = wp_strip_all_tags( (string) ( $beeoch_option['description'] ?? '' ) );
			$beeoch_label = html_entity_decode( $beeoch_label, ENT_QUOTES, 'UTF-8' );

			/*
			 * Removing the tags leaves their whitespace behind, so a price and its period end
			 * up separated by a run of spaces and a stray newline — "$40.50  / month". A single
			 * space is restored. `\xC2\xA0` is the non-breaking space the price markup uses,
			 * which `\s` does not match in non-unicode mode.
			 */
			$beeoch_label = trim( preg_replace( '/[\s\xC2\xA0]+/u', ' ', $beeoch_label ) ?? $beeoch_label );

			/*
			 * In a radio list the one-time option is legible from its position and its
			 * neighbours. Collapsed into a dropdown it can read as a bare price with nothing
			 * to distinguish it from a plan, so it is named — unless it already says so.
			 */
			if (
				false !== strpos( (string) ( $beeoch_option['class'] ?? '' ), 'one-time-option' )
				&& false === stripos( $beeoch_label, 'one time' )
				&& false === stripos( $beeoch_label, 'one-time' )
			) {
				$beeoch_label = '' === $beeoch_label
					? __( 'One-time purchase', 'beeoch-opc' )
					: sprintf(
						/* translators: %s: formatted price. */
						__( 'One-time purchase — %s', 'beeoch-opc' ),
						$beeoch_label
					);
			}

			if ( '' === $beeoch_label ) {
				continue;
			}

			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) ( $beeoch_option['value'] ?? '' ) ),
				selected( ! empty( $beeoch_option['selected'] ), true, false ),
				esc_html( $beeoch_label )
			);
		}
		?>
	</select>
</span>
<!--/beeoch-opc-plan-->
