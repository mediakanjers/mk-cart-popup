<?php
/**
 * MK Cart Popup — Bezorgdatum kiezer (premium)
 *
 * Toont een bol.com-stijl datumpicker op de checkout pagina.
 * Klant kiest een voorkeursdatum; datum wordt opgeslagen in order meta,
 * getoond in de bevestigingsmail, de bedankpagina en de WP-admin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Helpers ────────────────────────────────────────────────────────────────────

function mkcp_dd_enabled(): bool {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;
    return ! empty( mkcp_checkout_config()['delivery_date_enabled'] );
}

/**
 * Zoekt de rate-ID (bv. "flat_rate:2") van de op dit moment door de klant
 * gekozen verzendmethode op.
 *
 * LET OP — beperking bij meerdere verzendpakketten (split-verzending, bv.
 * een deel van de order als abonnement): er is in deze plugin maar één
 * bezorgdatum per order, niet per pakket. We pakken daarom de eerst gekozen
 * methode over alle pakketten heen (meestal package 0; de fallback dekt het
 * geval dat pakket 0 toevallig nog geen keuze heeft). Wil je écht een eigen
 * datum per pakket, dan is dat een aparte, grotere uitbreiding.
 */
function mkcp_dd_current_rate_id(): ?string {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) return null;
    $chosen = (array) WC()->session->get( 'chosen_shipping_methods', [] );
    return mkcp_dd_first_rate_id( $chosen );
}

/**
 * Geeft de eerste niet-lege rate-ID uit een lijst gekozen verzendmethodes
 * (session-array of $_POST['shipping_method']) — gedeelde logica zodat
 * session- en POST-gebaseerde lookups hetzelfde (multi-package-bestendige)
 * gedrag hebben.
 */
function mkcp_dd_first_rate_id( array $methods ): ?string {
    foreach ( $methods as $rate ) {
        if ( is_string( $rate ) && $rate !== '' ) return $rate;
    }
    return null;
}

/**
 * Fase 2: zoekt binnen $_POST['shipping_method'] (over ALLE verzendpakketten
 * heen, niet alleen pakket 0) de eerste rate die bij de gevraagde rol hoort —
 * 'delivery' (alles wat niet met "local_pickup:" begint) of 'pickup'. Rol-
 * gebaseerd i.p.v. generiek per-pakket: een order heeft nooit meer dan één
 * actieve bezorg-rate en één actieve afhaal-rate tegelijk (zie
 * project-memory over de Fase 2-scope-beslissing), dus dit is voldoende om
 * de validatie/opslag van bezorgdatum.php en pickup.php onafhankelijk van
 * elkaar te laten werken, ongeacht welke pakket-index welke rol heeft.
 *
 * Vervangt de oude "$_POST['shipping_method'][0]"-aanname, die altijd
 * package 0 als "de" gekozen methode behandelde.
 */
function mkcp_dd_role_rate_id_from_post( string $role ): ?string {
    $posted = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['shipping_method'] ?? [] ) ) );

    foreach ( $posted as $rate_id ) {
        if ( ! is_string( $rate_id ) || $rate_id === '' ) continue;
        $is_pickup = strpos( $rate_id, 'local_pickup:' ) === 0;
        if ( ( 'pickup' === $role ) === $is_pickup ) return $rate_id;
    }

    return null;
}

/**
 * Alle verzendmethodes (over alle zones + "rest van de wereld") als
 * [ rate_id => leesbaar label ], voor gebruik in de admin-UI en om
 * $rate_id-input te valideren. rate_id = "{method_id}:{instance_id}",
 * exact hetzelfde formaat als WooCommerce gebruikt in chosen_shipping_methods
 * en $_POST['shipping_method'].
 */
function mkcp_dd_get_shipping_methods(): array {
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return [];

    $out      = [];
    $zone_ids = array_keys( WC_Shipping_Zones::get_zones() );
    $zone_ids[] = 0; // Rest van de wereld (catch-all).

    foreach ( $zone_ids as $zone_id ) {
        $zone = new WC_Shipping_Zone( (int) $zone_id );
        $zone_name = $zone->get_zone_name();
        if ( $zone_name === '' ) $zone_name = __( 'Rest van de wereld', 'mk-cart-popup' );

        foreach ( $zone->get_shipping_methods( true ) as $instance_id => $method ) {
            $rate_id = $method->id . ':' . $instance_id;
            $out[ $rate_id ] = sprintf( '%s — %s', $method->get_title(), $zone_name );
        }
    }
    return $out;
}

/**
 * Telt hoeveel (niet-geannuleerde/mislukte) bestellingen al gekoppeld zijn
 * aan een bezorgdatum. Gebruikt wc_get_orders() (WC_Order_Query) i.p.v.
 * rechtstreekse SQL, zodat dit ook op HPOS-installaties (High-Performance
 * Order Storage) blijft werken.
 *
 * Twee caching-lagen:
 *   1. Per-request (static array) — voorkomt dubbele queries binnen één
 *      beschikbaarheidsberekening (die per datum wordt aangeroepen).
 *   2. Transient, 45s — bij de capaciteitslimiet wordt deze functie bij elke
 *      checkout-AJAX-refresh opnieuw aangeroepen voor alle zichtbare datums;
 *      zonder deze laag zou elke klant die de checkout ververst (adres/
 *      verzendmethode wijzigt) een verse databasequery per datum triggeren.
 *      45s is ruim genoeg om de load te dempen, maar kort genoeg dat de
 *      capaciteitslimiet niet merkbaar "achterloopt". Wordt bovendien direct
 *      geleegd zodra een order met die datum wordt opgeslagen (zie
 *      woocommerce_checkout_update_order_meta hieronder).
 */
