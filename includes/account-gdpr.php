<?php
/**
 * MK Cart Popup — Account: zelfservice "Account verwijderen" (GDPR)
 *
 * Bewust twee stappen i.p.v. één AJAX-call: bevestigingsmail met tijdelijke
 * link (HMAC-token met user_id + vervaltijd, zelfde principe als
 * mkcp_ac_unsub_token() in abandoned-cart.php), pas het klikken erop
 * (ingelogd als dezelfde klant) voert de verwijdering echt uit.
 *
 * Scope: wist/anonimiseert wishlists, adresboek, notificaties, retouren
 * (mkcp_account_purge_user_data()) + het WP-account zelf. Raakt NIET aan
 * bestelling-PII — dat valt onder WooCommerce's bewaartermijn en hoort bij
 * WordPress' eigen "Persoonlijke gegevens wissen"-tool.
 *
 * Onderaan dit bestand hangt dezelfde data óók aan WordPress' eigen privacy-
 * tools (Extra → Persoonlijke gegevens exporteren/wissen), zodat een winkelier
 * die dáármee werkt de Account-data meekrijgt in plaats van mist.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MKCP_ACCOUNT_DELETE_TOKEN_TTL = DAY_IN_SECONDS;

function mkcp_account_delete_token( int $user_id, int $expires ): string {
    return hash_hmac( 'sha256', $user_id . '|' . $expires, wp_salt( 'auth' ) );
}

function mkcp_account_delete_confirm_url( int $user_id ): string {
    $expires = time() + MKCP_ACCOUNT_DELETE_TOKEN_TTL;
    return add_query_arg( [
        'action'  => 'mkcp_account_delete_confirm',
        'user'    => $user_id,
        'expires' => $expires,
        'token'   => mkcp_account_delete_token( $user_id, $expires ),
    ], admin_url( 'admin-post.php' ) );
}


// ── AJAX: verwijderverzoek starten (stap 1 — verstuurt de bevestigingsmail) ──

add_action( 'wp_ajax_mkcp_account_delete_request', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user = wp_get_current_user();

    $confirm_url = mkcp_account_delete_confirm_url( $user->ID );
    $site_name   = get_bloginfo( 'name' );

    $subject = sprintf(
        /* translators: %s: sitenaam */
        __( 'Bevestig het verwijderen van je account bij %s', 'mk-cart-popup' ),
        $site_name
    );
    $body = sprintf(
        /* translators: 1: voornaam, 2: sitenaam, 3: bevestigingslink */
        __( "Hoi %1\$s,\n\nJe hebt gevraagd om je account bij %2\$s te verwijderen. Klik op onderstaande link om dit te bevestigen — deze link is 24 uur geldig:\n\n%3\$s\n\nHeb je dit niet zelf aangevraagd? Dan kun je deze e-mail gewoon negeren, er verandert dan niets.", 'mk-cart-popup' ),
        $user->first_name ?: $user->display_name,
        $site_name,
        $confirm_url
    );

    wp_mail( $user->user_email, $subject, $body );

    wp_send_json_success( [
        'message' => __( 'We hebben je een bevestigingsmail gestuurd. Klik op de link daarin om het verwijderen af te ronden.', 'mk-cart-popup' ),
    ] );
} );


// ── admin-post: verwijdering bevestigen (stap 2 — voert 'm echt uit) ─────────

add_action( 'admin_post_mkcp_account_delete_confirm', 'mkcp_account_handle_delete_confirm' );

function mkcp_account_handle_delete_confirm() {
    $user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
    $expires = isset( $_GET['expires'] ) ? absint( $_GET['expires'] ) : 0;
    $token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

    $site_name = get_bloginfo( 'name' );
    $valid     = $user_id && $expires && $token
        && $expires >= time()
        && hash_equals( mkcp_account_delete_token( $user_id, $expires ), $token );

    // Vereist een actieve sessie als DEZELFDE klant, zodat een doorgestuurde/
    // onderschepte e-maillink niet los van de klant zelf te gebruiken is.
    $logged_in_as_owner = is_user_logged_in() && get_current_user_id() === $user_id;

    if ( $valid && ! $logged_in_as_owner ) {
        $message = __( 'Log eerst in met dit account en klik daarna nogmaals op de link uit de e-mail om het verwijderen te bevestigen.', 'mk-cart-popup' );
        mkcp_account_render_delete_notice( $site_name, $message );
    }

    if ( ! $valid || ! $logged_in_as_owner ) {
        $message = __( 'Deze bevestigingslink is ongeldig of verlopen. Vraag het verwijderen van je account opnieuw aan vanuit Accountgegevens.', 'mk-cart-popup' );
        mkcp_account_render_delete_notice( $site_name, $message );
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        mkcp_account_render_delete_notice( $site_name, __( 'Dit account bestaat niet meer.', 'mk-cart-popup' ) );
    }

    // 1) Account-eigen data (wishlists/adressen/notificaties/retouren).
    if ( function_exists( 'mkcp_account_purge_user_data' ) ) {
        mkcp_account_purge_user_data( $user_id );
    }

    // 2) Anonimiseren i.p.v. wp_delete_user(): dat zou bestellingen loskoppelen
    // van hun klant (customer_id) en de boekhouding/order-geschiedenis van de
    // winkelier beschadigen. Anonimiseren behoudt de koppeling.
    $anon_email = 'verwijderd+' . $user_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
    wp_update_user( [
        'ID'           => $user_id,
        'user_email'   => $anon_email,
        'display_name' => __( 'Verwijderde gebruiker', 'mk-cart-popup' ),
        'first_name'   => '',
        'last_name'    => '',
        'user_url'     => '',
        'description'  => '',
    ] );
    delete_user_meta( $user_id, 'mkcp_phone' );
    delete_user_meta( $user_id, 'mkcp_date_of_birth' );
    delete_user_meta( $user_id, 'mkcp_newsletter_optin' );
    wp_set_password( wp_generate_password( 32, true, true ), $user_id );

    // 3) Direct uitloggen — wp_set_password() hierboven vernietigt de
    // sessie-tokens al server-side, dit ruimt ook de cookie in de browser op.
    wp_logout();

    mkcp_account_render_delete_notice(
        $site_name,
        __( 'Je account is verwijderd. Bedankt dat je gebruik hebt gemaakt van onze webshop.', 'mk-cart-popup' )
    );
}

