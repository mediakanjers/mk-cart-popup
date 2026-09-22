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
// Bewust NIET the_content() — de "Afrekenen"-pagina bevat vaak nog page-
// builder-shortcodes rond de eigenlijke [woocommerce_checkout]-shortcode.
// the_content() zou die builder-wrappers meerenderen, wat de distraction-free
// belofte ondermijnt — en shortcodes zitten niet in $wp_filter, dus de
// thema-hook-opruim-sweep (checkout-frontend.php) kan ze niet filteren.
// Rechtstreeks alleen de checkout-shortcode renderen houdt de checkout
// gegarandeerd schoon, ongeacht wat er ooit in de pagina-inhoud is geplakt.
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
