<?php
/**
 * MK Cart Popup — Vertrouwensbadge: live Google Reviews-score
 *
 * Haalt periodiek (dagelijks, via WP-Cron) de actuele score + aantal reviews
 * op via de Google Places API (Place Details, fields=rating,user_ratings_
 * total,url) en cachet die in een eigen optie. mkcp_trust_badge_html()
 * (config.php) leest ALLEEN die cache — nooit een live HTTP-call tijdens
 * het weergeven van een pagina, dat zou elke paginalaad laten wachten op
 * Google.
 *
 * Mislukt een ververs-poging (verlopen sleutel, geen internet, Google-storing):
 * de laatst bekende goede waarde blijft gewoon staan, met alleen een
 * foutmelding erbij voor de beheerder (zie mkcp_trust_badge_google_cache()).
 * Een tijdelijke hik bij Google mag nooit de badge laten verdwijnen.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MKCP_TRUST_GOOGLE_OPTION = 'mkcp_trust_badge_google_cache';
const MKCP_TRUST_GOOGLE_CRON   = 'mkcp_trust_badge_google_cron';

/**
 * De laatst gecachte Google-score, of null als er nog nooit succesvol
 * opgehaald is. Vorm: ['rating'=>float,'review_count'=>int,'url'=>string,
 * 'fetched_at'=>string (mysql), 'last_error'=>string, 'last_checked_at'=>string].
 */
function mkcp_trust_badge_google_cache(): ?array {
    $cache = get_option( MKCP_TRUST_GOOGLE_OPTION, null );
    return is_array( $cache ) ? $cache : null;
}

/**
 * Live ophalen bij Google (géén cache-interactie) — apart van
 * mkcp_trust_badge_google_refresh() zodat een test-knop in de admin en de
 * cron-job exact dezelfde ophaal-/foutafhandelingslogica delen.
 *
 * @return array{success:bool,rating?:float,review_count?:int,url?:string,error?:string}
 */
function mkcp_trust_badge_google_fetch( string $api_key, string $place_id ): array {
    if ( $api_key === '' || $place_id === '' ) {
        return [ 'success' => false, 'error' => 'Geen API-sleutel of Place ID ingevuld.' ];
    }

    $endpoint = add_query_arg( [
        'place_id' => $place_id,
        'fields'   => 'rating,user_ratings_total,url',
        'key'      => $api_key,
    ], 'https://maps.googleapis.com/maps/api/place/details/json' );

    $response = wp_remote_get( $endpoint, [ 'timeout' => 12 ] );

    if ( is_wp_error( $response ) ) {
        return [ 'success' => false, 'error' => $response->get_error_message() ];
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || ! is_array( $body ) ) {
        return [ 'success' => false, 'error' => 'Onverwacht antwoord van Google (HTTP ' . (int) $code . ').' ];
    }

    $status = $body['status'] ?? 'UNKNOWN_ERROR';
    if ( $status !== 'OK' ) {
        // Google's eigen statuscodes zijn al leesbaar genoeg voor in de admin
        // (bv. REQUEST_DENIED, INVALID_REQUEST, NOT_FOUND) — vertalen voegt
        // weinig toe en zou juist een echte oorzaak kunnen verbloemen.
        $msg = $status;
        if ( ! empty( $body['error_message'] ) ) $msg .= ': ' . $body['error_message'];
        return [ 'success' => false, 'error' => $msg ];
    }

    $result = $body['result'] ?? [];
    if ( empty( $result['rating'] ) ) {
        return [ 'success' => false, 'error' => 'Google gaf geen sterrenscore terug voor deze locatie (nog geen reviews?).' ];
    }

    return [
        'success'       => true,
        'rating'        => (float) $result['rating'],
        'review_count'  => (int) ( $result['user_ratings_total'] ?? 0 ),
        'url'           => (string) ( $result['url'] ?? '' ),
    ];
}

/**
 * Ververst de cache. Bij succes: nieuwe waarden + fetched_at, foutmelding
 * gewist. Bij mislukking: bestaande (mogelijk oudere) rating/count/url
 * blijven staan — alleen last_error/last_checked_at worden bijgewerkt, zodat
 * de badge op de site nooit zomaar verdwijnt door een tijdelijke hik.
 */
function mkcp_trust_badge_google_refresh(): array {
    $config = mkcp_config();
    $result = mkcp_trust_badge_google_fetch(
        (string) ( $config['trust_badge_google_key'] ?? '' ),
        (string) ( $config['trust_badge_google_place_id'] ?? '' )
    );

    $cache = mkcp_trust_badge_google_cache() ?? [];
    $cache['last_checked_at'] = current_time( 'mysql' );

    if ( $result['success'] ) {
        $cache['rating']       = $result['rating'];
        $cache['review_count'] = $result['review_count'];
        $cache['url']          = $result['url'];
        $cache['fetched_at']   = $cache['last_checked_at'];
        $cache['last_error']   = '';
    } else {
        $cache['last_error'] = $result['error'] ?? 'Onbekende fout.';
    }

    update_option( MKCP_TRUST_GOOGLE_OPTION, $cache, false );
    return $cache;
}

add_action( MKCP_TRUST_GOOGLE_CRON, 'mkcp_trust_badge_google_refresh' );

