<?php
/**
 * MK Cart Popup — Cart Popup: Installatie-check
 *
 * Zelfde patroon als mkcp_account_setup_status() (includes/account-frontend.php)
 * en mkcp_checkout_setup_status() (includes/checkout-frontend.php): platte,
 * read-only status-array voor het Installatie-check-kaartenblok op het Cart
 * Popup-dashboard (admin/views/settings-page.php). Eigen bestand i.p.v. in
 * mk-cart-popup.php zelf — dat bestand is al groot genoeg (zie eerdere
 * afspraak over god-files, niet verder laten groeien tenzij het concreet in
 * de weg zit).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function mkcp_popup_setup_status(): array {
    $config   = function_exists( 'mkcp_config' ) ? mkcp_config() : [];
    $cfg_co   = function_exists( 'mkcp_checkout_config' ) ? mkcp_checkout_config() : [];
    $license  = function_exists( 'mkcp_license_get_data' ) ? mkcp_license_get_data() : [];

    // delivery_date_enabled/pickup_enabled staan historisch in
    // mkcp_checkout_config() (de instellingen leven in dezelfde POST als de
    // rest van de checkout-gerelateerde velden), maar horen in de UI bij het
    // Cart Popup-product (tabblad "Verzending") — vandaar hier samengevoegd
    // uit beide config-bronnen i.p.v. dat als bug te behandelen.
    $delivery_date_enabled = ! empty( $cfg_co['delivery_date_enabled'] );
    $pickup_enabled        = ! empty( $cfg_co['pickup_enabled'] );
    $shipping_relevant     = $delivery_date_enabled || $pickup_enabled;

    $has_shipping_zones = function_exists( 'mkcp_onboarding_has_shipping_zones' )
        ? mkcp_onboarding_has_shipping_zones()
        : ( class_exists( 'WC_Shipping_Zones' ) && count( WC_Shipping_Zones::get_zones() ) > 0 );

    $has_pickup_method = function_exists( 'mkcp_pickup_get_locations_methods' )
        ? count( mkcp_pickup_get_locations_methods() ) > 0
        : false;

    $ac_enabled = ! empty( $config['abandoned_cart_enabled'] );

    // WP-Cron: DISABLE_WP_CRON alleen is niet genoeg (zie eerdere, nu
    // verwijderde admin_notice in abandoned-cart.php) — een site kan WP-Cron
    // technisch "aan" hebben maar 'm nooit daadwerkelijk laten vuren (bv. een
    // externe-cron-plugin die de hook niet raakt). mkcp_ac_cron_last_run
    // wordt bij elke ECHTE cron-run gezet (mkcp_ac_send_reminders()).
    $cron_last_run   = (int) get_option( 'mkcp_ac_cron_last_run', 0 );
    $cron_disabled   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    // Cron-interval is 15 minuten (mkcp_ac_interval) — twee gemiste beurten
    // (30 min) is een redelijke marge vóór "lijkt vast te zitten", zonder
    // meteen na de eerste page-load al loos alarm te slaan.
    $cron_stale = $cron_last_run > 0 && ( time() - $cron_last_run ) > 30 * MINUTE_IN_SECONDS;

    $last_mail = get_option( 'mkcp_ac_last_mail_result', null );

    return [
        'license_tier'    => function_exists( 'mkcp_license_tier' ) ? mkcp_license_tier() : 'none',
        'license_valid'   => ! empty( $license['valid'] ),

        'shipping_relevant'  => $shipping_relevant,
        'has_shipping_zones' => $has_shipping_zones,

        'pickup_relevant'    => $pickup_enabled,
        'has_pickup_method'  => $has_pickup_method,

        'abandoned_cart_enabled' => $ac_enabled,
        'cron_disabled'           => $cron_disabled,
        'cron_last_run'           => $cron_last_run,
        'cron_stale'               => $cron_stale,

        'last_mail_sent' => $last_mail['sent'] ?? null, // null = nog nooit verstuurd
        'last_mail_time' => $last_mail['time'] ?? 0,
    ];
}
