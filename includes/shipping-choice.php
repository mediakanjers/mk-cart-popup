<?php
/**
 * MK Cart Popup — Ophalen/Bezorgen keuzekaarten (premium)
 */

if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * De kaartenstijl is bedoeld voor élke verzendkeuze, los van afhalen/
 * bezorgdatum-kiezer. Voorheen leunde dit op pickup_enabled/delivery_date_
 * enabled — dat dwong sites zonder die features naar een ongestylede
 * thema-verzendrij. Nu puur op licentie gegate, net als andere premium
 * checkout-features; mkcp_pickup_feature_enabled() blijft apart bestaan
 * voor de afhaal-specifieke logica in pickup.php.
 */
function mkcp_shipping_choice_is_active(): bool {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    return function_exists( 'mkcp_license_has' ) && mkcp_license_has( 'premium' );
}


/**
 * Welke cart-item-keys zitten in een pakket dat ALLEEN afhaalmethodes heeft?
 * Gebruikt door checkout-kaarten én cart-popup.php, zodat ook daar al vóór
 * het afrekenen duidelijk is welke producten niet verzonden kunnen worden.
 *
 * Pakket-splitsing gebeurt niet in deze plugin maar in de losse "WooCommerce
 * Advanced Shipping Packages"-plugin — vandaar generiek naar de daadwerkelijke
 * pakket-tarieven kijken i.p.v. leunen op een hardgecodeerde verzendklasse-naam.
 *
 * Forceert bewust maar één keer per request een verzendkosten-berekening
 * (static cache), maar alleen waar de badge ook echt te zien is: op cart/
 * checkout en tijdens AJAX (de popup verschijnt bij een toevoeg-actie en
 * wordt dan als WC-fragment opnieuw gerenderd). Op een gewone product- of
 * archiefpagina staat de popup dicht en zou een volledige
 * verzendberekening — inclusief alle zone-/pakket-plugins die daaraan
 * hangen — puur verspilde tijd zijn.
 *
 * @return array<string,true> cart_item_key => true
 */
function mkcp_cart_pickup_only_item_keys(): array {
    static $map = null;
    if ( $map !== null ) return $map;
    $map = [];

    if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->shipping ) return $map;

    $on_shipping_page = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
        || ( function_exists( 'is_cart' ) && is_cart() )
        || ( function_exists( 'is_checkout' ) && is_checkout() );
    if ( ! $on_shipping_page ) return $map;

    if ( ! WC()->cart->needs_shipping() ) return $map;

    WC()->cart->calculate_shipping();

    foreach ( WC()->shipping->get_packages() as $package ) {
        $rates = $package['rates'] ?? [];
        if ( empty( $rates ) ) continue;

        $has_delivery = false;
        $has_pickup   = false;
        foreach ( $rates as $rate ) {
            if ( strpos( (string) $rate->id, 'local_pickup:' ) === 0 ) {
                $has_pickup = true;
            } else {
                $has_delivery = true;
            }
        }

        if ( $has_pickup && ! $has_delivery ) {
            foreach ( $package['contents'] ?? [] as $key => $item ) {
                $map[ $key ] = true;
            }
        }
    }

    return $map;
}


/**
 * Is dit specifieke cart-item alleen af te halen (geen bezorgoptie
 * beschikbaar voor het pakket waar het in valt)?
 */
function mkcp_cart_item_is_pickup_only( string $cart_item_key ): bool {
    return ! empty( mkcp_cart_pickup_only_item_keys()[ $cart_item_key ] );
}