function mkcp_dd_orders_count_for_date( string $ymd ): int {
    if ( ! function_exists( 'wc_get_orders' ) ) return 0;

    static $cache = [];
    if ( isset( $cache[ $ymd ] ) ) return $cache[ $ymd ];

    $transient_key = 'mkcp_dd_count_' . $ymd;
    $cached        = get_transient( $transient_key );
    if ( $cached !== false ) {
        $cache[ $ymd ] = (int) $cached;
        return $cache[ $ymd ];
    }

    $excluded = [ 'wc-cancelled', 'wc-failed', 'wc-trash' ];
    $statuses = array_values( array_diff( array_keys( wc_get_order_statuses() ), $excluded ) );

    $ids = wc_get_orders( [
        'limit'      => -1,
        'return'     => 'ids',
        'status'     => $statuses,
        'meta_key'   => '_mkcp_delivery_date',
        'meta_value' => $ymd,
    ] );

    $count = is_array( $ids ) ? count( $ids ) : 0;
    set_transient( $transient_key, $count, 45 );

    $cache[ $ymd ] = $count;
    return $cache[ $ymd ];
}

/**
 * Berekent welke Y-m-d datums beschikbaar zijn, rekening houdend met:
 *   – cutoff-tijd (voor die tijd = zelfde dag verstuurd, daarna +1 dag)
 *   – lead days (minimale aanlooptijd in dagen)
 *   – verzenddagen (weekdagen waarop er verstuurd wordt)
 *   – geblokkeerde datums
 *   – optionele eigen regels per verzendmethode ($rate_id)
 *   – optionele capaciteitslimiet (max. bestellingen per dag)
 *
 * @param string|null $rate_id Rate-ID van de gekozen verzendmethode
 *                              (bv. "flat_rate:2"). Null = geen methode
 *                              bekend, dan gelden altijd de algemene regels.
 */
/**
 * Lost de effectieve regels op voor een verzendmethode: eigen regels
 * (indien de admin die voor deze $rate_id heeft ingeschakeld) overschrijven
 * de algemene instellingen. Losgetrokken uit mkcp_dd_available_dates() zodat
 * de front-end (voor de "waarom niet beschikbaar"-tooltip) dezelfde
 * verzenddagen/geblokkeerde-datums te zien krijgt als de server gebruikt.
 */
function mkcp_dd_effective_rule( ?string $rate_id, array $cfg ): array {
    $rule = null;
    if ( $rate_id !== null ) {
        $rules = (array) ( $cfg['delivery_date_shipping_rules'] ?? [] );
        if ( ! empty( $rules[ $rate_id ]['enabled'] ) ) {
            $rule = $rules[ $rate_id ];
        }
    }

    return [
        'cutoff_time'   => (string) ( $rule['cutoff_time']    ?? $cfg['delivery_date_cutoff_time']     ?? '12:00' ),
        'lead_days'     => max( 0, (int) ( $rule['lead_days']  ?? $cfg['delivery_date_lead_days']       ?? 1  ) ),
        'shipping_days' => array_map( 'intval', (array) ( $rule['shipping_days'] ?? $cfg['delivery_date_shipping_days'] ?? [ 1, 2, 3, 4, 5, 6 ] ) ),
        'blackout_dates'=> (array) ( $cfg['delivery_date_blackout_dates'] ?? [] ),
        // Bezorg-tijdsloten: uitsluitend een per-verzendmethode override (zie
        // mkcp_sanitize_dd_shipping_rules()) — er is bewust geen algemene
        // aan/uit-instelling, want alleen methodes waarbij de shop zelf
        // rondbrengt (niet een vervoerder als PostNL) kunnen een tijdstip
        // beloven. Geen ingeschakelde regel voor dit rate_id? Dan altijd uit.
        'slots_enabled' => ! empty( $rule['slots_enabled'] ),
        'window_start'  => (string) ( $rule['window_start'] ?? '09:00' ),
        'window_end'    => (string) ( $rule['window_end']   ?? '17:00' ),
        'slot_minutes'  => max( 5, (int) ( $rule['slot_minutes'] ?? 60 ) ),
        'slot_capacity' => max( 0, (int) ( $rule['slot_capacity'] ?? 0 ) ),
        'prep_minutes'  => max( 0, (int) ( $rule['prep_minutes']  ?? 60 ) ),
    ];
}

/**
 * Tijdsloten voor een bezorgmethode, als lijst starttijden "HH:MM" — één vast
 * venster per methode (niet per weekdag zoals bij afhalen: welke dagen er
 * bezorgd wordt, bepaalt 'shipping_days' al; het bezorgvenster zelf is meestal
 * elke bezorgdag hetzelfde, dus geen aparte openingstijden-grid nodig).
 */
function mkcp_dd_slots_for_rule( array $rule ): array {
    if ( empty( $rule['slots_enabled'] ) ) return [];
    return mkcp_generate_time_slots( $rule['window_start'], $rule['window_end'], (int) $rule['slot_minutes'] );
}

/**
 * Zelfde vorm als mkcp_pickup_slots_by_dow() (alle 7 dagen gevuld, ook al is
 * het venster identiek voor elke dag) — assets/delivery-date.js verwacht deze
 * structuur ongeacht welke modus (afhalen/bezorgen) actief is.
 */
function mkcp_dd_slots_by_dow( array $rule ): array {
    $slots = mkcp_dd_slots_for_rule( $rule );
    $out = [];
    for ( $dow = 0; $dow <= 6; $dow++ ) $out[ $dow ] = $slots;
    return $out;
}

function mkcp_dd_slot_count( string $ymd, string $slot, string $rate_id ): int {
    return mkcp_slot_count( $ymd, $slot, $rate_id, '_mkcp_delivery_date', '_mkcp_delivery_slot', '_mkcp_delivery_slot_rate' );
}

/**
 * Geeft het cutoff-moment van vandaag terug als epoch-milliseconden (UTC-
 * gebaseerd, dus tijdzone-onafhankelijk te vergelijken met JS' Date.now()).
 *
 * De front-end mag dit NIET zelf herberekenen met de lokale browser-tijdzone
 * (new Date().setHours(...)) — als de sitetijdzone (wp_timezone_string())
 * afwijkt van de tijdzone van de bezoeker, zou dat "cutoff verstreken" op een
 * ander moment laten zien dan de server daadwerkelijk hanteert, waardoor de
 * datumlijst nooit ververst (de vergelijking in refreshDatesFromFragment ziet
 * dan geen verschil t.o.v. de al bekende datums). Eén bron van waarheid: PHP.
 */
