<?php
/**
 * Shipping Methods Display — Ophalen/Bezorgen keuzekaarten
 *
 * Vervangt WooCommerce's eigen templates/cart/cart-shipping.php via het
 * 'wc_get_template'-filter (zie includes/shipping-choice.php). Ontvangt
 * dezelfde $args als het origineel ($package, $available_methods,
 * $chosen_method, $index, etc. — extract() gebeurt al in wc_get_template()).
 *
 * Toont de kaartenstijl ("Laten bezorgen" / "Zelf afhalen") altijd zodra er
 * minstens één methode beschikbaar is — ook als er maar één groep (alleen
 * ophalen, of alleen bezorgen) of maar één methode in totaal is. Zo krijgt
 * elke verzend-/ophaalknop dezelfde opgemaakte kaart-stijl, in plaats van de
 * kale WooCommerce-lijst zodra er niets te kiezen valt tussen de twee
 * groepen. Valt alleen terug op de kale WooCommerce-markup wanneer er
 * helemaal geen methodes beschikbaar zijn (dan is er toch niets te tonen).
 */

defined( 'ABSPATH' ) || exit;

$formatted_destination    = isset( $formatted_destination )
    ? $formatted_destination
    : ( ! empty( $package['destination'] ) ? WC()->countries->get_formatted_address( $package['destination'], ', ' ) : '' );
$has_calculated_shipping  = ! empty( $has_calculated_shipping );
$show_shipping_calculator = ! empty( $show_shipping_calculator );
$calculator_text          = '';

$groups = [ 'delivery' => [], 'pickup' => [] ];
if ( ! empty( $available_methods ) && is_array( $available_methods ) ) {
    foreach ( $available_methods as $method ) {
        $groups[ strpos( (string) $method->id, 'local_pickup:' ) === 0 ? 'pickup' : 'delivery' ][] = $method;
    }
}
$show_cards = ! empty( $available_methods ) && is_array( $available_methods );

// Pakket heeft alleen afhaalmethodes (geen enkele bezorgmethode beschikbaar)
// — meestal doordat een product een "alleen-afhalen"-verzendklasse heeft.
// Toont dan een info-icoontje met uitleg naast de artikelenlijst, zodat de
// klant meteen begrijpt waarom hier geen bezorgoptie staat, i.p.v. zich af te
// vragen of dat een fout is.
$mkcp_sc_pickup_only = $show_cards && empty( $groups['delivery'] ) && ! empty( $groups['pickup'] );

