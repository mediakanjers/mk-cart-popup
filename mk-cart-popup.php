<?php
/**
 * Plugin Name:  MK Cart Popup & Checkout
 * Description:  Slide-in cart drawer for WooCommerce. Intercepts add-to-cart on every page, handles qty/remove via AJAX, and redirects /cart to a configurable URL.
 * Version:      1.14.31-beta.27
 * Author:       Mediakanjers
 * Author URI:   https://mediakanjers.nl
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain:  mk-cart-popup
 *
 * Configuration: WooCommerce → Cart Popup in wp-admin.
 * Advanced overrides: apply the 'mkcp_config' filter in your theme's functions.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MKCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'MKCP_URL',  plugin_dir_url( __FILE__ ) );
define( 'MKCP_VER',  '1.14.31-beta.27' );

// URL to the update manifest on GitHub (raw main branch).
// Change this when you move the repo to a different organisation or name.
define( 'MKCP_UPDATER_URL', 'https://raw.githubusercontent.com/mediakanjers/mk-cart-popup/main/mk-cart-popup-update.json' );

// Zelfde, maar voor het pre-release-kanaal. Wordt alleen geraadpleegd voor
// sites waarvan de licentie 'prerelease' toegang heeft (zie license.php).
define( 'MKCP_UPDATER_BETA_URL', 'https://raw.githubusercontent.com/mediakanjers/mk-cart-popup/main/mk-cart-popup-update-beta.json' );

// ── HPOS-compatibiliteit declareren ─────────────────────────────────────────
//
// De plugin was al functioneel HPOS-correct (overal $order->update_meta_data()
// /save(), dubbele hooks voor classic én HPOS-orderlijsten) maar declareerde
// dat nergens, waardoor WooCommerce 'm onterecht als "niet getest" toonde.
// Moet op before_woocommerce_init draaien — ongeacht of WooCommerce op dít
// moment (bij het laden van deze plugin) al actief is, want dat is precies
// waar dit hook-moment voor bestaat.
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
    }
} );

require_once MKCP_PATH . 'license.php';
require_once MKCP_PATH . 'config.php';
require_once MKCP_PATH . 'updater/updater.php';

require_once MKCP_PATH . 'admin/checkout-settings.php';

if ( is_admin() ) {
    require_once MKCP_PATH . 'admin/onboarding.php';
    require_once MKCP_PATH . 'admin/settings.php';
}

require_once MKCP_PATH . 'includes/checkout-frontend.php';
require_once MKCP_PATH . 'includes/account-db.php';
require_once MKCP_PATH . 'includes/account-frontend.php';
require_once MKCP_PATH . 'includes/account-profile.php';
require_once MKCP_PATH . 'includes/checkout-address-picker.php';
require_once MKCP_PATH . 'includes/account-orders.php';
require_once MKCP_PATH . 'includes/account-notifications.php';
require_once MKCP_PATH . 'includes/account-returns.php';
require_once MKCP_PATH . 'includes/account-reviews.php';
require_once MKCP_PATH . 'includes/account-gdpr.php';
require_once MKCP_PATH . 'includes/account-wishlist.php';
require_once MKCP_PATH . 'includes/account-admin.php';
require_once MKCP_PATH . 'includes/wishlist-icon.php';
require_once MKCP_PATH . 'includes/abandoned-cart.php';
require_once MKCP_PATH . 'includes/delivery-date.php';
require_once MKCP_PATH . 'includes/pickup.php';
require_once MKCP_PATH . 'includes/pickup-ready.php';
require_once MKCP_PATH . 'includes/thankyou.php';
require_once MKCP_PATH . 'includes/shipping-choice.php';

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'mkcp_ac_cron' );
    wp_clear_scheduled_hook( 'mkcp_account_wishlist_price_check' );
    wp_clear_scheduled_hook( 'mkcp_account_notifications_cleanup' );
} );

// Eenmalige vlag voor de onboarding-tour — admin/settings.php leest 'm bij de
// eerstvolgende load van de instellingenpagina en verwijdert 'm meteen weer
// (zie mkcp_show_onboarding daar), zodat de tour maar één keer automatisch
// start, direct na de allereerste activatie.
register_activation_hook( __FILE__, function() {
    update_option( 'mkcp_show_onboarding', 1 );
} );

// Auto-load child theme scaffold hooks if they exist.
add_action( 'plugins_loaded', function() {
    $dir = get_stylesheet_directory() . '/mk-cart-popup';
    foreach ( [ 'cart-hooks.php', 'checkout-hooks.php' ] as $file ) {
        $path = $dir . '/' . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}, 20 );


// ── Template resolver ─────────────────────────────────────────────────────────
//
// Themes can override the popup template by placing a file at:
//   child-theme/mk-cart-popup/cart-popup.php
//
// The plugin template is used as fallback when no theme override exists.

function mkcp_get_template_path() {
    $override = locate_template( 'mk-cart-popup/cart-popup.php' );
    return $override ?: MKCP_PATH . 'templates/cart-popup.php';
}


// ── WooCommerce guard ─────────────────────────────────────────────────────────

add_action( 'admin_notices', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        echo '<div class="notice notice-error"><p>'
            . '<strong>MK Cart Popup</strong> vereist WooCommerce. Activeer WooCommerce om de plugin te gebruiken.'
            . '</p></div>';
    }
} );

function mkcp_woocommerce_active() {
    return class_exists( 'WooCommerce' );
}


// ── Enqueue checkout CSS ───────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function() {
    if ( ! mkcp_woocommerce_active() || ! is_checkout() ) return;
    if ( ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    wp_enqueue_style(
        'mk-cart-popup-checkout',
        MKCP_URL . 'assets/checkout.css',
        [],
        MKCP_VER
    );

    // Eigen kleuren (Instellingen → Styling) — zelfde mechanisme als bij de
    // winkelwagen-popup hieronder. checkout.css leunt voor de meeste tekst-/
    // achtergrondkleuren op dezelfde --mkcp-*-variabelen als cart-popup.css;
    // zonder dit eigen blok kwam de override hier toevallig alleen binnen als
    // de popup-CSS's :root-override toevallig ná deze stylesheet in de <head>
    // terechtkwam — expliciet dus betrouwbaarder dan op die laadvolgorde leunen.
    if ( mkcp_license_has( 'premium' ) ) {
        $style_cfg = mkcp_config();
        wp_add_inline_style( 'mk-cart-popup-checkout', mkcp_style_inline_css( $style_cfg ) );
        if ( ! empty( $style_cfg['style_dark_mode_enabled'] ) ) {
            wp_add_inline_style( 'mk-cart-popup-checkout', mkcp_style_inline_css_dark( $style_cfg ) );
        }
    }

    // Auto-enqueue child theme checkout CSS overrides.
    $override_file = get_stylesheet_directory() . '/mk-cart-popup/checkout.css';
    if ( file_exists( $override_file ) ) {
        wp_enqueue_style(
            'mk-cart-popup-checkout-theme',
            get_stylesheet_directory_uri() . '/mk-cart-popup/checkout.css',
            [ 'mk-cart-popup-checkout' ],
            (string) filemtime( $override_file )
        );
    }

    // Auto-enqueue child theme checkout JS overrides — deliberately exempted
    // from the "Theme JS uitschakelen" sweep (checkout-frontend.php) so this
    // is always a safe place for a developer to add checkout JS.
    $override_js = get_stylesheet_directory() . '/mk-cart-popup/checkout.js';
    if ( file_exists( $override_js ) ) {
        wp_enqueue_script(
            'mk-cart-popup-checkout-theme',
            get_stylesheet_directory_uri() . '/mk-cart-popup/checkout.js',
            [ 'jquery' ],
            (string) filemtime( $override_js ),
            true
        );
    }
} );


// ── Enqueue: gast-e-mailadres vastleggen voor verlaten-winkelwagen-mails ───────

add_action( 'wp_enqueue_scripts', function() {
    if ( ! mkcp_woocommerce_active() || ! is_checkout() || is_user_logged_in() ) return;
    if ( is_wc_endpoint_url( 'order-received' ) ) return;
    if ( ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $config = mkcp_config();
    if ( empty( $config['abandoned_cart_enabled'] ) ) return;

    wp_enqueue_script(
        'mk-cart-popup-abandoned-cart',
        MKCP_URL . 'assets/abandoned-cart.js',
        [ 'jquery' ],
        MKCP_VER,
        true
    );

    wp_localize_script( 'mk-cart-popup-abandoned-cart', 'mkcp_ac_params', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'mkcp_ac_guest_email' ),
    ] );
} );


// ── Enqueue styles + script ────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function() {
    if ( ! mkcp_woocommerce_active() || ! mkcp_is_enabled() || is_cart() ) return;
    if ( ! mkcp_license_has( 'basic' ) ) return;

    wp_enqueue_style(
        'mk-cart-popup',
        MKCP_URL . 'assets/cart-popup.css',
        [],
        MKCP_VER
    );

    // Eigen kleuren/breedte (Instellingen → Styling) — premium. Ná de eigen
    // CSS geladen zodat het de :root-defaults daarin overschrijft; een
    // eventuele child-theme override (hieronder) laadt weer ná dit blok en
    // wint dus terecht van beide.
    if ( mkcp_license_has( 'premium' ) ) {
        $style_cfg = mkcp_config();
        wp_add_inline_style( 'mk-cart-popup', mkcp_style_inline_css( $style_cfg ) );
        if ( ! empty( $style_cfg['style_dark_mode_enabled'] ) ) {
            wp_add_inline_style( 'mk-cart-popup', mkcp_style_inline_css_dark( $style_cfg ) );
        }
    }

    wp_enqueue_script(
        'mk-cart-popup',
        MKCP_URL . 'assets/cart-popup.js',
        [ 'jquery', 'wc-add-to-cart', 'wc-cart-fragments' ],
        MKCP_VER,
        true
    );

    $config = mkcp_config();

    // Mobiele app-ervaring (Instellingen → Styling, premium): bottom-sheet-drag,
    // swipe-to-remove en haptics. Alleen geladen als de toggle aanstaat.
    if ( mkcp_license_has( 'premium' ) && ! empty( $config['mobile_app_experience'] ) ) {
        wp_enqueue_script(
            'mk-cart-popup-mobile',
            MKCP_URL . 'assets/cart-popup-mobile.js',
            [ 'jquery', 'mk-cart-popup' ],
            MKCP_VER,
            true
        );
    }

    // Auto-enqueue child theme CSS overrides.
    $override_file = get_stylesheet_directory() . '/mk-cart-popup/style.css';
    if ( file_exists( $override_file ) ) {
        wp_enqueue_style(
            'mk-cart-popup-theme',
            get_stylesheet_directory_uri() . '/mk-cart-popup/style.css',
            [ 'mk-cart-popup' ],
            (string) filemtime( $override_file ) // auto-bust cache on file save
        );
    }

    // Auto-enqueue child theme JS overrides.
    $override_js = get_stylesheet_directory() . '/mk-cart-popup/script.js';
    if ( file_exists( $override_js ) ) {
        wp_enqueue_script(
            'mk-cart-popup-theme',
            get_stylesheet_directory_uri() . '/mk-cart-popup/script.js',
            [ 'jquery', 'mk-cart-popup' ],
            (string) filemtime( $override_js ),
            true
        );
    }

    wp_localize_script( 'mk-cart-popup', 'mkcp_params', [
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'mkcp_nonce' ),
        'version'      => MKCP_VER,
        'license_tier' => mkcp_license_tier(),
        'btw_split'    => ! empty( $config['btw_split'] ) ? '1' : '0',
        'mobile_app'   => ( mkcp_license_has( 'premium' ) && ! empty( $config['mobile_app_experience'] ) ) ? '1' : '0',
        'analytics'           => ! empty( $config['analytics_enabled'] ) ? '1' : '0',
        'analytics_wc_stats'  => ! empty( $config['analytics_wc_stats'] ) ? '1' : '0',
        'analytics_debug'     => ( ! empty( $config['analytics_debug'] ) && current_user_can( 'manage_options' ) ) ? '1' : '0',
        'shipping_threshold'  => (string) mkcp_get_free_shipping_threshold(),
        'min_order'    => (string) ( (float) ( $config['min_order_amount'] ?? 0 ) ),
        'undo_timeout'    => 5000,
        'cart_url'        => wc_get_cart_url(),
        'save_for_later'       => ! empty( $config['save_for_later'] ) ? '1' : '0',
        'stock_indicator'      => ! empty( $config['stock_indicator'] ) ? '1' : '0',
        'cart_icon_selector'   => sanitize_text_field( $config['cart_icon_selector']  ?? '' ),
        'cart_badge_position'  => sanitize_text_field( $config['cart_badge_position'] ?? 'top-right' ),
        'cart_count_badge_enabled'  => ! empty( $config['cart_count_badge_enabled'] ) ? '1' : '0',
        'cart_count_badge_selector' => sanitize_text_field( $config['cart_count_badge_selector']  ?? '' ),
        'cart_count_badge_position' => sanitize_text_field( $config['cart_count_badge_position'] ?? 'top-right' ),
        'save_cart_url'        => ! empty( $config['save_cart_url'] ) ? '1' : '0',
        'save_cart_email'      => ! empty( $config['save_cart_email'] ) ? '1' : '0',
    ] );
} );


// ── Render popup HTML in wp_footer ────────────────────────────────────────────

add_action( 'wp_footer', function() {
    if ( ! mkcp_woocommerce_active() || ! mkcp_is_enabled() || is_cart() || mkcp_is_distraction_free_checkout() ) return;
    if ( ! mkcp_license_has( 'basic' ) ) return;
    include mkcp_get_template_path();
}, 5 );


// ── Render peek-tab in wp_footer (mobiele app-ervaring, premium) ───────────────
//
// Bewust GEEN onderdeel van templates/cart-popup.php: dat bestand is ook de
// waarde van de #mk-cart-popup-fragment (regel hieronder) — als de peek-tab
// daarin zou zitten, zou elke fragment-refresh (add/verwijder/aantal-
// wijziging) 'm dupliceren, want replaceWith() vervangt alleen de oude
// #mk-cart-popup-node, niet een losstaande sibling. In plaats daarvan wordt
// de teller na elke fragment-refresh puur via JS bijgewerkt (zelfde patroon
// als de bestaande thema-cart-icoon-badge, zie updateCartCountBadge() in
// cart-popup.js) — de knop zelf wordt dus maar één keer per paginalaad
// gerenderd.
add_action( 'wp_footer', function() {
    if ( ! mkcp_woocommerce_active() || ! mkcp_is_enabled() || is_cart() || mkcp_is_distraction_free_checkout() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $config = mkcp_config();
    if ( empty( $config['mobile_app_experience'] ) ) return;

    $cart_count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    ?>
    <button type="button" id="mkcp-peek" class="mkcp-peek mkcp-open" data-cart-count="<?php echo (int) $cart_count; ?>" <?php echo $cart_count > 0 ? '' : 'hidden'; ?>>
        <span class="mkcp-peek__handle" aria-hidden="true"></span>
        <span class="mkcp-peek__row">
            <span class="mkcp-peek__icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                <span class="mkcp-peek__count js-mkcp-peek-count"><?php echo (int) $cart_count; ?></span>
            </span>
            <span class="mkcp-peek__label">Bekijk winkelwagen</span>
        </span>
    </button>
    <?php
}, 6 );


// ── WooCommerce fragment: auto-refresh popup on every cart change ──────────────
//
// Bewust NIET onder de sleutel '#mk-cart-popup' (de echte, live drawer) —
// WooCommerce's eigen add-to-cart.js verwerkt élke fragment-sleutel blind
// (AddToCartHandler.prototype.updateFragments: block()/fadeTo(400ms)/
// replaceWith() voor ALLE keys, niet alleen de eigen mini-cart-widgets).
// Onze eigen wp_enqueue_script-dependency op 'wc-add-to-cart' zorgt dat dát
// script eerder bindt aan de 'added_to_cart'-event dan cart-popup.js, dus
// WooCommerce's eigen (a)synchrone block/replace-cyclus botst met onze
// eigen applyFragments() — met als gevolg dat #mk-cart-popup soms na een
// add-to-cart-vanaf-een-archiefpagina helemaal uit de DOM verdwijnt (en de
// scroll-lock die openPopup() erna nog wél zet, blijft dan voor altijd
// hangen). Een neutrale, nooit als los element bestaande sleutel voorkomt
// dat WooCommerce's eigen script de live drawer ooit aanraakt — alleen
// cart-popup.js zelf leest 'm uit (zie applyFragments() in cart-popup.js).
add_filter( 'woocommerce_add_to_cart_fragments', function( $fragments ) {
    if ( ! mkcp_woocommerce_active() || ! mkcp_is_enabled() || is_cart() || mkcp_is_distraction_free_checkout() ) return $fragments;
    if ( ! mkcp_license_has( 'basic' ) ) return $fragments;
    ob_start();
    include mkcp_get_template_path();
    $fragments['#mkcp-popup-refresh'] = ob_get_clean();
    return $fragments;
} );


// ── Redirect /cart to configured URL ──────────────────────────────────────────

add_action( 'template_redirect', function() {
    if ( ! mkcp_woocommerce_active() || ! mkcp_is_enabled() ) return;
    $config = mkcp_config();
    if ( empty( $config['redirect_cart'] ) ) return;
    if ( is_cart() ) {
        $url = ! empty( $config['redirect_cart_url'] ) ? $config['redirect_cart_url'] : home_url( '/' );
        wp_safe_redirect( esc_url_raw( $url ) );
        exit;
    }
} );


// ── Fix: variabele producten met een "Elke"-attribuut via wc-ajax=add_to_cart ──
//
// Root cause (uitgezocht 2026-08-06, na een screenshot van "Gelegenheid,
// Ontvanger, Kleur en Stijl zijn vereiste velden" ondanks alle 6 dropdowns
// zichtbaar ingevuld): dit is GEEN vervolg van de eerdere .disabled-race-
// conditionfix (beta.26) — het is een losstaande, structurele beperking van
// WooCommerce's EIGEN wc-ajax=add_to_cart-endpoint (WC_AJAX::add_to_cart(),
// class-wc-ajax.php), die dit plugin via wcAjaxUrl gebruikt voor élke
// form.cart-submit, inclusief die van de single-productpagina zelf.
//
// Bij een variatie waar één of meer attributen op "Elke" staan (geen vaste
// waarde per variatie — hier bv. Gelegenheid/Ontvanger/Kleur/Stijl, want de
// 24 variaties verschillen alleen op prijsklasse+vaas) bouwt WC_AJAX::
// add_to_cart() de $variation-array UITSLUITEND uit $product->
// get_variation_attributes() — de eigen opgeslagen data van de variatie zelf
// (dus lege strings voor "Elke"-attributen). Het negeert daarbij volledig
// wat de klant in de attribute_pa_*-dropdowns heeft gekozen, óók al staat
// dat gewoon in de POST (form.serialize() stuurt dat wél mee). WC_Cart::
// add_to_cart() ziet vervolgens voor die "Elke"-attributen geen enkele
// waarde (niet in de variatie-data, niet in wat er "gepost" lijkt, want dat
// laatste komt voor dit endpoint dus nooit aan) en gooit daarom "X is een
// vereist veld" — exact de melding uit de screenshot.
//
// Ter vergelijking: WooCommerce's EIGEN klassieke (niet-AJAX) form-handler
// (WC_Form_Handler::add_to_cart_handler_variable(), class-wc-form-handler.
// php) doet dit wél goed — die bouwt de $variation-array rechtstreeks uit
// alle geposte attribute_*-velden. Onderstaande hook kopieert precies díe
// aanpak, maar dan voor het AJAX-endpoint, en grijpt vóór WooCommerce's
// eigen handler in (prioriteit 5 < WC's 10) — alléén voor variaties met
// minstens één "Elke"-attribuut; alle andere add-to-cart-aanvragen
// (eenvoudige producten, variaties zonder "Elke") lopen ongewijzigd via
// WooCommerce's eigen, correcte standaardpad.
add_action( 'wc_ajax_add_to_cart',               'mkcp_ajax_fix_any_attribute_variation', 5 );
add_action( 'wp_ajax_woocommerce_add_to_cart',        'mkcp_ajax_fix_any_attribute_variation', 5 );
add_action( 'wp_ajax_nopriv_woocommerce_add_to_cart', 'mkcp_ajax_fix_any_attribute_variation', 5 );

function mkcp_ajax_fix_any_attribute_variation() {
    if ( ! mkcp_woocommerce_active() || ! WC()->cart ) return;
    if ( ! isset( $_POST['product_id'] ) ) return;

    $product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( wp_unslash( $_POST['product_id'] ) ) );
    $product    = wc_get_product( $product_id );

    // Alleen relevant voor een daadwerkelijke variatie — simpele producten
    // en de parent-ID van een variabel product lopen gewoon door naar
    // WooCommerce's eigen handler (die roept dit filter niet nogmaals aan,
    // wij geven hier alleen niets terug/doen niets).
    if ( ! $product || 'variation' !== $product->get_type() ) return;

    $stored_attributes = $product->get_variation_attributes();
    $has_any_attribute = in_array( '', $stored_attributes, true );
    if ( ! $has_any_attribute ) return; // WooCommerce's eigen pad is hier al correct

    $parent_id = $product->get_parent_id();
    $quantity  = empty( $_POST['quantity'] ) ? 1 : wc_stock_amount( wp_unslash( $_POST['quantity'] ) );

    $variations = [];
    foreach ( $_POST as $key => $value ) {
        if ( 'attribute_' === substr( $key, 0, 10 ) ) {
            $variations[ sanitize_title( wp_unslash( $key ) ) ] = wp_unslash( $value );
        }
    }

    $passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $parent_id, $quantity, $product_id, $variations );

    if ( $passed_validation && false !== WC()->cart->add_to_cart( $parent_id, $quantity, $product_id, $variations ) ) {
        do_action( 'woocommerce_ajax_added_to_cart', $parent_id );

        if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
            wc_add_to_cart_message( [ $parent_id => $quantity ], true );
        }

        // Publieke static method, exact dezelfde respons-vorm die WC_AJAX::
        // add_to_cart() ook stuurt ({fragments, cart_hash}) — stuurt zelf de
        // JSON-respons en stopt de request (wp_send_json -> wp_die()), dus
        // geen return meer nodig en WooCommerce's eigen handler op
        // prioriteit 10 wordt hierna niet meer bereikt.
        WC_AJAX::get_refreshed_fragments();
    }

    wp_send_json( [
        'error'       => true,
        'product_url' => apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $parent_id ), $parent_id ),
    ] );
}


// ── AJAX: update quantity ──────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_update_qty',        'mkcp_ajax_update_qty' );
add_action( 'wp_ajax_nopriv_mkcp_update_qty', 'mkcp_ajax_update_qty' );

function mkcp_ajax_update_qty() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $key = sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ?? '' ) );
    $qty = max( 0, intval( $_POST['qty'] ?? 0 ) );

    if ( $key ) {
        if ( $qty === 0 ) {
            WC()->cart->remove_cart_item( $key );
        } else {
            WC()->cart->set_quantity( $key, $qty );
        }
    }

    wp_send_json_success( [ 'fragments' => mkcp_get_fragment() ] );
}


// ── AJAX: remove item ──────────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_remove_item',        'mkcp_ajax_remove_item' );
add_action( 'wp_ajax_nopriv_mkcp_remove_item', 'mkcp_ajax_remove_item' );

function mkcp_ajax_remove_item() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $key = sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ?? '' ) );

    if ( $key ) {
        WC()->cart->remove_cart_item( $key );
    }

    wp_send_json_success( [ 'fragments' => mkcp_get_fragment() ] );
}


// ── AJAX: apply coupon ─────────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_apply_coupon',        'mkcp_ajax_apply_coupon' );
add_action( 'wp_ajax_nopriv_mkcp_apply_coupon', 'mkcp_ajax_apply_coupon' );

function mkcp_ajax_apply_coupon() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );

    if ( empty( $code ) ) {
        wp_send_json_error( [ 'message' => __( 'Voer een kortingscode in.', 'mk-cart-popup' ) ] );
        return;
    }

    wc_clear_notices();
    $result = WC()->cart->apply_coupon( $code );

    $notices = wc_get_notices();
    if ( ! empty( $notices['success'] ) ) {
        $message = wp_strip_all_tags( $notices['success'][0]['notice'] );
    } elseif ( ! empty( $notices['error'] ) ) {
        $message = wp_strip_all_tags( $notices['error'][0]['notice'] );
    } else {
        $message = $result
            ? __( 'Kortingscode toegepast!', 'mk-cart-popup' )
            : __( 'Ongeldige kortingscode.', 'mk-cart-popup' );
    }
    wc_clear_notices();

    if ( $result ) {
        wp_send_json_success( [ 'message' => $message, 'fragments' => mkcp_get_fragment() ] );
    } else {
        wp_send_json_error( [ 'message' => $message ] );
    }
}


// ── AJAX: remove coupon ────────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_remove_coupon',        'mkcp_ajax_remove_coupon' );
add_action( 'wp_ajax_nopriv_mkcp_remove_coupon', 'mkcp_ajax_remove_coupon' );

function mkcp_ajax_remove_coupon() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $code = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
    if ( $code ) {
        WC()->cart->remove_coupon( $code );
    }

    wp_send_json_success( [ 'fragments' => mkcp_get_fragment() ] );
}


// ── AJAX: check stock for saved-for-later items ───────────────────────────────

add_action( 'wp_ajax_mkcp_check_stock',        'mkcp_ajax_check_stock' );
add_action( 'wp_ajax_nopriv_mkcp_check_stock', 'mkcp_ajax_check_stock' );

function mkcp_ajax_check_stock() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $ids       = array_slice( array_map( 'absint', (array) ( $_POST['product_ids'] ?? [] ) ), 0, 50 );
    $config    = mkcp_config();
    $threshold = (int) ( $config['stock_threshold'] ?? 5 );
    $result    = [];

    foreach ( $ids as $id ) {
        if ( ! $id ) continue;
        $product = wc_get_product( $id );
        if ( ! $product ) continue;
        $stock_qty = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
        $result[ $id ] = [
            'low_stock'   => $stock_qty !== null && $stock_qty > 0 && $stock_qty <= $threshold,
            'out_of_stock'=> $stock_qty !== null && $stock_qty <= 0,
            'stock_qty'   => $stock_qty,
        ];
    }

    wp_send_json_success( $result );
}


// ── AJAX: re-add item (undo remove) ───────────────────────────────────────────

add_action( 'wp_ajax_mkcp_re_add_item',        'mkcp_ajax_re_add_item' );
add_action( 'wp_ajax_nopriv_mkcp_re_add_item', 'mkcp_ajax_re_add_item' );

function mkcp_ajax_re_add_item() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $product_id   = absint( $_POST['product_id']   ?? 0 );
    $qty          = max( 1, intval( $_POST['qty']   ?? 1 ) );
    $variation_id = absint( $_POST['variation_id'] ?? 0 );
    $variation    = json_decode( wp_unslash( $_POST['variation'] ?? '{}' ), true );

    if ( ! $product_id ) {
        wp_send_json_error( [ 'message' => __( 'Ongeldig product.', 'mk-cart-popup' ) ] );
        return;
    }

    $result = WC()->cart->add_to_cart(
        $product_id,
        $qty,
        $variation_id,
        is_array( $variation ) ? $variation : []
    );

    if ( $result ) {
        wp_send_json_success( [ 'fragments' => mkcp_get_fragment() ] );
    } else {
        wp_send_json_error( [ 'message' => __( 'Kon product niet terugplaatsen.', 'mk-cart-popup' ) ] );
    }
}


// ── Helpers ────────────────────────────────────────────────────────────────────

function mkcp_get_fragment() {
    ob_start();
    include mkcp_get_template_path();
    return [ '#mk-cart-popup' => ob_get_clean() ];
}

function mkcp_is_enabled() {
    $config = mkcp_config();
    return ! empty( $config['enabled'] );
}

/**
 * True op de checkout pagina wanneer de premium "Cart Checkout" (distraction-free
 * checkout) actief is. Op die pagina heeft de winkelwagen-popup geen functie —
 * er staat geen cart-icoon/trigger in de custom header — dus wordt de hele
 * (verborgen) popup-markup dan niet in de DOM gerenderd.
 */
