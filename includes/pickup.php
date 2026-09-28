<?php
/**
 * MK Cart Popup — Afhalen (premium)
 *
 * Zelfde bol.com-stijl datumpicker als de bezorgdatum-kiezer
 * (includes/delivery-date.php), maar dan voor "afhalen bij de zaak":
 * gekoppeld aan een WooCommerce-verzendmethode (rate_id, bv. een "Local
 * pickup"-instantie per locatie), met een eigen adres, openingstijden per
 * weekdag en optionele tijdsloten.
 *
 * Bezorgdatum en afhalen zijn niet wederzijds uitsluitend: een winkelwagentje
 * kan een pakket in bezorgmodus én een pakket in afhaalmodus tegelijk hebben.
 * Beide widgets renderen dan onafhankelijk, elk onder de kaartgroep van zijn
 * eigen pakket (templates/cart-shipping-choice.php). Rol-gebaseerd, niet per
 * pakket-index: een order heeft nooit meer dan één actieve rate per rol.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Helpers ────────────────────────────────────────────────────────────────────

function mkcp_pickup_feature_enabled(): bool {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;
    return ! empty( mkcp_checkout_config()['pickup_enabled'] );
}

/**
 * WooCommerce-verzendmethodes van het type "Local pickup" (rate_id begint
 * met "local_pickup:"), als [ rate_id => label ] — bewust een subset van
 * mkcp_dd_get_shipping_methods(), anders is het te makkelijk om per ongeluk
 * een gewone verzendmethode als afhaallocatie aan te vinken.
 */
function mkcp_pickup_get_locations_methods(): array {
    $methods = function_exists( 'mkcp_dd_get_shipping_methods' ) ? mkcp_dd_get_shipping_methods() : [];
    return array_filter( $methods, function( $rate_id ) {
        return strpos( $rate_id, 'local_pickup:' ) === 0;
    }, ARRAY_FILTER_USE_KEY );
}

/**
 * Geeft de genormaliseerde locatie-config voor een rate_id terug, of null
 * als die rate geen (ingeschakelde) afhaallocatie is.
 */
function mkcp_pickup_location_for_rate( ?string $rate_id ): ?array {
    if ( ! $rate_id || ! mkcp_pickup_feature_enabled() ) return null;
    // Defense-in-depth: ook als er (bv. door oudere/foutieve data) een
    // niet-local_pickup rate_id als 'enabled' in de opgeslagen locaties
    // staat, telt die nooit mee als afhaallocatie.
    if ( strpos( $rate_id, 'local_pickup:' ) !== 0 ) return null;

    $locations = (array) ( mkcp_checkout_config()['pickup_locations'] ?? [] );
    if ( empty( $locations[ $rate_id ]['enabled'] ) ) return null;

    $loc = $locations[ $rate_id ];
    $loc['rate_id'] = $rate_id;

    $methods = function_exists( 'mkcp_dd_get_shipping_methods' ) ? mkcp_dd_get_shipping_methods() : [];
    $loc['method_label'] = $methods[ $rate_id ] ?? $rate_id;

    // Klantgerichte weergavenaam i.p.v. de technische verzendmethodenaam (die
    // vaak interne jargon bevat). method_label blijft ongewijzigd staan voor
    // order-meta/admin/e-mail, handig om locaties te onderscheiden.
    $display_name = trim( (string) ( $loc['display_name'] ?? '' ) );
    $loc['location_label'] = $display_name !== '' ? $display_name : 'Afhaallocatie';

    return $loc;
}

/**
 * Actieve afhaallocatie op basis van de sessie (voor pageload/enqueue) —
 * gebruik mkcp_pickup_location_for_rate() rechtstreeks met een uit $_POST
 * afgeleide rate_id op het moment van submit (zie mkcp_pickup_rate_id_from_post()).
 */
function mkcp_pickup_active_location( ?string $rate_id = null ): ?array {
    $rate_id = $rate_id ?? ( function_exists( 'mkcp_dd_current_rate_id' ) ? mkcp_dd_current_rate_id() : null );
    return mkcp_pickup_location_for_rate( $rate_id );
}

/**
 * Zoekt binnen $_POST['shipping_method'] (alle pakketten) de eerste rate die
 * daadwerkelijk een afhaallocatie is. Delegeert aan de gedeelde
 * mkcp_dd_role_rate_id_from_post() (delivery-date.php).
 */
