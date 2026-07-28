<?php
/**
 * MK Cart Popup — Ophalen/Bezorgen keuzekaarten (premium)
 */

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * De kaartenstijl is bedoeld voor élke verzendkeuze, los van of de site ook
 * afhalen of een bezorgdatum-kiezer gebruikt (zie de docblock van templates/
 * cart-shipping-choice.php: die toont zichzelf netjes ook met maar één
 * groep/methode). Voorheen leunde dit op mkcp_pickup_feature_enabled() resp.
 * pickup_enabled/delivery_date_enabled — dat dwong sites die geen van beide
 * gebruiken (bv. alleen standaard verzending) naar een volledig ongestylede,
 * thema-eigen verzendrij, terwijl dit puur een visuele premium-verbetering
 * is die niets met die twee losstaande features te maken heeft. Daarom hier
 * alleen nog op de licentie gegate, net als de andere premium checkout-
 * features — mkcp_pickup_feature_enabled() blijft ongewijzigd bestaan voor
 * de afhaal-specifieke logica in pickup.php.
 */
function mkcp_shipping_choice_is_active(): bool {
    return function_exists( 'mkcp_license_has' ) && mkcp_license_has( 'premium' );
}


/**
 * Verbergt betaalde bezorgmethodes (cost > 0) uit $rates zodra er BINNEN de
 * bezorg-groep (dus niet ophalen — dat is een aparte keuze, geen "alternatief"
 * voor bezorgen) ook een gratis methode (cost <= 0) beschikbaar is. Zonder dit
 * kan een klant een betaalde optie kiezen terwijl gratis verzending net zo
 * goed beschikbaar was — verwarrend en onnodig.
 *
 * Kijkt naar de daadwerkelijke kosten (WC_Shipping_Rate::get_cost()), niet
 * naar method_id ('free_shipping' vs 'flat_rate'): zo werkt het ook voor een
 * flat_rate die met kosten 0 is geconfigureerd, of een methode die niet
 * "free_shipping" heet maar wel gratis is — generiek voor elk thema/elke
 * verzendmethode-configuratie, in plaats van op specifieke method_id's of
 * rate_id's te leunen (zoals de site-specifieke mk_shipping_filter_rates()
 * in het child-thema dat wél doet, en die daardoor alleen werkt voor de rates
 * die daar met de hand zijn opgesomd).
 *
 * Instelbaar (checkout_settings: hide_paid_delivery_if_free, standaard uit)
 * zodat een winkelier die bewust een betaalde snellere/expresoptie náást een
 * gratis standaardoptie wil tonen (dan is het geen "alternatief" maar een
 * echte keuze) dit kan uitschakelen.
 */
function mkcp_shipping_choice_hide_paid_delivery( array $rates ): array {
    $cfg = function_exists( 'mkcp_checkout_config' ) ? mkcp_checkout_config() : [];
    if ( empty( $cfg['hide_paid_delivery_if_free'] ) ) return $rates;

    $has_free_delivery = false;
    foreach ( $rates as $rate ) {
        if ( strpos( (string) $rate->id, 'local_pickup:' ) === 0 ) continue;
        if ( (float) $rate->get_cost() <= 0 ) {
            $has_free_delivery = true;
            break;
        }
    }
    if ( ! $has_free_delivery ) return $rates;

    foreach ( $rates as $rate_id => $rate ) {
        if ( strpos( (string) $rate->id, 'local_pickup:' ) === 0 ) continue;
        if ( (float) $rate->get_cost() > 0 ) {
            unset( $rates[ $rate_id ] );
        }
    }
    return $rates;
}


/**
 * Gathers the arguments required by the cart-shipping-choice.php template.
 * This mimics the arguments passed by WooCommerce when it renders the
 * original cart/cart-shipping.php template.
 */