function mkcp_is_distraction_free_checkout() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;
    if ( ! function_exists( 'mkcp_checkout_config' ) ) return false;
    $cfg = mkcp_checkout_config();
    return ! empty( $cfg['checkout_enabled'] );
}

/**
 * Returns the free-shipping minimum order amount for the current customer.
 *
 * Resolution order:
 *   1. Manual override in plugin settings (use sparingly — overrides WC zones).
 *   2. Auto-detect: look up the WooCommerce shipping zone that matches the
 *      customer's country/state/postcode. WC picks the most specific zone,
 *      falling back to "Rest of the World" (zone 0) when nothing else matches.
 *      Different zones can have different thresholds — the correct one is shown.
 *
 * Results are cached per destination for the lifetime of the PHP request.
 */
function mkcp_get_free_shipping_threshold(): float {
    static $request_cache = [];

    $config = mkcp_config();
    $manual = (float) ( $config['free_shipping_threshold'] ?? 0 );
    if ( $manual > 0 ) return $manual;

    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return 0.0;

    // Resolve customer destination; fall back to store base country when unknown.
    $customer = WC()->customer ?? null;
    $country  = $customer ? (string) $customer->get_shipping_country()  : '';
    $state    = $customer ? (string) $customer->get_shipping_state()    : '';
    $postcode = $customer ? (string) $customer->get_shipping_postcode() : '';

    if ( $country === '' ) {
        $country = (string) WC()->countries->get_base_country();
    }

    $cache_key = $country . '|' . $state . '|' . $postcode;
    if ( array_key_exists( $cache_key, $request_cache ) ) {
        return $request_cache[ $cache_key ];
    }

    $package = [
        'destination' => [
            'country'  => $country,
            'state'    => $state,
            'postcode' => $postcode,
        ],
    ];

    // WC returns the most specific matching zone (or zone 0 as catch-all).
    $zone   = WC_Shipping_Zones::get_zone_matching_package( $package );
    $result = mkcp_threshold_from_zone( $zone );

    $request_cache[ $cache_key ] = $result;
    return $result;
}

