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
 * Bezorgdatum en afhalen zijn wederzijds uitsluitend: welke van de twee
 * getoond wordt, hangt af van de op dit moment gekozen verzendmethode. De
 * daadwerkelijke rendering/validatie-hooks van de bezorgdatum-kiezer
 * (delivery-date.php) checken zelf of afhalen actief is en wijken dan uit —
 * zie de "pickup-guard" op die hooks.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Helpers ────────────────────────────────────────────────────────────────────

function mkcp_pickup_feature_enabled(): bool {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;
    return ! empty( mkcp_checkout_config()['pickup_enabled'] );
}

/**
 * Alle WooCommerce-verzendmethodes van het type "Local pickup" (rate_id
 * begint met "local_pickup:"), als [ rate_id => label ] — dit is bewust een
 * subset van mkcp_dd_get_shipping_methods() (die ALLE methodes teruggeeft,
 * incl. flat_rate/free_shipping). Een "flat rate"- of "gratis verzending"-
 * methode kan nooit een afhaallocatie zijn, dus die horen niet in deze
 * lijst — anders is het te makkelijk om per ongeluk een gewone verzend-
 * methode als afhaallocatie aan te vinken, waardoor er voor klanten die
 * gewoon willen laten bezorgen ineens geen bezorgdatum meer verschijnt.
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

    // Klantgerichte weergavenaam (admin-instelbaar per locatie, bv. "Afhaallocatie
    // Buitengebied") i.p.v. de technische WooCommerce-verzendmethodenaam, die vaak
    // interne jargon bevat ("Lokaal afhalen — Buitengebied (verse bloemen)") dat de
    // klant niks zegt. method_label blijft ongewijzigd staan (order-meta/admin/e-mail
    // blijven de echte verzendmethodenaam tonen, handig om locaties te onderscheiden).
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
 * Zelfde soort resolutie als mkcp_dd_current_rate_id(), maar leest eerst
 * $_POST['shipping_method'] (aanwezig tijdens checkout-submit/AJAX-refresh,
 * betrouwbaarder dan de sessie op dat exacte moment) met de sessie als fallback.
 */
function mkcp_pickup_rate_id_from_post(): ?string {
    $posted = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['shipping_method'] ?? [] ) ) );
    $rate   = function_exists( 'mkcp_dd_first_rate_id' ) ? mkcp_dd_first_rate_id( $posted ) : null;
    return $rate ?? ( function_exists( 'mkcp_dd_current_rate_id' ) ? mkcp_dd_current_rate_id() : null );
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
// NB: gehaakt vanuit mkcp_dd_render_field() in delivery-date.php (die als
// eerste checkt of afhalen actief is) — niet apart op woocommerce_review_order_before_submit,
// om dubbele/verkeerd-geordende output te voorkomen.