function mkcp_pickup_rate_id_from_post(): ?string {
    return function_exists( 'mkcp_dd_role_rate_id_from_post' ) ? mkcp_dd_role_rate_id_from_post( 'pickup' ) : null;
}

/**
 * Beschikbare afhaaldatums voor een locatie: cutoff/aanlooptijd net als bij
 * bezorgdatum, maar "verzenddag" wordt hier bepaald door de openingstijden
 * (niet-gesloten weekdag) i.p.v. een aparte lijst geselecteerde weekdagen.
 */
function mkcp_pickup_available_dates( array $loc ): array {
    $range = 60;

    $tz  = new DateTimeZone( wp_timezone_string() );
    $now = new DateTime( 'now', $tz );

    $cutoff_dt = clone $now;
    $cutoff_dt->setTimestamp( (int) ( mkcp_dd_cutoff_timestamp( $loc['cutoff_time'], $tz, $now ) / 1000 ) );

    $days_ahead = (int) $loc['lead_days'] + ( $now >= $cutoff_dt ? 1 : 0 );
    $start = clone $now;
    if ( $days_ahead > 0 ) $start->modify( '+' . $days_ahead . ' days' );
    $start->setTime( 0, 0, 0 );

    $end = clone $now;
    $end->modify( '+' . ( $range + 14 ) . ' days' );

    $blackout  = (array) ( $loc['blackout_dates'] ?? [] );
    $available = [];
    $cursor    = clone $start;

    while ( $cursor <= $end && count( $available ) < $range ) {
        $dow = (int) $cursor->format( 'w' );
        $ymd = $cursor->format( 'Y-m-d' );
        $hrs = $loc['hours'][ $dow ] ?? [ 'closed' => true ];

        if ( empty( $hrs['closed'] ) && ! in_array( $ymd, $blackout, true ) ) {
            $available[] = $ymd;
        }
        $cursor->modify( '+1 day' );
    }

    return $available;
}

/**
 * Tijdsloten voor één weekdag, als lijst starttijden "HH:MM" — leest de
 * openingstijden van de locatie en delegeert de generatie aan de gedeelde
 * mkcp_generate_time_slots() (config.php), die ook door de bezorgdatum-
 * tijdsloten per verzendmethode wordt gebruikt.
 */
function mkcp_pickup_slots_for_dow( array $loc, int $dow ): array {
    if ( empty( $loc['slots_enabled'] ) ) return [];

    $hrs = $loc['hours'][ $dow ] ?? [ 'closed' => true ];
    if ( ! empty( $hrs['closed'] ) ) return [];

    return mkcp_generate_time_slots( $hrs['open'] ?? '09:00', $hrs['close'] ?? '17:00', (int) ( $loc['slot_minutes'] ?? 60 ) );
}

function mkcp_pickup_slots_by_dow( array $loc ): array {
    $out = [];
    for ( $dow = 0; $dow <= 6; $dow++ ) $out[ $dow ] = mkcp_pickup_slots_for_dow( $loc, $dow );
    return $out;
}

/**
 * Controleert of een tijdslot ver genoeg in de toekomst ligt om de bestelling
 * nog te kunnen voorbereiden ('prep_minutes' per locatie — zie
 * mkcp_sanitize_pickup_locations()). Delegeert aan de gedeelde
 * mkcp_slot_is_reachable() (config.php), die ook door de bezorgdatum-
 * tijdsloten wordt gebruikt.
 */
function mkcp_pickup_slot_is_reachable( string $ymd, string $slot, array $loc ): bool {
    return mkcp_slot_is_reachable( $ymd, $slot, (int) ( $loc['prep_minutes'] ?? 60 ) );
}

/**
 * Aantal (niet-geannuleerde/mislukte) orders voor een datum+tijdslot-combinatie
 * bij één specifieke locatie, voor de optionele capaciteitslimiet per tijdslot.
 * Delegeert aan de gedeelde mkcp_slot_count() (config.php) met de afhaal-
 * specifieke metasleutels; de bezorgdatum-tijdsloten gebruiken dezelfde
 * gedeelde functie met hun eigen metasleutels.
 */
function mkcp_pickup_slot_count( string $ymd, string $slot, string $rate_id ): int {
    return mkcp_slot_count( $ymd, $slot, $rate_id, '_mkcp_pickup_date', '_mkcp_pickup_slot', '_mkcp_pickup_rate_id' );
}

