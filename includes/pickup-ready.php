<?php
/**
 * MK Cart Popup — Afhaalmeldingen (premium)
 *
 * Knop op de WooCommerce-bestelpagina ("Deze bestelling kan worden opgehaald.")
 * die de klant een e-mail en/of sms stuurt zodra een afhaalbestelling klaarstaat.
 * Bouwt voort op de order-meta die includes/pickup.php al opslaat
 * (_mkcp_pickup_date/_location/_slot) — hier komt alleen de notificatie bij.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Config ─────────────────────────────────────────────────────────────────────

function mkcp_pu_ready_feature_enabled(): bool {
    if ( ! function_exists( 'mkcp_pickup_feature_enabled' ) || ! mkcp_pickup_feature_enabled() ) return false;
    return ! empty( mkcp_checkout_config()['pickup_ready_enabled'] );
}

function mkcp_pu_ready_config(): array {
    $cfg = mkcp_checkout_config();
    return [
        'email_enabled'   => ! empty( $cfg['pickup_ready_email_enabled'] ),
        'email_subject'   => (string) $cfg['pickup_ready_email_subject'],
        'email_body'      => (string) $cfg['pickup_ready_email_body'],

        'sms_enabled'             => ! empty( $cfg['pickup_ready_sms_enabled'] ),
        'sms_body'                => (string) $cfg['pickup_ready_sms_body'],
        'sms_endpoint_url'        => (string) $cfg['pickup_ready_sms_endpoint_url'],
        'sms_api_key'             => (string) $cfg['pickup_ready_sms_api_key'],
        'sms_auth_header_name'    => (string) $cfg['pickup_ready_sms_auth_header_name'],
        'sms_auth_header_value'   => (string) $cfg['pickup_ready_sms_auth_header_value'],
        'sms_recipient_field'     => (string) $cfg['pickup_ready_sms_recipient_field'],
        'sms_message_field'       => (string) $cfg['pickup_ready_sms_message_field'],
        'sms_from_field'          => (string) $cfg['pickup_ready_sms_from_field'],
        'sms_from'                => (string) $cfg['pickup_ready_sms_from'],
        'sms_default_country_prefix' => (string) $cfg['pickup_ready_sms_default_country_prefix'],
        'sms_test_mode'           => ! empty( $cfg['pickup_ready_sms_test_mode'] ),
    ];
}

/**
 * Plaatshouders voor e-mail/sms-body. $order = null levert testdata op, gebruikt
 * door de testmail/test-sms-knoppen op de instellingenpagina (geen echte order nodig).
 */
function mkcp_pu_ready_placeholders( ?WC_Order $order ): array {
    if ( ! $order ) {
        return [
            '{voornaam}'      => 'Test',
            '{achternaam}'    => 'Klant',
            '{ordernummer}'   => '12345',
            '{afhaallocatie}' => 'Voorbeeld Afhaalpunt',
            '{afhaaldatum}'   => function_exists( 'mkcp_dd_format_date' ) ? mkcp_dd_format_date( date( 'Y-m-d', strtotime( '+1 day' ) ) ) : date( 'Y-m-d', strtotime( '+1 day' ) ),
            '{afhaaltijd}'    => ' om 14:00',
            '{winkel_naam}'   => get_bloginfo( 'name' ),
        ];
    }

    $slot = (string) $order->get_meta( '_mkcp_pickup_slot' );
    $date = (string) $order->get_meta( '_mkcp_pickup_date' );

    return [
        '{voornaam}'      => $order->get_billing_first_name() ?: __( 'daar', 'mk-cart-popup' ),
        '{achternaam}'    => $order->get_billing_last_name(),
        '{ordernummer}'   => $order->get_order_number(),
        '{afhaallocatie}' => (string) $order->get_meta( '_mkcp_pickup_location' ),
        '{afhaaldatum}'   => $date && function_exists( 'mkcp_dd_format_date' ) ? mkcp_dd_format_date( $date ) : $date,
        '{afhaaltijd}'    => $slot ? ' om ' . $slot : '',
        '{winkel_naam}'   => get_bloginfo( 'name' ),
    ];
}