/**
 * Scan a shipping zone for an enabled free_shipping method with a displayable
 * minimum-amount threshold. Returns the amount, or 0.0 when none is found.
 *
 * Skipped requires values:
 *   'coupon' — only triggered by a coupon, no product-amount threshold.
 *   'both'   — requires coupon AND minimum amount; adding products alone won't
 *              unlock it, so displaying a threshold bar would be misleading.
 */
function mkcp_threshold_from_zone( WC_Shipping_Zone $zone ): float {
    foreach ( $zone->get_shipping_methods( true ) as $method ) {
        if ( $method->id !== 'free_shipping' ) continue;

        $requires = $method->get_option( 'requires' );
        if ( in_array( $requires, [ 'coupon', 'both' ], true ) ) continue;

        $min = (float) $method->get_option( 'min_amount' );
        if ( $min > 0 ) return $min;
    }
    return 0.0;
}

/**
 * Returns all WooCommerce shipping zones with their free-shipping threshold.
 * Used in the admin shipping panel to show a live overview of what WC has set.
 *
 * @return array<int, array{name: string, locations: string, threshold: float, is_default: bool}>
 */
function mkcp_get_all_zone_thresholds(): array {
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return [];

    $result = [];

    // Named zones (most specific) sorted by WooCommerce zone order.
    // get_zones() is indexed by zone_id; each entry contains 'formatted_zone_location'
    // (a WC-computed readable string) and 'zone_name'. We use these directly instead
    // of iterating zone_locations, which are stdClass objects — not plain arrays.
    foreach ( WC_Shipping_Zones::get_zones() as $zone_id => $zone_data ) {
        $zone      = new WC_Shipping_Zone( (int) $zone_id );
        $threshold = mkcp_threshold_from_zone( $zone );

        $result[] = [
            'name'       => $zone_data['zone_name'] ?? '',
            'locations'  => $zone_data['formatted_zone_location'] ?? '',
            'threshold'  => $threshold,
            'is_default' => false,
        ];
    }

    // Zone 0 = Rest of the World (catch-all fallback).
    $zone0     = new WC_Shipping_Zone( 0 );
    $result[]  = [
        'name'       => __( 'Rest van de wereld', 'mk-cart-popup' ),
        'locations'  => __( 'Alles wat niet in een andere zone valt', 'mk-cart-popup' ),
        'threshold'  => mkcp_threshold_from_zone( $zone0 ),
        'is_default' => true,
    ];

    return $result;
}