function mkcp_get_shipping_choice_template_args(): array {
    if ( ! function_exists('WC') || ! WC()->shipping || ! WC()->cart || ! WC()->session ) {
        return [];
    }

    $packages = WC()->shipping->get_packages();
    if ( empty( $packages ) ) {
        return [
            'package'                  => null,
            'available_methods'        => [],
            'show_package_details'     => false,
            'show_shipping_calculator' => false,
            'package_details'          => '',
            'package_name'             => '',
            'index'                    => 0,
            'chosen_method'            => null,
            'formatted_destination'    => null,
            'has_calculated_shipping'  => false,
        ];
    }

    // This plugin seems to only support the first package for shipping choice.
    $package_index = 0;
    if ( ! isset( $packages[ $package_index ] ) ) {
        $package_index = key( $packages );
        $package = reset( $packages );
    } else {
        $package = $packages[ $package_index ];
    }

    // Sessie eerst, $_POST alleen als vangnet — zelfde volgorde en reden als
    // mkcp_dd_current_rate_id() (delivery-date.php). WC_AJAX::update_order_review()
    // draait WC()->cart->calculate_shipping() vóórdat deze fragments-filter
    // vuurt, en die core-aanroep corrigeert de sessie zelf al naar een geldige
    // rate zodra de eerder gekozen rate niet meer bestaat voor het nieuwe
    // pakket (bv. na een postcode-wijziging naar een andere zone — zie
    // wc_get_chosen_shipping_method_for_package() in WooCommerce core).
    // $_POST bevat op dat moment nog de oude, inmiddels ongeldige keuze (de
    // browser stuurt altijd de vóór-AJAX aangevinkte radio mee, ook als de
    // klant 'm niet aanraakte) — zou dat voorrang krijgen, dan kan deze kaart
    // een andere methode tonen dan de bezorgdatum-/afhaal-kalender op basis
    // van dezelfde respons. $_POST dient alleen als vangnet voor het
    // zeldzame geval dat de sessie niet beschikbaar is.
    $chosen_method = function_exists( 'mkcp_dd_current_rate_id' ) ? mkcp_dd_current_rate_id() : null;

    if ( null === $chosen_method ) {
        $chosen_method = isset( $_POST['shipping_method'][ $package_index ] )
            ? wc_clean( wp_unslash( $_POST['shipping_method'][ $package_index ] ) )
            : '';
    }

    // Betaalde bezorgmethodes verbergen zodra gratis bezorging beschikbaar is
    // (indien ingeschakeld) — vóór de default-methode-bepaling hieronder, zodat
    // die nooit een net-verborgen betaalde methode als "eerste beschikbare"
    // kiest.
    if ( isset( $package['rates'] ) && is_array( $package['rates'] ) ) {
        $package['rates'] = mkcp_shipping_choice_hide_paid_delivery( $package['rates'] );
    }

    // If no method is chosen (e.g., first visit), default to the first available delivery method.
    if ( empty( $chosen_method ) && ! empty( $package['rates'] ) ) {
        $default_method = null;
        foreach ( $package['rates'] as $method ) {
            if ( strpos( (string) $method->id, 'local_pickup' ) === false ) {
                $default_method = $method;
                break;
            }
        }

        // If no delivery method was found, fall back to the very first available method.
        if ( ! $default_method ) {
            $default_method = reset( $package['rates'] );
        }

        if ( $default_method ) {
            $chosen_method = $default_method->id;
            $chosen_shipping_methods = (array) WC()->session->get( 'chosen_shipping_methods', [] );
            $chosen_shipping_methods[ $package_index ] = $chosen_method;
            WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
        }
    }

    return array(
        'package'                  => $package,
        'available_methods'        => $package['rates'] ?? [],
        'show_package_details'     => count( $packages ) > 1,
        'show_shipping_calculator' => is_cart(),
        'package_details'          => implode( ', ', wp_list_pluck( $package['contents'] ?? [], 'quantity' ) ) . ' ' . _n( 'item', 'items', count( $package['contents'] ?? [] ), 'woocommerce' ),
        'package_name'             => $package['package_name'] ?? '',
        'index'                    => $package_index,
        'chosen_method'            => $chosen_method,
        'formatted_destination'    => isset($package['destination']) ? WC()->countries->get_formatted_address( $package['destination'], ', ' ) : '',
        'has_calculated_shipping'  => WC()->customer->has_calculated_shipping(),
    );
}

/**
 * Rendert de keuzekaarten. Deze functie wordt door een van de hooks hieronder aangeroepen.
 */
function mkcp_render_shipping_choice_cards() {
	if ( ! mkcp_shipping_choice_is_active() ) return;
    wc_get_template( 'cart-shipping-choice.php', mkcp_get_shipping_choice_template_args(), '', MKCP_PATH . 'templates/' );
}

// ── Render keuzekaarten (conditioneel) ───────────────────────────────────────
// Bepaalt de juiste plek om de keuzekaarten te tonen. Als de custom 3-blokken
// layout van de plugin actief is, worden de kaarten in de "Verzending en
// levering"-sectie geplaatst. Zo niet, dan vallen ze terug op de standaard
// WooCommerce-locatie binnen het #order_review-blok. Dit ontkoppelt de
// keuzekaarten-feature van de visuele layout-feature, zodat de kaarten ook
// werken op een standaard checkout.
$cfg = function_exists('mkcp_checkout_config') ? mkcp_checkout_config() : [];
$is_custom_layout_active = ! empty( $cfg['checkout_enabled'] ) && (
    ! empty( $cfg['header_enabled'] ) || ! empty( $cfg['footer_enabled'] ) ||
    ! empty( $cfg['steps_enabled'] ) || ! empty( $cfg['payment_icons_enabled'] )
);