/**
 * Map cart_item_key => 'pickup'|'delivery', gebaseerd op de daadwerkelijk
 * GEKOZEN verzendmethode per pakket — NIET hetzelfde als
 * mkcp_cart_pickup_only_item_keys() hierboven, die kijkt of een pakket
 * sowieso alleen afhalen ALS optie heeft. Deze functie kijkt naar wat de
 * klant heeft aangeklikt: een pakket met zowel bezorgen als afhalen als
 * optie hoort dus ook bij 'pickup' zodra de klant daar bewust voor kiest.
 * BELANGRIJK — volgorde is bewust $_POST EERST, sessie als terugval (het
 * omgekeerde van hoe het op het eerste gezicht logisch lijkt): deze functie
 * cachet zijn resultaat (static $map). $_POST['shipping_method'] verandert
 * nooit binnen één request — dus eerst daarnaar kijken is altijd veilig en
 * actueel, ook al draait deze functie (via een filter, zie
 * mkcp_checkout_register_fulfillment_grouping() in checkout-frontend.php)
 * pas TIJDENS het echte tabel-renderen. Bij een gewone paginalaad (geen
 * $_POST) valt het gewoon terug op de sessie.
 *
 * Roept BEWUST WC()->cart->calculate_shipping() NIET zelf aan (in
 * tegenstelling tot mkcp_cart_pickup_only_item_keys() hierboven, die dat
 * wél moet omdat 'ie op de vroege 'wp'-hook draait): een eerdere versie
 * deed dat wél, maar de allereerste aanroep liep toen via de vroege
 * 'wp'/'woocommerce_checkout_update_order_review'-hook (vóórdat WC het
 * geposte adres/methode had verwerkt) — dat forceerde een dure,
 * verzendberekening op VEROUDERDE klantgegevens, die WooCommerce's eigen
 * flow daarna toch nog een keer (met de juiste gegevens) opnieuw deed.
 * Merkbaar trager "totalen bijwerken"-verzoek, voor niets. Nu wordt de
 * filter altijd geregistreerd (geen vroege gate meer), en draait de
 * daadwerkelijke rol-berekening pas wanneer WooCommerce zelf de rijen
 * rendert — op dát moment heeft WC()->cart->calculate_totals() (dat
 * calculate_shipping() al intern aanroept) allang gedraaid, met de
 * correcte, verse gegevens. Geen dubbele berekening meer nodig.
 *
 * @return array<string,string> cart_item_key => 'pickup'|'delivery'
 */
function mkcp_cart_item_fulfillment_roles(): array {
    static $map = null;
    if ( $map !== null ) return $map;
    $map = [];

    if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->shipping || ! WC()->session ) return $map;
    if ( ! WC()->cart->needs_shipping() ) return $map;

    $posted_shipping_methods = isset( $_POST['shipping_method'] ) ? (array) wc_clean( wp_unslash( $_POST['shipping_method'] ) ) : [];
    $chosen_shipping_methods = (array) WC()->session->get( 'chosen_shipping_methods', [] );

    foreach ( WC()->shipping->get_packages() as $package_index => $package ) {
        $rates = $package['rates'] ?? [];
        if ( empty( $rates ) ) continue;

        $chosen_method = $posted_shipping_methods[ $package_index ] ?? ( $chosen_shipping_methods[ $package_index ] ?? '' );

        // Nog geen keuze bekend (bv. allereerste page-load vóórdat WC's eigen
        // checkout.js de standaardmethode heeft gepost) — terugvallen op de
        // eerste beschikbare rate, zodat items altijd een rol krijgen i.p.v.
        // nergens bij te horen.
        $rate = $rates[ $chosen_method ] ?? reset( $rates );
        if ( ! $rate ) continue;

        $role = strpos( (string) $rate->id, 'local_pickup:' ) === 0 ? 'pickup' : 'delivery';

        foreach ( $package['contents'] ?? [] as $key => $item ) {
            $map[ $key ] = $role;
        }
    }

    return $map;
}


/**
 * Heeft dit winkelmandje ECHT een mix van "wordt verzonden" en "wordt
 * afgehaald" producten, gegeven de HUIDIG GEKOZEN verzendmethodes? Alleen dan
 * is een gegroepeerd besteloverzicht zinvol — kiest de klant voor alles
 * dezelfde afhandeling (alles verzenden, of alles afhalen), dan valt de
 * groepering vanzelf weer weg.
 */
function mkcp_cart_has_mixed_fulfillment(): bool {
    $roles = mkcp_cart_item_fulfillment_roles();
    if ( count( $roles ) < 2 ) return false;
    return count( array_unique( $roles ) ) > 1;
}


/**
 * Verbergt betaalde bezorgmethodes (cost > 0) uit $rates zodra er BINNEN de
 * bezorg-groep (niet ophalen, dat is een aparte keuze) ook een gratis methode
 * beschikbaar is — anders kan een klant onnodig voor betaald kiezen.
 *
 * Kijkt naar de daadwerkelijke kosten (get_cost()), niet naar method_id: werkt
 * dus ook voor een flat_rate met kosten 0, generiek voor elk thema/elke
 * configuratie i.p.v. leunen op specifieke method_id's/rate_id's (zoals de
 * site-specifieke mk_shipping_filter_rates() in het child-thema doet).
 *
 * Instelbaar (hide_paid_delivery_if_free, standaard uit) voor een winkelier
 * die bewust een betaalde expresoptie náást een gratis optie wil tonen.
 */