function mkcp_dd_cutoff_timestamp( string $cutoff_time, DateTimeZone $tz, ?DateTime $now = null ): int {
    $now = $now ?? new DateTime( 'now', $tz );
    [ $ch, $cm ] = array_pad( explode( ':', $cutoff_time, 2 ), 2, '0' );
    $cutoff_dt = clone $now;
    $cutoff_dt->setTime( (int) $ch, (int) $cm, 0 );

    return $cutoff_dt->getTimestamp() * 1000;
}

function mkcp_dd_available_dates( ?string $rate_id = null ): array {
    $cfg  = mkcp_checkout_config();
    $rule = mkcp_dd_effective_rule( $rate_id, $cfg );

    $cutoff   = $rule['cutoff_time'];
    $lead     = $rule['lead_days'];
    $range    = max( 7, (int) ( $cfg['delivery_date_calendar_range'] ?? 60 ) );
    $ship_dow = $rule['shipping_days'];
    $blackout = $rule['blackout_dates'];

    $capacity_on  = ! empty( $cfg['delivery_date_capacity_enabled'] );
    $capacity_max = max( 1, (int) ( $cfg['delivery_date_capacity_max'] ?? 20 ) );

    $tz  = new DateTimeZone( wp_timezone_string() );
    $now = new DateTime( 'now', $tz );

    $cutoff_dt = clone $now;
    $cutoff_dt->setTimestamp( (int) ( mkcp_dd_cutoff_timestamp( $cutoff, $tz, $now ) / 1000 ) );

    // Na de cutoff-tijd schuift de eerste mogelijke dag 1 dag op.
    $days_ahead = $lead + ( $now >= $cutoff_dt ? 1 : 0 );
    $start      = clone $now;
    if ( $days_ahead > 0 ) {
        $start->modify( '+' . $days_ahead . ' days' );
    }
    $start->setTime( 0, 0, 0 );

    $available = [];
    $end       = clone $now;
    // Ruimere buffer dan alleen +7 dagen: de capaciteitslimiet kan losse
    // datums wegfilteren, dus er moet meer ruimte zijn om alsnog $range
    // datums te vinden.
    $end->modify( '+' . ( $range + 30 ) . ' days' );

    $cursor = clone $start;
    while ( $cursor <= $end && count( $available ) < $range ) {
        $dow = (int) $cursor->format( 'w' ); // 0 = zondag, 6 = zaterdag
        $ymd = $cursor->format( 'Y-m-d' );
        if ( in_array( $dow, $ship_dow, true ) && ! in_array( $ymd, $blackout, true ) ) {
            if ( ! $capacity_on || mkcp_dd_orders_count_for_date( $ymd ) < $capacity_max ) {
                $available[] = $ymd;
            }
        }
        $cursor->modify( '+1 day' );
    }

    /**
     * Filter: mkcp_dd_available_dates
     *
     * Laat externe code (thema of andere plugin) de lijst met beschikbare
     * bezorgdatums verder aanpassen — bijvoorbeeld datums uitsluiten op
     * basis van voorraad, een externe feestdagen-/vakantie-API, of
     * carrier-capaciteit die niet via de admin-instellingen te sturen is.
     * De admin-instelling "Geblokkeerde datums" dekt alleen een statische
     * lijst; deze hook is voor dynamische/programmatische uitsluitingen.
     *
     * Voorbeeld (in een thema's functions.php):
     *
     *     add_filter( 'mkcp_dd_available_dates', function( $dates, $rate_id ) {
     *         return array_values( array_diff( $dates, [ '2026-12-24' ] ) );
     *     }, 10, 2 );
     *
     * @param string[]    $available Beschikbare datums, Y-m-d, gesorteerd.
     * @param string|null $rate_id   Rate-ID van de gekozen verzendmethode
     *                                (bv. "flat_rate:2"), of null.
     */
    return apply_filters( 'mkcp_dd_available_dates', $available, $rate_id );
}

/**
 * Geeft een Nederlandse volledige datumopmaak terug, bijv. "Vrijdag 4 juli 2026".
 */
function mkcp_dd_format_date( string $ymd ): string {
    static $days_nl   = [ 'Zondag', 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag' ];
    static $months_nl = [ 1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni',
                          'juli', 'augustus', 'september', 'oktober', 'november', 'december' ];

    $ts = strtotime( $ymd );
    if ( ! $ts ) return $ymd;

    return sprintf( '%s %d %s %d',
        $days_nl[ (int) date( 'w', $ts ) ],
        (int) date( 'j', $ts ),
        $months_nl[ (int) date( 'n', $ts ) ],
        (int) date( 'Y', $ts )
    );
}


// ── Assets op checkout pagina ──────────────────────────────────────────────────

// Fase 2: geen wp_localize_script meer — met twee mogelijk-gelijktijdige
// widgets (bezorgen + afhalen) zou dat een aparte "welke rol(len) zijn actief"-
// administratie vereisen die al bestaat, namelijk: staat het bijbehorende
// data-eilandje (#mkcp-dd-data resp. #mkcp-pu-data) in de DOM? assets/
// delivery-date.js leest daarom zijn volledige config rechtstreeks uit dat
// element (zie mkcp_dd_data_div_html()), zowel bij de eerste paginalaad als
// na elke AJAX-refresh — dezelfde bron, geen aparte synchronisatie nodig.
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_checkout() ) return;
    if ( ! mkcp_dd_enabled() && ! ( function_exists( 'mkcp_pickup_feature_enabled' ) && mkcp_pickup_feature_enabled() ) ) return;

    wp_enqueue_style(
        'mkcp-delivery-date',
        MKCP_URL . 'assets/delivery-date.css',
        [],
        MKCP_VER
    );

    wp_enqueue_script(
        'mkcp-delivery-date',
        MKCP_URL . 'assets/delivery-date.js',
        [ 'jquery' ],
        MKCP_VER,
        true
    );
} );

