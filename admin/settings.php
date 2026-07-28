<?php
/**
 * MK Cart Popup — Settings Page (Premium UI)
 *
 * Adds a settings page under WooCommerce → Cart Popup.
 * All values are saved to wp_options as 'mkcp_settings'.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once MKCP_PATH . 'admin/scaffold.php';
require_once MKCP_PATH . 'admin/changelog.php';
require_once MKCP_PATH . 'admin/docs.php';


// ── Register page under WooCommerce ───────────────────────────────────────────

add_action( 'admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        __( 'Cart Popup', 'mk-cart-popup' ),
        __( 'Cart Popup', 'mk-cart-popup' ),
        'manage_options',
        'mkcp-settings',
        'mkcp_render_settings_page'
    );
} );

add_action( 'admin_menu', function() {
    global $submenu;
    if ( ! isset( $submenu['woocommerce'] ) ) return;
    foreach ( $submenu['woocommerce'] as &$item ) {
        if ( isset( $item[2] ) && $item[2] === 'mkcp-settings' ) {
            $item[0] = __( 'Cart Popup', 'mk-cart-popup' )
                . ' <span class="mkcp-menu-badge">MK</span>';
            break;
        }
    }
    unset( $item );
}, 999 );

// ── Donkere modus voor de admin-UI zelf — persoonlijke voorkeur per gebruiker ──
//
// Los van de bezoekers-dark-mode van de popup (mk-cart-popup.php): settings.css
// is standaard al donker en schakelt via prefers-color-scheme automatisch naar
// licht (zie :root vs. @media (prefers-color-scheme: light) daar). Deze klasse
// forceert een keuze ongeacht het systeem — 'auto' (geen meta opgeslagen) laat
// dat gedrag intact. Als body class toegevoegd vóórdat de pagina rendert, dus
// geen flits van het verkeerde thema bij het laden.
add_filter( 'admin_body_class', function( $classes ) {
    $page = sanitize_key( $_GET['page'] ?? '' );
    if ( ! in_array( $page, [ 'mkcp-settings', 'mkcp-docs' ], true ) ) return $classes;
    $pref = get_user_meta( get_current_user_id(), 'mkcp_admin_theme', true );
    if ( ! in_array( $pref, [ 'light', 'dark' ], true ) ) return $classes;
    return $classes . ' mkcp-theme-' . $pref . ' ';
} );

add_action( 'wp_ajax_mkcp_set_admin_theme', function() {
    check_ajax_referer( 'mkcp_admin_theme', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    $theme = sanitize_key( $_POST['theme'] ?? 'auto' );
    if ( ! in_array( $theme, [ 'auto', 'light', 'dark' ], true ) ) $theme = 'auto';
    if ( $theme === 'auto' ) {
        delete_user_meta( get_current_user_id(), 'mkcp_admin_theme' );
    } else {
        update_user_meta( get_current_user_id(), 'mkcp_admin_theme', $theme );
    }
    wp_send_json_success();
} );

add_action( 'admin_head', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    ?>
    <style id="mkcp-admin-menu-styles">
        #adminmenu .mkcp-menu-badge {
            display        : inline-block;
            background     : #5d6bf8;
            color          : #fff;
            font-size      : 9px;
            font-weight    : 700;
            letter-spacing : .4px;
            padding        : 1px 5px;
            border-radius  : 3px;
            vertical-align : middle;
            margin-left    : 5px;
            line-height    : 1.6;
            text-transform : uppercase;
            pointer-events : none;
        }
    </style>
    <?php
} );


// ── Plugin-rij links (Plugins-pagina) ────────────────────────────────────────
//
// Volgorde: Instellingen | Documentatie | [Deactiveren — door WordPress]

add_filter( 'plugin_action_links_' . plugin_basename( MKCP_PATH . 'mk-cart-popup.php' ), function( $links ) {
    $custom = [
        '<a href="' . esc_url( admin_url( 'admin.php?page=mkcp-settings' ) ) . '">' . __( 'Instellingen', 'mk-cart-popup' ) . '</a>',
        '<a href="' . esc_url( admin_url( 'admin.php?page=mkcp-docs' ) ) . '">' . __( 'Documentatie', 'mk-cart-popup' ) . '</a>',
    ];
    return array_merge( $custom, $links );
} );


// ── Save handler (admin_init = vóór HTML output, zodat redirect werkt) ────────

add_action( 'admin_init', function() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'mkcp-settings' ) return;
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) return;
    if ( ! isset( $_POST['mkcp_save_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['mkcp_save_nonce'], 'mkcp_save_settings' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $post = $_POST;

    // License key is always saved first so users can always update or clear it.
    if ( isset( $post['mkcp_license_key'] ) ) {
        $new_key = sanitize_text_field( wp_unslash( $post['mkcp_license_key'] ) );
        $old_key = (string) get_option( 'mkcp_license_key', '' );
        if ( $new_key !== $old_key ) {
            update_option( 'mkcp_license_key', $new_key );
            mkcp_license_invalidate();
        }
    }

    // Checkout settings — always save unconditionally, regardless of license tier.
    // The premium gate is enforced on the frontend (checkout-frontend.php).
    $raw_steps_labels = (array) ( $post['mkcp_checkout_steps_labels'] ?? [] );
    $checkout = [
        'checkout_enabled'      => ! empty( $post['mkcp_checkout_enabled'] ),
        'header_enabled'        => ! empty( $post['mkcp_checkout_header_enabled'] ),
        'header_logo_id'        => absint( $post['mkcp_checkout_header_logo_id'] ?? 0 ),
        'header_bg'             => sanitize_hex_color( $post['mkcp_checkout_header_bg'] ?? '#ffffff' ) ?: '#ffffff',
        'footer_enabled'        => ! empty( $post['mkcp_checkout_footer_enabled'] ),
        'footer_blocks'         => mkcp_sanitize_blocks( wp_unslash( $post['mkcp_footer_blocks'] ?? '[]' ) ),
        'checkout_blocks'       => mkcp_sanitize_blocks( wp_unslash( $post['mkcp_checkout_blocks'] ?? '[]' ), 'mkcp_checkout_zone_is_valid' ),
        'steps_enabled'         => ! empty( $post['mkcp_checkout_steps_enabled'] ),
        'steps_labels'          => array_map( 'sanitize_text_field', array_slice( $raw_steps_labels, 0, 3 ) ),
        'ssl_badge_enabled'     => ! empty( $post['mkcp_checkout_ssl_badge_enabled'] ),
        'ssl_badge_text'        => sanitize_text_field( $post['mkcp_checkout_ssl_badge_text'] ?? 'SSL-versleuteling' ),
        'payment_icons_enabled' => ! empty( $post['mkcp_checkout_payment_icons_enabled'] ),
        'dequeue_theme_css'     => ! empty( $post['mkcp_checkout_dequeue_theme_css'] ),
        'dequeue_theme_hooks'   => ! empty( $post['mkcp_checkout_dequeue_theme_hooks'] ),
        'dequeue_theme_js'      => ! empty( $post['mkcp_checkout_dequeue_theme_js'] ),
        'btw_follow_popup'             => ! isset( $post['mkcp_checkout_btw_follow_popup'] ) || ! empty( $post['mkcp_checkout_btw_follow_popup'] ),
        'btw_switch'                   => ! empty( $post['mkcp_checkout_btw_switch'] ),
        'order_review_collapsible_mobile' => ! empty( $post['mkcp_checkout_order_review_collapsible_mobile'] ),
        'postcode_checker_lock_fields' => ! empty( $post['mkcp_checkout_postcode_checker_lock_fields'] ),
        'country_field_visible'        => ! empty( $post['mkcp_checkout_country_field_visible'] ),
        'country_field_locked'         => ! empty( $post['mkcp_checkout_country_field_locked'] ),
        'company_field_enabled'        => ! empty( $post['mkcp_checkout_company_field_enabled'] ),
        'order_notes_enabled'          => ! empty( $post['mkcp_checkout_order_notes_enabled'] ),
        'checkout_button_text'         => sanitize_text_field( $post['mkcp_checkout_button_text'] ?? '' ),
        'vat_checker_status_enabled'   => ! empty( $post['mkcp_checkout_vat_checker_status_enabled'] ),

        // Bezorgdatum kiezer
        'delivery_date_enabled'        => ! empty( $post['mkcp_dd_enabled'] ),
        'delivery_date_required'       => ! empty( $post['mkcp_dd_required'] ),
        'delivery_date_label'          => sanitize_text_field( $post['mkcp_dd_label'] ?? 'Gewenste bezorgdatum' ),
        'delivery_date_disclaimer'     => isset( $post['mkcp_dd_disclaimer'] )
            ? sanitize_text_field( wp_unslash( $post['mkcp_dd_disclaimer'] ) )
            : 'Dit is een inschatting — in uitzonderlijke gevallen (bv. drukte bij de vervoerder) kan de bezorging uitlopen.',
        'delivery_date_cutoff_time'    => sanitize_text_field( $post['mkcp_dd_cutoff_time'] ?? '12:00' ),
        'delivery_date_lead_days'      => max( 0, min( 30, (int) ( $post['mkcp_dd_lead_days'] ?? 1 ) ) ),
        'delivery_date_shipping_days'  => array_map( 'intval', (array) ( $post['mkcp_dd_shipping_days'] ?? [] ) ),
        'delivery_date_blackout_dates' => mkcp_sanitize_blackout_dates_field( wp_unslash( $post['mkcp_dd_blackout_dates'] ?? '' ) ),
        'delivery_date_calendar_range' => max( 7, min( 365, (int) ( $post['mkcp_dd_calendar_range'] ?? 60 ) ) ),
        'delivery_date_capacity_enabled' => ! empty( $post['mkcp_dd_capacity_enabled'] ),
        'delivery_date_capacity_max'     => max( 1, (int) ( $post['mkcp_dd_capacity_max'] ?? 20 ) ),
        'delivery_date_shipping_rules'   => mkcp_sanitize_dd_shipping_rules( $post ),

        // Afhalen
        'pickup_enabled'   => ! empty( $post['mkcp_pu_feature_enabled'] ),
        'pickup_locations' => mkcp_sanitize_pickup_locations( $post ),

        // Afhaalmeldingen
        'pickup_ready_enabled'       => ! empty( $post['mkcp_pu_ready_enabled'] ),
        'pickup_ready_email_enabled' => ! empty( $post['mkcp_pu_ready_email_enabled'] ),
        'pickup_ready_email_subject' => sanitize_text_field( $post['mkcp_pu_ready_email_subject'] ?? '' ),
        'pickup_ready_email_body'    => sanitize_textarea_field( wp_unslash( $post['mkcp_pu_ready_email_body'] ?? '' ) ),
        'pickup_ready_sms_enabled'   => ! empty( $post['mkcp_pu_ready_sms_enabled'] ),
        'pickup_ready_sms_body'      => sanitize_textarea_field( wp_unslash( $post['mkcp_pu_ready_sms_body'] ?? '' ) ),
        'pickup_ready_sms_provider_label'    => sanitize_text_field( $post['mkcp_pu_ready_sms_provider_label'] ?? '' ),
        'pickup_ready_sms_endpoint_url'      => esc_url_raw( $post['mkcp_pu_ready_sms_endpoint_url'] ?? '' ),
        'pickup_ready_sms_api_key'           => sanitize_text_field( wp_unslash( $post['mkcp_pu_ready_sms_api_key'] ?? '' ) ),
        'pickup_ready_sms_auth_header_name'  => sanitize_text_field( $post['mkcp_pu_ready_sms_auth_header_name'] ?? 'Authorization' ),
        'pickup_ready_sms_auth_header_value' => sanitize_text_field( wp_unslash( $post['mkcp_pu_ready_sms_auth_header_value'] ?? 'Bearer {api_key}' ) ),
        'pickup_ready_sms_recipient_field'   => sanitize_key( $post['mkcp_pu_ready_sms_recipient_field'] ?? 'recipients' ),
        'pickup_ready_sms_message_field'     => sanitize_key( $post['mkcp_pu_ready_sms_message_field'] ?? 'body' ),
        'pickup_ready_sms_from_field'        => sanitize_key( $post['mkcp_pu_ready_sms_from_field'] ?? 'originator' ),
        'pickup_ready_sms_from'              => sanitize_text_field( $post['mkcp_pu_ready_sms_from'] ?? '' ),
        'pickup_ready_sms_default_country_prefix' => preg_replace( '/\D/', '', $post['mkcp_pu_ready_sms_default_country_prefix'] ?? '31' ) ?: '31',
        'pickup_ready_sms_test_mode'         => ! empty( $post['mkcp_pu_ready_sms_test_mode'] ),

        // Bedankt-pagina
        'thankyou_enabled'            => ! empty( $post['mkcp_ty_enabled'] ),
        'thankyou_heading_template'   => sanitize_text_field( wp_unslash( $post['mkcp_ty_heading_template'] ?? '' ) ),
        'thankyou_crosssell_enabled'  => ! empty( $post['mkcp_ty_crosssell_enabled'] ),
        'thankyou_crosssell_title'    => sanitize_text_field( $post['mkcp_ty_crosssell_title'] ?? '' ),
        'thankyou_invoice_enabled'    => ! empty( $post['mkcp_ty_invoice_enabled'] ),
        'thankyou_trust_return_text'  => sanitize_text_field( $post['mkcp_ty_trust_return_text'] ?? '' ),
        'thankyou_trust_return_url'   => esc_url_raw( $post['mkcp_ty_trust_return_url'] ?? '' ),
        'thankyou_trust_contact_text' => sanitize_text_field( $post['mkcp_ty_trust_contact_text'] ?? '' ),

        'hide_paid_delivery_if_free' => ! empty( $post['mkcp_hide_paid_delivery'] ),
    ];
    update_option( 'mkcp_checkout_settings', $checkout );

    // Block saving popup settings when no valid license is active.
    $license_tier = mkcp_license_tier();
    if ( $license_tier === 'none' ) {
        $tab     = sanitize_key( $post['mkcp_active_tab']     ?? 'licentie' );
        $product = sanitize_key( $post['mkcp_active_product'] ?? 'popup' );
        wp_safe_redirect( admin_url( 'admin.php?page=mkcp-settings&saved=1&tab=' . $tab . '&product=' . $product ) );
        exit;
    }
    $is_premium = mkcp_license_has( 'premium' );
    $existing   = mkcp_config();
    $allowed_icons = array_keys( mkcp_usp_icons() );
    $usp_icons     = array_map( 'sanitize_text_field', $post['mkcp_usp_icon'] ?? [] );
    $usp_texts     = array_map( 'sanitize_text_field', $post['mkcp_usp_text'] ?? [] );
    $usps = [];
    foreach ( $usp_icons as $i => $icon ) {
        if ( ! in_array( $icon, $allowed_icons, true ) ) continue;
        $text = $usp_texts[ $i ] ?? '';
        if ( $text ) $usps[] = [ 'icon' => $icon, 'text' => $text ];
    }

    $pay_urls        = array_map( 'esc_url_raw', (array) ( $post['mkcp_pay_icon_url'] ?? [] ) );
    $pay_labels      = array_map( 'sanitize_text_field', (array) ( $post['mkcp_pay_icon_label'] ?? [] ) );
    $payment_icons   = [];
    foreach ( $pay_urls as $i => $url ) {
        if ( $url ) $payment_icons[] = [ 'url' => $url, 'label' => $pay_labels[ $i ] ?? '' ];
    }

    $settings = [
        // ── Basic fields (all licensed tiers) ────────────────────────────────
        'enabled'                 => ! empty( $post['mkcp_enabled'] ),
        'title'                   => sanitize_text_field( $post['mkcp_title']                   ?? '' ),
        'btn_checkout'            => sanitize_text_field( $post['mkcp_btn_checkout']            ?? '' ),
        'col_product'             => sanitize_text_field( $post['mkcp_col_product']             ?? '' ),
        'col_total'               => sanitize_text_field( $post['mkcp_col_total']              ?? '' ),
        'empty_heading'           => sanitize_text_field( $post['mkcp_empty_heading']           ?? '' ),
        'empty_button'            => sanitize_text_field( $post['mkcp_empty_button']            ?? '' ),
        'free_shipping_bar'       => ! empty( $post['mkcp_free_shipping_bar'] ),
        'free_shipping_threshold' => floatval( $post['mkcp_free_shipping_threshold']            ?? 0 ),
        'shipping_note'           => sanitize_text_field( $post['mkcp_shipping_note']           ?? '' ),
        'free_shipping_note'      => sanitize_text_field( $post['mkcp_free_shipping_note']      ?? '' ),
        'redirect_cart'           => ! empty( $post['mkcp_redirect_cart'] ),
        'redirect_cart_url'       => esc_url_raw( $post['mkcp_redirect_cart_url']              ?? '' ),
        'usps'                    => $usps,
        'min_order_amount'        => floatval( $post['mkcp_min_order_amount'] ?? 0 ),
        'show_coupon'             => ! empty( $post['mkcp_show_coupon'] ),
        'payment_icons'           => $payment_icons,
        'delivery_preview_enabled' => ! empty( $post['mkcp_delivery_preview_enabled'] ),
        'cart_count_badge_enabled'  => ! empty( $post['mkcp_cart_count_badge_enabled'] ),
        'cart_count_badge_selector' => sanitize_text_field( wp_unslash( $post['mkcp_cart_count_badge_selector'] ?? '' ) ),
        'cart_count_badge_position' => in_array( $post['mkcp_cart_count_badge_position'] ?? '', [ 'top-right', 'top-left', 'bottom-right', 'bottom-left' ], true ) ? $post['mkcp_cart_count_badge_position'] : 'top-right',

        // ── Premium fields — only updated when tier is premium, otherwise keep existing ──
        'btw_split'               => $is_premium ? ! empty( $post['mkcp_btw_split'] )                                                          : (bool) ( $existing['btw_split'] ?? false ),
        'label_excl_tax'          => $is_premium ? sanitize_text_field( $post['mkcp_label_excl_tax']          ?? '' )                          : (string) ( $existing['label_excl_tax'] ?? '' ),
        'label_incl_tax'          => $is_premium ? sanitize_text_field( $post['mkcp_label_incl_tax']          ?? '' )                          : (string) ( $existing['label_incl_tax'] ?? '' ),
        'analytics_enabled'       => $is_premium ? ! empty( $post['mkcp_analytics_enabled'] )                                                  : (bool) ( $existing['analytics_enabled'] ?? false ),
        'analytics_wc_stats'      => $is_premium ? ! empty( $post['mkcp_analytics_wc_stats'] )                                                 : (bool) ( $existing['analytics_wc_stats'] ?? false ),
        'analytics_debug'         => $is_premium ? ! empty( $post['mkcp_analytics_debug'] )                                                    : (bool) ( $existing['analytics_debug'] ?? false ),
        'save_for_later'          => $is_premium ? ! empty( $post['mkcp_save_for_later'] )                                                      : (bool) ( $existing['save_for_later'] ?? false ),
        'cart_icon_selector'      => $is_premium ? sanitize_text_field( wp_unslash( $post['mkcp_cart_icon_selector'] ?? '' ) )                  : (string) ( $existing['cart_icon_selector'] ?? '' ),
        'cart_badge_position'     => $is_premium ? ( in_array( $post['mkcp_cart_badge_position'] ?? '', [ 'top-right', 'top-left', 'bottom-right', 'bottom-left' ], true ) ? $post['mkcp_cart_badge_position'] : 'top-right' ) : (string) ( $existing['cart_badge_position'] ?? 'top-right' ),
        'save_cart_url'           => $is_premium ? ! empty( $post['mkcp_save_cart_url'] )                                                       : (bool) ( $existing['save_cart_url'] ?? false ),
        'save_cart_email'         => $is_premium ? ! empty( $post['mkcp_save_cart_email'] )                                                     : (bool) ( $existing['save_cart_email'] ?? false ),
        'save_cart_email_subject' => $is_premium ? sanitize_text_field( $post['mkcp_save_cart_email_subject'] ?? '' )                           : (string) ( $existing['save_cart_email_subject'] ?? '' ),
        'save_cart_email_body'    => $is_premium ? sanitize_textarea_field( wp_unslash( $post['mkcp_save_cart_email_body'] ?? '' ) )             : (string) ( $existing['save_cart_email_body'] ?? '' ),
        'save_cart_expiry_days'   => $is_premium ? max( 1, min( 30, intval( $post['mkcp_save_cart_expiry_days'] ?? 7 ) ) )                      : (int) ( $existing['save_cart_expiry_days'] ?? 7 ),
        'abandoned_cart_enabled'  => $is_premium ? ! empty( $post['mkcp_abandoned_cart_enabled'] )                                             : (bool) ( $existing['abandoned_cart_enabled'] ?? false ),
        'abandoned_cart_delay'    => $is_premium ? max( 30, intval( $post['mkcp_abandoned_cart_delay'] ?? 60 ) )                                 : (int) ( $existing['abandoned_cart_delay'] ?? 60 ),
        'abandoned_cart_subject'  => $is_premium ? sanitize_text_field( $post['mkcp_abandoned_cart_subject'] ?? '' )                             : (string) ( $existing['abandoned_cart_subject'] ?? '' ),
        'abandoned_cart_body'     => $is_premium ? sanitize_textarea_field( wp_unslash( $post['mkcp_abandoned_cart_body'] ?? '' ) )               : (string) ( $existing['abandoned_cart_body'] ?? '' ),
        'stock_indicator'         => $is_premium ? ! empty( $post['mkcp_stock_indicator'] )                                                     : (bool) ( $existing['stock_indicator'] ?? false ),
        'stock_threshold'         => $is_premium ? max( 1, intval( $post['mkcp_stock_threshold'] ?? 5 ) )                                       : (int) ( $existing['stock_threshold'] ?? 5 ),
        'blocks'                  => $is_premium ? mkcp_sanitize_blocks( wp_unslash( $post['mkcp_blocks'] ?? '[]' ) )                           : ( $existing['blocks'] ?? [] ),
        'crosssell_enabled'       => ! empty( $post['mkcp_crosssell_enabled'] ),
        'crosssell_mode'          => in_array( $post['mkcp_crosssell_mode'] ?? '', [ 'crosssells', 'category' ], true ) ? $post['mkcp_crosssell_mode'] : 'category',
        'crosssell_limit'         => max( 1, min( 6, intval( $post['mkcp_crosssell_limit'] ?? 3 ) ) ),
        'crosssell_title'         => sanitize_text_field( $post['mkcp_crosssell_title'] ?? '' ),
        'style_accent'            => $is_premium ? ( sanitize_hex_color( $post['mkcp_style_accent']   ?? '' ) ?: '#2e7d32' ) : (string) ( $existing['style_accent']   ?? '#2e7d32' ),
        'style_bg'                => $is_premium ? ( sanitize_hex_color( $post['mkcp_style_bg']       ?? '' ) ?: '#ffffff' ) : (string) ( $existing['style_bg']       ?? '#ffffff' ),
        'style_text'              => $is_premium ? ( sanitize_hex_color( $post['mkcp_style_text']     ?? '' ) ?: '#1a1a1a' ) : (string) ( $existing['style_text']     ?? '#1a1a1a' ),
        'style_btn_text'          => $is_premium ? ( sanitize_hex_color( $post['mkcp_style_btn_text']  ?? '' ) ?: '#ffffff' ) : (string) ( $existing['style_btn_text'] ?? '#ffffff' ),
        'style_border'            => $is_premium ? ( sanitize_hex_color( $post['mkcp_style_border']   ?? '' ) ?: '#cccccc' ) : (string) ( $existing['style_border']   ?? '#cccccc' ),
        'style_danger'            => $is_premium ? ( sanitize_hex_color( $post['mkcp_style_danger']   ?? '' ) ?: '#d32f2f' ) : (string) ( $existing['style_danger']   ?? '#d32f2f' ),
        'style_width'             => $is_premium ? max( 360, min( 640, intval( $post['mkcp_style_width'] ?? 500 ) ) )       : (int) ( $existing['style_width']       ?? 500 ),
        'style_btn_style'         => $is_premium ? ( in_array( $post['mkcp_style_btn_style'] ?? '', [ 'filled', 'outline' ], true ) ? $post['mkcp_style_btn_style'] : 'filled' ) : (string) ( $existing['style_btn_style'] ?? 'filled' ),
        'style_position'          => $is_premium ? ( in_array( $post['mkcp_style_position'] ?? '', [ 'left', 'right', 'center' ], true ) ? $post['mkcp_style_position'] : 'right' ) : (string) ( $existing['style_position'] ?? 'right' ),
        'style_expand_enabled'    => $is_premium ? ! empty( $post['mkcp_style_expand_enabled'] )                              : (bool) ( $existing['style_expand_enabled'] ?? true ),
        'style_dark_mode_enabled' => $is_premium ? ! empty( $post['mkcp_style_dark_mode_enabled'] )                           : (bool) ( $existing['style_dark_mode_enabled'] ?? true ),
        'mobile_app_experience'   => $is_premium ? ! empty( $post['mkcp_mobile_app_experience'] )                             : (bool) ( $existing['mobile_app_experience'] ?? true ),
    ];

    update_option( 'mkcp_settings', $settings );

    $tab     = sanitize_key( $post['mkcp_active_tab']     ?? 'general' );
    $product = sanitize_key( $post['mkcp_active_product'] ?? 'popup' );
    wp_safe_redirect( admin_url( 'admin.php?page=mkcp-settings&saved=1&tab=' . $tab . '&product=' . $product ) );
    exit;
} );


// ── Enqueue admin assets ──────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', function( $hook ) {
    $pages = [ 'woocommerce_page_mkcp-settings', 'admin_page_mkcp-docs' ];
    if ( ! in_array( $hook, $pages, true ) ) return;
    wp_enqueue_style( 'mkcp-admin', MKCP_URL . 'admin/assets/settings.css', [], MKCP_VER );
    if ( $hook === 'woocommerce_page_mkcp-settings' ) {
        wp_enqueue_media();
        wp_enqueue_script( 'mkcp-admin', MKCP_URL . 'admin/assets/settings.js', [ 'jquery', 'media-editor' ], MKCP_VER, true );
        wp_localize_script( 'mkcp-admin', 'mkcpAdmin', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'adminPostUrl'   => admin_url( 'admin-post.php' ),
            'licenseNonce'   => wp_create_nonce( 'mkcp_license_nonce' ),
            'licenseTier'    => mkcp_license_tier(),
            'testEmailNonce' => wp_create_nonce( 'mkcp_test_email' ),
            'themeNonce'     => wp_create_nonce( 'mkcp_admin_theme' ),
            'homeUrl'        => home_url( '/' ),
        ] );

        // Builder assets
        wp_enqueue_script( 'mkcp-sortable', MKCP_URL . 'admin/assets/sortable.min.js', [], '1.15.3', true );
        wp_enqueue_script( 'mkcp-builder', MKCP_URL . 'admin/assets/builder.js', [ 'jquery', 'mkcp-sortable', 'media-editor' ], MKCP_VER, true );
        wp_enqueue_script( 'mkcp-checkout-admin', MKCP_URL . 'admin/assets/checkout.js', [ 'jquery', 'mkcp-sortable', 'media-editor' ], MKCP_VER, true );
        wp_localize_script( 'mkcp-checkout-admin', 'mkcpCheckoutBuilder', [
            'fields' => mkcp_checkout_known_fields(),
        ] );
        wp_enqueue_style( 'mkcp-checkout-admin', MKCP_URL . 'admin/assets/checkout.css', [ 'mkcp-admin' ], MKCP_VER );
        wp_enqueue_style( 'mkcp-builder', MKCP_URL . 'admin/assets/builder.css', [ 'mkcp-admin' ], MKCP_VER );
        wp_enqueue_style( 'mk-cart-popup-preview', MKCP_URL . 'assets/cart-popup.css', [], MKCP_VER );

        // Eigen kleuren/breedte (Instellingen → Styling) — zelfde mechanisme als
        // op de live site (zie mk-cart-popup.php). Zonder dit blok laadt de
        // live-preview alleen de :root-defaults uit cart-popup.css, en lijkt het
        // net na opslaan/herladen alsof de opgeslagen kleuren niet zijn
        // doorgekomen — ze staan dan wél goed in de database en op de
        // daadwerkelijke site, alleen de admin-preview toonde ze niet.
        if ( mkcp_license_has( 'premium' ) ) {
            wp_add_inline_style( 'mk-cart-popup-preview', mkcp_style_inline_css( mkcp_config() ) );
        }
        wp_localize_script( 'mkcp-builder', 'mkcpBuilder', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'mkcp_builder_preview' ),
            'licenseNonce'  => wp_create_nonce( 'mkcp_license_nonce' ),
        ] );

        // Onboarding-tour (Driver.js) — vendored, zelfde patroon als
        // mkcp-sortable hierboven. mkcp_show_onboarding wordt gezet door
        // register_activation_hook() in mk-cart-popup.php en hier meteen
        // weer verwijderd, zodat de tour maar één keer automatisch start.
        wp_enqueue_style( 'mkcp-driver', MKCP_URL . 'admin/assets/driver.css', [], '1.3.1' );
        wp_enqueue_script( 'mkcp-driver', MKCP_URL . 'admin/assets/driver.iife.js', [], '1.3.1', true );
        wp_enqueue_style( 'mkcp-onboarding', MKCP_URL . 'admin/assets/onboarding.css', [ 'mkcp-driver' ], MKCP_VER );
        wp_enqueue_script( 'mkcp-onboarding', MKCP_URL . 'admin/assets/onboarding.js', [ 'mkcp-driver', 'mkcp-admin' ], MKCP_VER, true );

        $mkcp_start_tour = (bool) get_option( 'mkcp_show_onboarding' );
        if ( $mkcp_start_tour ) {
            delete_option( 'mkcp_show_onboarding' );
        }
        wp_localize_script( 'mkcp-onboarding', 'mkcpOnboarding', mkcp_onboarding_localize_data( $mkcp_start_tour ) );
    } else {
        wp_enqueue_script( 'mkcp-admin', MKCP_URL . 'admin/assets/settings.js', [], MKCP_VER, true );
    }
} );


// ── AJAX: live builder preview ────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_builder_preview', function() {
    check_ajax_referer( 'mkcp_builder_preview', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $blocks = mkcp_sanitize_blocks( wp_unslash( $_POST['blocks'] ?? '[]' ) );

    // Inject preview blocks into config (static cache is empty in AJAX context).
    add_filter( 'mkcp_config', function( $config ) use ( $blocks ) {
        $config['blocks'] = $blocks;
        return $config;
    }, 99 );

    // Flag: cross-sell preview — fetches sample products even with empty cart.
    $GLOBALS['mkcp_builder_preview'] = true;

    ob_start();
    if ( function_exists( 'WC' ) && WC()->cart ) {
        // Force config cache reset by calling with filter already applied.
        include MKCP_PATH . 'templates/cart-popup.php';
    } else {
        echo '<div style="padding:20px;color:#888;font-size:12px">WooCommerce winkelwagen niet beschikbaar in preview.</div>';
    }
    $html = ob_get_clean();

    wp_send_json_success( [ 'html' => $html ] );
} );


// ── AJAX: builder quick-save ──────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_builder_save', function() {
    check_ajax_referer( 'mkcp_builder_preview', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $existing = get_option( 'mkcp_settings', [] );

    // JS sends mirror keys with mkcp_ prefix — strip it and whitelist allowed fields.
    // Deze lijsten dekken bewust alleen de velden die de live-preview builder
    // daadwerkelijk inline-bewerkbaar maakt (zie textFields/boolFields in
    // src/admin/builder/index.js — moet hiermee in sync blijven). Nieuwe
    // inline-bewerkbare velden in de builder moeten hier én daar toegevoegd
    // worden, anders slaat quick-save die wijziging stilzwijgend niet op.
    $allowed_text = [ 'title', 'btn_checkout', 'col_product', 'col_total',
                      'empty_heading', 'empty_button', 'shipping_note',
                      'free_shipping_note', 'crosssell_title' ];
    // Premium-only velden — zelfde gating als de volledige save-handler
    // hierboven, anders zou quick-save (dat geen is_premium-check had) een
    // basic-tier account's premium-instellingen alsnog kunnen overschrijven
    // omdat de builder-UI ze alleen visueel (disabled) vergrendelt, niet
    // server-side.
    // 'cart_count_badge_enabled' hoort hier bewust niet bij: de builder heeft
    // er geen veld voor (dat zit in de Shipping-tab), dus de JS stuurt 'm
    // nooit mee — stond deze wel in de whitelist, dan zette elke quick-save
    // 'm alsnog stilzwijgend terug naar uit.
    $allowed_bool          = [ 'free_shipping_bar', 'show_coupon', 'crosssell_enabled' ];
    $allowed_bool_premium  = [ 'btw_split', 'save_for_later', 'stock_indicator', 'save_cart_url', 'save_cart_email' ];

    $post = [];
    foreach ( $_POST as $k => $v ) {
        $key         = strpos( $k, 'mkcp_' ) === 0 ? substr( $k, 5 ) : $k;
        $post[ $key ] = $v;
    }

    foreach ( $allowed_text as $f ) {
        if ( isset( $post[ $f ] ) ) {
            $existing[ $f ] = sanitize_text_field( wp_unslash( $post[ $f ] ) );
        }
    }

    foreach ( $allowed_bool as $f ) {
        $existing[ $f ] = ! empty( $post[ $f ] );
    }

    if ( mkcp_license_has( 'premium' ) ) {
        foreach ( $allowed_bool_premium as $f ) {
            $existing[ $f ] = ! empty( $post[ $f ] );
        }
    }

    if ( isset( $post['free_shipping_threshold'] ) ) {
        $existing['free_shipping_threshold'] = floatval( $post['free_shipping_threshold'] );
    }

    if ( isset( $post['blocks'] ) ) {
        $blocks = mkcp_sanitize_blocks( wp_unslash( $post['blocks'] ) );
        if ( is_array( $blocks ) ) {
            $existing['blocks'] = $blocks;
        }
    }

    update_option( 'mkcp_settings', $existing );
    wp_send_json_success( [ 'message' => 'Opgeslagen' ] );
} );


// ── Page renderer ─────────────────────────────────────────────────────────────

function mkcp_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    include MKCP_PATH . 'admin/views/settings-page.php';
}