// ── Log (laatste 20 verzendpogingen — voor testmodus-inzicht en troubleshooting) ─

function mkcp_pu_ready_log_add( int $order_id, string $channel, array $result ): void {
    $log = get_option( 'mkcp_pu_ready_log', [] );
    if ( ! is_array( $log ) ) $log = [];

    $log[] = [
        'order_id' => $order_id,
        'channel'  => $channel,
        'success'  => ! empty( $result['success'] ),
        'message'  => (string) ( $result['message'] ?? '' ),
        'at'        => current_time( 'mysql' ),
    ];

    $log = array_slice( $log, -20 );
    update_option( 'mkcp_pu_ready_log', $log, false );
}


// ── E-mail versturen ──────────────────────────────────────────────────────────
// Zelfde opzet als mkcp_ac_send_email() (includes/abandoned-cart.php): strtr()-
// placeholders, inline-HTML wrap, wp_mail_content_type via een named callback
// (i.v.m. remove_filter — anonieme functies zijn niet verwijderbaar).

function mkcp_pu_ready_send_email( ?WC_Order $order, string $override_to = '' ): bool {
    $cfg = mkcp_pu_ready_config();
    $to  = $override_to !== '' ? $override_to : ( $order ? $order->get_billing_email() : '' );
    if ( ! is_email( $to ) ) return false;

    $vars    = mkcp_pu_ready_placeholders( $order );
    $subject = strtr( $cfg['email_subject'], $vars );
    $body    = strtr( $cfg['email_body'], $vars );

    $site_name = get_bloginfo( 'name' );
    $html  = '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#111;max-width:560px;margin:0 auto;padding:32px 16px">';
    $html .= '<h2 style="margin-bottom:8px">' . esc_html( $site_name ) . '</h2>';
    $html .= '<p style="line-height:1.7">' . nl2br( esc_html( $body ) ) . '</p>';
    $html .= '</body></html>';

    $set_html_type = static function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $set_html_type );
    $sent = wp_mail( $to, $subject, $html, [ 'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>' ] );
    remove_filter( 'wp_mail_content_type', $set_html_type );

    return (bool) $sent;
}


// ── Telefoonnummer normaliseren ──────────────────────────────────────────────
// Pure functie, geen WP-afhankelijkheden — zet 06.../+31 6.../0031... allemaal
// om naar cijfers-zonder-plus ("31612345678"), het formaat dat SMS-gateways
// als Spryng/MessageBird verwachten.

function mkcp_pu_normalize_phone( string $raw, string $default_country_prefix = '31' ): string {
    $digits = preg_replace( '/\D+/', '', $raw );
    if ( $digits === '' ) return '';

    if ( strpos( $raw, '00' ) === 0 ) {
        $digits = substr( $digits, 2 );
    } elseif ( $digits[0] === '0' ) {
        $digits = $default_country_prefix . substr( $digits, 1 );
    }

    return $digits;
}


// ── SMS versturen ─────────────────────────────────────────────────────────────
// Provider-agnostisch: JSON-payload met configureerbare veldnamen (defaults
// zijn letterlijk Spryng's/MessageBird's veldnamen), verstuurd via
// wp_remote_post(). Testmodus (standaard aan) logt de payload i.p.v. 'm te
// versturen — zo te testen zonder sms-account.