// Leeg, JS-gevuld eindsamenvattingsblok direct boven de bestelknop —
// bundelt de bezorg- én afhaal-samenvatting op één plek zodat je niet terug
// hoeft te scrollen naar de losse pakket-widgets om je hele keuze te
// checken. delivery-date.js vult/toont 'm alleen zodra er daadwerkelijk 2
// widgets tegelijk actief zijn (bij 1 widget is de eigen samenvattingsregel
// er vlak boven al genoeg, dat zou dubbelop zijn). Prioriteit 20: ná de
// eventuele betaalmethode-content die op de standaardprioriteit hangt, dus
// vlak boven de knop.
add_action( 'woocommerce_review_order_before_submit', function() {
    if ( ! is_checkout() ) return;
    if ( ! mkcp_dd_enabled() && ! ( function_exists( 'mkcp_pickup_feature_enabled' ) && mkcp_pickup_feature_enabled() ) ) return;
    echo '<div class="mkcp-dd-final-summary" id="mkcp-dd-final-summary" hidden></div>';
}, 20 );


// ── Databron voor de kiezer(s) ──────────────────────────────────────────────────
//
// Fase 2: geen aparte woocommerce_update_order_review_fragments-filter meer
// die alleen het #mkcp-dd-data-eilandje ververst. Sinds de kiezer(s) per
// pakket rechtstreeks binnen templates/cart-shipping-choice.php renderen
// (mkcp_dd_render_delivery_field()/mkcp_pickup_render_field(), zie hieronder),
// wordt de VOLLEDIGE widget (inclusief dit data-element) al bij elke AJAX-
// refresh vers meegerenderd via de bestaande fragment-mechanismen van
// shipping-choice.php (het normale #order_review-fragment, of het losse
// #shipping-choice-ajax-anchor-fragment bij de 3-blokken layout) — een aparte
// registratie hier zou een tweede, overlappend ververs-mechanisme zijn.
// assets/delivery-date.js leest dit element puur uit als databron (geen apart
// wp_localize_script-object meer nodig): elke instantie (bezorgen/afhalen)
// zoekt zijn EIGEN, vaste dom_id op ('mkcp-dd-data' resp. 'mkcp-pu-data') en
// bestaat pas als dat element ook echt in de DOM staat — zo weet de JS vanzelf
// welke van de twee rollen op dit moment relevant zijn, zonder een aparte
// aanwezigheids-vlag te hoeven bijhouden.

/**
 * Bouwt het volledige data-element (alle data-* attributen: dates, cutoff,
 * sloten, pickup-modus, afhaallocatie, label — niet alleen dates/rate-id)
 * voor een gegeven rate_id. $dom_id onderscheidt de twee rollen: 'mkcp-dd-data'
 * (bezorgen, default) vanuit mkcp_dd_render_delivery_field(), 'mkcp-pu-data'
 * (afhalen) vanuit mkcp_pickup_render_field() in includes/pickup.php.
 */
function mkcp_dd_data_div_html( ?string $rate_id, string $dom_id = 'mkcp-dd-data' ): string {
    $pickup_loc = function_exists( 'mkcp_pickup_location_for_rate' ) ? mkcp_pickup_location_for_rate( $rate_id ) : null;

    if ( $pickup_loc ) {
        $dates = mkcp_pickup_available_dates( $pickup_loc );
        $tz    = new DateTimeZone( wp_timezone_string() );

        return '<div id="' . esc_attr( $dom_id ) . '" style="display:none" '
            . 'data-dates="' . esc_attr( wp_json_encode( $dates ) ) . '" '
            . 'data-rate-id="' . esc_attr( (string) $rate_id ) . '" '
            . 'data-shipping-days="' . esc_attr( wp_json_encode( array_values( array_filter( range( 0, 6 ), function( $d ) use ( $pickup_loc ) {
                return empty( $pickup_loc['hours'][ $d ]['closed'] ?? true );
            } ) ) ) ) . '" '
            . 'data-blackout-dates="' . esc_attr( wp_json_encode( $pickup_loc['blackout_dates'] ?? [] ) ) . '" '
            . 'data-cutoff-ts="' . esc_attr( (string) mkcp_dd_cutoff_timestamp( $pickup_loc['cutoff_time'], $tz ) ) . '" '
            . 'data-cutoff-time="' . esc_attr( $pickup_loc['cutoff_time'] ) . '" '
            . 'data-slots-by-dow="' . esc_attr( wp_json_encode( mkcp_pickup_slots_by_dow( $pickup_loc ) ) ) . '" '
            . 'data-slot-minutes="' . esc_attr( (string) (int) ( $pickup_loc['slot_minutes'] ?? 60 ) ) . '" '
            . 'data-prep-minutes="' . esc_attr( (string) (int) ( $pickup_loc['prep_minutes'] ?? 60 ) ) . '" '
            . 'data-pickup="1" '
            . 'data-slots-enabled="' . ( ! empty( $pickup_loc['slots_enabled'] ) ? '1' : '0' ) . '" '
            . 'data-required="1" '
            . 'data-address="' . esc_attr( (string) ( $pickup_loc['address'] ?? '' ) ) . '" '
            . 'data-method-label="' . esc_attr( (string) ( $pickup_loc['location_label'] ?? 'Afhaallocatie' ) ) . '" '
            . 'data-label="' . esc_attr( 'Afhaaldatum' ) . '"></div>';
    }

    $dates = mkcp_dd_available_dates( $rate_id );
    $rule  = mkcp_dd_effective_rule( $rate_id, mkcp_checkout_config() );
    $cfg   = mkcp_checkout_config();
    $tz    = new DateTimeZone( wp_timezone_string() );

    return '<div id="' . esc_attr( $dom_id ) . '" style="display:none" '
        . 'data-dates="' . esc_attr( wp_json_encode( $dates ) ) . '" '
        . 'data-rate-id="' . esc_attr( (string) $rate_id ) . '" '
        . 'data-shipping-days="' . esc_attr( wp_json_encode( $rule['shipping_days'] ) ) . '" '
        . 'data-blackout-dates="' . esc_attr( wp_json_encode( $rule['blackout_dates'] ) ) . '" '
        . 'data-cutoff-ts="' . esc_attr( (string) mkcp_dd_cutoff_timestamp( $rule['cutoff_time'], $tz ) ) . '" '
        . 'data-cutoff-time="' . esc_attr( $rule['cutoff_time'] ) . '" '
        . 'data-slots-by-dow="' . esc_attr( wp_json_encode( mkcp_dd_slots_by_dow( $rule ) ) ) . '" '
        . 'data-slot-minutes="' . esc_attr( (string) $rule['slot_minutes'] ) . '" '
        . 'data-prep-minutes="' . esc_attr( (string) $rule['prep_minutes'] ) . '" '
        . 'data-pickup="0" '
        . 'data-slots-enabled="' . ( $rule['slots_enabled'] ? '1' : '0' ) . '" '
        . 'data-required="' . ( ! empty( $cfg['delivery_date_required'] ) ? '1' : '0' ) . '" '
        . 'data-address="" '
        . 'data-method-label="" '
        . 'data-label="' . esc_attr( sanitize_text_field( $cfg['delivery_date_label'] ?? 'Gewenste bezorgdatum' ) ) . '"></div>';
}