/**
 * Data voor wp_localize_script — zelfde vorm als de bezorgdatum-kiezer
 * gebruikt (mkcpDD), plus de afhaal-specifieke velden (slots). Zo kan
 * assets/delivery-date.js één script blijven voor beide modi.
 */
function mkcp_pickup_localize_data( array $loc ): array {
    $tz = new DateTimeZone( wp_timezone_string() );

    return [
        'pickup'        => true,
        'dates'         => mkcp_pickup_available_dates( $loc ),
        'required'      => '1',
        'label'         => 'Afhaaldatum',
        'cutoffTime'    => (string) $loc['cutoff_time'],
        'cutoffTs'      => mkcp_dd_cutoff_timestamp( $loc['cutoff_time'], $tz ),
        'shippingDays'  => array_values( array_filter( range( 0, 6 ), function( $d ) use ( $loc ) {
            return empty( $loc['hours'][ $d ]['closed'] ?? true );
        } ) ),
        'blackoutDates' => (array) ( $loc['blackout_dates'] ?? [] ),
        'slotsEnabled'  => ! empty( $loc['slots_enabled'] ),
        'slotMinutes'   => (int) ( $loc['slot_minutes'] ?? 60 ),
        'slotsByDow'    => mkcp_pickup_slots_by_dow( $loc ),
        'prepMinutes'   => (int) ( $loc['prep_minutes'] ?? 60 ),
        'address'       => (string) ( $loc['address'] ?? '' ),
        'methodLabel'   => (string) ( $loc['location_label'] ?? 'Afhaallocatie' ),
    ];
}