if ( $is_custom_layout_active ) {
    add_action( 'mkcp_checkout_delivery_section', 'mkcp_render_shipping_choice_cards', 5 );
} else {
    add_action( 'woocommerce_review_order_before_shipping', 'mkcp_render_shipping_choice_cards', 5 );
}


// ── Onderdruk WooCommerce's eigen dubbele render op de checkout ─────────────
//
// mkcp_render_shipping_choice_cards() hierboven rendert de kaarten al apart
// via een eigen hook (woocommerce_review_order_before_shipping of
// mkcp_checkout_delivery_section) — vlak vóórdat WooCommerce zelf óók nog
// gewoon wc_cart_totals_shipping_html() aanroept vanuit checkout/review-
// order.php. Zonder onderdrukking bestaan er dan twee complete radio-groepen
// met dezelfde name="shipping_method[...]" tegelijk in hetzelfde formulier:
// de browser houdt bij het parsen alleen de láátste in de DOM aangevinkt (WC's
// eigen, ongestylede exemplaar in #order_review), waardoor onze kaarten nooit
// als "actief" herkend worden, ongeacht welke $chosen_method er PHP-zijdig
// wordt meegegeven. Alleen op de checkout onderdrukken — op de winkelwagen-
// pagina (cart/cart-totals.php) rendert onze hook niet, dus daar moet
// WooCommerce's eigen shippinglijst gewoon blijven werken.
add_filter( 'wc_get_template', function( $template, $template_name ) {
    if ( $template_name !== 'cart/cart-shipping.php' ) return $template;
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return $template;
    if ( ! mkcp_shipping_choice_is_active() ) return $template;

    return MKCP_PATH . 'templates/empty.php';
}, 10, 2 );


// ── Verzendkosten-regel in de totalen-tabel (compenseert de onderdrukking hierboven) ──
//
// De suppressie hierboven verwijdert wc_cart_totals_shipping_html()'s <tr>
// volledig uit checkout/review-order.php — nodig om de dubbele radio-groep te
// voorkomen, maar in WooCommerce's eigen (niet-thema-)template is die <tr>
// tegelijk de ENIGE plek waar de verzendkosten als bedrag in de totalen-tabel
// verschijnen (Subtotaal → Verzendkosten → Totaal). Zonder deze vervangende
// regel ziet de klant dus nergens meer een "Verzendkosten"-bedrag tussen
// Subtotaal en Totaal. Deze regel toont bewust alléén het bedrag (géén tweede
// interactieve keuze) via WooCommerce's eigen WC_Cart::get_cart_shipping_
// total() — dezelfde waarde die de gekozen kaart al gebruikt, dus altijd
// consistent. Top-level geregistreerd op een hook die al binnen het door WC
// AJAX-ververste tabel-fragment ligt (woocommerce_review_order_before_order_
// total, vlak binnen <tfoot>) — ververst dus vanzelf mee, geen aparte ajax-
// registratie nodig (zelfde redenering als de andere zone-render hooks).
add_action( 'woocommerce_review_order_before_order_total', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! mkcp_shipping_choice_is_active() ) return;
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
    if ( ! WC()->cart->needs_shipping() || ! WC()->cart->show_shipping() ) return;

    // Label past zich aan de daadwerkelijk gekozen methode aan: "Verzendkosten:
    // Gratis" is verwarrend wanneer de klant net "Zelf afhalen" koos — er wordt
    // dan niets verzonden. mkcp_dd_current_rate_id() (delivery-date.php) is de
    // al-gedeelde, sessie-/POST-bestendige manier om de huidige keuze op te
    // zoeken (zelfde functie die de bezorgdatum-kiezer ook gebruikt).
    $rate_id  = function_exists( 'mkcp_dd_current_rate_id' ) ? mkcp_dd_current_rate_id() : null;
    $is_pickup = $rate_id && strpos( $rate_id, 'local_pickup:' ) === 0;
    $label     = $is_pickup ? __( 'Afhalen', 'mk-cart-popup' ) : __( 'Verzendkosten', 'mk-cart-popup' );

    echo '<tr class="shipping-costs">'
       . '<th>' . esc_html( $label ) . '</th>'
       . '<td>' . wp_kses_post( WC()->cart->get_cart_shipping_total() ) . '</td>'
       . '</tr>';
} );


