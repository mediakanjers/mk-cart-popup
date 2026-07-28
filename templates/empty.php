<?php
/**
 * Intentioneel leeg — zie includes/shipping-choice.php.
 *
 * WooCommerce roept 'cart/cart-shipping.php' zelf ALTIJD nog aan vanuit
 * checkout/review-order.php (wc_cart_totals_shipping_html()), ook al rendert
 * mkcp_render_shipping_choice_cards() de kaarten al apart via een eigen hook.
 * Zonder deze onderdrukking bestaan er twee complete radio-groepen met
 * dezelfde name="shipping_method[...]" tegelijk in hetzelfde formulier — de
 * browser houdt dan alleen de laatste in de DOM aangevinkt (WooCommerce's
 * eigen, ongestylede exemplaar), waardoor onze kaarten nooit als "actief"
 * herkend worden. Dit bestand vervangt die tweede render door niets.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