/**
 * Cron aan/uit houden met de instelling mee — alleen actief zolang de
 * badge aan staat, provider "google" is, én er een sleutel + Place ID
 * zijn ingevuld. Licht genoeg (één keer per admin-laad een
 * wp_next_scheduled()-check) om op elke admin_init te doen.
 */
add_action( 'admin_init', function() {
    if ( ! function_exists( 'mkcp_config' ) || ! function_exists( 'mkcp_license_has' ) ) return;
    $config = mkcp_config();
    $should_run = ! empty( $config['trust_badge_enabled'] )
        && mkcp_license_has( 'premium' )
        && ( $config['trust_badge_provider'] ?? '' ) === 'google'
        && ( $config['trust_badge_google_key'] ?? '' ) !== ''
        && ( $config['trust_badge_google_place_id'] ?? '' ) !== '';

    $scheduled = wp_next_scheduled( MKCP_TRUST_GOOGLE_CRON );

    if ( $should_run && ! $scheduled ) {
        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', MKCP_TRUST_GOOGLE_CRON );
    } elseif ( ! $should_run && $scheduled ) {
        wp_unschedule_event( $scheduled, MKCP_TRUST_GOOGLE_CRON );
    }

    if ( ! $should_run ) return;

    // Nog nooit een score opgehaald (bv. meteen na het instellen, of de
    // eerdere poging faalde nog vóórdat er een geldige sleutel/Place ID
    // stond) — dan niet tot de volgende cron-tik wachten, want dan blijft de
    // badge tot morgen leeg. Was er cache is inderdaad ooit een RATING
    // opgeslagen (de check hier), niet "is er al wél een cache-record" —
    // die kan namelijk ook alleen een oude foutmelding bevatten, bv. van
    // vóórdat de sleutel/Place ID goed stonden.
    //
    // Om niet bij elke admin-paginalaad een falende sleutel opnieuw te
    // proberen: hoogstens elke 5 minuten een nieuwe poging.
    $cache = mkcp_trust_badge_google_cache();
    if ( empty( $cache['rating'] ) ) {
        $last_try = strtotime( (string) ( $cache['last_checked_at'] ?? '' ) );
        if ( ! $last_try || $last_try < time() - 5 * MINUTE_IN_SECONDS ) {
            mkcp_trust_badge_google_refresh();
        }
    }
} );

// Nette opruiming bij het uitschakelen van de plugin.
register_deactivation_hook( MKCP_PATH . 'mk-cart-popup.php', function() {
    $scheduled = wp_next_scheduled( MKCP_TRUST_GOOGLE_CRON );
    if ( $scheduled ) wp_unschedule_event( $scheduled, MKCP_TRUST_GOOGLE_CRON );
} );

/**
 * AJAX: "Nu verversen"-knop in de admin (Cart Popup → Vertrouwensbadge).
 */
add_action( 'wp_ajax_mkcp_trust_badge_refresh_now', function() {
    check_ajax_referer( 'mkcp_trust_badge_refresh', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Geen toegang.' ], 403 );
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) wp_send_json_error( [ 'message' => 'Premium vereist.' ], 403 );

    // Sleutel/Place ID uit het formulier zelf, als die zijn meegestuurd —
    // zodat "Nu verversen" test met wat er NU in de velden staat, ook als de
    // admin nog niet op "Opslaan" heeft geklikt. Anders (knop op de oude
    // pagina, geen velden meegegeven) terugvallen op de opgeslagen config.
    if ( isset( $_POST['api_key'] ) || isset( $_POST['place_id'] ) ) {
        $config  = mkcp_config();
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : (string) ( $config['trust_badge_google_key'] ?? '' );
        $place_id = isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : (string) ( $config['trust_badge_google_place_id'] ?? '' );

        $result = mkcp_trust_badge_google_fetch( $api_key, $place_id );
        $cache  = mkcp_trust_badge_google_cache() ?? [];
        $cache['last_checked_at'] = current_time( 'mysql' );

        if ( $result['success'] ) {
            $cache['rating']       = $result['rating'];
            $cache['review_count'] = $result['review_count'];
            $cache['url']          = $result['url'];
            $cache['fetched_at']   = $cache['last_checked_at'];
            $cache['last_error']   = '';
        } else {
            $cache['last_error'] = $result['error'] ?? 'Onbekende fout.';
        }

        // Alleen de cache bijwerken als deze waarden ook echt de opgeslagen
        // instellingen zijn — anders zou een test met een ANDERE sleutel dan
        // opgeslagen de cache kunnen overschrijven met resultaten die niet bij
        // de live site horen. Bij afwijkende testwaarden dus niet opslaan,
        // alleen het resultaat teruggeven voor de statusmelding.
        $saved_config = mkcp_config();
        $matches_saved = $api_key === (string) ( $saved_config['trust_badge_google_key'] ?? '' )
            && $place_id === (string) ( $saved_config['trust_badge_google_place_id'] ?? '' );
        if ( $matches_saved ) {
            update_option( MKCP_TRUST_GOOGLE_OPTION, $cache, false );
        }
    } else {
        $cache = mkcp_trust_badge_google_refresh();
    }

    if ( $cache['last_error'] ?? '' ) {
        wp_send_json_error( [
            'message' => $cache['last_error'],
            'cache'   => $cache,
        ] );
    }

    wp_send_json_success( [ 'cache' => $cache ] );
} );
