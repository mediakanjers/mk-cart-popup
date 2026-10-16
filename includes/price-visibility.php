<?php
/**
 * MK Cart Popup — Prijzen verbergen (premium)
 *
 * Voor webshops zonder (openbare) prijzen. Drie volledig onafhankelijke
 * instellingengroepen — winkelwagen-popup (config.php, 'price_*'), checkout
 * (admin/checkout-settings.php, 'checkout_price_*') en bedankt-pagina
 * ('thankyou_price_*') — delen hier dezelfde resolutie-logica. Alleen
 * front-end weergave: in wp-admin en op de order-edit-pagina zelf blijven
 * bedragen altijd gewoon zichtbaar (de bedankt-pagina-instelling hieronder
 * verbergt uitsluitend de klant-gerichte order-bevestigingspagina).
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Zichtbaarheidsregel (wie mag prijzen zien) ──────────────────────────────

function mkcp_price_user_may_see_prices( string $visibility, array $roles ): bool {
    if ( 'guests' === $visibility ) {
        return ! is_user_logged_in();
    }
    if ( 'roles' === $visibility ) {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        return (bool) array_intersect( $roles, (array) $user->roles );
    }
    return true; // 'everyone'
}

/**
 * Leest een $prefix+'*'-groep uit een config-array en rekent 'm om naar de
 * uiteindelijke, voor de huidige bezoeker geldende zichtbaarheid per
 * prijsonderdeel. Bij hoofdschakelaar-aan of een niet-gerechtigde bezoeker:
 * alles verborgen.
 */
function mkcp_resolve_price_flags( array $cfg, string $prefix ): array {
    $hidden = [ 'item' => false, 'subtotal' => false, 'shipping' => false, 'tax' => false, 'total' => false ];

    if ( ! mkcp_license_has( 'premium' ) ) {
        return [ 'item' => true, 'subtotal' => true, 'shipping' => true, 'tax' => true, 'total' => true ];
    }

    $hide_all      = ! empty( $cfg[ $prefix . 'hide_all' ] );
    $visibility    = (string) ( $cfg[ $prefix . 'visibility' ] ?? 'everyone' );
    $visible_roles = (array) ( $cfg[ $prefix . 'visible_roles' ] ?? [] );

    if ( $hide_all || ! mkcp_price_user_may_see_prices( $visibility, $visible_roles ) ) {
        return $hidden;
    }

    return [
        'item'     => ! empty( $cfg[ $prefix . 'show_item' ] ),
        'subtotal' => ! empty( $cfg[ $prefix . 'show_subtotal' ] ),
        'shipping' => ! empty( $cfg[ $prefix . 'show_shipping' ] ),
        'tax'      => ! empty( $cfg[ $prefix . 'show_tax' ] ),
        'total'    => ! empty( $cfg[ $prefix . 'show_total' ] ),
    ];
}

function mkcp_price_flags_popup(): array {
    static $flags = null;
    if ( null === $flags ) $flags = mkcp_resolve_price_flags( mkcp_config(), 'price_' );
    return $flags;
}

function mkcp_price_flags_checkout(): array {
    static $flags = null;
    if ( null === $flags ) $flags = mkcp_resolve_price_flags( mkcp_checkout_config(), 'checkout_price_' );
    return $flags;
}

function mkcp_price_flags_thankyou(): array {
    static $flags = null;
    if ( null === $flags ) $flags = mkcp_resolve_price_flags( mkcp_checkout_config(), 'thankyou_price_' );
    return $flags;
}

// ── Checkout: WooCommerce's eigen cart/checkout-totalen-filters ────────────
//
// Het besteloverzicht (checkout/review-order.php) gebruikt WooCommerce's
// eigen filters voor elk prijs-onderdeel — hier hoeven dus geen sjablonen
// overschreven te worden. Alleen actief op de checkout-pagina zelf, zodat de
// 'checkout_price_*'-instellingen nooit de winkelwagen-popup raken (die
// volgt zijn eigen 'price_*'-instellingen, zie templates/cart-popup.php).
//
// Dekt bewust (nog) niet de premium "Ophalen/Bezorgen"-keuzekaarten
// (includes/shipping-choice.php) — die berekenen hun eigen prijsweergave
// los van deze WooCommerce-filters.

