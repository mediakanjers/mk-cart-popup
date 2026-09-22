<?php
/**
 * MK Cart Popup — Distraction-free account login template
 *
 * Geladen via template_include (includes/account-frontend.php) wanneer de
 * bezoeker niet is ingelogd. Doet NIET get_header()/get_footer(), zodat het
 * thema's header.php/footer.php nooit uitvoeren — zelfde aanpak als
 * templates/checkout-page.php en templates/account-page.php (ingelogde
 * staat).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php
// Eigen achtergrondfoto (WooCommerce → Cart Popup → Account → Loginscherm) —
// vast gepositioneerd, rechts vanaf het gekleurde paneel tot de schermrand.
// account-login.js lijnt 'm uit op de werkelijke positie van
// .mkcp-account-login-panel, zie positionLoginBgPhoto() daar.
//
// '1536x1536' i.p.v. 'full': dit is een CSS-achtergrond die hooguit de helft
// van het scherm vult, geen zin om de originele upload ongewijzigd te laden.
// Terugval op 'full' als die tussenmaat niet gegenereerd is.
$mkcp_login_bg_id  = (int) ( function_exists( 'mkcp_account_config' ) ? ( mkcp_account_config()['account_login_bg_image_id'] ?? 0 ) : 0 );
$mkcp_login_bg_url = $mkcp_login_bg_id
    ? ( wp_get_attachment_image_url( $mkcp_login_bg_id, '1536x1536' ) ?: wp_get_attachment_image_url( $mkcp_login_bg_id, 'full' ) )
    : '';
if ( $mkcp_login_bg_url ) :
?>
<div class="mkcp-account-login-bgphoto" style="background-image:url('<?php echo esc_url( $mkcp_login_bg_url ); ?>')" aria-hidden="true"></div>
<?php endif; ?>

<main class="mkcp-account-login-main">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="mkcp-account-login-back">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        <?php esc_html_e( 'Terug naar winkel', 'mk-cart-popup' ); ?>
    </a>
    <?php
    // Bewust NIET the_content() — zelfde reden als checkout-page.php: de
    // "Mijn account"-pagina kan page-builder-wrappers rond het
    // [woocommerce_my_account]-shortcode bevatten, die de distraction-free
    // belofte zouden ondermijnen. Rechtstreeks de shortcode renderen levert
    // de markup op die account-frontend.php al verwacht.
    while ( have_posts() ) {
        the_post();
    }
    echo do_shortcode( '[woocommerce_my_account]' );
    ?>
</main>

<?php wp_footer(); ?>
</body>
</html>