// ── Checkout veld renderen ─────────────────────────────────────────────────────
//
// Fase 2: geen eigen hook meer op woocommerce_review_order_before_submit — dat
// vuurde precies één keer per checkout-render, ongeacht hoeveel verzendpakketten
// er zijn. De kiezer wordt nu per pakket aangeroepen vanuit
// templates/cart-shipping-choice.php, direct onder de kaartgroep van dát
// pakket (net als mkcp_pickup_render_field() hieronder) — zo kan een gemengd
// winkelwagentje (een pakket in bezorgmodus, een ander in afhaalmodus) beide
// tegelijk tonen i.p.v. altijd maar één widget voor de hele checkout.
//
// @param ?string $rate_id De (bezorg-)rate van het pakket waarvoor gerenderd wordt.
function mkcp_dd_render_delivery_field( ?string $rate_id ) {
    if ( ! mkcp_dd_enabled() ) return;

    $cfg        = mkcp_checkout_config();
    $label      = esc_html( $cfg['delivery_date_label'] ?? 'Gewenste bezorgdatum' );
    $disclaimer = $cfg['delivery_date_disclaimer'] ?? 'Dit is een inschatting — in uitzonderlijke gevallen (bv. drukte bij de vervoerder) kan de bezorging uitlopen.';
    $required   = ! empty( $cfg['delivery_date_required'] );
    $dates      = mkcp_dd_available_dates( $rate_id );
    $rule       = mkcp_dd_effective_rule( $rate_id, $cfg );

    if ( empty( $dates ) ) {
        mkcp_dd_render_empty_state( $label, $required );
        return;
    }

    $chips       = array_slice( $dates, 0, 6 );
    $days_short  = [ 'Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za' ];
    ?>
    <div class="mkcp-dd-wrap" id="mkcp-dd-wrap">

        <?php // Statisch en leeg neergezet (i.p.v. pas bij de eerste fout door JS
              // aangemaakt), zelfde patroon als de aria-live-containers in de
              // cart-drawer (templates/cart-popup.php) — zo kan #mkcp-dd-chips/
              // #mkcp-dd-slots hieronder er altijd al naar verwijzen via
              // aria-describedby, en hoeft delivery-date.js alleen nog tekst te
              // zetten en hidden te wisselen. ?>
        <div id="mkcp-dd-error" class="mkcp-dd-error" role="alert" hidden></div>

        <div class="mkcp-dd-header">
            <span class="mkcp-dd-label">
                <?php echo $label; ?>
                <?php if ( $required ) : ?><abbr class="required" title="verplicht veld">*</abbr><?php endif; ?>
            </span>
        </div>

        <?php // Alles wat na een geldige keuze niet meer nodig is om te tónen
              // klapt hierbinnen samen dicht (zie delivery-date.js: collapseIfComplete()/
              // expand()) — alleen de header en de .mkcp-dd-summary hieronder blijven
              // dan zichtbaar. Voorkomt dat de checkout torenhoog wordt zodra zowel
              // een bezorg- als een afhaalwidget tegelijk (allebei al ingevuld)
              // op de pagina staan. ?>
        <div class="mkcp-dd-body" id="mkcp-dd-body">
        <div class="mkcp-dd-body-inner">

        <?php /* aria-hidden: dit is een tikkende seconde-teller — een aria-live regio zou
                 elke seconde opnieuw voorgelezen worden, wat storend is voor screenreaders. */ ?>
        <p class="mkcp-dd-microcopy" id="mkcp-dd-microcopy" aria-hidden="true"></p>
        <?php if ( $disclaimer !== '' ) : ?>
        <p class="mkcp-dd-disclaimer"><?php echo esc_html( $disclaimer ); ?></p>
        <?php endif; ?>

        <div class="mkcp-dd-chips-row" id="mkcp-dd-chips" role="group"
             aria-label="<?php esc_attr_e( 'Kies een bezorgdatum', 'mk-cart-popup' ); ?>"
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
                    aria-label="<?php esc_attr_e( 'Kalender openen', 'mk-cart-popup' ); ?>"
                    aria-haspopup="dialog" aria-expanded="false">
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
            <button type="button" class="mkcp-dd-nav mkcp-dd-nav--prev" id="mkcp-dd-nav-prev"
                    aria-label="<?php esc_attr_e( 'Vorige data', 'mk-cart-popup' ); ?>">&#8249;</button>
            <div class="mkcp-dd-cards-viewport" id="mkcp-dd-cards-viewport">
                <div class="mkcp-dd-cards-list" id="mkcp-dd-cards"></div>
            </div>
            <button type="button" class="mkcp-dd-nav mkcp-dd-nav--next" id="mkcp-dd-nav-next"
                    aria-label="<?php esc_attr_e( 'Volgende data', 'mk-cart-popup' ); ?>">&#8250;</button>
        </div>

        <?php /* Bevestiging voor datums die niet als grote kaart zichtbaar zijn
                 (gekozen via chip 5/6 of via de kalender) — de eerste 4 datums
                 hebben al hun eigen "geselecteerd"-weergave op de kaart zelf. */ ?>
        <div class="mkcp-dd-confirm" id="mkcp-dd-confirm" hidden></div>

        <?php if ( ! empty( $rule['slots_enabled'] ) ) : ?>
        <div class="mkcp-pu-slots" id="mkcp-dd-slots" role="group"
             aria-label="<?php esc_attr_e( 'Kies een tijdstip', 'mk-cart-popup' ); ?>"
             aria-describedby="mkcp-dd-error" hidden>
            <span class="mkcp-pu-slots-label">Kies een tijdstip</span>
            <div class="mkcp-pu-slots-row" id="mkcp-dd-slots-row"></div>
        </div>
        <?php /* class="mkcp-dd-*-field" (gedeeld met pickup.php) is wat
                 delivery-date.js gebruikt om dit veld te vinden — niet de naam/id.
                 Zie het commentaar bij pickup.php's versie van dit veld voor de
                 volledige toelichting (AJAX ververst nooit dit input-veld zelf,
                 alleen #mkcp-dd-data, dus JS moet naam/id zelf bijwerken bij een
                 modus-wissel). */ ?>
        <input type="hidden" name="mkcp_time_slot" id="mkcp_time_slot" class="mkcp-dd-slot-field" value="">
        <?php endif; ?>

        <input type="hidden" name="mkcp_delivery_date" id="mkcp_delivery_date" class="mkcp-dd-date-field" value="">

        <?php // Volledige attributenset (niet alleen dates/rate-id) — zie
              // mkcp_dd_data_div_html() voor waarom dit moet matchen met wat
              // de AJAX-fragment-filter verderop in dit bestand bouwt. ?>
        <?php echo mkcp_dd_data_div_html( $rate_id ); ?>

        <div class="mkcp-dd-calendar" id="mkcp-dd-calendar" role="dialog"
             aria-label="<?php esc_attr_e( 'Kies een datum', 'mk-cart-popup' ); ?>" aria-hidden="true">
            <div class="mkcp-dd-cal-nav">
                <button type="button" class="mkcp-dd-cal-prev" id="mkcp-dd-cal-prev"
                        aria-label="<?php esc_attr_e( 'Vorige maand', 'mk-cart-popup' ); ?>">&#8249;</button>
                <span class="mkcp-dd-cal-month-title" id="mkcp-dd-cal-month-title"></span>
                <button type="button" class="mkcp-dd-cal-next" id="mkcp-dd-cal-next"
                        aria-label="<?php esc_attr_e( 'Volgende maand', 'mk-cart-popup' ); ?>">&#8250;</button>
            </div>
            <div class="mkcp-dd-cal-dow-row">
                <span>Ma</span><span>Di</span><span>Wo</span>
                <span>Do</span><span>Vr</span><span>Za</span><span>Zo</span>
            </div>
            <div class="mkcp-dd-cal-days" id="mkcp-dd-cal-days"></div>
            <div class="mkcp-dd-cal-legend" aria-hidden="true">
                <span class="mkcp-dd-cal-legend-item">
                    <span class="mkcp-dd-cal-legend-dot mkcp-dd-cal-legend-dot--today"></span>
                    <?php esc_html_e( 'Vandaag', 'mk-cart-popup' ); ?>
                </span>
                <span class="mkcp-dd-cal-legend-item">
                    <span class="mkcp-dd-cal-legend-dot mkcp-dd-cal-legend-dot--selected"></span>
                    <?php esc_html_e( 'Geselecteerd', 'mk-cart-popup' ); ?>
                </span>
            </div>
        </div>

        </div><?php // /.mkcp-dd-body-inner ?>
        </div><?php // /.mkcp-dd-body ?>

        <?php // Rendert hier als kind van de wrap (i.p.v. los op
              // woocommerce_review_order_before_payment) zodat 'm meeleeft met
              // dezelfde ververscyclus als de rest van de wrap — zie de
              // toelichting bij .mkcp-dd-summary in delivery-date.scss. ?>
        <div class="mkcp-dd-summary" id="mkcp-dd-summary" hidden></div>

    </div>
    <?php
}