// ── AJAX-anker voor verzendkosten (alleen 3-blokken layout) ─────────────────
//
// Alleen nodig wanneer de kaarten NIET al rechtstreeks binnen #order_review
// renderen (dus alleen bij de 3-blokken layout, waar mkcp_render_shipping_
// choice_cards() hierboven op mkcp_checkout_delivery_section hangt — een hook
// die nooit opnieuw vuurt tijdens een AJAX-refresh, zie mkco_reorganize() in
// checkout-frontend.php). In de standaard layout renderen de kaarten al
// rechtstreeks binnen #order_review, dat WEL bij elke AJAX-refresh opnieuw
// wordt gerenderd — daar zou dit anker een onnodige tweede kopie opleveren.
if ( $is_custom_layout_active ) {
    add_action( 'woocommerce_review_order_before_shipping', function() {
        if ( ! mkcp_shipping_choice_is_active() ) return;
        echo '<div id="shipping-choice-ajax-anchor" style="display:none!important;"></div>';
    });
}


// ── AJAX-fragment: verse kaarten meesturen bij élke update_checkout ─────────
//
// wc-ajax (?wc-ajax=update_order_review, bv. bij het wijzigen van postcode/
// adres, wisselen van verzendmethode, toepassen van een coupon) verloopt via
// WC_AJAX::do_wc_ajax(), gehaakt op 'template_redirect'@0 — die functie roept
// de handler aan en beëindigt het request meteen met wp_die(), zonder ooit
// een template te laden. 'wp_enqueue_scripts' vuurt pas wanneer een thema
// wp_head() aanroept vanuit een geladen template — dat gebeurt dus NOOIT
// tijdens deze AJAX-cyclus. Dit filter stond voorheen alleen geregistreerd
// ván binnenuit wp_enqueue_scripts, waardoor het bij ELKE update_checkout-
// aanroep simpelweg nooit werd toegevoegd: de #shipping-choice-ajax-anchor-
// fragment ontbrak dan compleet uit de AJAX-respons, de move-JS vond niets
// om te verplaatsen, en de kaarten in de "Verzending en levering"-sectie
// (3-blokken layout) bleven daardoor de allereerste render tonen — ongeacht
// wat er daarna wijzigde — tot een harde paginaherlaad. Zelfde diagnose/
// oplossing als eerder bij de BTW-switch en de hook-removal-sweep in
// checkout-frontend.php: apart registreren op woocommerce_checkout_update_
// order_review — die hook vuurt in WC_AJAX::update_order_review() vóórdat
// WC()->cart->calculate_shipping() de tarieven herberekent, maar de callback
// zelf voert pas uit zodra apply_filters('woocommerce_update_order_review_
// fragments', ...) verderop in diezelfde functie wordt aangeroepen — dus met
// de al-verse tarieven voor het (nieuwe) adres.
function mkcp_shipping_choice_register_ajax_fragment() {
    static $registered = false;
    if ( $registered ) return;
    $registered = true;

    add_filter( 'woocommerce_update_order_review_fragments', function( $fragments ) {
        if ( ! mkcp_shipping_choice_is_active() ) return $fragments;

        ob_start();
        if ( file_exists( MKCP_PATH . 'templates/cart-shipping-choice.php' ) ) {
            wc_get_template( 'cart-shipping-choice.php', mkcp_get_shipping_choice_template_args(), '', MKCP_PATH . 'templates/' );
        }
        $html = ob_get_clean();

        // Gebruik een ander ID dan het element zelf om conflicten te vermijden.
        $fragments['#shipping-choice-ajax-anchor'] = $html;

        return $fragments;
    } );
}
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_shipping_choice_register_ajax_fragment', 1 );


// ── Assets op checkout pagina ──────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_checkout() || ! mkcp_shipping_choice_is_active() ) return;

    wp_enqueue_style(
        'mkcp-shipping-choice',
        MKCP_URL . 'assets/shipping-choice.css',
        [],
        MKCP_VER
    );

    wp_enqueue_script(
        'mkcp-shipping-choice',
        MKCP_URL . 'assets/shipping-choice.js',
        [ 'jquery' ],
        MKCP_VER,
        true
    );

    // Ook op normale paginalaad registreren (dekt eventuele niet-AJAX
    // fragment-berekeningen af) — de static $registered guard voorkomt
    // dubbele registratie.
    mkcp_shipping_choice_register_ajax_fragment();

    // Geen eigen "verplaats na AJAX"-JS meer hier: dat verplaatsen gebeurt nu
    // in mkco_reorganize() (checkout-frontend.php), samen met alle andere
    // content die van #order_review naar de leveringssectie verhuist (#payment,
    // #mkcp-dd-wrap, etc.) — één plek, dezelfde beproefde aanpak, i.p.v. een
    // los mechanisme dat leunde op het anker-element-id na een replaceWith
    // (dat id bestaat na de eerste AJAX-cyclus niet meer, want replaceWith
    // vervangt het ankerelement zelf door de kaarten-HTML, die geen eigen id
    // heeft — waardoor een latere getElementById()-lookup altijd niets vond en
    // de kaarten dus nooit werden verplaatst of opgeruimd).
} );