// ── Checkout veld renderen ─────────────────────────────────────────────────────
// Aangeroepen vanuit templates/cart-shipping-choice.php, direct onder de
// "Zelf afhalen"-kaartgroep van het pakket waarvoor $loc is opgezocht, zodat
// een gemengd winkelwagentje deze widget en de bezorgdatum-widget
// (mkcp_dd_render_delivery_field() in delivery-date.php) tegelijk kan tonen.
// Eigen id-namespace (mkcp-pu-* i.p.v. mkcp-dd-*) zodat de twee widgets
// elkaars elementen niet raken; de CSS-classes (mkcp-dd-*) blijven gedeeld.
function mkcp_pickup_render_field( array $loc ) {
    $dates = mkcp_pickup_available_dates( $loc );

    if ( empty( $dates ) ) {
        mkcp_dd_render_empty_state( 'Afhaaldatum', true, 'mkcp-pu-wrap' );
        return;
    }

    ?>
    <div class="mkcp-dd-wrap mkcp-pu-wrap" id="mkcp-pu-wrap">

        <?php // Zelfde statische foutmeld-container als de bezorgdatum-variant. ?>
        <div id="mkcp-pu-error" class="mkcp-dd-error" role="alert" hidden></div>

        <div class="mkcp-dd-header">
            <span class="mkcp-dd-label">
                Afhaaldatum <abbr class="required" title="verplicht veld">*</abbr>
            </span>
        </div>

        <?php // Zelfde inklap-mechanisme als de bezorgdatum-variant. ?>
        <div class="mkcp-dd-body" id="mkcp-pu-body">
        <div class="mkcp-dd-body-inner">

        <p class="mkcp-dd-microcopy" id="mkcp-pu-microcopy" aria-hidden="true"></p>

        <div class="mkcp-dd-track" id="mkcp-pu-track">
            <button type="button" class="mkcp-dd-nav mkcp-dd-nav--prev" id="mkcp-pu-nav-prev" aria-label="Vorige data">&#8249;</button>
            <div class="mkcp-dd-cards-viewport" id="mkcp-pu-cards-viewport">
                <div class="mkcp-dd-cards-list" id="mkcp-pu-cards" role="group" aria-label="Kies een afhaaldatum"
                     aria-describedby="mkcp-pu-error"></div>
            </div>
            <button type="button" class="mkcp-dd-nav mkcp-dd-nav--next" id="mkcp-pu-nav-next" aria-label="Volgende data">&#8250;</button>
        </div>

        <?php
        // Altijd de locatienaam tonen (ook als er geen adres is ingevuld) — anders
        // ziet de klant helemaal geen aanduiding van welke afhaallocatie hij heeft
        // gekozen zodra het adresveld in de admin leeg is gelaten.
        $loc_address = trim( (string) ( $loc['address'] ?? '' ) );
        ?>
        <div class="mkcp-pu-location" id="mkcp-pu-location">
            <strong><?php echo esc_html( $loc['location_label'] ?? 'Afhaallocatie' ); ?></strong>
            <?php if ( $loc_address !== '' ) : ?>
            <p><?php echo nl2br( esc_html( $loc['address'] ) ); ?></p>
            <?php endif; ?>
        </div>

        <div class="mkcp-dd-confirm" id="mkcp-pu-confirm" hidden></div>

        <?php if ( ! empty( $loc['slots_enabled'] ) ) : ?>
        <div class="mkcp-pu-slots" id="mkcp-pu-slots" role="group" aria-label="Kies een tijdstip"
             aria-describedby="mkcp-pu-error" hidden>
            <span class="mkcp-pu-slots-label">Kies een tijdstip</span>
            <div class="mkcp-pu-slots-row" id="mkcp-pu-slots-row"></div>
        </div>
        <?php endif; ?>
        <input type="hidden" name="mkcp_pickup_time_slot" id="mkcp_pickup_time_slot" class="mkcp-pu-slot-field" value="">

        <input type="hidden" name="mkcp_pickup_date" id="mkcp_pickup_date" class="mkcp-pu-date-field" value="">

        <?php // Eigen dom_id ('mkcp-pu-data') zodat beide widgets, indien
              // gelijktijdig gerenderd, elk hun eigen databron hebben. ?>
        <?php echo mkcp_dd_data_div_html( $loc['rate_id'], 'mkcp-pu-data' ); ?>

        <div class="mkcp-dd-calendar" id="mkcp-pu-calendar" role="dialog" aria-label="Kies een datum" aria-hidden="true">
            <div class="mkcp-dd-cal-nav">
                <button type="button" class="mkcp-dd-cal-prev" id="mkcp-pu-cal-prev" aria-label="Vorige maand">&#8249;</button>
                <span class="mkcp-dd-cal-month-title" id="mkcp-pu-cal-month-title"></span>
                <button type="button" class="mkcp-dd-cal-next" id="mkcp-pu-cal-next" aria-label="Volgende maand">&#8250;</button>
            </div>
            <div class="mkcp-dd-cal-dow-row">
                <span>Ma</span><span>Di</span><span>Wo</span>
                <span>Do</span><span>Vr</span><span>Za</span><span>Zo</span>
            </div>
            <div class="mkcp-dd-cal-days" id="mkcp-pu-cal-days"></div>
            <div class="mkcp-dd-cal-legend" aria-hidden="true">
                <span class="mkcp-dd-cal-legend-item">
                    <span class="mkcp-dd-cal-legend-dot mkcp-dd-cal-legend-dot--today"></span> Vandaag
                </span>
                <span class="mkcp-dd-cal-legend-item">
                    <span class="mkcp-dd-cal-legend-dot mkcp-dd-cal-legend-dot--selected"></span> Geselecteerd
                </span>
            </div>
        </div>

        </div><?php // /.mkcp-dd-body-inner ?>
        </div><?php // /.mkcp-dd-body ?>

        <div class="mkcp-dd-summary" id="mkcp-pu-summary" hidden></div>

    </div>
    <?php
}