/**
 * Nette lege staat wanneer er (tijdelijk) geen enkele bezorgdatum
 * beschikbaar is — bv. alle verzenddagen geblokkeerd, of een filter/
 * capaciteitslimiet heeft alles weggefilterd. Voorheen verdween het veld
 * hier stilzwijgend; nu krijgt de klant altijd een duidelijke melding.
 */
function mkcp_dd_render_empty_state( string $label, bool $required, string $wrap_id = 'mkcp-dd-wrap' ) {
    ?>
    <div class="mkcp-dd-wrap mkcp-dd-wrap--empty" id="<?php echo esc_attr( $wrap_id ); ?>">
        <div class="mkcp-dd-header">
            <span class="mkcp-dd-label">
                <?php echo $label; ?>
                <?php if ( $required ) : ?><abbr class="required" title="verplicht veld">*</abbr><?php endif; ?>
            </span>
        </div>
        <div class="mkcp-dd-empty">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8"  y1="2" x2="8"  y2="6"/>
                <line x1="3"  y1="10" x2="21" y2="10"/>
                <line x1="9" y1="16" x2="15" y2="16"/>
            </svg>
            <span><?php esc_html_e( 'Er is op dit moment geen bezorgdatum beschikbaar om te kiezen. We nemen na je bestelling contact met je op om een datum af te stemmen.', 'mk-cart-popup' ); ?></span>
        </div>
    </div>
    <?php
}


// ── Mini-samenvatting bij de bezorgdatum-kiezer ─────────────────────────────────
//
// Bevestigt de gekozen bezorgdatum nogmaals, direct onder de kalender in
// dezelfde wrap (zie mkcp_dd_render_delivery_field()/mkcp_pickup_render_field()
// in includes/pickup.php) — rendert als kind van de wrap zodat 'm meeleeft
// met dezelfde ververscyclus (de hele wrap wordt bij elke AJAX-refresh vers
// meegerenderd, zie de docblock bovenaan dit bestand). JS vult 'm en houdt
// 'm in sync.


