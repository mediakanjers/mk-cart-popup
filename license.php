<?php
/**
 * MK Cart Popup — License validation
 *
 * Validates the license key against a remote endpoint (MKCP_LICENSE_URL).
 * Results are cached as a transient for 24 hours. On network failure the
 * plugin falls back to a stale copy for up to MKCP_LICENSE_GRACE seconds
 * so a temporary outage never kills a live store.
 *
 * Tiers:  'none' → plugin disabled
 *         'basic'   → core popup features
 *         'premium' → all features (save-for-later, share cart, analytics, …)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ──────────────────────────────────────────────────────────────────

// Development bypass — set in wp-config.php to skip remote validation entirely.
// REMOVE this line (or set to '') before going live.
// Example:  define( 'MKCP_LICENSE_DEV_TIER', 'premium' );
if ( ! defined( 'MKCP_LICENSE_DEV_TIER' ) ) {
    define( 'MKCP_LICENSE_DEV_TIER', '' );
}

// URL of your license validation endpoint.
// Override via wp-config.php if needed:  define( 'MKCP_LICENSE_URL', 'https://…' );
if ( ! defined( 'MKCP_LICENSE_URL' ) ) {
    define( 'MKCP_LICENSE_URL', 'https://support.mediakanjers.nl/license/validate.php' );
}

// Geen apart HMAC-geheim: de licentiesleutel zelf is het enige credential.
// Die is al uniek per klant, cryptografisch willekeurig (zie mk_gen_key in
// het license-dashboard) en op elk moment in te trekken/te vervangen via het
// dashboard — dat dekt "vermoeden van misbruik → nieuwe sleutel uitgeven"
// zonder dat er een tweede geheim bij hoeft, met alle bootstrap/rotatie-
// complicaties van dien.

// How long to accept a stale (cached) result when the remote is unreachable.
define( 'MKCP_LICENSE_GRACE',      7 * DAY_IN_SECONDS );
define( 'MKCP_LICENSE_CACHE_TTL',  DAY_IN_SECONDS );
define( 'MKCP_LICENSE_FAIL_TTL',   HOUR_IN_SECONDS );

const MKCP_LICENSE_TRANSIENT = 'mkcp_license_cache';
const MKCP_LICENSE_STALE_OPT = 'mkcp_license_stale';


// ── Core API ───────────────────────────────────────────────────────────────────

/**
 * Geeft de laatst bekende geldige licentiedata terug (met 'from_stale' => true)
 * als die binnen de coulanceperiode (MKCP_LICENSE_GRACE) valt, anders null.
 * Gedeelde fallback voor elke plek waar de remote-validatie niet bruikbaar
 * teruggeeft, zodat een tijdelijke storing nooit meteen een live winkel platlegt.
 */
function mkcp_license_stale_fallback(): ?array {
    $stale = get_option( MKCP_LICENSE_STALE_OPT, false );
    if (
        is_array( $stale ) &&
        isset( $stale['saved_at'], $stale['data'] ) &&
        ( time() - (int) $stale['saved_at'] ) < MKCP_LICENSE_GRACE
    ) {
        return array_merge( (array) $stale['data'], [ 'from_stale' => true ] );
    }
    return null;
}

/**
 * Bouwt de request-args voor de licentieserver.
 */
function mkcp_license_build_request_args( string $key, string $domain ): array {
    return [
        'timeout'    => 8,
        'user-agent' => 'MK-Cart-Popup/' . MKCP_VER . '; WordPress/' . get_bloginfo( 'version' ),
        'sslverify'  => true,
        'body'       => [
            'key'    => $key,
            'domain' => $domain,
        ],
    ];
}

/**
 * Returns license data. Hits the remote at most once per MKCP_LICENSE_CACHE_TTL.
 *
 * @param  bool  $force  Skip transient cache and re-validate immediately.
 * @return array{valid:bool, tier:string, message:string, expires:string}
 */