// ── Validatie ──────────────────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_process', function() {
    $loc = mkcp_pickup_active_location( mkcp_pickup_rate_id_from_post() );
    if ( ! $loc ) return;

    // Telefoon is altijd al verplicht (zie de woocommerce_billing_fields-filter
    // hieronder) — WooCommerce's eigen validatie handhaaft dat al, een eigen
    // duplicaat-melding hier is niet meer nodig.

    $date = sanitize_text_field( wp_unslash( $_POST['mkcp_pickup_date'] ?? '' ) );
    if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        wc_add_notice( __( '"Afhaaldatum" is een verplicht veld.', 'mk-cart-popup' ), 'error' );
        return;
    }

    $available = mkcp_pickup_available_dates( $loc );
    if ( ! in_array( $date, $available, true ) ) {
        wc_add_notice( __( 'De geselecteerde afhaaldatum is niet beschikbaar.', 'mk-cart-popup' ), 'error' );
        return;
    }

    if ( ! empty( $loc['slots_enabled'] ) ) {
        $slot = sanitize_text_field( wp_unslash( $_POST['mkcp_pickup_time_slot'] ?? '' ) );
        if ( empty( $slot ) || ! preg_match( '/^\d{2}:\d{2}$/', $slot ) ) {
            wc_add_notice( __( 'Kies een tijdstip om af te halen.', 'mk-cart-popup' ), 'error' );
            return;
        }

        $dow = (int) date( 'w', strtotime( $date ) );
        if ( ! in_array( $slot, mkcp_pickup_slots_for_dow( $loc, $dow ), true ) ) {
            wc_add_notice( __( 'Het geselecteerde tijdstip is niet beschikbaar.', 'mk-cart-popup' ), 'error' );
            return;
        }

        if ( ! mkcp_pickup_slot_is_reachable( $date, $slot, $loc ) ) {
            wc_add_notice( __( 'Dit tijdstip ligt te dichtbij op het bestelmoment — kies een tijdstip verder in de toekomst zodat we je bestelling kunnen voorbereiden.', 'mk-cart-popup' ), 'error' );
            return;
        }

        if ( ! empty( $loc['slot_capacity'] ) && mkcp_pickup_slot_count( $date, $slot, $loc['rate_id'] ) >= (int) $loc['slot_capacity'] ) {
            wc_add_notice( __( 'Dit tijdstip zit helaas vol, kies een ander tijdstip.', 'mk-cart-popup' ), 'error' );
        }
    }
}, 9 ); // Bezorgdatum- en afhaal-validatie draaien altijd allebei (niet meer
        // mutueel uitsluitend), elk resolvet zijn eigen rol zonder onderlinge
        // interactie. Prioriteit is puur voor een voorspelbare volgorde.


// ── Checkout: telefoon altijd verplicht ─────────────────────────────────────────
// Voorheen alleen verplicht bij afhalen, maar dat bleek onbetrouwbaar: welke
// verzendmethode "actief" was werd via meerdere, niet altijd synchrone routes
// (sessie, $_POST, live DOM) afgeleid, waardoor de melding soms niet klopte
// met het getoonde veld. Simpeler: telefoon altijd verplicht, ongeacht modus.
//
// Twee plekken nodig: deze filter regelt de eerste server-gerenderde HTML,
// maar WooCommerce's wc-address-i18n.js herstelt bij élke landwissel het veld
// o.b.v. de globale optie woocommerce_checkout_phone_field — zonder die optie
// aan te passen zou het label na een landwissel terugspringen naar "optioneel".
add_filter( 'woocommerce_billing_fields', function( $fields ) {
    if ( isset( $fields['billing_phone'] ) ) {
        $fields['billing_phone']['required'] = true;
    }
    return $fields;
} );

add_action( 'init', function() {
    if ( get_option( 'woocommerce_checkout_phone_field' ) !== 'required' ) {
        update_option( 'woocommerce_checkout_phone_field', 'required' );
    }
} );


// ── Opslaan in order meta ──────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_update_order_meta', function( $order_id ) {
    $loc = mkcp_pickup_active_location( mkcp_pickup_rate_id_from_post() );
    if ( ! $loc ) return;

    $date = sanitize_text_field( wp_unslash( $_POST['mkcp_pickup_date'] ?? '' ) );
    if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $order->update_meta_data( '_mkcp_pickup_date', $date );
    $order->update_meta_data( '_mkcp_pickup_location', $loc['method_label'] );
    $order->update_meta_data( '_mkcp_pickup_rate_id', $loc['rate_id'] );

    $slot = '';
    if ( ! empty( $loc['slots_enabled'] ) ) {
        $slot = sanitize_text_field( wp_unslash( $_POST['mkcp_pickup_time_slot'] ?? '' ) );
        if ( preg_match( '/^\d{2}:\d{2}$/', $slot ) ) {
            $order->update_meta_data( '_mkcp_pickup_slot', $slot );
        } else {
            $slot = '';
        }
    }
    $order->save();

    if ( $slot !== '' && ! empty( $loc['slot_capacity'] ) ) {
        delete_transient( 'mkcp_pu_count_' . md5( $loc['rate_id'] ) . '_' . $date . '_' . str_replace( ':', '', $slot ) );
    }
}, 5 ); // Fase 2: draait onafhankelijk van delivery-date.php's save-handler
        // (zelfde reden als bij de validatie hierboven) — prioriteit is puur
        // voor een voorspelbare volgorde.


// ── Admin bestellingenpagina ───────────────────────────────────────────────────