function mkcp_pu_ready_send_sms( ?WC_Order $order, string $override_phone = '' ): array {
    $cfg = mkcp_pu_ready_config();
    if ( ! $cfg['sms_enabled'] ) {
        return [ 'success' => null, 'message' => __( 'SMS-kanaal uitgeschakeld.', 'mk-cart-popup' ) ];
    }

    $raw_phone = $override_phone !== '' ? $override_phone : ( $order ? $order->get_billing_phone() : '' );
    $phone     = mkcp_pu_normalize_phone( $raw_phone, $cfg['sms_default_country_prefix'] );
    if ( $phone === '' ) {
        $result = [ 'success' => false, 'message' => __( 'Geen (geldig) telefoonnummer.', 'mk-cart-popup' ) ];
        mkcp_pu_ready_log_add( $order ? $order->get_id() : 0, 'sms', $result );
        return $result;
    }

    if ( empty( $cfg['sms_endpoint_url'] ) || empty( $cfg['sms_api_key'] ) ) {
        $result = [ 'success' => false, 'message' => __( 'SMS-provider niet volledig geconfigureerd (endpoint/API-key ontbreekt).', 'mk-cart-popup' ) ];
        mkcp_pu_ready_log_add( $order ? $order->get_id() : 0, 'sms', $result );
        return $result;
    }

    $vars    = mkcp_pu_ready_placeholders( $order );
    $message = strtr( $cfg['sms_body'], $vars );

    $payload = [];
    if ( $cfg['sms_recipient_field'] ) $payload[ $cfg['sms_recipient_field'] ] = [ $phone ];
    if ( $cfg['sms_message_field'] )   $payload[ $cfg['sms_message_field'] ]   = $message;
    if ( $cfg['sms_from_field'] && $cfg['sms_from'] !== '' ) $payload[ $cfg['sms_from_field'] ] = $cfg['sms_from'];

    // Ontsnappingsluik voor een provider met een afwijkende payload-vorm
    // (bv. form-encoded i.p.v. JSON) — later toe te voegen zonder deze functie te herschrijven.
    $payload = apply_filters( 'mkcp_pu_ready_sms_payload', $payload, $order, $cfg );

    $headers = [ 'Content-Type' => 'application/json' ];
    if ( $cfg['sms_auth_header_name'] ) {
        $headers[ $cfg['sms_auth_header_name'] ] = strtr( $cfg['sms_auth_header_value'], [ '{api_key}' => $cfg['sms_api_key'] ] );
    }

    if ( $cfg['sms_test_mode'] ) {
        $result = [ 'success' => true, 'message' => '[Testmodus] niet echt verzonden — payload: ' . wp_json_encode( $payload ) ];
        mkcp_pu_ready_log_add( $order ? $order->get_id() : 0, 'sms', $result );
        return $result;
    }

    $response = wp_remote_post( $cfg['sms_endpoint_url'], [
        'timeout' => 10,
        'headers' => $headers,
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        $result = [ 'success' => false, 'message' => $response->get_error_message() ];
    } else {
        $code = wp_remote_retrieve_response_code( $response );
        $ok   = $code >= 200 && $code < 300;
        $result = [
            'success' => $ok,
            'message' => $ok
                ? sprintf( __( 'Verzonden (HTTP %d).', 'mk-cart-popup' ), $code )
                : sprintf( 'HTTP %d: %s', $code, wp_trim_words( wp_remote_retrieve_body( $response ), 20 ) ),
        ];
    }

    mkcp_pu_ready_log_add( $order ? $order->get_id() : 0, 'sms', $result );
    return $result;
}


// ── AJAX: markeer als klaar ───────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_pu_mark_ready', function() {
    $order_id = absint( $_POST['order_id'] ?? 0 );
    check_ajax_referer( 'mkcp_pu_mark_ready_' . $order_id, 'nonce' );

    if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'mk-cart-popup' ) ] );
    }
    if ( ! mkcp_pu_ready_feature_enabled() ) {
        wp_send_json_error( [ 'message' => __( 'Functie niet ingeschakeld.', 'mk-cart-popup' ) ] );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || ! $order->get_meta( '_mkcp_pickup_date' ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen afhaalbestelling.', 'mk-cart-popup' ) ] );
    }

    $cfg        = mkcp_pu_ready_config();
    $email_sent = $cfg['email_enabled'] ? mkcp_pu_ready_send_email( $order ) : null;
    $sms_result = $cfg['sms_enabled']   ? mkcp_pu_ready_send_sms( $order )   : null;

    $order->update_meta_data( '_mkcp_pickup_ready_sent_at', current_time( 'mysql' ) );
    $order->update_meta_data( '_mkcp_pickup_ready_sent_by', get_current_user_id() );
    if ( $email_sent !== null ) {
        $order->update_meta_data( '_mkcp_pickup_ready_email_sent', $email_sent ? '1' : '' );
    }
    if ( $sms_result !== null ) {
        $order->update_meta_data( '_mkcp_pickup_ready_sms_sent', $sms_result['success'] ? '1' : '' );
        $order->update_meta_data( '_mkcp_pickup_ready_sms_error', $sms_result['success'] ? '' : $sms_result['message'] );
    }
    $order->save();

    $note = sprintf(
        'Afhaalmelding verstuurd door %s — e-mail: %s, sms: %s',
        wp_get_current_user()->display_name,
        $email_sent === null ? 'uit' : ( $email_sent ? 'OK' : 'mislukt' ),
        $sms_result === null ? 'uit' : ( $sms_result['success'] ? 'OK' : 'mislukt (' . $sms_result['message'] . ')' )
    );
    $order->add_order_note( $note );

    wp_send_json_success( [
        'sentAtHuman' => date_i18n( 'j M Y H:i', strtotime( $order->get_meta( '_mkcp_pickup_ready_sent_at' ) ) ),
        'sentBy'      => wp_get_current_user()->display_name,
        'emailSent'   => $email_sent,
        'smsSent'     => $sms_result['success'] ?? null,
        'smsError'    => $sms_result['message'] ?? '',
    ] );
} );