/**
 * Zelfde label als WC's wc_cart_totals_shipping_method_label(), alleen met de
 * methodenaam vervangen door de eigen tekst uit "Verzendkeuze-kaarten"
 * (indien ingesteld) — het prijs-/BTW-gedeelte blijft ongewijzigd, we
 * vervangen alleen het begin van de string ($method->get_label()). Raakt
 * NIET de "echte" WC-methodenaam (blijft ongemoeid in mails/facturen).
 *
 * $with_price = false laat prijs/BTW helemaal weg — voor de enkele-methode-
 * kaart, waar de prijs anders dubbelop met het orderoverzicht zou staan.
 */
function mkcp_sc_shipping_method_label( $method, bool $with_price = true ): string {
    $cfg       = function_exists( 'mkcp_checkout_config' ) ? mkcp_checkout_config() : [];
    $overrides = (array) ( $cfg['shipping_choice_labels'] ?? [] );
    $override  = $overrides[ $method->id ] ?? '';

    if ( ! $with_price ) {
        return $override !== '' ? esc_html( $override ) : esc_html( $method->get_label() );
    }

    $full = wc_cart_totals_shipping_method_label( $method );
    if ( $override === '' ) return $full;

    $original_label = $method->get_label();
    if ( strpos( $full, $original_label ) !== 0 ) return $full; // onverwachte vorm, niet in raden

    return esc_html( $override ) . substr( $full, strlen( $original_label ) );
}


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
 * Bezorgmethodes vóór afhaalmethodes in de rates-array. WC's eigen
 * wc_get_default_shipping_method_for_package() pakt zonder eerdere keuze
 * gewoon de EERSTE rate — staat "Zelf afhalen" toevallig eerst in de
 * verzendzone, dan wordt dat stilzwijgend de default en cachet WC dat meteen
 * in de sessie (waardoor de fallback hieronder er nooit meer aan toekomt).
 * Herordenen hier, vóór WC's default-bepaling draait, lost het bij de bron
 * op: "Laten bezorgen" moet default aangevinkt staan zodra dat een optie is.
 */
add_filter( 'woocommerce_package_rates', function( $rates ) {
    if ( ! mkcp_shipping_choice_is_active() ) return $rates;
    if ( count( $rates ) < 2 ) return $rates;

    $delivery = [];
    $pickup   = [];
    foreach ( $rates as $rate_id => $rate ) {
        if ( strpos( (string) $rate->id, 'local_pickup' ) === 0 ) {
            $pickup[ $rate_id ] = $rate;
        } else {
            $delivery[ $rate_id ] = $rate;
        }
    }
    // Alleen herordenen als er van beide daadwerkelijk iets is — anders
    // verandert er toch niets aan de effectieve default, en behouden we de
    // oorspronkelijke volgorde onnodig niet.
    if ( empty( $delivery ) || empty( $pickup ) ) return $rates;

    return $delivery + $pickup;
}, 20 );


/**
 * Vriendelijkere pakketnaam dan WC's kale "Verzending 2" bij een gesplitste
 * winkelwagen. Bevat een pakket precies één product, gebruik die productnaam
 * (meest voorkomende splitsituatie); bij meerdere producten blijft WC's
 * "Verzending N" staan, een opsomming zou te lang worden.
 */
add_filter( 'woocommerce_shipping_package_name', function( $name, $package_id, $package ) {
    if ( ! mkcp_shipping_choice_is_active() ) return $name;

    $contents = $package['contents'] ?? [];
    if ( count( $contents ) !== 1 ) return $name;

    $item = reset( $contents );
    $product = $item['data'] ?? null;
    if ( ! $product || ! is_a( $product, 'WC_Product' ) ) return $name;

    return $product->get_name();
}, 10, 3 );


/**
 * Leesbare opsomming van de producten in een pakket (bv. "Kenteken ABC-123,
 * T-shirt rood (2x)") i.p.v. WC's kale "1 item"/"2 items" — zodat bij
 * meerdere pakketten duidelijk is welke artikelen bij welke kaartgroep horen.
 *
 * Toont altijd de volledige lijst (geen "en N meer"-afkapping meer — die
 * gaf onnodig een extra klik voor iets dat prima in kleine tekst past).
 *
 * @return array{full: string}
 */
