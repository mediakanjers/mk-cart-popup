<?php
/**
 * Intentioneel leeg — zie includes/shipping-choice.php.
 *
 * WooCommerce roept 'cart/cart-shipping.php' zelf altijd nog aan vanuit
 * review-order.php, ook al rendert mkcp_render_shipping_choice_cards() de
 * kaarten al apart. Zonder onderdrukking bestaan er twee radio-groepen met
 * dezelfde name="shipping_method[...]" — de browser houdt dan alleen de
 * laatste (ongestylede) aangevinkt, waardoor onze kaarten nooit "actief"
 * herkend worden.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
