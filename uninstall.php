<?php
/**
 * MK Cart Popup — Uninstall
 *
 * Runs automatically when the plugin is deleted via WordPress Admin → Plugins.
 * Cleans up all data left in the database: alle mkcp_*-opties (incl. de
 * dynamisch-benoemde transients, bv. mkcp_saved_cart_{hash}), de twee custom
 * verlaten-winkelwagen-tabellen, en het cron-event — op élke subsite bij een
 * netwerk-installatie, niet alleen de site waarop verwijderd wordt.
 *
 * Note: this file is NOT run on deactivation — only on full deletion.
 * Deactivating and re-activating the plugin keeps all settings intact.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

/**
 * Ruimt alle plugin-data op de HUIDIGE site op (of, bij multisite, de site
 * waar op dat moment naartoe geswitcht is — zie de netwerk-lus onderaan).
 */
function mkcp_uninstall_cleanup_current_site() {
    global $wpdb;

    // Statisch-benoemde opties.
    $options = [
        'mkcp_settings',
        'mkcp_checkout_settings',
        'mkcp_license_key',
        'mkcp_license_clock_offset',
        'mkcp_ac_db_version',
        'mkcp_ac_delay_migrated',
        'mkcp_pu_ready_log',
        'mkcp_seen_feature_highlights',
        'mkcp_show_onboarding',
        'mkcp_stats_removed',
        'mkcp_stats_gap',
        'mkcp_stats_assisted',
    ];
    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Statisch-benoemde transients.
    $transients = [
        'mkcp_update_data',   // updater.php
        'mkcp_license_cache', // license.php — MKCP_LICENSE_TRANSIENT
        'mkcp_ac_cron_lock',  // includes/abandoned-cart.php
    ];
    foreach ( $transients as $transient ) {
        delete_transient( $transient );
    }

    // Dynamisch-benoemde transients (bv. mkcp_saved_cart_{hash},
    // mkcp_pu_count_{datum}, mkcp_dd_count_{datum}, mkcp_slotcnt_{datum}) —
    // delete_transient() kent geen wildcard-variant, dus dit gaat rechtstreeks
    // via SQL. Dekt zowel de waarde- als de timeout-rij die elke transient
    // in wp_options achterlaat.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_mkcp_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_mkcp_' ) . '%'
        )
    );

    // Custom tabellen van de verlaten-winkelwagen-feature.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mkcp_abandoned_carts" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mkcp_ac_suppressed" );

    // Cron-event — deactivatie ruimt dit al op, maar een plugin kan ook
    // zonder tussentijdse deactivatie direct verwijderd worden.
    wp_clear_scheduled_hook( 'mkcp_ac_cron' );
}

if ( is_multisite() ) {
    $site_ids = get_sites( [ 'fields' => 'ids' ] );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        mkcp_uninstall_cleanup_current_site();
        restore_current_blog();
    }
} else {
    mkcp_uninstall_cleanup_current_site();
}