add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    $date = $order->get_meta( '_mkcp_pickup_date' );
    if ( ! $date ) return;

    $loc  = $order->get_meta( '_mkcp_pickup_location' );
    $slot = $order->get_meta( '_mkcp_pickup_slot' );

    echo '<p><strong>' . esc_html__( 'Afhalen', 'mk-cart-popup' ) . ':</strong><br>'
        . esc_html( mkcp_dd_format_date( $date ) ) . ( $slot ? ' — ' . esc_html( $slot ) : '' )
        . ( $loc ? '<br>' . esc_html( $loc ) : '' ) . '</p>';
} );


// ── Bedankpagina ──────────────────────────────────────────────────────────────

add_action( 'woocommerce_order_details_after_order_table', function( $order ) {
    // Vervangen door de grote bezorg-/afhaal-banner (includes/thankyou.php)
    // zodra die actief is — anders staat dezelfde info twee keer op de pagina.
    if ( function_exists( 'mkcp_thankyou_enabled' ) && mkcp_thankyou_enabled() ) return;

    $date = $order->get_meta( '_mkcp_pickup_date' );
    if ( ! $date ) return;

    $slot = $order->get_meta( '_mkcp_pickup_slot' );
    echo '<p style="margin-top:8px"><strong>' . esc_html__( 'Afhalen', 'mk-cart-popup' ) . ':</strong> '
        . esc_html( mkcp_dd_format_date( $date ) ) . ( $slot ? ', ' . esc_html( $slot ) : '' ) . '</p>';
} );


// ── E-mail ────────────────────────────────────────────────────────────────────

add_filter( 'woocommerce_email_order_meta_fields', function( $fields, $sent_to_admin, $order ) {
    $date = $order->get_meta( '_mkcp_pickup_date' );
    if ( ! $date ) return $fields;

    $slot = $order->get_meta( '_mkcp_pickup_slot' );
    $fields['mkcp_pickup_date'] = [
        'label' => __( 'Afhalen', 'mk-cart-popup' ),
        'value' => mkcp_dd_format_date( $date ) . ( $slot ? ', ' . $slot : '' ),
    ];

    return $fields;
}, 10, 3 );


// ── PDF (WP Overnight — woocommerce-pdf-invoices-packing-slips) ───────────────
//
// wpo_wcpdf_after_order_data vuurt binnen de order-data-tabel, direct ná de
// "Betaalmethode"-rij (zie dezelfde toelichting bij de bezorgdatum-variant
// in delivery-date.php) — vandaar een <tr> die dezelfde <th>/<td>-opmaak
// volgt als de omliggende rijen, i.p.v. de eerder gebruikte <div>.
mkcp_pdf_add_order_data_row( function( $document_type, $order ) {
    if ( ! $order || ! mkcp_pdf_option( 'pdf_pickup_info' ) || mkcp_pdf_option( 'pdf_headline' ) ) return;
    $date = $order->get_meta( '_mkcp_pickup_date' );
    if ( ! $date ) return;

    $rate_id = (string) $order->get_meta( '_mkcp_pickup_rate_id' );
    $loc     = function_exists( 'mkcp_pickup_location_for_rate' ) ? mkcp_pickup_location_for_rate( $rate_id ) : null;
    $label   = $loc['location_label'] ?? $order->get_meta( '_mkcp_pickup_location' ) ?: __( 'Afhaallocatie', 'mk-cart-popup' );
    $slot    = $order->get_meta( '_mkcp_pickup_slot' );

    echo '<tr class="mkcp-pickup-info"><th>' . esc_html__( 'Afhalen:', 'mk-cart-popup' ) . '</th><td>'
        . esc_html( mkcp_dd_format_date( $date ) . ( $slot ? ', ' . $slot : '' ) ) . '<br>'
        . esc_html( $label );
    if ( ! empty( $loc['address'] ) ) {
        echo '<br>' . nl2br( esc_html( $loc['address'] ) );
    }
    echo '</td></tr>';
} );


// ── Adresvelden bij afhalen: verplicht, optioneel of weg ──────────────────────
//
// Instelling "Adresvelden bij afhalen" (standaard 'required' = gedrag zoals
// altijd). 'optional': bij een afhaalmethode zijn straat, huisnummer,
// toevoeging, postcode en plaats niet verplicht. 'hidden': die velden zijn dan
// ook niet zichtbaar. De server bepaalt dat aan de hand van de geposte (bij het
// tonen: de gekozen) verzendmethode; JS past het live aan zodra de klant tussen
// bezorgen en afhalen wisselt.

