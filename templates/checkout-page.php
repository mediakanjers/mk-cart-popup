<?php
/**
 * MK Cart Popup — Distraction-free checkout template
 *
 * Loaded via template_include filter. Does NOT call get_header() / get_footer(),
 * so the theme's header.php and footer.php are never executed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$mkcp_cfg = mkcp_checkout_config();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'mkcp-distraction-free-checkout' ); ?>>
<?php wp_body_open(); ?>

<?php if ( ! empty( $mkcp_cfg['header_enabled'] ) ) : ?>
<?php mkcp_checkout_render_header(); ?>
<?php endif; ?>

<main id="mkcp-checkout-main" class="mkcp-checkout-main">
<?php
// Bewust NIET the_content() — de WooCommerce "Afrekenen"-pagina bevat vaak
// nog page-builder-shortcodes (bv. het thema's mk_sectie/mk_rij/mk_module)
// rond de eigenlijke [woocommerce_checkout]-shortcode, omdat de pagina ooit
// via de builder is aangemaakt/bewerkt. the_content() rendert die builder-
// wrappers gewoon mee, wat precies de "distraction-free" belofte van deze
// template ondermijnt — en shortcodes zitten niet in $wp_filter, dus de
// thema-hook-opruim-sweep hierboven (checkout-frontend.php) kan dit sowieso
// niet filteren. Door hier altijd rechtstreeks alleen de checkout-shortcode
// te renderen, maakt het niet meer uit wat er ooit in de pagina-inhoud is
// geplakt — de checkout blijft gegarandeerd schoon, ook na een toekomstige
// bewerking van die pagina in de editor.
while ( have_posts() ) {
    the_post();
}
echo do_shortcode( '[woocommerce_checkout]' );
?>
</main>

<?php if ( ! empty( $mkcp_cfg['footer_enabled'] ) ) : ?>
<?php mkcp_checkout_render_footer(); ?>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