// ── Validatie ──────────────────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_process', function() {
    if ( ! mkcp_dd_enabled() ) return;

    // Fase 2: scant ALLE geposte verzendpakketten op de bezorg-rol i.p.v. de
    // oude "pickup actief? dan skippen, anders pakket 0"-aanname — zo werkt
    // dit onafhankelijk van pickup.php's eigen validatie (zie daar), ook als
    // deze order BEIDE rollen tegelijk heeft (een gemengd winkelwagentje).
    $rate_id = mkcp_dd_role_rate_id_from_post( 'delivery' );
    if ( ! $rate_id ) return; // geen bezorg-pakket in deze order — niets te valideren

    $cfg      = mkcp_checkout_config();
    $required = ! empty( $cfg['delivery_date_required'] );
    $date     = sanitize_text_field( wp_unslash( $_POST['mkcp_delivery_date'] ?? '' ) );

    if ( $required && empty( $date ) ) {
        $label = sanitize_text_field( $cfg['delivery_date_label'] ?? 'Gewenste bezorgdatum' );
        wc_add_notice(
            sprintf( __( '"%s" is een verplicht veld.', 'mk-cart-popup' ), $label ),
            'error'
        );
        return;
    }

    if ( ! empty( $date ) ) {
        // Valideer dat de datum een geldige Y-m-d is én in de beschikbare lijst staat.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            wc_add_notice( __( 'Ongeldige bezorgdatum geselecteerd.', 'mk-cart-popup' ), 'error' );
            return;
        }

        $available = mkcp_dd_available_dates( $rate_id );
        if ( ! in_array( $date, $available, true ) ) {
            wc_add_notice( __( 'De geselecteerde bezorgdatum is niet beschikbaar.', 'mk-cart-popup' ), 'error' );
            return;
        }

        $rule = mkcp_dd_effective_rule( $rate_id, $cfg );
        if ( ! empty( $rule['slots_enabled'] ) ) {
            $slot = sanitize_text_field( wp_unslash( $_POST['mkcp_time_slot'] ?? '' ) );
            if ( empty( $slot ) || ! preg_match( '/^\d{2}:\d{2}$/', $slot ) ) {
                wc_add_notice( __( 'Kies een tijdstip om te laten bezorgen.', 'mk-cart-popup' ), 'error' );
                return;
            }

            if ( ! in_array( $slot, mkcp_dd_slots_for_rule( $rule ), true ) ) {
                wc_add_notice( __( 'Het geselecteerde tijdstip is niet beschikbaar.', 'mk-cart-popup' ), 'error' );
                return;
            }

            if ( ! mkcp_slot_is_reachable( $date, $slot, (int) $rule['prep_minutes'] ) ) {
                wc_add_notice( __( 'Dit tijdstip ligt te dichtbij op het bestelmoment — kies een tijdstip verder in de toekomst.', 'mk-cart-popup' ), 'error' );
                return;
            }

            if ( ! empty( $rule['slot_capacity'] ) && mkcp_dd_slot_count( $date, $slot, $rate_id ) >= (int) $rule['slot_capacity'] ) {
                wc_add_notice( __( 'Dit tijdstip zit helaas vol, kies een ander tijdstip.', 'mk-cart-popup' ), 'error' );
            }
        }
    }
} );


// ── Opslaan in order meta ──────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_update_order_meta', function( $order_id ) {
    if ( ! mkcp_dd_enabled() ) return;

    $rate_id = mkcp_dd_role_rate_id_from_post( 'delivery' );
    if ( ! $rate_id ) return;

    $date = sanitize_text_field( wp_unslash( $_POST['mkcp_delivery_date'] ?? '' ) );
    if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $order->update_meta_data( '_mkcp_delivery_date', $date );

    $rule = mkcp_dd_effective_rule( $rate_id, mkcp_checkout_config() );

    $slot = '';
    if ( ! empty( $rule['slots_enabled'] ) ) {
        $slot = sanitize_text_field( wp_unslash( $_POST['mkcp_time_slot'] ?? '' ) );
        if ( preg_match( '/^\d{2}:\d{2}$/', $slot ) ) {
            $order->update_meta_data( '_mkcp_delivery_slot', $slot );
            $order->update_meta_data( '_mkcp_delivery_slot_rate', (string) $rate_id );
        } else {
            $slot = '';
        }
    }
    $order->save();

    // Capaciteitstelling voor deze datum(+slot) is nu direct verouderd — leeg
    // de transient-cache zodat de volgende klant meteen de juiste stand ziet.
    delete_transient( 'mkcp_dd_count_' . $date );
    if ( $slot !== '' && ! empty( $rule['slot_capacity'] ) ) {
        delete_transient( 'mkcp_slotcnt_' . md5( '_mkcp_delivery_date' . $rate_id ) . '_' . $date . '_' . str_replace( ':', '', $slot ) );
    }
} );


// ── Admin bestellingenpagina ───────────────────────────────────────────────────

add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    $date = $order->get_meta( '_mkcp_delivery_date' );
    if ( ! $date ) return;
    $slot = $order->get_meta( '_mkcp_delivery_slot' );
    echo '<p><strong>' . esc_html__( 'Gewenste bezorgdatum', 'mk-cart-popup' ) . ':</strong><br>'
        . esc_html( mkcp_dd_format_date( $date ) ) . ( $slot ? ' — ' . esc_html( $slot ) : '' ) . '</p>';
} );


// ── Bedankpagina ──────────────────────────────────────────────────────────────

add_action( 'woocommerce_order_details_after_order_table', function( $order ) {
    // Vervangen door de grote bezorg-/afhaal-banner (includes/thankyou.php)
    // zodra die actief is — anders staat dezelfde info twee keer op de pagina.
    if ( function_exists( 'mkcp_thankyou_enabled' ) && mkcp_thankyou_enabled() ) return;

    $date = $order->get_meta( '_mkcp_delivery_date' );
    if ( ! $date ) return;
    $slot = $order->get_meta( '_mkcp_delivery_slot' );
    echo '<p style="margin-top:8px"><strong>' . esc_html__( 'Gewenste bezorgdatum', 'mk-cart-popup' ) . ':</strong> '
        . esc_html( mkcp_dd_format_date( $date ) ) . ( $slot ? ', ' . esc_html( $slot ) : '' ) . '</p>';
} );