function mkcp_pickup_address_fields(): array {
    return [ 'billing_postcode', 'billing_house_number', 'billing_house_number_suffix', 'billing_street_name', 'billing_city', 'billing_address_1', 'billing_address_2' ];
}

function mkcp_pickup_address_mode(): string {
    $cfg  = function_exists( 'mkcp_checkout_config' ) ? mkcp_checkout_config() : [];
    $mode = $cfg['pickup_address_mode'] ?? 'required';
    return in_array( $mode, [ 'required', 'optional', 'hidden' ], true ) ? $mode : 'required';
}

/** Is er nu een afhaalmethode gekozen (geposte methode, anders sessie)? */
function mkcp_pickup_chosen_now(): bool {
    if ( ! empty( $_POST['shipping_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return (bool) mkcp_pickup_rate_id_from_post();
    }
    $chosen = ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [];
    foreach ( $chosen as $rate ) {
        if ( is_string( $rate ) && strpos( $rate, 'local_pickup:' ) === 0 ) return true;
    }
    return false;
}

add_filter( 'woocommerce_checkout_fields', function( $fields ) {
    if ( mkcp_pickup_address_mode() === 'required' || ! mkcp_pickup_chosen_now() ) return $fields;
    foreach ( mkcp_pickup_address_fields() as $key ) {
        if ( isset( $fields['billing'][ $key ] ) ) $fields['billing'][ $key ]['required'] = false;
    }
    return $fields;
}, 1001 );

add_action( 'wp_footer', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || mkcp_pickup_address_mode() === 'required' ) return;
    $ids = mkcp_pickup_address_fields();
    ?>
    <style>
        body.mkcp-pickup-addr-hidden <?php echo implode( ', body.mkcp-pickup-addr-hidden ', array_map( function( $id ) { return '#' . $id . '_field'; }, $ids ) ); ?>,
        body.mkcp-pickup-addr-hidden .woocommerce-billing-fields .mkcp-pc-status { display: none !important; }
    </style>
    <script>
    (function () {
        var IDS  = <?php echo wp_json_encode( $ids ); ?>;
        var MODE = <?php echo wp_json_encode( mkcp_pickup_address_mode() ); ?>;
        var REQ  = [ 'billing_postcode', 'billing_house_number', 'billing_street_name', 'billing_city' ];
        var lastState = null;

        function pickupChosen() {
            var radios = document.querySelectorAll( 'input[name^="shipping_method"]' );
            for ( var i = 0; i < radios.length; i++ ) {
                var r = radios[ i ];
                if ( ( r.type === 'radio' ? r.checked : true ) && String( r.value ).indexOf( 'local_pickup:' ) === 0 ) return true;
            }
            return false;
        }

        function apply() {
            var pickup = pickupChosen();
            if ( pickup === lastState ) return;
            lastState = pickup;
            // De afhaalopties verschijnen pas nadat de klant postcode + huisnummer
            // heeft ingevuld ("Vul hierboven eerst je postcode en huisnummer in"),
            // dus bij afhalen zijn de velden altijd al gevuld en kunnen weg.
            document.body.classList.toggle( 'mkcp-pickup-addr-hidden', pickup && MODE === 'hidden' );
            IDS.forEach( function ( id ) {
                var row = document.getElementById( id + '_field' );
                if ( ! row ) return;
                var star = row.querySelector( 'abbr.required' );
                if ( row.dataset.mkcpWasRequired === undefined ) {
                    // Pagina al op afhalen geladen: de server gaf dan geen sterretje mee.
                    row.dataset.mkcpWasRequired = ( star || ( pickup && REQ.indexOf( id ) !== -1 ) ) ? '1' : '0';
                }
                var wasReq = row.dataset.mkcpWasRequired === '1';
                row.classList.toggle( 'validate-required', ! pickup && wasReq );
                if ( ! pickup && wasReq && ! star ) {
                    var label = row.querySelector( 'label' );
                    if ( label ) {
                        var opt = label.querySelector( '.optional' );
                        if ( opt ) opt.remove();
                        star = document.createElement( 'abbr' );
                        star.className = 'required';
                        star.title = 'verplicht';
                        star.textContent = '*';
                        label.appendChild( document.createTextNode( ' ' ) );
                        label.appendChild( star );
                    }
                }
                if ( star ) star.style.display = pickup ? 'none' : '';
                var input = row.querySelector( 'input, select' );
                if ( input && wasReq ) input.setAttribute( 'aria-required', pickup ? 'false' : 'true' );
            } );
        }

        // Meerdere routes: het wisselen tussen bezorgen en afhalen gebeurt ook via
        // eigen tabs/kaarten die de keuze zetten zonder een native change-event
        // (jQuery .trigger('change') bereikt addEventListener niet). Daarom
        // jQuery-delegatie + native events + updated_checkout + een lichte controle.
        document.addEventListener( 'change', apply, true );
        document.addEventListener( 'click', function () { setTimeout( apply, 0 ); }, true );
        if ( window.jQuery ) {
            jQuery( document.body ).on( 'change click', 'input[name^="shipping_method"]', apply );
            jQuery( document.body ).on( 'updated_checkout', apply );
        }
        setInterval( apply, 400 );
        apply();
    })();
    </script>
    <?php
}, 40 );