function mkcp_shipping_choice_package_contents_label( array $package, int $max_name_length = 28 ): array {
    $empty = [ 'full' => '' ];
    $contents = $package['contents'] ?? [];
    if ( empty( $contents ) ) return $empty;

    $names = [];
    foreach ( $contents as $item ) {
        $product = $item['data'] ?? null;
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) continue;

        $qty  = (int) ( $item['quantity'] ?? 1 );
        $name = $product->get_name();
        // Lange individuele productnamen afkappen — anders kan zelfs met
        // 1-2 producten de opsomming een onleesbare lap tekst worden.
        if ( mb_strlen( $name ) > $max_name_length ) {
            $name = mb_substr( $name, 0, $max_name_length - 1 ) . '…';
        }
        $names[] = $qty > 1 ? sprintf( '%s (%dx)', $name, $qty ) : $name;
    }

    return [
        'full' => implode( ', ', $names ),
    ];
}

/**
 * Gathers the arguments required by the cart-shipping-choice.php template
 * voor ÉÉN specifiek verzendpakket. Mimicked WooCommerce's eigen
 * wc_cart_totals_shipping_html() (wc-cart-functions.php) — die loopt zelf
 * ook gewoon over alle pakketten en roept per pakket cart/cart-shipping.php
 * aan met zijn eigen $index/$package; dit doet hetzelfde voor onze
 * gestylede kaarten-variant.
 *
 * @param array $package       Eén pakket uit WC()->shipping->get_packages().
 * @param int   $package_index De index van dat pakket (voor shipping_method[index]-veldnamen).
 * @param int   $total_packages Totaal aantal pakketten (voor show_package_details).
 */
function mkcp_get_shipping_choice_template_args_for_package( array $package, int $package_index, int $total_packages ): array {
    // Sessie eerst, $_POST alleen als vangnet, PER PAKKET — zelfde reden als
    // voorheen (zie git-historie): WC_AJAX::update_order_review() draait
    // WC()->cart->calculate_shipping() vóórdat deze fragments-filter vuurt,
    // en die core-aanroep corrigeert de sessie zelf al naar een geldige rate
    // zodra de eerder gekozen rate niet meer bestaat voor dit pakket (bv. na
    // een postcode-wijziging). $_POST bevat op dat moment nog de oude,
    // inmiddels ongeldige keuze — dient hier alleen als vangnet voor het
    // zeldzame geval dat de sessie niet beschikbaar is.
    $chosen_shipping_methods = (array) WC()->session->get( 'chosen_shipping_methods', [] );
    $chosen_method = $chosen_shipping_methods[ $package_index ] ?? null;

    if ( empty( $chosen_method ) ) {
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
            $chosen_shipping_methods[ $package_index ] = $chosen_method;
            WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
        }
    }

    $contents_label = mkcp_shipping_choice_package_contents_label( $package );

    return array(
        'package'                  => $package,
        'available_methods'        => $package['rates'] ?? [],
        'show_package_details'     => $total_packages > 1,
        'show_shipping_calculator' => is_cart(),
        'package_details'          => $contents_label['full'],
        'package_name'             => $package['package_name'] ?? '',
        'index'                    => $package_index,
        'chosen_method'            => $chosen_method,
        'formatted_destination'    => isset($package['destination']) ? WC()->countries->get_formatted_address( $package['destination'], ', ' ) : '',
        'has_calculated_shipping'  => WC()->customer->has_calculated_shipping(),
    );
}

/**
 * Rendert de keuzekaarten voor ÉÉN pakket (gebruikt door zowel de normale
 * render als de AJAX-fragment-registratie hieronder). Vervangt de oude
 * mkcp_get_shipping_choice_template_args() die altijd pakket 0 aannam.
 *
 * @param int|null $only_package_index Als gezet: render alleen dit pakket
 *                                      (voor eventueel toekomstig los gebruik).
 */
