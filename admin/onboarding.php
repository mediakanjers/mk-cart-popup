<?php
/**
 * MK Cart Popup — Onboarding-tour: server-kant
 *
 * Bundelt alles wat de Driver.js-tour (admin/assets/onboarding.js) nodig
 * heeft aan server-data en -acties, los van admin/settings.php zelf zodat
 * dat bestand niet verder blijft groeien. De activatie-hook die de tour
 * initieel triggert staat in mk-cart-popup.php; de enqueue van de assets
 * blijft in admin/settings.php staan (naast de andere admin-asset-enqueues).
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── C1: heeft de winkel al een WooCommerce-verzendzone? ──────────────────────
//
// Zelfde detectiepatroon als includes/delivery-date.php (mkcp_dd_get_shipping_
// methods()) gebruikt — hier alleen de "bestaat er minstens één zone"-vraag,
// niet de volledige rate-lijst.
function mkcp_onboarding_has_shipping_zones(): bool {
    if ( ! class_exists( 'WC_Shipping_Zones' ) ) return false;
    return count( WC_Shipping_Zones::get_zones() ) > 0;
}


// ── D2: "wat is er nieuw"-infrastructuur ──────────────────────────────────────
//
// Start bewust leeg — er is nu geen concrete nieuwe feature om aan te
// kondigen. Een toekomstige highlight toevoegen is één add_filter-item:
// [ 'id' => 'unieke-slug', 'selector' => '...', 'tabActivator' => '...',
//   'title' => '...', 'description' => '...' ].
function mkcp_onboarding_feature_highlights(): array {
    return apply_filters( 'mkcp_onboarding_feature_highlights', [] );
}

function mkcp_onboarding_unseen_highlights(): array {
    $seen = (array) get_option( 'mkcp_seen_feature_highlights', [] );
    return array_values( array_filter( mkcp_onboarding_feature_highlights(), function( $h ) use ( $seen ) {
        return ! empty( $h['id'] ) && ! in_array( $h['id'], $seen, true );
    } ) );
}

add_action( 'wp_ajax_mkcp_mark_feature_seen', function() {
    check_ajax_referer( 'mkcp_onboarding', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );
    if ( $id === '' ) wp_send_json_error();

    $seen = (array) get_option( 'mkcp_seen_feature_highlights', [] );
    if ( ! in_array( $id, $seen, true ) ) {
        $seen[] = $id;
        update_option( 'mkcp_seen_feature_highlights', $seen );
    }
    wp_send_json_success();
} );


// ── Gebundelde localize-data voor admin/assets/onboarding.js ─────────────────
//
// $mkcp_start_tour komt uit admin/settings.php (leest + verwijdert de
// mkcp_show_onboarding-vlag die mk-cart-popup.php bij activatie zet) — hier
// alleen als parameter binnengehaald, niet opnieuw bepaald, anders zou de
// eenmalige option twee keer worden "geconsumeerd" als deze functie ooit
// vaker dan één keer per request wordt aangeroepen.
function mkcp_onboarding_localize_data( bool $start_tour ): array {
    $license = mkcp_license_get_data();
    $config  = mkcp_config();

    return [
        'autoStart'         => $start_tour,
        'isPremium'         => mkcp_license_has( 'premium' ),
        'enabled'           => ! empty( $config['enabled'] ),
        'licenseValid'      => ! empty( $license['valid'] ),
        'siteUrl'           => home_url( '/' ),
        'hasShippingZones'  => mkcp_onboarding_has_shipping_zones(),
        'featureHighlights' => mkcp_onboarding_unseen_highlights(),
        'nonce'             => wp_create_nonce( 'mkcp_onboarding' ),
        'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
    ];
}