// ── WordPress' eigen privacytools (Extra → Persoonlijke gegevens) ───────────
//
// Zonder deze twee koppelingen ziet een winkelier die het standaard WP-scherm
// gebruikt de Account-eigen data helemaal niet: WooCommerce exporteert/wist
// alleen zijn eigen klant- en bestelgegevens. Bewust GEEN orders hier — die
// doet WooCommerce zelf al.

add_filter( 'wp_privacy_personal_data_exporters', function( $exporters ) {
    $exporters['mk-cart-popup-account'] = [
        'exporter_friendly_name' => __( 'Account-omgeving (verlanglijst, adresboek, meldingen, retouren)', 'mk-cart-popup' ),
        'callback'               => 'mkcp_account_privacy_exporter',
    ];
    return $exporters;
} );

add_filter( 'wp_privacy_personal_data_erasers', function( $erasers ) {
    $erasers['mk-cart-popup-account'] = [
        'eraser_friendly_name' => __( 'Account-omgeving (verlanglijst, adresboek, meldingen, retouren)', 'mk-cart-popup' ),
        'callback'             => 'mkcp_account_privacy_eraser',
    ];
    return $erasers;
} );

/**
 * Alles in één keer: de datasets zijn per klant klein (verlanglijst,
 * adresboek, meldingen, retouren) en de WP-exporter geeft ze in één ronde
 * netjes gegroepeerd terug — geen paginering nodig.
 */