function mkcp_render_all_shipping_choice_cards( ?int $only_package_index = null ): void {
    if ( ! function_exists('WC') || ! WC()->shipping || ! WC()->cart || ! WC()->session ) return;

    $packages = WC()->shipping->get_packages();

    // Bij een verse, uitgelogde bezoeker (geen adres, geen sessiegeschiedenis)
    // levert get_packages() soms een LEGE array op i.p.v. één pakket met lege
    // $package['rates'] — dat tweede geval ving templates/cart-shipping-
    // choice.php al netjes op met een nette "vul eerst je postcode in"-
    // melding, maar dit eerste geval (géén pakket überhaupt) sloeg tot nu toe
    // stil af, zonder ENIGE tekst. Zelfde melding, dezelfde stijl — alleen
    // getoond als er ook echt iets te verzenden valt (anders is "vul je
    // postcode in" misleidend voor bv. een winkelwagen met alleen download-
    // producten).
    if ( empty( $packages ) ) {
        if ( WC()->cart->needs_shipping() ) {
            // Verplicht in dezelfde ".woocommerce-shipping-totals.shipping"-
            // wrapper als het echte template hieronder gebruikt: de bestaande
            // opruim-JS (mkco_reorganize() in checkout-frontend.php) herkent
            // en verwijdert VERSE-vs-oude kopieën uitsluitend op die class.
            // Zonder deze wrapper bleef deze melding als wees in de DOM
            // achter zodra er ná een adreswijziging wél pakketten kwamen —
            // precies de gemelde bug ("tekst blijft zichtbaar na adres").
            echo '<div class="woocommerce-shipping-totals shipping"><div class="mkcp-sc-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><span>'
                . esc_html__( 'Vul hierboven eerst je postcode en huisnummer in om de verzend- en afhaalopties te bekijken.', 'mk-cart-popup' )
                . '</span></div></div>';
        }
        return;
    }

    $total = count( $packages );

    // Eerste doorloop: args per pakket opbouwen en per rol (bezorgen/
    // afhalen) onthouden welk pakket-index de LAATSTE is met die rol. Fase 2
    // toont de datum-/tijdvakkiezer voor een rol pas ná dát pakket, zodat 'ie
    // nooit tussen twee kaartgroepen van dezelfde rol in komt te staan (bv.
    // twee losse "Zelf afhalen"-pakketten in één winkelwagen) — en er ook bij
    // meerdere pakketten met dezelfde rol maar 1 kiezer voor die rol getoond
    // wordt, in plaats van een dubbele/kapotte weergave met identieke ids.
    $all_args        = [];
    $role_last_index = [ 'delivery' => null, 'pickup' => null ];
    foreach ( $packages as $package_index => $package ) {
        if ( null !== $only_package_index && $package_index !== $only_package_index ) continue;
        $args = mkcp_get_shipping_choice_template_args_for_package( $package, (int) $package_index, $total );
        $all_args[ $package_index ] = $args;
        $role = strpos( (string) $args['chosen_method'], 'local_pickup:' ) === 0 ? 'pickup' : 'delivery';
        $role_last_index[ $role ] = $package_index;
    }

    foreach ( $all_args as $package_index => $args ) {
        $args['mkcp_render_role_widget'] = in_array( $package_index, $role_last_index, true );
        wc_get_template( 'cart-shipping-choice.php', $args, '', MKCP_PATH . 'templates/' );
    }
}

/**
 * Rendert de keuzekaarten. Deze functie wordt door een van de hooks hieronder aangeroepen.
 */
