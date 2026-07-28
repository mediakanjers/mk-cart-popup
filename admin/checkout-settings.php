<?php
/**
 * MK Cart Popup — Checkout config helper
 *
 * Provides mkcp_checkout_config() for both the admin settings page
 * and the frontend checkout customization.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


function mkcp_checkout_config() {
    static $cfg = null;
    if ( $cfg !== null ) return $cfg;

    $defaults = [
        'checkout_enabled'        => false,
        'header_enabled'          => false,
        'header_logo_id'          => 0,
        'header_bg'               => '#ffffff',
        'footer_enabled'          => false,
        'footer_blocks'           => [],
        'checkout_blocks'         => [],
        'steps_enabled'           => false,
        'steps_labels'            => [ 'Winkelwagen', 'Gegevens', 'Bevestiging' ],
        'ssl_badge_enabled'       => false,
        'ssl_badge_text'          => 'SSL-versleuteling',
        'payment_icons_enabled'   => false,
        'dequeue_theme_css'       => false,
        'dequeue_theme_hooks'     => false,
        'dequeue_theme_js'        => false,
        'btw_switch'              => false,
        'btw_follow_popup'        => true,
        'order_review_collapsible_mobile' => false,

        // Formuliervelden — thema-onafhankelijke, portable alternatieven voor
        // wat voorheen alleen via een thema-hook bestond (en dus verdween
        // zodra "Thema hooks uitschakelen" aanstond). Bedrijfsnaam gebruikt
        // WooCommerce's eigen billing_company/shipping_company-veld (staat er
        // al, maar zonder grid-positie); bestelnotities stuurt WooCommerce's
        // eigen woocommerce_enable_order_notes_field-filter aan i.p.v. een
        // duplicaat-veld te renderen — zo blijft ook de admin-orderpagina's
        // "Customer provided note"-weergave (die dezelfde filter leest) in
        // lijn met deze instelling.
        'company_field_enabled' => false,
        'order_notes_enabled'   => false,

        // Aanpasbare tekst op de "Bestelling plaatsen"-knop. Leeg =
        // WooCommerce's eigen standaardtekst.
        'checkout_button_text' => '',

        // BTW-nummer-checker integratie (premium) — spiegelt de postcode-
        // checker-integratie hierboven: detecteert de EU/UK VAT Validation
        // Manager for WooCommerce-plugin (WPFactory) en toont bij aan/uit
        // dezelfde .mkcp-pc-status laad-/succes-/foutmelding-balk bij het
        // billing_eu_vat_number-veld dat die plugin toevoegt. Zie
        // mkcp_vat_checker_active() in includes/checkout-frontend.php.
        'vat_checker_status_enabled' => false,

        // Bezorgdatum kiezer (premium)
        'delivery_date_enabled'        => false,
        'delivery_date_required'       => false,
        'delivery_date_label'          => 'Gewenste bezorgdatum',
        'delivery_date_cutoff_time'    => '12:00',
        'delivery_date_lead_days'      => 1,
        'delivery_date_shipping_days'  => [ 1, 2, 3, 4, 5, 6 ], // Ma t/m Za
        'delivery_date_blackout_dates' => [],
        'delivery_date_calendar_range' => 60,

        // Afhalen (premium) — locaties gekoppeld aan verzendmethode-rate_id's,
        // zie mkcp_sanitize_pickup_locations() voor de structuur per locatie.
        'pickup_enabled'   => false,
        'pickup_locations' => [],

        // Afhaalmeldingen (premium) — e-mail + sms wanneer de admin
        // een afhaal-order op de order-edit pagina als "klaar" markeert.
        // Zie includes/pickup-ready.php.
        'pickup_ready_enabled'       => false,
        'pickup_ready_email_enabled' => true,
        'pickup_ready_email_subject' => 'Je bestelling #{ordernummer} ligt klaar om af te halen!',
        'pickup_ready_email_body'    => "Hoi {voornaam},\n\nGoed nieuws — je bestelling #{ordernummer} ligt klaar bij {afhaallocatie}.\n\nAfhalen kan vanaf: {afhaaldatum}{afhaaltijd}\n\nTot snel!",

        'pickup_ready_sms_enabled'                => false,
        'pickup_ready_sms_body'                   => 'Hoi {voornaam}, je bestelling #{ordernummer} ligt klaar bij {afhaallocatie}. Tot snel!',
        'pickup_ready_sms_provider_label'         => '',
        'pickup_ready_sms_endpoint_url'           => '',
        'pickup_ready_sms_api_key'                => '',
        'pickup_ready_sms_auth_header_name'       => 'Authorization',
        'pickup_ready_sms_auth_header_value'      => 'Bearer {api_key}',
        'pickup_ready_sms_recipient_field'        => 'recipients',
        'pickup_ready_sms_message_field'          => 'body',
        'pickup_ready_sms_from_field'             => 'originator',
        'pickup_ready_sms_from'                   => '',
        'pickup_ready_sms_default_country_prefix' => '31',
        'pickup_ready_sms_test_mode'              => true,

        // Bedankt-pagina (premium) — persoonlijke heading, bezorg-/afhaal-banner,
        // stappenstrip, cross-sell, factuurknop en vertrouwenselementen.
        // Zie includes/thankyou.php.
        'thankyou_enabled'            => false,
        'thankyou_heading_template'   => 'Bedankt, {voornaam}! Je bestelling is in goede handen',
        'thankyou_crosssell_enabled'  => false,
        'thankyou_crosssell_title'    => 'Nog een tip voor erbij',
        'thankyou_invoice_enabled'    => false,
        'thankyou_trust_return_text'  => '',
        'thankyou_trust_return_url'   => '',
        'thankyou_trust_contact_text' => '',

        // Verberg betaalde bezorgmethodes zodra gratis bezorging beschikbaar
        // is binnen dezelfde "Laten bezorgen"-kaart — zie mkcp_get_shipping_
        // choice_template_args() in includes/shipping-choice.php. Standaard
        // uit: een winkelier kan bewust een betaalde snelle/expresoptie naast
        // gratis standaardverzending willen tonen (bv. "Gratis 5-7 dagen" vs
        // "Express €6,95"), dus dit mag geen ongevraagde gedragsverandering
        // zijn voor bestaande installaties.
        'hide_paid_delivery_if_free' => false,
    ];

    $saved = get_option( 'mkcp_checkout_settings', [] );
    $cfg   = wp_parse_args( $saved, $defaults );

    $cfg['checkout_enabled']      = (bool) $cfg['checkout_enabled'];
    $cfg['header_enabled']        = (bool) $cfg['header_enabled'];
    $cfg['footer_enabled']        = (bool) $cfg['footer_enabled'];
    $cfg['steps_enabled']         = (bool) $cfg['steps_enabled'];
    $cfg['ssl_badge_enabled']     = (bool) $cfg['ssl_badge_enabled'];
    $cfg['ssl_badge_text']        = (string) $cfg['ssl_badge_text'];
    $cfg['payment_icons_enabled'] = (bool) $cfg['payment_icons_enabled'];
    $cfg['dequeue_theme_css']     = (bool) $cfg['dequeue_theme_css'];
    $cfg['dequeue_theme_hooks']   = (bool) $cfg['dequeue_theme_hooks'];
    $cfg['dequeue_theme_js']      = (bool) $cfg['dequeue_theme_js'];
    $cfg['btw_switch']            = (bool) $cfg['btw_switch'];
    $cfg['btw_follow_popup']      = (bool) $cfg['btw_follow_popup'];
    $cfg['order_review_collapsible_mobile'] = (bool) $cfg['order_review_collapsible_mobile'];
    $cfg['company_field_enabled'] = (bool) $cfg['company_field_enabled'];
    $cfg['order_notes_enabled']   = (bool) $cfg['order_notes_enabled'];
    $cfg['checkout_button_text']  = (string) $cfg['checkout_button_text'];
    $cfg['vat_checker_status_enabled'] = (bool) $cfg['vat_checker_status_enabled'];
    $cfg['header_logo_id']        = (int)  $cfg['header_logo_id'];
    if ( ! is_array( $cfg['footer_blocks'] ) )   $cfg['footer_blocks']   = [];
    if ( ! is_array( $cfg['checkout_blocks'] ) ) $cfg['checkout_blocks'] = [];
    $cfg['pickup_enabled'] = (bool) $cfg['pickup_enabled'];
    $cfg['hide_paid_delivery_if_free'] = (bool) $cfg['hide_paid_delivery_if_free'];
    if ( ! is_array( $cfg['pickup_locations'] ) ) $cfg['pickup_locations'] = [];
    $cfg['pickup_ready_enabled']       = (bool) $cfg['pickup_ready_enabled'];
    $cfg['pickup_ready_email_enabled'] = (bool) $cfg['pickup_ready_email_enabled'];
    $cfg['pickup_ready_sms_enabled']   = (bool) $cfg['pickup_ready_sms_enabled'];
    $cfg['pickup_ready_sms_test_mode'] = (bool) $cfg['pickup_ready_sms_test_mode'];
    $cfg['thankyou_enabled']           = (bool) $cfg['thankyou_enabled'];
    $cfg['thankyou_crosssell_enabled'] = (bool) $cfg['thankyou_crosssell_enabled'];
    $cfg['thankyou_invoice_enabled']   = (bool) $cfg['thankyou_invoice_enabled'];
    if ( ! is_array( $cfg['steps_labels'] ) || count( $cfg['steps_labels'] ) < 3 ) {
        $cfg['steps_labels'] = [ 'Winkelwagen', 'Gegevens', 'Bevestiging' ];
    }

    return $cfg;
}