// ── Cart opslaan & herstellen ─────────────────────────────────────────────────

// Restore cart from transient when ?mkcp_restore=hash is in the URL.
// Uses 'wp' hook so WooCommerce session is ready.
add_action( 'wp', function() {
    if ( empty( $_GET['mkcp_restore'] ) ) return;
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;

    $hash  = sanitize_text_field( wp_unslash( $_GET['mkcp_restore'] ) );
    $items = get_transient( 'mkcp_saved_cart_' . $hash );

    if ( ! is_array( $items ) || empty( $items ) ) return;

    WC()->cart->empty_cart();
    foreach ( $items as $item ) {
        WC()->cart->add_to_cart(
            absint( $item['product_id'] ),
            max( 1, intval( $item['qty'] ) ),
            absint( $item['variation_id'] ?? 0 ),
            is_array( $item['variation'] ?? null ) ? $item['variation'] : []
        );
    }

    delete_transient( 'mkcp_saved_cart_' . $hash );

    wp_safe_redirect( add_query_arg( 'mkcp_restored', '1', home_url( '/' ) ) );
    exit;
} );

// Show a WooCommerce notice when the cart was restored via ?mkcp_restored=1.
add_action( 'wp', function() {
    if ( empty( $_GET['mkcp_restored'] ) ) return;
    wc_add_notice( __( 'Je winkelmand is hersteld!', 'mk-cart-popup' ), 'success' );
} );