function mkcp_account_privacy_exporter( $email_address, $page = 1 ): array {
    $user = get_user_by( 'email', $email_address );
    if ( ! $user ) return [ 'data' => [], 'done' => true ];

    global $wpdb;
    $user_id = (int) $user->ID;
    $export  = [];

    foreach ( $wpdb->get_results( $wpdb->prepare(
        "SELECT i.*, w.name AS wishlist_name FROM {$wpdb->prefix}mkcp_wishlist_items i
         INNER JOIN {$wpdb->prefix}mkcp_wishlists w ON w.id = i.wishlist_id
         WHERE w.user_id = %d ORDER BY i.added_at ASC",
        $user_id
    ) ) as $item ) {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $item->variation_id ?: $item->product_id ) : null;
        $export[] = [
            'group_id'    => 'mkcp-wishlist',
            'group_label' => __( 'Verlanglijst', 'mk-cart-popup' ),
            'item_id'     => 'mkcp-wishlist-item-' . $item->id,
            'data'        => [
                [ 'name' => __( 'Lijst', 'mk-cart-popup' ),            'value' => $item->wishlist_name ],
                [ 'name' => __( 'Product', 'mk-cart-popup' ),          'value' => $product ? $product->get_name() : $item->product_id ],
                [ 'name' => __( 'Notitie', 'mk-cart-popup' ),          'value' => $item->note ],
                [ 'name' => __( 'Gewenste prijs', 'mk-cart-popup' ),   'value' => $item->target_price ],
                [ 'name' => __( 'Toegevoegd op', 'mk-cart-popup' ),    'value' => $item->added_at ],
            ],
        ];
    }

    foreach ( $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_addresses WHERE user_id = %d ORDER BY id ASC",
        $user_id
    ) ) as $address ) {
        $export[] = [
            'group_id'    => 'mkcp-addresses',
            'group_label' => __( 'Adresboek', 'mk-cart-popup' ),
            'item_id'     => 'mkcp-address-' . $address->id,
            'data'        => [
                [ 'name' => __( 'Label', 'mk-cart-popup' ),        'value' => $address->label ],
                [ 'name' => __( 'Bedrijf', 'mk-cart-popup' ),      'value' => $address->company ],
                [ 'name' => __( 'Btw-nummer', 'mk-cart-popup' ),   'value' => $address->vat_number ],
                [ 'name' => __( 'Naam', 'mk-cart-popup' ),         'value' => trim( $address->first_name . ' ' . $address->last_name ) ],
                [ 'name' => __( 'Adres', 'mk-cart-popup' ),        'value' => trim( $address->address_1 . ' ' . $address->address_2 ) ],
                [ 'name' => __( 'Postcode', 'mk-cart-popup' ),     'value' => $address->postcode ],
                [ 'name' => __( 'Plaats', 'mk-cart-popup' ),       'value' => $address->city ],
                [ 'name' => __( 'Land', 'mk-cart-popup' ),         'value' => $address->country ],
                [ 'name' => __( 'Telefoonnummer', 'mk-cart-popup' ), 'value' => $address->phone ],
            ],
        ];
    }

    foreach ( $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_notifications WHERE user_id = %d ORDER BY created_at ASC",
        $user_id
    ) ) as $notification ) {
        $export[] = [
            'group_id'    => 'mkcp-notifications',
            'group_label' => __( 'Meldingen', 'mk-cart-popup' ),
            'item_id'     => 'mkcp-notification-' . $notification->id,
            'data'        => [
                [ 'name' => __( 'Soort', 'mk-cart-popup' ),      'value' => $notification->type ],
                [ 'name' => __( 'Titel', 'mk-cart-popup' ),      'value' => $notification->title ],
                [ 'name' => __( 'Bericht', 'mk-cart-popup' ),    'value' => $notification->body ],
                [ 'name' => __( 'Ontvangen op', 'mk-cart-popup' ), 'value' => $notification->created_at ],
            ],
        ];
    }

    foreach ( $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_return_requests WHERE user_id = %d ORDER BY requested_at ASC",
        $user_id
    ) ) as $request ) {
        $export[] = [
            'group_id'    => 'mkcp-returns',
            'group_label' => __( 'Retour-aanvragen', 'mk-cart-popup' ),
            'item_id'     => 'mkcp-return-' . $request->id,
            'data'        => [
                [ 'name' => __( 'Bestelling', 'mk-cart-popup' ),  'value' => $request->order_id ],
                [ 'name' => __( 'Aantal', 'mk-cart-popup' ),      'value' => $request->quantity ],
                [ 'name' => __( 'Reden', 'mk-cart-popup' ),       'value' => $request->reason ],
                [ 'name' => __( 'Toelichting', 'mk-cart-popup' ), 'value' => $request->reason_note ],
                [ 'name' => __( 'Status', 'mk-cart-popup' ),      'value' => $request->status ],
                [ 'name' => __( 'Aangevraagd op', 'mk-cart-popup' ), 'value' => $request->requested_at ],
            ],
        ];
    }

    return [ 'data' => $export, 'done' => true ];
}

/**
 * Hergebruikt exact dezelfde opschoning als de zelfservice-flow hierboven en
 * de delete_user-hook, zodat er maar één plek is die bepaalt wát er weg moet.
 */
function mkcp_account_privacy_eraser( $email_address, $page = 1 ): array {
    $response = [
        'items_removed'  => false,
        'items_retained' => false,
        'messages'       => [],
        'done'           => true,
    ];

    $user = get_user_by( 'email', $email_address );
    if ( ! $user || ! function_exists( 'mkcp_account_purge_user_data' ) ) return $response;

    mkcp_account_purge_user_data( (int) $user->ID );

    $response['items_removed']  = true;
    // Retour-aanvragen blijven als geanonimiseerde regel bestaan (boekhoudkundige
    // koppeling aan de bestelling) — dat is bewust behouden data, dus melden.
    $response['items_retained'] = true;
    $response['messages'][]     = __( 'Retour-aanvragen zijn losgekoppeld van de klant maar bewaard: ze horen bij de bestelling zelf.', 'mk-cart-popup' );

    return $response;
}

/** Kleine, op zichzelf staande bevestigingspagina — zelfde stijl als mkcp_ac_handle_unsubscribe() in abandoned-cart.php. Beëindigt de request altijd (exit). */
function mkcp_account_render_delete_notice( string $site_name, string $message ) {
    header( 'Content-Type: text/html; charset=utf-8' );
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#111;max-width:480px;margin:80px auto;padding:0 16px;text-align:center">'
        . '<h2 style="margin-bottom:16px">' . esc_html( $site_name ) . '</h2>'
        . '<p style="line-height:1.6">' . esc_html( $message ) . '</p>'
        . '<p style="margin-top:24px"><a href="' . esc_url( home_url( '/' ) ) . '">&larr; ' . esc_html__( 'Terug naar de website', 'mk-cart-popup' ) . '</a></p>'
        . '</body></html>';
    exit;
}
