<?php
/**
 * MK Cart Popup — GitHub Updater
 *
 * Hooks into WordPress's plugin update system and checks the JSON file
 * on GitHub for a newer version.  When found, WordPress shows the familiar
 * "Update available" notice in Plugins → admin and handles the download
 * and installation automatically.
 *
 * Release workflow:
 *   1. Bump MKCP_VER in mk-cart-popup.php
 *   2. Create a GitHub Release (tag e.g. v1.0.1) and attach the plugin zip
 *   3. Update mk-cart-popup-update.json → version + download_url
 *   4. Commit & push to main — WordPress sites will detect the update
 *      on the next check (or immediately via Dashboard → Updates → Check again)
 *
 * Pre-releases follow the same steps but on the separate `pre-release`
 * branch (mk-cart-popup-update-beta.json instead), so `main` only ever
 * holds real, stable releases. See DEVELOPMENT.md → "Pre-releases".
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Cached fetch from GitHub ───────────────────────────────────────────────────
//
// Stores the parsed JSON as a transient for 6 hours so we only hit GitHub
// once per site per update-check cycle, not on every admin page load.
// The transient is deleted when the user clicks "Check again" in Dashboard → Updates,
// so manual checks always get a fresh result.

// Hosts waarvan we een update-zip of detailpagina accepteren. Het manifest staat
// op GitHub en is dus in principe manipuleerbaar zodra iemand bij die repo kan;
// deze allowlist zorgt dat een gewijzigd manifest WordPress' installer nooit
// naar een vreemd domein kan sturen. Geen volledige checksum-verificatie
// (vereist server-side signing), maar wel het domein hard afgedwongen.
const MKCP_UPDATER_TRUSTED_HOSTS = [
    'github.com',
    'raw.githubusercontent.com',
    'objects.githubusercontent.com', // waar GitHub release-downloads naartoe redirecten
];

/** True als $url https is én op een vertrouwde host staat. */
function mkcp_updater_url_is_trusted( $url ): bool {
    if ( ! is_string( $url ) || $url === '' ) return false;
    $parts = wp_parse_url( $url );
    if ( empty( $parts['scheme'] ) || strtolower( $parts['scheme'] ) !== 'https' ) return false;
    if ( empty( $parts['host'] ) ) return false;
    return in_array( strtolower( $parts['host'] ), MKCP_UPDATER_TRUSTED_HOSTS, true );
}

function mkcp_fetch_manifest( string $url ) {
    $debug  = defined( 'WP_DEBUG' ) && WP_DEBUG;
    $remote = wp_remote_get( $url, [
        'timeout'   => 15,
        'sslverify' => true,
        'headers'   => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $remote ) ) {
        if ( $debug ) error_log( 'MKCP updater: wp_remote_get faalde voor ' . $url . ' — ' . $remote->get_error_message() );
        return null;
    }

    $code = wp_remote_retrieve_response_code( $remote );
    if ( $code !== 200 ) {
        if ( $debug ) error_log( 'MKCP updater: ' . $url . ' gaf HTTP ' . $code . ' terug (verwacht 200).' );
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $remote ) );
    if ( ! $data || empty( $data->version ) ) {
        if ( $debug ) error_log( 'MKCP updater: ' . $url . ' gaf geen geldige JSON/versie terug. Body: ' . substr( wp_remote_retrieve_body( $remote ), 0, 300 ) );
        return null;
    }

    // Een manifest met een download- of details-URL buiten de vertrouwde hosts
    // wordt volledig genegeerd: liever géén update dan een pakket van een
    // onbekend domein door WordPress' installer laten uitpakken.
    if ( ! empty( $data->download_url ) && ! mkcp_updater_url_is_trusted( $data->download_url ) ) {
        if ( $debug ) error_log( 'MKCP updater: download_url ' . $data->download_url . ' staat niet op een vertrouwde host — manifest genegeerd.' );
        return null;
    }
    if ( ! empty( $data->details_url ) && ! mkcp_updater_url_is_trusted( $data->details_url ) ) {
        if ( $debug ) error_log( 'MKCP updater: details_url ' . $data->details_url . ' staat niet op een vertrouwde host — manifest genegeerd.' );
        return null;
    }

    if ( $debug ) error_log( 'MKCP updater: ' . $url . ' → versie ' . $data->version . ' opgehaald.' );
    return $data;
}

/**
 * Haalt het stabiele manifest op, en — als deze licentie pre-release-toegang
 * heeft (zie license.php) — ook het beta-manifest. Geeft de hoogste van de
 * twee versies terug. Zolang er nog nooit een pre-release is gepubliceerd
 * bestaat mk-cart-popup-update-beta.json simpelweg niet (404) en valt dit
 * gewoon terug op het stabiele manifest.
 */