// ── Helper: bouw items-array op basis van scope ───────────────────────────────

function mkcp_build_items_from_scope( string $scope, array $saved_items ): array {
    $items = [];

    if ( $scope === 'cart' || $scope === 'both' ) {
        if ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                $items[] = [
                    'product_id'   => absint( $item['product_id'] ),
                    'qty'          => absint( $item['quantity'] ),
                    'variation_id' => absint( $item['variation_id'] ?? 0 ),
                    'variation'    => $item['variation'] ?? [],
                ];
            }
        }
    }

    if ( $scope === 'saved' || $scope === 'both' ) {
        foreach ( $saved_items as $si ) {
            if ( empty( $si['product_id'] ) ) continue;
            $items[] = [
                'product_id'   => absint( $si['product_id'] ),
                'qty'          => max( 1, intval( $si['qty'] ?? 1 ) ),
                'variation_id' => absint( $si['variation_id'] ?? 0 ),
                'variation'    => is_array( $si['variation'] ?? null ) ? $si['variation'] : [],
            ];
        }
    }

    return $items;
}

function mkcp_parse_saved_items_post(): array {
    $raw = wp_unslash( $_POST['saved_items'] ?? '[]' );
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}


// ── AJAX: genereer winkelmand herstel-URL ────────────────────────────────────

