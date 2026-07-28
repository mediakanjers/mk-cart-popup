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
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Cached fetch from GitHub ───────────────────────────────────────────────────
//
// Stores the parsed JSON as a transient for 6 hours so we only hit GitHub
// once per site per update-check cycle, not on every admin page load.
// The transient is deleted when the user clicks "Check again" in Dashboard → Updates,
// so manual checks always get a fresh result.

function mkcp_fetch_manifest( string $url ) {
    $remote = wp_remote_get( $url, [
        'timeout' => 15,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $remote ) || wp_remote_retrieve_response_code( $remote ) !== 200 ) {
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $remote ) );
    if ( ! $data || empty( $data->version ) ) return null;

    return $data;
}

/**
 * Haalt het stabiele manifest op, en — als deze licentie pre-release-toegang
 * heeft (zie license.php) — ook het beta-manifest. Geeft de hoogste van de
 * twee versies terug. Zolang er nog nooit een pre-release is gepubliceerd
 * bestaat mk-cart-popup-update-beta.json simpelweg niet (404) en valt dit
 * gewoon terug op het stabiele manifest.
 */
function mkcp_fetch_update_data() {
    $cached = get_transient( 'mkcp_update_data' );
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
    if ( empty( $transient->checked ) ) return $transient;

    $plugin_file = plugin_basename( MKCP_PATH . 'mk-cart-popup.php' );
    $current_ver = $transient->checked[ $plugin_file ] ?? null;

    if ( ! $current_ver ) return $transient;

    $data = mkcp_fetch_update_data();
    if ( ! $data || empty( $data->download_url ) ) return $transient;

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
            'icons'        => [],
            'banners'      => [],
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
        'sections'      => [
            'description' => $data->description ?? 'Slide-in cart drawer for WooCommerce.',
            'changelog'   => $data->changelog   ?? '',
        ],
    ];
}