function mkcp_render_shipping_choice_cards() {
	if ( ! mkcp_shipping_choice_is_active() ) return;
    mkcp_render_all_shipping_choice_cards();
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
/**
 * Fase 2: telt de gekozen verzendkosten (excl./incl. btw, zie hieronder) per
 * ROL op over ALLE verzendpakketten heen — i.p.v. de oude aanname dat er
 * hooguit één actieve methode voor de hele order is. Bij een gemengd
 * winkelwagentje (1 pakket bezorgen, 1 pakket afhalen) moet het totaalbedrag
 * dus over de twee rollen verdeeld worden, anders staat er bv. "Afhalen:
 * €4,95" terwijl die €4,95 in werkelijkheid de bezorgkosten van het ándere
 * pakket zijn (afhalen zelf is gratis) — precies de bug die dit oplost.
 *
 * Bepaalt de gekozen rate hetzelfde als mkcp_get_shipping_choice_template_
 * args_for_package(): sessie eerst, $_POST als vangnet per pakket-index.
 *
 * @return array<string,array{cost:float,tax:float,present:bool}> 'delivery'/'pickup' => kosten
 */
function mkcp_shipping_choice_costs_by_role(): array {
    $totals = [
        'delivery' => [ 'cost' => 0.0, 'tax' => 0.0, 'present' => false ],
        'pickup'   => [ 'cost' => 0.0, 'tax' => 0.0, 'present' => false ],
    ];

    if ( ! function_exists( 'WC' ) || ! WC()->shipping || ! WC()->session ) return $totals;

    $chosen_shipping_methods = (array) WC()->session->get( 'chosen_shipping_methods', [] );

    foreach ( WC()->shipping->get_packages() as $package_index => $package ) {
        $rates = $package['rates'] ?? [];
        if ( empty( $rates ) ) continue;

        $chosen_method = $chosen_shipping_methods[ $package_index ] ?? '';
        if ( empty( $chosen_method ) ) {
            $chosen_method = isset( $_POST['shipping_method'][ $package_index ] )
                ? wc_clean( wp_unslash( $_POST['shipping_method'][ $package_index ] ) )
                : '';
        }

        $rate = $rates[ $chosen_method ] ?? null;
        if ( ! $rate ) continue;

        $role = strpos( (string) $rate->id, 'local_pickup:' ) === 0 ? 'pickup' : 'delivery';
        // NB: 'present' apart bijhouden van cost/tax — een gratis afhaalpakket
        // (cost=0, tax=0) telt ook mee als aanwezige rol, anders zou "Afhalen:
        // Gratis" ten onrechte wegvallen zodra er ook een betaald bezorgpakket
        // is (cost/tax>0 zou dan de enige manier zijn om een rij te tonen).
        $totals[ $role ]['present'] = true;
        $totals[ $role ]['cost']   += (float) $rate->cost;
        $totals[ $role ]['tax']   += array_sum( (array) $rate->taxes );
    }

    return $totals;
}

add_action( 'woocommerce_review_order_before_order_total', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! mkcp_shipping_choice_is_active() ) return;
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
    if ( ! WC()->cart->needs_shipping() || ! WC()->cart->show_shipping() ) return;

    $totals = mkcp_shipping_choice_costs_by_role();
    $roles  = array_filter( $totals, function( $role ) {
        // 'present', niet cost/tax > 0 — een gratis afhaalpakket moet gewoon
        // als "Afhalen: Gratis" getoond worden, ook naast een betaald
        // bezorgpakket (zie de docblock bij mkcp_shipping_choice_costs_by_role()).
        return $role['present'];
    } );

    // Geen van beide rollen kwam voor in de daadwerkelijk berekende pakketten
    // (bv. sessie nog niet gevuld bij de allereerste page-load) — dan tonen we,
    // net als voorheen, één rij op basis van de eerst gekozen methode zodat er
    // sowieso een bedrag zichtbaar blijft.
    if ( empty( $roles ) ) {
        $rate_id   = function_exists( 'mkcp_dd_current_rate_id' ) ? mkcp_dd_current_rate_id() : null;
        $is_pickup = $rate_id && strpos( $rate_id, 'local_pickup:' ) === 0;
        $roles     = [ $is_pickup ? 'pickup' : 'delivery' => $totals[ $is_pickup ? 'pickup' : 'delivery' ] ];
    }

    $labels = [
        'delivery' => __( 'Verzendkosten', 'mk-cart-popup' ),
        'pickup'   => __( 'Afhalen', 'mk-cart-popup' ),
    ];

    foreach ( $roles as $role => $amounts ) {
        $price = mkcp_shipping_choice_format_role_total( $amounts['cost'], $amounts['tax'] );

        echo '<tr class="shipping-costs shipping-costs--' . esc_attr( $role ) . '">'
           . '<th>' . esc_html( $labels[ $role ] ) . '</th>'
           . '<td>' . wp_kses_post( $price ) . '</td>'
           . '</tr>';
    }
} );

/**
 * Formatteert een opgeteld rol-subtotaal (cost excl. btw + btw-bedrag) op
 * dezelfde manier als WC_Cart::get_cart_shipping_total() dat voor de hele
 * order doet — nodig omdat die core-functie zelf geen subset van pakketten
 * accepteert, alleen "alles". Zelfde incl./excl.-btw-weergave-instelling
 * (WC()->cart->display_prices_including_tax()) en dezelfde "incl./excl.
 * btw"-suffix-logica.
 */
function mkcp_shipping_choice_format_role_total( float $cost, float $tax ): string {
    if ( WC()->cart->display_prices_including_tax() ) {
        $total = wc_price( wc_round_tax_total( $cost + $tax ) );

        if ( $tax > 0 && ! wc_prices_include_tax() ) {
            $total .= ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
        }
    } else {
        $total = wc_price( $cost );

        if ( $tax > 0 && wc_prices_include_tax() ) {
            $total .= ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
        }
    }

    return $total;
}


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
            mkcp_render_all_shipping_choice_cards();
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