// ── AJAX: testmail / test-sms vanaf de instellingenpagina ────────────────────
// Hergebruikt de bestaande mkcp_test_email-nonce (al gelokaliseerd in
// admin/settings.php) — geen nieuwe nonce/localize-plumbing nodig.

add_action( 'wp_ajax_mkcp_pu_ready_send_test_email', function() {
    check_ajax_referer( 'mkcp_test_email', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'mk-cart-popup' ) ] );
    }

    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'message' => __( 'Ongeldig e-mailadres.', 'mk-cart-popup' ) ] );
    }

    if ( mkcp_pu_ready_send_email( null, $email ) ) {
        wp_send_json_success( [ 'message' => __( 'Testmail verzonden!', 'mk-cart-popup' ) ] );
    } else {
        wp_send_json_error( [ 'message' => __( 'Versturen mislukt. Controleer je SMTP-instellingen.', 'mk-cart-popup' ) ] );
    }
} );

add_action( 'wp_ajax_mkcp_pu_ready_send_test_sms', function() {
    check_ajax_referer( 'mkcp_test_email', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'mk-cart-popup' ) ] );
    }

    $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
    if ( $phone === '' ) {
        wp_send_json_error( [ 'message' => __( 'Vul een telefoonnummer in.', 'mk-cart-popup' ) ] );
    }

    $result = mkcp_pu_ready_send_sms( null, $phone );
    if ( $result['success'] ) {
        wp_send_json_success( [ 'message' => $result['message'] ] );
    } else {
        wp_send_json_error( [ 'message' => $result['message'] ] );
    }
} );


// ── Admin bestelpagina: knop "Deze bestelling kan worden opgehaald." ─────────
// Hook-slot woocommerce_admin_order_data_after_billing_address is al bezet door
// de read-only afhaal-info in includes/pickup.php — deze knop komt daarom bij
// het verzendadres i.p.v. het factuuradres.