function mkcp_license_get_data( bool $force = false ): array {
    // Dev bypass: skip all remote validation.
    $dev_tier = (string) MKCP_LICENSE_DEV_TIER;
    if ( $dev_tier !== '' && in_array( $dev_tier, [ 'basic', 'premium' ], true ) ) {
        return [ 'valid' => true, 'tier' => $dev_tier, 'message' => 'Dev-bypass actief (MKCP_LICENSE_DEV_TIER).', 'expires' => '', 'dev' => true ];
    }

    $key = trim( (string) get_option( 'mkcp_license_key', '' ) );

    if ( $key === '' ) {
        return [ 'valid' => false, 'tier' => 'none', 'message' => 'Geen licentiesleutel ingevoerd.', 'expires' => '' ];
    }

    // Return cached result unless forced.
    if ( ! $force ) {
        $cached = get_transient( MKCP_LICENSE_TRANSIENT );
        if ( $cached !== false ) {
            return $cached;
        }
    }

    // ── Remote validation ──────────────────────────────────────────────────────

    $domain = (string) parse_url( home_url(), PHP_URL_HOST );

    // POST voorkomt dat de licentiesleutel in serverlogs verschijnt.
    $response = wp_remote_post( MKCP_LICENSE_URL, mkcp_license_build_request_args( $key, $domain ) );

    if ( is_wp_error( $response ) ) {
        // Echte netwerkstoring (timeout, DNS, SSL, connectie geweigerd) —
        // val terug op de stale cache binnen de coulanceperiode.
        $stale = mkcp_license_stale_fallback();
        if ( $stale !== null ) return $stale;

        $err = [ 'valid' => false, 'tier' => 'none', 'message' => 'Licentieserver niet bereikbaar.', 'expires' => '', 'error' => true ];
        set_transient( MKCP_LICENSE_TRANSIENT, $err, MKCP_LICENSE_FAIL_TTL );
        return $err;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $body ) ) {
        // Server bereikbaar, maar het antwoord was geen (geldige) JSON — bv.
        // een 500 met een HTML-foutpagina van een tussenliggende proxy/CDN.
        $stale = mkcp_license_stale_fallback();
        if ( $stale !== null ) return $stale;

        $err = [ 'valid' => false, 'tier' => 'none', 'message' => 'Ongeldig antwoord van licentieserver.', 'expires' => '' ];
        set_transient( MKCP_LICENSE_TRANSIENT, $err, MKCP_LICENSE_FAIL_TTL );
        return $err;
    }

    // Server heeft geldige JSON teruggegeven, ongeacht de HTTP-statuscode —
    // ook bv. een 400 bij een afgewezen verzoek bevat een nette eigen
    // boodschap. Die tonen we voortaan gewoon aan de gebruiker i.p.v. 'm te
    // overschrijven met de misleidende "niet bereikbaar"-tekst — de server
    // IS immers gewoon bereikt.

    $tier  = in_array( $body['tier'] ?? '', [ 'basic', 'premium' ], true ) ? $body['tier'] : 'none';
    $valid = ! empty( $body['valid'] ) && $tier !== 'none';

    $data = [
        'valid'   => $valid,
        'tier'    => $tier,
        'message' => sanitize_text_field( $body['message'] ?? ( $valid ? 'Actief' : 'Ongeldige sleutel.' ) ),
        'expires' => sanitize_text_field( $body['expires'] ?? '' ),
    ];

    if ( $valid ) {
        set_transient( MKCP_LICENSE_TRANSIENT, $data, MKCP_LICENSE_CACHE_TTL );
        // Save a stale copy for the grace-period fallback.
        update_option( MKCP_LICENSE_STALE_OPT, [ 'data' => $data, 'saved_at' => time() ], false );
    } else {
        // Cache invalid result briefly so we don't hammer the server.
        set_transient( MKCP_LICENSE_TRANSIENT, $data, MKCP_LICENSE_FAIL_TTL );
    }

    return $data;
}

/**
 * Returns the active license tier: 'none', 'basic', or 'premium'.
 */
function mkcp_license_tier(): string {
    static $tier = null;
    global $mkcp_license_tier_needs_reset;
    if ( $tier === null || ! empty( $mkcp_license_tier_needs_reset ) ) {
        $mkcp_license_tier_needs_reset = false;
        $tier = mkcp_license_get_data()['tier'] ?? 'none';
    }
    return $tier;
}

/**
 * Returns true when the active tier meets or exceeds $required.
 *
 * mkcp_license_has('basic')   → true for basic + premium
 * mkcp_license_has('premium') → true only for premium
 */
function mkcp_license_has( string $required ): bool {
    $hierarchy = [ 'none' => 0, 'basic' => 1, 'premium' => 2 ];
    return ( $hierarchy[ mkcp_license_tier() ] ?? 0 ) >= ( $hierarchy[ $required ] ?? 1 );
}

/**
 * Clears the cached result, forcing a fresh check on the next request.
 * Called when the admin saves a new license key.
 */
function mkcp_license_invalidate(): void {
    delete_transient( MKCP_LICENSE_TRANSIENT );
    mkcp_license_tier_reset();
}

/** Resets the static tier cache so mkcp_license_tier() re-reads on next call. */
function mkcp_license_tier_reset(): void {
    // PHP has no built-in way to unset a static, so we store the reset flag
    // in a global that mkcp_license_tier() checks before returning.
    global $mkcp_license_tier_needs_reset;
    $mkcp_license_tier_needs_reset = true;
}


// ── Admin AJAX: verify key on demand ──────────────────────────────────────────

add_action( 'wp_ajax_mkcp_verify_license', function() {
    check_ajax_referer( 'mkcp_license_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    // Optionally save a new key submitted alongside the verify request.
    if ( isset( $_POST['key'] ) ) {
        $new_key = sanitize_text_field( wp_unslash( $_POST['key'] ) );
        update_option( 'mkcp_license_key', $new_key );
    }

    mkcp_license_invalidate();
    $data = mkcp_license_get_data( true );

    wp_send_json_success( $data );
} );


// ── Admin notice when no valid license is active ───────────────────────────────

add_action( 'admin_notices', function() {
    // Only show on WooCommerce/plugin pages, not globally.
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'mkcp' ) === false && strpos( $screen->id, 'woocommerce' ) === false ) return;
    if ( mkcp_license_has( 'basic' ) ) return;

    $url = admin_url( 'admin.php?page=mkcp-settings&tab=licentie' );
    echo '<div class="notice notice-warning is-dismissible"><p>'
        . '<strong>MK Cart Popup</strong> — '
        . sprintf(
            /* translators: %s: URL to license tab */
            wp_kses( __( 'De plugin heeft een geldige licentiesleutel nodig om te werken. <a href="%s">Voer je sleutel in →</a>', 'mk-cart-popup' ), [ 'a' => [ 'href' => [] ] ] ),
            esc_url( $url )
        )
        . '</p></div>';
} );