// Bij de allereerste paginalaad (vóór WooCommerce's eerste update_checkout-
// cyclus) staat er nog niets in WC()->session->chosen_shipping_methods —
// $chosen_method komt dan leeg binnen. Hetzelfde gat kan ontstaan als de
// eerder gekozen methode niet meer in de huidige $available_methods zit (bv.
// na een adreswijziging die de vorige rate uit de zone haalt) — dan bestaat
// $chosen_method wél, maar matcht 'ie geen enkele kaart, met exact hetzelfde
// resultaat: niets aangevinkt of gemarkeerd als actief. Val in beide gevallen
// terug op de eerst zichtbare optie (bezorgen vóór ophalen, dezelfde volgorde
// als hieronder gerenderd) zodat er altijd een kaart met de is-active-styling
// en een aangevinkte radio te zien is — de browser stuurt die gewoon mee als
// de klant 'm niet meer aanraakt.
$mkcp_sc_available_ids = array_map( fn( $m ) => $m->id, $available_methods ?: [] );
if ( $show_cards && ! in_array( $chosen_method, $mkcp_sc_available_ids, true ) ) {
    foreach ( [ 'delivery', 'pickup' ] as $mkcp_sc_type ) {
        if ( ! empty( $groups[ $mkcp_sc_type ] ) ) {
            $chosen_method = $groups[ $mkcp_sc_type ][0]->id;
            break;
        }
    }
}
?>
<div class="woocommerce-shipping-totals shipping" data-title="<?php echo esc_attr( $package_name ); ?>">
		<?php // Zichtbare artikelenlijst bóven de keuzekaarten bij meerdere
		      // pakketten (bv. een deel bezorgen, een deel alleen af te halen)
		      // — anders is met meerdere kaartgroepen op de pagina niet te zien
		      // welke artikelen bij welke kaartgroep horen. Toont de echte
		      // productnamen i.p.v. WooCommerce's kale "1 item". Bij één pakket
		      // (verreweg het normale geval) blijft dit weg, zoals voorheen. ?>
		<?php if ( $show_package_details && $package_details !== '' ) : ?>
		<p class="mkcp-sc-package-name">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 11 3.83A2 2 0 0 0 9.59 3H4a1 1 0 0 0-1 1v5.59a2 2 0 0 0 .59 1.41l9.58 9.59a2 2 0 0 0 2.83 0l4.59-4.59a2 2 0 0 0 0-2.83Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
			<?php // Één flex-item voor de hele tekst (short + full samen) — zo
			      // deelt alleen déze wrapper de flex-rij met het icoontje, en
			      // blijft het icoontje altijd links staan, ongeacht of de
			      // ingeklapte of uitgeklapte tekst zichtbaar is. Zonder deze
			      // wrapper waren short/full zélf losse flex-items, en duwde de
			      // uitgeklapte (flex-basis:100%) versie het icoontje naar een
			      // eigen regel erboven zodra short verborgen werd. ?>
			<span class="mkcp-sc-package-text">
				<span class="mkcp-sc-package-details-short">
					<?php echo esc_html( $package_details ); ?>
					<?php if ( ! empty( $package_details_has_more ) ) : ?>
					<button type="button" class="mkcp-sc-package-more js-mkcp-sc-package-more">
						<?php echo esc_html( sprintf(
							/* translators: %d: aantal overige producten */
							_n( 'en %d meer', 'en %d meer', $package_details_remaining, 'mk-cart-popup' ),
							$package_details_remaining
						) ); ?>
					</button>
					<?php endif; ?>
				</span>
				<?php if ( ! empty( $package_details_has_more ) ) : ?>
				<span class="mkcp-sc-package-details-full" hidden>
					<?php echo esc_html( $package_details_full ); ?>
					<button type="button" class="mkcp-sc-package-less js-mkcp-sc-package-less"><?php esc_html_e( 'minder tonen', 'mk-cart-popup' ); ?></button>
				</span>
				<?php endif; ?>
			</span>
			<?php if ( $mkcp_sc_pickup_only ) : ?>
			<span class="mkcp-sc-info" tabindex="0">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
				<span class="mkcp-sc-tooltip"><?php esc_html_e( 'Dit product kan alleen worden opgehaald, niet verzonden.', 'mk-cart-popup' ); ?></span>
			</span>
			<?php endif; ?>
		</p>
		<?php endif; ?>
		<?php if ( ! empty( $available_methods ) && is_array( $available_methods ) && $show_cards ) :

			$titles = [ 'delivery' => __( 'Laten bezorgen', 'mk-cart-popup' ), 'pickup' => __( 'Zelf afhalen', 'mk-cart-popup' ) ];
			$icons  = [
				'delivery' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="6" width="14" height="11"/><path d="M15 9h4l3 3v5h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17.5" cy="19" r="2"/></svg>',
				'pickup'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1-5h16l1 5"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M9 21v-8h6v8"/></svg>',
			];
			$check_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
			?>
			<div class="mkcp-ship-choice">
				<?php foreach ( [ 'delivery', 'pickup' ] as $type ) :
					$methods = $groups[ $type ];
					if ( empty( $methods ) ) continue;

					$is_active = false;
					foreach ( $methods as $m ) { if ( $m->id === $chosen_method ) { $is_active = true; break; } }
					$single = 1 === count( $methods );
					?>
					<div class="mkcp-sc-card-wrap" data-role="<?php echo esc_attr( $type ); ?>">
						<label class="mkcp-sc-card<?php echo $is_active ? ' is-active' : ''; ?>">
							<?php if ( $single ) :
								$m = $methods[0];
								printf(
									'<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="shipping_method mkcp-sc-radio" %4$s />',
									$index,
									esc_attr( sanitize_title( $m->id ) ),
									esc_attr( $m->id ),
									checked( $m->id, $chosen_method, false )
								);
							endif; ?>
							<span class="mkcp-sc-card-icon"><?php echo $icons[ $type ]; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span class="mkcp-sc-card-body">
								<span class="mkcp-sc-card-title"><?php echo esc_html( $titles[ $type ] ); ?></span>
								<span class="mkcp-sc-card-sub">
									<?php
									if ( $single ) {
										echo wc_cart_totals_shipping_method_label( $methods[0] ); // phpcs:ignore WordPress.Security.EscapeOutput
									} else {
										// Goedkoopste optie alvast tonen i.p.v. alleen een aantal
										// — geeft nuttige info zonder open te hoeven klappen.
										$mkcp_sc_cheapest = null;
										foreach ( $methods as $mkcp_sc_m ) {
											$mkcp_sc_cost = (float) $mkcp_sc_m->get_cost();
											if ( null === $mkcp_sc_cheapest || $mkcp_sc_cost < $mkcp_sc_cheapest ) {
												$mkcp_sc_cheapest = $mkcp_sc_cost;
											}
										}
										if ( $mkcp_sc_cheapest > 0 ) {
											printf(
												/* translators: 1: goedkoopste prijs, 2: aantal opties */
												esc_html__( 'vanaf %1$s · %2$d opties', 'mk-cart-popup' ),
												wp_strip_all_tags( wc_price( $mkcp_sc_cheapest ) ),
												count( $methods )
											);
										} else {
											/* translators: %d: aantal opties */
											printf( esc_html__( 'Gratis mogelijk · %d opties', 'mk-cart-popup' ), count( $methods ) );
										}
									}
									?>
								</span>
							</span>
							<span class="mkcp-sc-card-check"><?php echo $check_icon; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</label>

						<?php if ( ! $single ) : ?>
						<div class="mkcp-sc-card-options<?php echo $is_active ? ' is-open' : ''; ?>">
							<?php foreach ( $methods as $method ) : ?>
								<div class="mkcp-sc-option">
									<?php
									printf(
										'<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="shipping_method" %4$s />',
										$index,
										esc_attr( sanitize_title( $method->id ) ),
										esc_attr( $method->id ),
										checked( $method->id, $chosen_method, false )
									);
									printf(
										'<label for="shipping_method_%1$s_%2$s">%3$s</label>',
										$index,
										esc_attr( sanitize_title( $method->id ) ),
										wc_cart_totals_shipping_method_label( $method ) // phpcs:ignore WordPress.Security.EscapeOutput
									);
									do_action( 'woocommerce_after_shipping_rate', $method, $index );
									?>
								</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php
			// Fase 2: bezorgdatum-/afhaal-tijdvakkiezer direct onder DIT
			// pakket se kaartgroep — gebaseerd op de daadwerkelijk gekozen
			// methode voor dit specifieke pakket (niet meer op één globale
			// "huidige methode over de hele winkelwagen heen"). Zo kan een
			// gemengd winkelwagentje (dit pakket bezorgen, een ander pakket
			// afhalen) beide kiezers tegelijk tonen.
			// Er is echter maar 1 bezorg-widget en 1 afhaal-widget per order
			// (afgesproken scope). $mkcp_render_role_widget komt uit
			// mkcp_render_all_shipping_choice_cards() (shipping-choice.php)
			// en staat alleen op true voor het LAATSTE pakket met deze rol —
			// zo verschijnt de kiezer nooit tussen twee kaartgroepen van
			// dezelfde rol in (bv. twee losse "Zelf afhalen"-pakketten), en
			// ook nooit dubbel (met identieke, dus ongeldige, DOM-ids).
			// Alleen op de checkout: de cart-pagina toont deze template ook
			// (winkelwagen-overzicht), maar de datum-/tijdvakkiezers horen
			// daar niet, net als voorheen.
			if ( is_checkout() && ( $mkcp_render_role_widget ?? true ) ) {
				$mkcp_sc_is_pickup_choice = strpos( (string) $chosen_method, 'local_pickup:' ) === 0;
				if ( $mkcp_sc_is_pickup_choice ) {
					$mkcp_sc_pu_loc = function_exists( 'mkcp_pickup_location_for_rate' ) ? mkcp_pickup_location_for_rate( $chosen_method ) : null;
					if ( $mkcp_sc_pu_loc && function_exists( 'mkcp_pickup_render_field' ) ) {
						mkcp_pickup_render_field( $mkcp_sc_pu_loc );
					}
				} elseif ( function_exists( 'mkcp_dd_render_delivery_field' ) ) {
					mkcp_dd_render_delivery_field( $chosen_method );
				}
			}
			?>

		<?php elseif ( ! empty( $available_methods ) && is_array( $available_methods ) ) : ?>
			<ul id="shipping_method" class="woocommerce-shipping-methods">
				<?php foreach ( $available_methods as $method ) : ?>
					<li>
						<?php
						if ( 1 < count( $available_methods ) ) {
							printf( '<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="shipping_method" %4$s />', $index, esc_attr( sanitize_title( $method->id ) ), esc_attr( $method->id ), checked( $method->id, $chosen_method, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						} else {
							printf( '<input type="hidden" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="shipping_method" />', $index, esc_attr( sanitize_title( $method->id ) ), esc_attr( $method->id ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						printf( '<label for="shipping_method_%1$s_%2$s">%3$s</label>', $index, esc_attr( sanitize_title( $method->id ) ), wc_cart_totals_shipping_method_label( $method ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						do_action( 'woocommerce_after_shipping_rate', $method, $index );
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( is_cart() ) : ?>
				<p class="woocommerce-shipping-destination">
					<?php
					if ( $formatted_destination ) {
						/* translators: %s: shipping destination */
						printf( esc_html__( 'Shipping to %s.', 'woocommerce' ) . ' ', '<strong>' . esc_html( $formatted_destination ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput
						$calculator_text = esc_html__( 'Change address', 'woocommerce' );
					} else {
						echo wp_kses_post( apply_filters( 'woocommerce_shipping_estimate_html', __( 'Shipping options will be updated during checkout.', 'woocommerce' ) ) );
					}
					?>
				</p>
			<?php endif; ?>
			<?php
		elseif ( ! $has_calculated_shipping || ! $formatted_destination ) :
			if ( is_cart() && 'no' === get_option( 'woocommerce_enable_shipping_calc' ) ) {
				echo wp_kses_post( apply_filters( 'woocommerce_shipping_not_enabled_on_cart_html', __( 'Shipping costs are calculated during checkout.', 'woocommerce' ) ) );
			} else {
				echo wp_kses_post( apply_filters( 'woocommerce_shipping_may_be_available_html', __( 'Enter your address to view shipping options.', 'woocommerce' ) ) );
			}
		elseif ( ! is_cart() ) :
			echo wp_kses_post( apply_filters( 'woocommerce_no_shipping_available_html', __( 'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.', 'woocommerce' ) ) );
		else :
			echo wp_kses_post(
				apply_filters(
					'woocommerce_cart_no_shipping_available_html',
					/* translators: %s: shipping destination */
					sprintf( esc_html__( 'No shipping options were found for %s.', 'woocommerce' ) . ' ', '<strong>' . esc_html( $formatted_destination ) . '</strong>' ),
					$formatted_destination
				)
			);
			$calculator_text = esc_html__( 'Enter a different address', 'woocommerce' );
		endif;
		?>

		<?php if ( $show_shipping_calculator ) : ?>
			<?php woocommerce_shipping_calculator( $calculator_text ); ?>
		<?php endif; ?>
</div>