// ── "Verzenden naar een ander adres?" verbergen bij afhalen ───────────────────
//
// Bij afhalen wordt er niets verzonden, dus de vraag of de bestelling naar een
// ander adres moet is dan onzinnig. Zodra een afhaalmethode gekozen is: het
// vinkje + de bijbehorende adresvelden verbergen en het vinkje uitzetten (zodat
// een eerder ingevuld ander adres niet alsnog wordt meegestuurd); terug naar
// bezorgen toont alles weer. De server negeert een meegestuurd "ander adres"
// bij afhalen ook, mocht er zonder JS toch iets binnenkomen.
add_action( 'woocommerce_checkout_process', function() {
    if ( ! mkcp_pickup_rate_id_from_post() ) return;
    unset( $_POST['ship_to_different_address'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}, 0 );

add_action( 'wp_footer', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    ?>
    <style>
        body.mkcp-pickup-chosen #ship-to-different-address,
        body.mkcp-pickup-chosen .shipping_address { display: none !important; }
    </style>
    <script>
    (function () {
        var lastState = null;
        function pickupChosen() {
            var radios = document.querySelectorAll( 'input[name^="shipping_method"]' );
            for ( var i = 0; i < radios.length; i++ ) {
                var r = radios[ i ];
                if ( ( r.type === 'radio' ? r.checked : true ) && String( r.value ).indexOf( 'local_pickup:' ) === 0 ) return true;
            }
            return false;
        }
        function apply() {
            var pickup = pickupChosen();
            if ( pickup === lastState ) return;
            lastState = pickup;
            document.body.classList.toggle( 'mkcp-pickup-chosen', pickup );
            if ( pickup ) {
                var cb = document.getElementById( 'ship-to-different-address-checkbox' );
                if ( cb && cb.checked ) {
                    cb.checked = false;
                    if ( window.jQuery ) jQuery( cb ).trigger( 'change' );
                }
            }
        }
        document.addEventListener( 'change', apply, true );
        document.addEventListener( 'click', function () { setTimeout( apply, 0 ); }, true );
        if ( window.jQuery ) {
            jQuery( document.body ).on( 'change click', 'input[name^="shipping_method"]', apply );
            jQuery( document.body ).on( 'updated_checkout', apply );
        }
        setInterval( apply, 400 );
        apply();
    })();
    </script>
    <?php
}, 41 );


// ── Half verzendadres bij afhalen opruimen ────────────────────────────────────
//
// Bij afhalen wist het thema het verzendadres, waarna de postcode-checker er
// alleen straat + huisnummer weer in zet ("Teststraat 12" zonder postcode en
// plaats). Een half adres is erger dan geen adres: als de rest van het
// verzendadres leeg is, ook die regel leegmaken. (Het factuuradres blijft.)
add_action( 'woocommerce_checkout_order_processed', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $is_pickup = false;
    foreach ( $order->get_shipping_methods() as $line ) {
        if ( strpos( (string) $line->get_method_id(), 'local_pickup' ) === 0 ) { $is_pickup = true; break; }
    }
    if ( ! $is_pickup ) return;
    if ( $order->get_shipping_postcode() !== '' || $order->get_shipping_city() !== '' ) return;
    if ( $order->get_shipping_address_1() === '' && $order->get_shipping_address_2() === '' ) return;

    $order->set_shipping_address_1( '' );
    $order->set_shipping_address_2( '' );
    $order->save();
}, 999 );