add_action( 'wp_ajax_mkcp_generate_save_url',        'mkcp_ajax_generate_save_url' );
add_action( 'wp_ajax_nopriv_mkcp_generate_save_url', 'mkcp_ajax_generate_save_url' );

function mkcp_ajax_generate_save_url() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $config = mkcp_config();
    if ( empty( $config['save_cart_url'] ) && empty( $config['save_cart_email'] ) ) {
        wp_send_json_error( [ 'message' => __( 'Functie niet ingeschakeld.', 'mk-cart-popup' ) ] );
        return;
    }

    $scope_raw   = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'cart' ) );
    $scope       = in_array( $scope_raw, [ 'cart', 'saved', 'both' ], true ) ? $scope_raw : 'cart';
    $saved_items = mkcp_parse_saved_items_post();
    $items       = mkcp_build_items_from_scope( $scope, $saved_items );

    if ( empty( $items ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen producten om op te slaan.', 'mk-cart-popup' ) ] );
        return;
    }

    $hash   = wp_generate_password( 32, false );
    $expiry = max( 1, intval( $config['save_cart_expiry_days'] ?? 7 ) ) * DAY_IN_SECONDS;
    set_transient( 'mkcp_saved_cart_' . $hash, $items, $expiry );

    wp_send_json_success( [
        'url' => add_query_arg( 'mkcp_restore', $hash, home_url( '/' ) ),
    ] );
}


// ── AJAX: stuur winkelmand via e-mail ────────────────────────────────────────

add_action( 'wp_ajax_mkcp_send_cart_email',        'mkcp_ajax_send_cart_email' );
add_action( 'wp_ajax_nopriv_mkcp_send_cart_email', 'mkcp_ajax_send_cart_email' );