function mkcp_register_checkout_price_filters() {
    static $registered = false;
    if ( $registered ) return;
    $registered = true;

    if ( ! mkcp_license_has( 'premium' ) ) return;

    $flags = mkcp_price_flags_checkout();
    if ( $flags['item'] && $flags['subtotal'] && $flags['shipping'] && $flags['tax'] && $flags['total'] ) return; // niets te verbergen

    // Priority 30: mkcp_register_btw_price_filters() (checkout-frontend.php)
    // hangt bij een actieve BTW-schakelaar zijn eigen dual-price-HTML op
    // dezelfde filters op prioriteit 20 en bouwt die ongeacht de inkomende
    // waarde altijd opnieuw op — op prioriteit 10 zou onze lege string dus
    // meteen weer overschreven worden. Hoger dan 20 zorgt dat verbergen altijd
    // wint, ongeacht of de BTW-schakelaar aan staat.
    if ( ! $flags['item'] ) {
        add_filter( 'woocommerce_cart_item_price', '__return_empty_string', 30 );
        add_filter( 'woocommerce_cart_item_subtotal', '__return_empty_string', 30 );
        // BTW-schakelaar plakt de stuksprijs achter de productnaam
        // (mkcp-co-unit-price span, altijd aan het eind toegevoegd) —
        // die hoort hier ook weg te vallen.
        add_filter( 'woocommerce_cart_item_name', function( $name ) {
            $pos = strpos( $name, '<span class="mkcp-co-unit-price' );
            return false === $pos ? $name : substr( $name, 0, $pos );
        }, 30 );
    }
    if ( ! $flags['subtotal'] ) {
        add_filter( 'woocommerce_cart_subtotal', '__return_empty_string', 30 );
    }
    if ( ! $flags['shipping'] ) {
        add_filter( 'woocommerce_cart_shipping_method_full_label', function( $label, $method ) {
            $label = wp_strip_all_tags( html_entity_decode( $method->get_label(), ENT_QUOTES, 'UTF-8' ) );
            // De kosten staan niet altijd netjes los (WC's eigen "Methode –
            // €bedrag"-opbouw), maar soms ook gewoon in de zelf ingestelde
            // titel van de verzendmethode zelf (bv. "Verzending - €15,-") —
            // het bedrag moet er dus hoe dan ook uit, niet alleen de HTML.
            $label = preg_replace( '/[\s\-–—:]*(?:€|&euro;)\s?\d[\d.,]*,?-?/u', '', $label );
            return trim( $label );
        }, 10, 2 );
    }
    if ( ! $flags['tax'] ) {
        add_filter( 'woocommerce_cart_totals_taxes_total_html', '__return_empty_string' );
    }
    if ( ! $flags['total'] ) {
        add_filter( 'woocommerce_cart_totals_order_total_html', '__return_empty_string', 30 );
    }
    // Kortingsbedrag hoort bij de subtotaal-samenvatting; volgt dezelfde vlag.
    if ( ! $flags['subtotal'] ) {
        add_filter( 'woocommerce_cart_totals_coupon_html', '__return_empty_string' );
    }
}

// Normale paginalaad.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    mkcp_register_checkout_price_filters();
}, 20 );

// WooCommerce's AJAX-ververing van het besteloverzicht (bv. na een
// adres-/verzendwissel) draait via admin-ajax.php — 'wp' vuurt daar wel,
// maar is_checkout() staat dan niet op true (verkeerde URL/query), dus de
// bovenstaande hook registreert daar niets. Zonder deze tweede hook
// overschrijft die ververing het verborgen bedrag meteen weer met een
// nieuw, wél gevuld besteloverzicht — exact dezelfde reden waarom
// mkcp_register_btw_price_filters() hierboven ook dubbel gehaakt is.
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_register_checkout_price_filters' );


// ── Lege rijen/kolommen écht laten verdwijnen (niet alleen het bedrag) ─────
//
// De filters hierboven legen alleen de bedrag-waarde; het label/de rij zelf
// (bv. "Subtotaal") blijft in WooCommerce's sjabloon gewoon staan. Dat lossen
// we op via CSS (checkout.scss), gestuurd door een body-class — betrouwbaarder
// dan deze rijen hier zelf wegfilteren, want WooCommerce's AJAX-ververing van
// het besteloverzicht vervangt alleen de tabel, nooit de <body>.
add_filter( 'body_class', function( $classes ) {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return $classes;
    if ( ! mkcp_license_has( 'premium' ) ) return $classes;

    foreach ( mkcp_price_flags_checkout() as $part => $visible ) {
        if ( ! $visible ) $classes[] = 'mkcp-co-hide-' . $part;
    }
    return $classes;
} );


// ── Bedankt-pagina: WooCommerce's eigen besteldetails-tabel (order-received) ──
//
// Derde, volledig losse instellingengroep ('thankyou_price_*') — raakt
// uitsluitend het order_details_table()-blok op de klant-gerichte
// bevestigingspagina (includes/thankyou.php), nooit de order-edit-pagina in
// wp-admin of e-mails. Werkt via WooCommerce's eigen order-totalenfilters
// i.p.v. een los sjabloon te overschrijven, net als het checkout-blok
// hierboven.

function mkcp_thankyou_price_active(): bool {
    if ( ! mkcp_license_has( 'premium' ) ) return false;
    if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) return false;
    $f = mkcp_price_flags_thankyou();
    return ! ( $f['item'] && $f['subtotal'] && $f['shipping'] && $f['tax'] && $f['total'] ); // niets te verbergen?
}