function mkcp_pickup_render_field( array $loc ) {
    $dates = mkcp_pickup_available_dates( $loc );

    if ( empty( $dates ) ) {
        mkcp_dd_render_empty_state( 'Afhaaldatum', true );
        return;
    }

    $chips      = array_slice( $dates, 0, 6 );
    $days_short = [ 'Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za' ];
    ?>
    <div class="mkcp-dd-wrap mkcp-pu-wrap" id="mkcp-dd-wrap">

        <?php // Zelfde statische, altijd-aanwezige foutmeld-container als de
              // bezorgdatum-variant (includes/delivery-date.php) — delivery-date.js
              // vult 'm bij beide modi op dezelfde manier. ?>
        <div id="mkcp-dd-error" class="mkcp-dd-error" role="alert" hidden></div>

        <div class="mkcp-dd-header">
            <span class="mkcp-dd-label">
                Afhaaldatum <abbr class="required" title="verplicht veld">*</abbr>
            </span>
        </div>

        <p class="mkcp-dd-microcopy" id="mkcp-dd-microcopy" aria-hidden="true"></p>

        <div class="mkcp-dd-chips-row" id="mkcp-dd-chips" role="group" aria-label="Kies een afhaaldatum"
             aria-describedby="mkcp-dd-error">
            <?php foreach ( $chips as $ymd ) :
                $ts  = strtotime( $ymd );
                $dow = (int) date( 'w', $ts );
                $day = (int) date( 'j', $ts );
            ?>
            <button type="button" class="mkcp-dd-chip" data-date="<?php echo esc_attr( $ymd ); ?>">
                <span class="mkcp-dd-chip-day"><?php echo esc_html( $days_short[ $dow ] ); ?></span>
                <span class="mkcp-dd-chip-num"><?php echo $day; ?></span>
            </button>
            <?php endforeach; ?>

            <button type="button" class="mkcp-dd-chip mkcp-dd-chip--cal" id="mkcp-dd-cal-btn"
                    aria-label="Kalender openen" aria-haspopup="dialog" aria-expanded="false">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8"  y1="2" x2="8"  y2="6"/>
                    <line x1="3"  y1="10" x2="21" y2="10"/>
                </svg>
            </button>
        </div>

        <div class="mkcp-dd-track" id="mkcp-dd-track">
            <button type="button" class="mkcp-dd-nav mkcp-dd-nav--prev" id="mkcp-dd-nav-prev" aria-label="Vorige data">&#8249;</button>
            <div class="mkcp-dd-cards-viewport" id="mkcp-dd-cards-viewport">
                <div class="mkcp-dd-cards-list" id="mkcp-dd-cards"></div>
            </div>
            <button type="button" class="mkcp-dd-nav mkcp-dd-nav--next" id="mkcp-dd-nav-next" aria-label="Volgende data">&#8250;</button>
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

        <div class="mkcp-dd-confirm" id="mkcp-dd-confirm" hidden></div>

        <?php if ( ! empty( $loc['slots_enabled'] ) ) : ?>
        <div class="mkcp-pu-slots" id="mkcp-dd-slots" role="group" aria-label="Kies een tijdstip"
             aria-describedby="mkcp-dd-error" hidden>
            <span class="mkcp-pu-slots-label">Kies een tijdstip</span>
            <div class="mkcp-pu-slots-row" id="mkcp-dd-slots-row"></div>
        </div>
        <?php /* Generieke veldnaam (niet "pickup"-specifiek): bezorgdatum-tijdsloten
                 (zie includes/delivery-date.php) gebruiken hetzelfde veld — de twee
                 modi zijn wederzijds uitsluitend, dus delen ze zonder conflict één
                 hidden input. Elke kant slaat 'm op onder zijn eigen order-meta-key. */ ?>
        <input type="hidden" name="mkcp_time_slot" id="mkcp_time_slot" value="">
        <?php endif; ?>

        <input type="hidden" name="mkcp_delivery_date" id="mkcp_delivery_date" value="">

        <?php // Volledige attributenset (niet alleen dates/rate-id) — zie
              // mkcp_dd_data_div_html() in includes/delivery-date.php voor
              // waarom dit moet matchen met wat de AJAX-fragment-filter bouwt. ?>
        <?php echo mkcp_dd_data_div_html( $loc['rate_id'] ); ?>

        <div class="mkcp-dd-calendar" id="mkcp-dd-calendar" role="dialog" aria-label="Kies een datum" aria-hidden="true">
            <div class="mkcp-dd-cal-nav">
                <button type="button" class="mkcp-dd-cal-prev" id="mkcp-dd-cal-prev" aria-label="Vorige maand">&#8249;</button>
                <span class="mkcp-dd-cal-month-title" id="mkcp-dd-cal-month-title"></span>
                <button type="button" class="mkcp-dd-cal-next" id="mkcp-dd-cal-next" aria-label="Volgende maand">&#8250;</button>
            </div>
            <div class="mkcp-dd-cal-dow-row">
                <span>Ma</span><span>Di</span><span>Wo</span>
                <span>Do</span><span>Vr</span><span>Za</span><span>Zo</span>
            </div>
            <div class="mkcp-dd-cal-days" id="mkcp-dd-cal-days"></div>
            <div class="mkcp-dd-cal-legend" aria-hidden="true">
                <span class="mkcp-dd-cal-legend-item">
                    <span class="mkcp-dd-cal-legend-dot mkcp-dd-cal-legend-dot--today"></span> Vandaag
                </span>
                <span class="mkcp-dd-cal-legend-item">
                    <span class="mkcp-dd-cal-legend-dot mkcp-dd-cal-legend-dot--selected"></span> Geselecteerd
                </span>
            </div>
        </div>

        <div class="mkcp-dd-summary" id="mkcp-dd-summary" hidden></div>

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

    $date = sanitize_text_field( wp_unslash( $_POST['mkcp_delivery_date'] ?? '' ) );
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
        $slot = sanitize_text_field( wp_unslash( $_POST['mkcp_time_slot'] ?? '' ) );
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
}, 9 ); // vóór de reguliere bezorgdatum-validatie (prioriteit 10) — zonder effect op elkaar, want gebaseerd op verschillende rate_id's, maar zo blijft de volgorde voorspelbaar


