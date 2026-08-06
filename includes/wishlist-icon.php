<?php
/**
 * MK Cart Popup — Wishlist hart-icoon (sitebreed)
 *
 * Los van de Account-omgeving zelf (dat blijft alleen de #/wishlist-tab) —
 * dit haakt in op WooCommerce's eigen, stabiele template-hooks om het
 * hart-icoon overal te tonen waar een product wordt weergegeven (archieven,
 * gerelateerde producten, cross-sell-widgets, single product) zónder een
 * thema-template te hoeven overschrijven of een CSS-selector te hoeven
 * raden — precies hoe WooCommerce-plugins dit generiek oplossen (zelfde
 * aanpak als bv. YITH/TI WooCommerce Wishlist). Alleen actief voor
 * ingelogde klanten met premium + de Account-hoofdschakelaar aan (de
 * Wishlist is immers een Account-feature, zie account-wishlist.php).
 *
 * Localized 'current_product_id' hierin wordt ook hergebruikt door de
 * "Recent bekeken producten"-tracking in assets/wishlist-icon.js (client-
 * side localStorage, geen eigen server-side tracking-tabel) — zelfde
 * sitebrede-script-lading, geen apart bestand nodig voor die ene regel.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function mkcp_wishlist_icon_should_render(): bool {
    return function_exists( 'mkcp_account_is_active' ) && mkcp_account_is_active();
}

function mkcp_wishlist_render_heart_icon( int $product_id, string $context = 'loop' ): string {
    if ( ! $product_id || ! mkcp_wishlist_icon_should_render() ) return '';

    $is_active = mkcp_account_wishlist_contains_product( get_current_user_id(), $product_id );
    $label     = $is_active ? __( 'Verwijder van verlanglijst', 'mk-cart-popup' ) : __( 'Bewaar op verlanglijst', 'mk-cart-popup' );

    // Drie losse icoonlagen (hart/spinner/kruisje), CSS toont er telkens
    // maar één op basis van de status-klasse op de knop zelf (is-loading/
    // is-error) — geen innerHTML-gewissel nodig vanuit JS, dus dit werkt
    // identiek voor elke server-side gerenderde knop, overal op de site.
    $heart_svg   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="mkcp-wishlist-heart__icon mkcp-wishlist-heart__icon--heart"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
    $spinner_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="mkcp-wishlist-heart__icon mkcp-wishlist-heart__icon--spinner"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="34 100"/></svg>';
    $error_svg   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="mkcp-wishlist-heart__icon mkcp-wishlist-heart__icon--error"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>';

    return sprintf(
        '<button type="button" class="mkcp-wishlist-heart mkcp-wishlist-heart--%1$s js-mkcp-wishlist-heart%2$s" data-product-id="%3$d" aria-pressed="%4$s" aria-label="%5$s" title="%5$s">%6$s%7$s%8$s</button>',
        esc_attr( $context ),
        $is_active ? ' is-active' : '',
        $product_id,
        $is_active ? 'true' : 'false',
        esc_attr( $label ),
        $heart_svg,
        $spinner_svg,
        $error_svg
    );
}

// Archieven / gerelateerde producten / cross-sell-widgets — elke plek die de
// standaard WooCommerce-loop-template gebruikt vuurt deze hook, ongeacht
// thema (het is een WooCommerce-core-hook, geen thema-specifieke).
add_action( 'woocommerce_before_shop_loop_item_title', function() {
    global $product;
    if ( ! $product instanceof WC_Product ) return;
    echo mkcp_wishlist_render_heart_icon( $product->get_id(), 'loop' );
}, 15 );

// Single product page — ná de "Toevoegen aan winkelwagen"-knop (die zit op
// prioriteit 30), als eigen regel i.p.v. absoluut gepositioneerd overlay-icoon.
add_action( 'woocommerce_single_product_summary', function() {
    global $product;
    if ( ! $product instanceof WC_Product ) return;
    echo mkcp_wishlist_render_heart_icon( $product->get_id(), 'single' );
}, 35 );


// ── Assets ─────────────────────────────────────────────────────────────────
//
// Breed geladen (zelfde "overal behalve /cart"-aanpak als cart-popup.js),
// niet beperkt tot shop-/productpagina's alleen — een thema kan producten
// (related/cross-sell/homepage-carrousel) op vrijwel elke paginasoort tonen.
// mkcp_wishlist_params wordt ook hergebruikt door assets/cart-popup.js
// (de "bewaar voor later → ook in wishlist"-koppeling, zie daar).

add_action( 'wp_enqueue_scripts', function() {
    if ( ! function_exists( 'mkcp_woocommerce_active' ) || ! mkcp_woocommerce_active() ) return;
    if ( ! mkcp_wishlist_icon_should_render() ) return;

    wp_enqueue_style( 'mk-cart-popup-wishlist-icon', MKCP_URL . 'assets/wishlist-icon.css', [], MKCP_VER );

    wp_enqueue_script(
        'mk-cart-popup-wishlist-icon',
        MKCP_URL . 'assets/wishlist-icon.js',
        [ 'jquery' ],
        MKCP_VER,
        true
    );

    $params = [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'mkcp_account_action' ),
        // Voor de "Recent bekeken producten"-tracking (assets/wishlist-icon.js
        // + Dashboard-widget, account-orders.php) — alleen gezet op een
        // single product-pagina, 0 op elke andere paginasoort.
        'current_product_id' => function_exists( 'is_product' ) && is_product() ? get_the_ID() : 0,
    ];
    wp_localize_script( 'mk-cart-popup-wishlist-icon', 'mkcp_wishlist_params', $params );

    // cart-popup.js is een apart, altijd-geladen bestand (basic-tier) — geen
    // harde dependency hierop leggen, wél dezelfde params ook daar
    // beschikbaar maken zodat de save-for-later-koppeling los kan werken
    // ongeacht laadvolgorde van de twee scripts.
    if ( wp_script_is( 'mk-cart-popup', 'enqueued' ) || wp_script_is( 'mk-cart-popup', 'registered' ) ) {
        wp_localize_script( 'mk-cart-popup', 'mkcp_wishlist_params', $params );
    }
}, 20 );