// ── E-mail ────────────────────────────────────────────────────────────────────

add_filter( 'woocommerce_email_order_meta_fields', function( $fields, $sent_to_admin, $order ) {
    $date = $order->get_meta( '_mkcp_delivery_date' );
    if ( ! $date ) return $fields;

    $slot = $order->get_meta( '_mkcp_delivery_slot' );
    $fields['mkcp_delivery_date'] = [
        'label' => __( 'Gewenste bezorgdatum', 'mk-cart-popup' ),
        'value' => mkcp_dd_format_date( $date ) . ( $slot ? ', ' . $slot : '' ),
    ];

    return $fields;
}, 10, 3 );


// ── PDF (WP Overnight — woocommerce-pdf-invoices-packing-slips) ───────────────

// wpo_wcpdf_after_order_data vuurt binnen de order-data-tabel, direct ná de
// "Betaalmethode"-rij (zie templates/Simple/invoice.php) — vandaar een <tr>
// die dezelfde <th>/<td>-opmaak volgt als de omliggende rijen, i.p.v. de
// eerder gebruikte <div> (die hoorde bij wpo_wcpdf_after_order_details, dat
// pas ná de hele tabel vuurt, dus buiten elke <table> — een kale <div> daar
// was geldige HTML, een <tr> zou dat niet zijn geweest: DOMPDF (dat de PDF
// daadwerkelijk opbouwt) gooit dan "Parent table not found for table cell"
// en de factuur genereert niet meer).
add_action( 'wpo_wcpdf_after_order_data', function( $document_type, $order ) {
    if ( ! $order ) return;
    $date = $order->get_meta( '_mkcp_delivery_date' );
    if ( ! $date ) return;
    $slot = $order->get_meta( '_mkcp_delivery_slot' );
    // <strong>: het factuursjabloon zet th (net als de andere rijen hierboven)
    // bewust op font-weight:normal (zie style.css), dus zonder dit blijft het
    // label net zo dun als de rest i.p.v. dikgedrukt zoals gevraagd.
    echo '<tr class="mkcp-delivery-date"><th><strong>' . esc_html__( 'Gewenste bezorgdatum', 'mk-cart-popup' ) . '</strong></th><td>'
        . esc_html( mkcp_dd_format_date( $date ) . ( $slot ? ', ' . $slot : '' ) ) . '</td></tr>';
}, 10, 2 );


// ── Admin orderlijst: bezorgdatum-kolom + filter ────────────────────────────────
//
// Werkt zowel op de klassieke (CPT-based) als de HPOS (High-Performance
// Order Storage) orderlijst — WooCommerce's eigen aanbevolen manier om beide
// te ondersteunen is losse hooks voor elk scherm die naar dezelfde callbacks
// wijzen. Handig voor het magazijn om per bezorgdag te kunnen picken.

add_filter( 'manage_edit-shop_order_columns',          'mkcp_dd_add_order_column' );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'mkcp_dd_add_order_column' );

function mkcp_dd_add_order_column( $columns ) {
    if ( ! mkcp_dd_enabled() ) return $columns;

    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'order_status' ) {
            $new['mkcp_delivery_date'] = __( 'Bezorgdatum', 'mk-cart-popup' );
        }
    }
    // Fallback: als 'order_status' niet bestaat (thema/plugin wijzigde kolommen), toch toevoegen.
    if ( ! isset( $new['mkcp_delivery_date'] ) ) {
        $new['mkcp_delivery_date'] = __( 'Bezorgdatum', 'mk-cart-popup' );
    }
    return $new;
}

add_action( 'manage_shop_order_posts_custom_column',           'mkcp_dd_render_order_column', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'mkcp_dd_render_order_column', 10, 2 );

function mkcp_dd_render_order_column( $column, $order_or_id ) {
    if ( $column !== 'mkcp_delivery_date' ) return;

    $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order ) return;

    $date = $order->get_meta( '_mkcp_delivery_date' );
    echo $date ? esc_html( mkcp_dd_format_date( $date ) ) : '—';
}

// Filterveld boven de orderlijst (klassiek + HPOS).
add_action( 'restrict_manage_posts',                             'mkcp_dd_render_order_filter' );
add_action( 'woocommerce_order_list_table_restrict_manage_orders', 'mkcp_dd_render_order_filter' );

function mkcp_dd_render_order_filter( $post_type_or_order_type = '' ) {
    if ( ! mkcp_dd_enabled() ) return;
    if ( $post_type_or_order_type && $post_type_or_order_type !== 'shop_order' ) return;
    if ( ! current_user_can( 'edit_shop_orders' ) ) return;

    $current = isset( $_GET['mkcp_delivery_date_filter'] )
        ? sanitize_text_field( wp_unslash( $_GET['mkcp_delivery_date_filter'] ) )
        : '';
    ?>
    <input type="date" name="mkcp_delivery_date_filter" value="<?php echo esc_attr( $current ); ?>"
           style="margin-right:6px" title="<?php esc_attr_e( 'Filter op bezorgdatum', 'mk-cart-popup' ); ?>">
    <?php
}

// Klassieke (CPT) orderlijst: meta_query injecteren via pre_get_posts.
add_action( 'pre_get_posts', function( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return;
    if ( $query->get( 'post_type' ) !== 'shop_order' ) return;

    $date = isset( $_GET['mkcp_delivery_date_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['mkcp_delivery_date_filter'] ) ) : '';
    if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return;

    $query->set( 'meta_query', array_merge( (array) $query->get( 'meta_query', [] ), [ [
        'key'   => '_mkcp_delivery_date',
        'value' => $date,
    ] ] ) );
} );

// HPOS-orderlijst: meta_query injecteren via de eigen prepare-items-filter.
add_filter( 'woocommerce_order_list_table_prepare_items_query_args', function( $args ) {
    $date = isset( $_GET['mkcp_delivery_date_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['mkcp_delivery_date_filter'] ) ) : '';
    if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return $args;

    $args['meta_query'] = array_merge( (array) ( $args['meta_query'] ?? [] ), [ [
        'key'   => '_mkcp_delivery_date',
        'value' => $date,
    ] ] );
    return $args;
} );