// ── Checkout: telefoon altijd verplicht ─────────────────────────────────────────
// Voorheen alleen verplicht bij afhalen (op basis van de gekozen verzendmethode),
// maar die voorwaarde bleek onbetrouwbaar: een klant die van afhalen terugschakelde
// naar bezorgen kon de melding "Telefoon is een vereist veld" krijgen terwijl het
// veld zelf al als optioneel werd getoond, omdat de op dat moment gekozen
// verzendmethode via meerdere, niet altijd synchrone routes (sessie, $_POST,
// live DOM-status) werd afgeleid. Simpeler en betrouwbaarder: telefoon gewoon
// altijd verplicht, ongeacht afhalen/bezorgen — dan klopt de melding altijd.
//
// Twee plekken nodig, niet één: deze filter regelt de EERSTE server-gerenderde
// HTML. Maar WooCommerce's eigen wc-address-i18n.js herstelt bij élke land-
// wissel het veld naar verplicht/optioneel op basis van de globale instelling
// woocommerce_checkout_phone_field ('optional' hier) — die data staat los van
// get_checkout_fields()/deze filter, dus zonder de option zelf aan te passen
// zou het label na een landwissel alsnog terugspringen naar "optioneel".
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

    $date = sanitize_text_field( wp_unslash( $_POST['mkcp_delivery_date'] ?? '' ) );
    if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $order->update_meta_data( '_mkcp_pickup_date', $date );
    $order->update_meta_data( '_mkcp_pickup_location', $loc['method_label'] );
    $order->update_meta_data( '_mkcp_pickup_rate_id', $loc['rate_id'] );

    $slot = '';
    if ( ! empty( $loc['slots_enabled'] ) ) {
        $slot = sanitize_text_field( wp_unslash( $_POST['mkcp_time_slot'] ?? '' ) );
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
}, 5 ); // vóór de reguliere bezorgdatum-meta-save (prioriteit 10) — zelfde reden als hierboven


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
add_action( 'wpo_wcpdf_after_order_data', function( $document_type, $order ) {
    if ( ! $order ) return;
    $date = $order->get_meta( '_mkcp_pickup_date' );
    if ( ! $date ) return;

    $rate_id = (string) $order->get_meta( '_mkcp_pickup_rate_id' );
    $loc     = function_exists( 'mkcp_pickup_location_for_rate' ) ? mkcp_pickup_location_for_rate( $rate_id ) : null;
    $label   = $loc['location_label'] ?? $order->get_meta( '_mkcp_pickup_location' ) ?: __( 'Afhaallocatie', 'mk-cart-popup' );
    $slot    = $order->get_meta( '_mkcp_pickup_slot' );

    echo '<tr class="mkcp-pickup-info"><th>' . esc_html__( 'Afhalen', 'mk-cart-popup' ) . '</th><td>'
        . esc_html( mkcp_dd_format_date( $date ) . ( $slot ? ', ' . $slot : '' ) ) . '<br>'
        . esc_html( $label );
    if ( ! empty( $loc['address'] ) ) {
        echo '<br>' . nl2br( esc_html( $loc['address'] ) );
    }
    echo '</td></tr>';
}, 10, 2 );