function mkcp_ajax_send_cart_email() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );

    $config = mkcp_config();
    if ( empty( $config['save_cart_email'] ) ) {
        wp_send_json_error( [ 'message' => __( 'Functie niet ingeschakeld.', 'mk-cart-popup' ) ] );
        return;
    }

    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'message' => __( 'Ongeldig e-mailadres.', 'mk-cart-popup' ) ] );
        return;
    }

    $scope_raw   = sanitize_text_field( wp_unslash( $_POST['scope'] ?? 'cart' ) );
    $scope       = in_array( $scope_raw, [ 'cart', 'saved', 'both' ], true ) ? $scope_raw : 'cart';
    $saved_items = mkcp_parse_saved_items_post();
    $items       = mkcp_build_items_from_scope( $scope, $saved_items );

    if ( empty( $items ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen producten om te versturen.', 'mk-cart-popup' ) ] );
        return;
    }

    $hash        = wp_generate_password( 32, false );
    $expiry_days = max( 1, intval( $config['save_cart_expiry_days'] ?? 7 ) );
    set_transient( 'mkcp_saved_cart_' . $hash, $items, $expiry_days * DAY_IN_SECONDS );

    $restore_url = add_query_arg( 'mkcp_restore', $hash, home_url( '/' ) );
    $subject     = ! empty( $config['save_cart_email_subject'] )
        ? $config['save_cart_email_subject']
        : __( 'Jouw bewaarde winkelmand', 'mk-cart-popup' );
    $body_text   = ! empty( $config['save_cart_email_body'] )
        ? $config['save_cart_email_body']
        : __( 'Je hebt een winkelmand bewaard. Klik op de knop hieronder om je producten terug te zetten.', 'mk-cart-popup' );
    $body_text   = str_replace( '{expiry_days}', $expiry_days, $body_text );

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
    ];

    $html = mkcp_build_cart_email( $restore_url, $body_text, $items, $expiry_days );
    $sent = wp_mail( $email, $subject, $html, $headers );

    if ( $sent ) {
        wp_send_json_success( [ 'message' => __( 'Mail verzonden! Controleer je inbox.', 'mk-cart-popup' ) ] );
    } else {
        wp_send_json_error( [ 'message' => __( 'Mail kon niet worden verzonden. Controleer de SMTP-instellingen.', 'mk-cart-popup' ) ] );
    }
}


// ── AJAX (admin): testmail "winkelmand delen" ────────────────────────────────
//
// Gebruikt een paar echte, willekeurige producten uit de winkel als
// voorbeeldinhoud i.p.v. losse placeholder-tekst — de herstel-link in de
// testmail werkt daardoor ook meteen echt (zelfde opslagmechanisme als de
// reguliere mail hierboven).

add_action( 'wp_ajax_mkcp_send_test_cart_email', 'mkcp_ajax_send_test_cart_email' );

function mkcp_ajax_send_test_cart_email() {
    check_ajax_referer( 'mkcp_test_email', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'mk-cart-popup' ) ] );
    }

    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'message' => __( 'Ongeldig e-mailadres.', 'mk-cart-popup' ) ] );
    }

    $config   = mkcp_config();
    $products = wc_get_products( [ 'limit' => 2, 'status' => 'publish', 'orderby' => 'rand' ] );
    $items    = array_map( fn( $p ) => [ 'product_id' => $p->get_id(), 'qty' => 1 ], $products );

    if ( empty( $items ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen gepubliceerde producten gevonden om als voorbeeld te tonen.', 'mk-cart-popup' ) ] );
    }

    $hash        = wp_generate_password( 32, false );
    $expiry_days = max( 1, intval( $config['save_cart_expiry_days'] ?? 7 ) );
    set_transient( 'mkcp_saved_cart_' . $hash, $items, $expiry_days * DAY_IN_SECONDS );

    $restore_url = add_query_arg( 'mkcp_restore', $hash, home_url( '/' ) );
    $subject     = ! empty( $config['save_cart_email_subject'] )
        ? $config['save_cart_email_subject']
        : __( 'Jouw bewaarde winkelmand', 'mk-cart-popup' );
    $body_text   = ! empty( $config['save_cart_email_body'] )
        ? $config['save_cart_email_body']
        : __( 'Je hebt een winkelmand bewaard. Klik op de knop hieronder om je producten terug te zetten.', 'mk-cart-popup' );
    $body_text   = str_replace( '{expiry_days}', $expiry_days, $body_text );

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
    ];

    $html = mkcp_build_cart_email( $restore_url, $body_text, $items, $expiry_days );
    $sent = wp_mail( $email, '[TEST] ' . $subject, $html, $headers );

    if ( $sent ) {
        wp_send_json_success( [ 'message' => __( 'Testmail verzonden! Controleer je inbox.', 'mk-cart-popup' ) ] );
    } else {
        wp_send_json_error( [ 'message' => __( 'Versturen mislukt. Controleer je SMTP-instellingen.', 'mk-cart-popup' ) ] );
    }
}


// ── WC-native statistieken ────────────────────────────────────────────────────

add_action( 'woocommerce_cart_item_removed', function( $cart_item_key, $cart ) {
    $config = mkcp_config();
    if ( empty( $config['analytics_wc_stats'] ) ) return;

    $item       = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
    $product_id = (int) ( $item['product_id'] ?? 0 );
    if ( ! $product_id ) return;

    $stats = get_option( 'mkcp_stats_removed', [] );
    if ( ! isset( $stats[ $product_id ] ) ) {
        $product = wc_get_product( $product_id );
        $stats[ $product_id ] = [ 'name' => $product ? $product->get_name() : "Product #{$product_id}", 'count' => 0 ];
    }
    $stats[ $product_id ]['count']++;
    update_option( 'mkcp_stats_removed', $stats, false );
}, 10, 2 );

add_action( 'wp_ajax_mkcp_record_gap',        'mkcp_ajax_record_gap' );
add_action( 'wp_ajax_nopriv_mkcp_record_gap', 'mkcp_ajax_record_gap' );