add_action( 'woocommerce_admin_order_data_after_shipping_address', function( $order ) {
    if ( ! mkcp_pu_ready_feature_enabled() ) return;
    if ( ! $order->get_meta( '_mkcp_pickup_date' ) ) return;
    if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) return;

    $cfg      = mkcp_pu_ready_config();
    $sent_at  = $order->get_meta( '_mkcp_pickup_ready_sent_at' );
    $sent_by  = $order->get_meta( '_mkcp_pickup_ready_sent_by' );
    $btn_text = $sent_at ? __( 'Opnieuw versturen', 'mk-cart-popup' ) : __( 'Deze bestelling kan worden opgehaald.', 'mk-cart-popup' );

    $status_text = '';
    if ( $sent_at ) {
        $user = $sent_by ? get_userdata( (int) $sent_by ) : false;
        $status_text = sprintf(
            /* translators: 1: datum/tijd, 2: gebruikersnaam */
            __( 'Verstuurd op %1$s door %2$s', 'mk-cart-popup' ),
            date_i18n( 'j M Y H:i', strtotime( $sent_at ) ),
            $user ? $user->display_name : '—'
        );
        $parts = [];
        if ( $cfg['email_enabled'] ) $parts[] = 'e-mail: ' . ( $order->get_meta( '_mkcp_pickup_ready_email_sent' ) ? '✓' : '✗' );
        if ( $cfg['sms_enabled'] )   $parts[] = 'sms: '    . ( $order->get_meta( '_mkcp_pickup_ready_sms_sent' )   ? '✓' : '✗' );
        if ( $parts ) $status_text .= ' — ' . implode( ' · ', $parts );
    }
    ?>
    <div class="mkcp-pu-ready-box" id="mkcp-pu-ready-box" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
         style="margin-top:12px;padding-top:12px;border-top:1px solid #eee">
        <h4 style="margin:0 0 8px"><?php esc_html_e( 'Afhaalmelding', 'mk-cart-popup' ); ?></h4>
        <button type="button" class="button button-primary" id="mkcp-pu-ready-btn"><?php echo esc_html( $btn_text ); ?></button>
        <p class="mkcp-pu-ready-status" id="mkcp-pu-ready-status" style="margin:8px 0 0;font-size:12px;color:#666"><?php echo esc_html( $status_text ); ?></p>
    </div>
    <?php
}, 20 );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( ! function_exists( 'wc_get_page_screen_id' ) || $hook !== wc_get_page_screen_id( 'shop-order' ) ) return;
    if ( ! mkcp_pu_ready_feature_enabled() ) return;

    global $post, $theorder;
    $order = $theorder ?? ( $post ? wc_get_order( $post->ID ) : null );
    if ( ! $order || ! $order->get_meta( '_mkcp_pickup_date' ) ) return;
    if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) ) return;

    wp_enqueue_script( 'mkcp-pickup-ready', MKCP_URL . 'admin/assets/pickup-ready.js', [ 'jquery' ], MKCP_VER, true );
    wp_localize_script( 'mkcp-pickup-ready', 'mkcpPuReady', [
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'orderId'     => $order->get_id(),
        'nonce'       => wp_create_nonce( 'mkcp_pu_mark_ready_' . $order->get_id() ),
        'confirmText' => __( 'Afhaalmelding (e-mail + sms) versturen naar de klant?', 'mk-cart-popup' ),
        'sendingText' => __( 'Versturen…', 'mk-cart-popup' ),
        'resendText'  => __( 'Opnieuw versturen', 'mk-cart-popup' ),
        'errorText'   => __( 'Versturen mislukt.', 'mk-cart-popup' ),
    ] );
} );


// ── Admin orderlijst: "Afhaalklaar?"-kolom ────────────────────────────────────
// Zelfde stramien als mkcp_dd_add_order_column()/mkcp_dd_render_order_column()
// (includes/delivery-date.php) — dubbele hook-registratie voor klassiek + HPOS.

add_filter( 'manage_edit-shop_order_columns',           'mkcp_pu_ready_add_order_column' );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'mkcp_pu_ready_add_order_column' );

function mkcp_pu_ready_add_order_column( $columns ) {
    if ( ! mkcp_pu_ready_feature_enabled() ) return $columns;

    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'order_status' ) {
            $new['mkcp_pickup_ready'] = __( 'Afhaalklaar?', 'mk-cart-popup' );
        }
    }
    if ( ! isset( $new['mkcp_pickup_ready'] ) ) {
        $new['mkcp_pickup_ready'] = __( 'Afhaalklaar?', 'mk-cart-popup' );
    }
    return $new;
}

add_action( 'manage_shop_order_posts_custom_column',           'mkcp_pu_ready_render_order_column', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'mkcp_pu_ready_render_order_column', 10, 2 );

function mkcp_pu_ready_render_order_column( $column, $order_or_id ) {
    if ( $column !== 'mkcp_pickup_ready' ) return;

    $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order || ! $order->get_meta( '_mkcp_pickup_date' ) ) return;

    $sent_at = $order->get_meta( '_mkcp_pickup_ready_sent_at' );
    echo $sent_at ? '✓ ' . esc_html( date_i18n( 'j M H:i', strtotime( $sent_at ) ) ) : '—';
}