function mkcp_fetch_update_data( bool $force = false ) {
    $cached = $force ? false : get_transient( 'mkcp_update_data' );
    if ( $cached !== false ) return $cached;

    $best = mkcp_fetch_manifest( MKCP_UPDATER_URL );

    $wants_beta = function_exists( 'mkcp_license_get_data' )
        && ! empty( mkcp_license_get_data()['prerelease'] );

    if ( $wants_beta ) {
        $beta = mkcp_fetch_manifest( MKCP_UPDATER_BETA_URL );
        if ( $beta && ( ! $best || version_compare( $beta->version, $best->version, '>' ) ) ) {
            $best = $beta;
        }
    }

    if ( ! $best ) return null;

    set_transient( 'mkcp_update_data', $best, 6 * HOUR_IN_SECONDS );
    return $best;
}

// Clear the transient whenever WordPress forces a fresh update check.
add_action( 'delete_site_transient_update_plugins', function() {
    delete_transient( 'mkcp_update_data' );
} );


// ── Check for update ──────────────────────────────────────────────────────────

add_filter( 'pre_set_site_transient_update_plugins', 'mkcp_check_for_update' );

function mkcp_check_for_update( $transient ) {
    $debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

    if ( empty( $transient->checked ) ) {
        if ( $debug ) error_log( 'MKCP updater: $transient->checked is leeg, update-check overgeslagen.' );
        return $transient;
    }

    $plugin_file = plugin_basename( MKCP_PATH . 'mk-cart-popup.php' );
    $current_ver = $transient->checked[ $plugin_file ] ?? null;

    if ( ! $current_ver ) {
        if ( $debug ) error_log( 'MKCP updater: geen geïnstalleerde versie gevonden voor ' . $plugin_file . ' in $transient->checked.' );
        return $transient;
    }

    $data = mkcp_fetch_update_data();
    if ( ! $data || empty( $data->download_url ) ) {
        if ( $debug ) error_log( 'MKCP updater: geen (bruikbaar) manifest opgehaald — geen update-check mogelijk.' );
        return $transient;
    }

    if ( $debug ) error_log( "MKCP updater: geïnstalleerd={$current_ver}, manifest={$data->version}, update nodig=" . ( version_compare( $data->version, $current_ver, '>' ) ? 'ja' : 'nee' ) );

    if ( version_compare( $data->version, $current_ver, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'id'           => $plugin_file,
            'slug'         => 'mk-cart-popup',
            'plugin'       => $plugin_file,
            'new_version'  => $data->version,
            'url'          => $data->details_url  ?? '',
            'package'      => $data->download_url,
            'requires'     => $data->requires     ?? '',
            'requires_php' => $data->requires_php ?? '',
            'tested'       => $data->tested       ?? '',
            // M2: eigen SVG-icoon/banner op basis van de site-huisstijlkleur
            // i.p.v. lege arrays — WordPress' update-UI accepteert een SVG-URL
            // hier gewoon (schaalt vanzelf), geen losse PNG-export nodig.
            'icons'        => [ 'svg' => MKCP_URL . 'assets/plugin-icon.svg' ],
            'banners'      => [ 'low' => MKCP_URL . 'assets/plugin-banner.svg', 'high' => MKCP_URL . 'assets/plugin-banner.svg' ],
        ];
    }

    return $transient;
}


// ── Plugin info modal ("View version details") ────────────────────────────────

add_filter( 'plugins_api', 'mkcp_plugin_info', 20, 3 );

function mkcp_plugin_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' ) return $result;
    if ( ( $args->slug ?? '' ) !== 'mk-cart-popup' ) return $result;

    $data = mkcp_fetch_update_data();
    if ( ! $data ) return $result;

    return (object) [
        'name'          => $data->name         ?? 'MK Cart Popup',
        'slug'          => 'mk-cart-popup',
        'version'       => $data->version       ?? '',
        'author'        => $data->author        ?? '',
        'homepage'      => $data->details_url   ?? '',
        'download_link' => $data->download_url  ?? '',
        'requires'      => $data->requires      ?? '',
        'requires_php'  => $data->requires_php  ?? '',
        'tested'        => $data->tested        ?? '',
        'icons'         => [ 'svg' => MKCP_URL . 'assets/plugin-icon.svg' ],
        'banners'       => [ 'low' => MKCP_URL . 'assets/plugin-banner.svg', 'high' => MKCP_URL . 'assets/plugin-banner.svg' ],
        'sections'      => [
            'description' => $data->description ?? 'Slide-in cart drawer for WooCommerce.',
            'changelog'   => $data->changelog   ?? '',
        ],
    ];
}