function mkcp_ajax_record_gap() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );
    $config = mkcp_config();
    if ( empty( $config['analytics_wc_stats'] ) ) { wp_send_json_success(); return; }

    $threshold  = mkcp_get_free_shipping_threshold();
    if ( $threshold <= 0 ) { wp_send_json_success(); return; }

    $cart_total = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_cart_contents_total() : 0.0;
    $gap        = $threshold - $cart_total;
    if ( $gap <= 0 ) { wp_send_json_success(); return; }

    $stats = get_option( 'mkcp_stats_gap', [ 'total_gap' => 0.0, 'count' => 0 ] );
    $stats['total_gap'] = round( (float) $stats['total_gap'] + $gap, 2 );
    $stats['count']     = (int) $stats['count'] + 1;
    update_option( 'mkcp_stats_gap', $stats, false );

    wp_send_json_success();
}

add_action( 'wp_ajax_mkcp_mark_assist',        'mkcp_ajax_mark_assist' );
add_action( 'wp_ajax_nopriv_mkcp_mark_assist', 'mkcp_ajax_mark_assist' );

function mkcp_ajax_mark_assist() {
    check_ajax_referer( 'mkcp_nonce', 'nonce' );
    $config = mkcp_config();
    if ( empty( $config['analytics_wc_stats'] ) ) { wp_send_json_success(); return; }

    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'mkcp_popup_assist', 1 );
    }
    wp_send_json_success();
}

add_action( 'woocommerce_checkout_order_processed', function( $order_id, $posted_data, $order ) {
    $config = mkcp_config();
    if ( empty( $config['analytics_wc_stats'] ) ) return;
    if ( ! function_exists( 'WC' ) || ! WC()->session ) return;

    if ( WC()->session->get( 'mkcp_popup_assist' ) ) {
        $order->update_meta_data( '_mkcp_assisted', 1 );
        $order->save();
        WC()->session->set( 'mkcp_popup_assist', null );
    }
}, 10, 3 );

add_action( 'woocommerce_payment_complete', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || ! $order->get_meta( '_mkcp_assisted' ) ) return;

    $stats = get_option( 'mkcp_stats_assisted', [ 'revenue' => 0.0, 'count' => 0 ] );
    $stats['revenue'] = round( (float) $stats['revenue'] + (float) $order->get_total(), 2 );
    $stats['count']   = (int) $stats['count'] + 1;
    update_option( 'mkcp_stats_assisted', $stats, false );
} );

add_action( 'admin_post_mkcp_clear_stats', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    check_admin_referer( 'mkcp_clear_stats' );

    $which = sanitize_key( $_POST['mkcp_stat'] ?? 'all' );
    if ( $which === 'removed' || $which === 'all' ) delete_option( 'mkcp_stats_removed' );
    if ( $which === 'gap'     || $which === 'all' ) delete_option( 'mkcp_stats_gap' );
    if ( $which === 'assisted'|| $which === 'all' ) delete_option( 'mkcp_stats_assisted' );

    wp_safe_redirect( admin_url( 'admin.php?page=mkcp-settings&saved=1&tab=analytics' ) );
    exit;
} );


function mkcp_build_cart_email( string $url, string $body_text, array $items, int $expiry_days ): string {
    $site_name  = esc_html( get_bloginfo( 'name' ) );
    $accent     = apply_filters( 'mkcp_email_accent_color', '#2e7d32' );

    $products_html = '';
    foreach ( $items as $item ) {
        $product = wc_get_product( $item['product_id'] );
        if ( ! $product ) continue;
        $name      = esc_html( $product->get_name() );
        $qty       = absint( $item['qty'] );
        $price     = esc_html( html_entity_decode( strip_tags( wc_price( wc_get_price_to_display( $product ) ) ), ENT_HTML5, 'UTF-8' ) );

        // Prefer variation image when available, then parent product image, then placeholder.
        $image_id  = 0;
        if ( ! empty( $item['variation_id'] ) ) {
            $variation = wc_get_product( absint( $item['variation_id'] ) );
            if ( $variation ) $image_id = $variation->get_image_id();
        }
        if ( ! $image_id ) $image_id = $product->get_image_id();
        $thumb_url = esc_url( wp_get_attachment_image_url( $image_id, [ 60, 60 ] ) ?: wc_placeholder_img_src() );

        $products_html .= '
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid #f0f0f0;vertical-align:middle;width:60px">
            <img src="' . $thumb_url . '" width="48" height="48" style="border-radius:6px;display:block;object-fit:cover" alt="">
          </td>
          <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle">
            <span style="font-size:14px;color:#1a1a1a;font-weight:500">' . $name . '</span><br>
            <span style="font-size:12px;color:#888">Aantal: ' . $qty . '</span>
          </td>
          <td style="padding:8px 0;border-bottom:1px solid #f0f0f0;vertical-align:middle;text-align:right;white-space:nowrap">
            <span style="font-size:14px;color:#1a1a1a">' . $price . '</span>
          </td>
        </tr>';
    }

    $body_html = nl2br( esc_html( $body_text ) );
    $accent    = esc_attr( $accent );

    return '<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . $site_name . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 16px">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%">

  <tr>
    <td style="background:' . $accent . ';border-radius:12px 12px 0 0;padding:24px 32px;text-align:center">
      <span style="font-size:20px;font-weight:700;color:#fff">' . $site_name . '</span>
    </td>
  </tr>

  <tr>
    <td style="background:#fff;padding:32px">
      <p style="font-size:16px;color:#1a1a1a;margin:0 0 20px;line-height:1.6">' . $body_html . '</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px">
        ' . $products_html . '
      </table>
      <table cellpadding="0" cellspacing="0" style="margin:0 auto">
        <tr>
          <td align="center" style="background:' . $accent . ';border-radius:8px">
            <a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 32px;font-size:16px;font-weight:700;color:#fff;text-decoration:none;border-radius:8px">
              Herstel mijn winkelmand &rarr;
            </a>
          </td>
        </tr>
      </table>
      <p style="font-size:12px;color:#888;margin:24px 0 0;text-align:center;word-break:break-all">
        Of kopieer: <a href="' . esc_url( $url ) . '" style="color:' . $accent . '">' . esc_url( $url ) . '</a>
      </p>
    </td>
  </tr>

  <tr>
    <td style="background:#f0f0f0;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center">
      <p style="font-size:11px;color:#888;margin:0">
        Deze link is ' . $expiry_days . ' ' . ( $expiry_days === 1 ? 'dag' : 'dagen' ) . ' geldig &nbsp;&middot;&nbsp; ' . $site_name . '
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body></html>';
}