// Bewust NIET op de generieke 'wp'-hook (zoals het checkout-blok hierboven) —
// die blijft voor de rest van het verzoek hangen, en dat is hier te breed.
// Een bestelling met een doorstuur-/offsite-betaalmethode (bv. een klassieke
// redirect-gateway) kan de betaling pas bij terugkomst ÓP deze pagina
// afronden, en kan daarbij in hetzelfde verzoek — via de order-status-wissel
// die dat triggert — alsnog de bevestigingsmail versturen. Blijven deze
// filters dan nog aanstaan, dan zouden ze (ongewild, in strijd met de
// documentatie bovenaan dit bestand) ook de prijzen uit die e-mail
// verwijderen. 'woocommerce_before_thankyou'/'woocommerce_thankyou' omsluiten
// in WooCommerce's eigen checkout/thankyou.php-sjabloon precies en alleen de
// klant-gerichte paginaweergave (zowel de bovenste samenvattingslijst als de
// besteldetails-tabel) — filters gaan hier aan vlak vóór die weergave en weer
// uit vlak erna, zodat ze nooit buiten dat ene render-moment actief zijn.
add_action( 'woocommerce_before_thankyou', function() {
    if ( ! mkcp_thankyou_price_active() ) return;

    $flags = mkcp_price_flags_thankyou();

    if ( ! $flags['item'] ) {
        add_filter( 'woocommerce_order_formatted_line_subtotal', '__return_empty_string' );
    }

    // Het "Totaal:"-regeltje in WooCommerce's eigen bovenste samenvattingslijst
    // (checkout/thankyou.php, <ul class="woocommerce-order-overview">) gebruikt
    // een heel andere filter dan de besteldetails-tabel hieronder — die lijst
    // staat bovendien altijd vast in het template, buiten de woocommerce_
    // thankyou-hook om, dus die rij zelf blijft staan (leeggemaakt, niet
    // verwijderd) en verdwijnt pas via CSS op de 'mkcp-ty-hide-total'-klasse.
    if ( ! $flags['total'] ) {
        add_filter( 'woocommerce_get_formatted_order_total', '__return_empty_string' );
    }

    // get_order_item_totals() geeft elke voettabel-rij een eigen 'type' mee
    // (subtotal/discount/shipping/tax/total/fee/payment_method) — daarop
    // filteren i.p.v. op de array-key, want die laatste varieert (bv. een
    // apart btw-tarief per belastingregel bij "gespecificeerd" BTW-totaal).
    add_filter( 'woocommerce_get_order_item_totals', 'mkcp_ty_hide_order_item_totals' );
}, 1 );

function mkcp_ty_hide_order_item_totals( $rows ) {
    $flags = mkcp_price_flags_thankyou();
    foreach ( $rows as $key => $row ) {
        $type = $row['type'] ?? '';
        if ( ! $flags['subtotal'] && in_array( $type, [ 'subtotal', 'discount' ], true ) ) unset( $rows[ $key ] );
        if ( ! $flags['shipping'] && 'shipping' === $type ) unset( $rows[ $key ] );
        if ( ! $flags['tax'] && 'tax' === $type ) unset( $rows[ $key ] );
        if ( ! $flags['total'] && 'total' === $type ) unset( $rows[ $key ] );
    }
    return $rows;
}

// Weer uitzetten zodra WooCommerce's eigen sjabloon klaar is met deze
// pagina-weergave (woocommerce_thankyou is daarin de laatste van de twee
// omsluitende hooks) — hoge prioriteit zodat dit ná de besteldetails-tabel-
// render van includes/thankyou.php (standaardprioriteit 10) zelf draait.
add_action( 'woocommerce_thankyou', function() {
    remove_filter( 'woocommerce_order_formatted_line_subtotal', '__return_empty_string' );
    remove_filter( 'woocommerce_get_formatted_order_total', '__return_empty_string' );
    remove_filter( 'woocommerce_get_order_item_totals', 'mkcp_ty_hide_order_item_totals' );
}, 999 );

// Lege productkolom écht laten verdwijnen (niet alleen het bedrag) — zelfde
// aanpak als het checkout-blok hierboven, via een body-class i.p.v. de
// kolom hier zelf wegfilteren (die staat al vast in WooCommerce's sjabloon
// vóórdat de regelprijzen zelf bekend zijn).
add_filter( 'body_class', function( $classes ) {
    if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) return $classes;
    if ( ! mkcp_license_has( 'premium' ) ) return $classes;

    foreach ( mkcp_price_flags_thankyou() as $part => $visible ) {
        if ( ! $visible ) $classes[] = 'mkcp-ty-hide-' . $part;
    }
    return $classes;
} );
