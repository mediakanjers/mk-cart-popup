<?php
/**
 * MK Cart Popup — Account: zelfservice "Account verwijderen" (GDPR)
 *
 * Eigen bestand (zie de "god file"-notitie in account-profile.php). Twee
 * stappen, bewust NIET één enkele AJAX-call: een klant die op "Account
 * verwijderen" klikt, krijgt eerst een bevestigingsmail met een tijdelijke
 * link — pas het klikken op díe link (terwijl ingelogd als dezelfde klant)
 * voert de verwijdering echt uit. Zelfde soort HMAC-token als de bestaande
 * afmeldlink in includes/abandoned-cart.php (mkcp_ac_unsub_token()), hier
 * met user_id + vervaltijd in de payload zodat een oude link vanzelf
 * ongeldig wordt.
 *
 * Scope, bewust begrensd:
 * - Wist/anonimiseert: wishlists, adresboek, notificaties, retour-aanvragen
 *   (via mkcp_account_purge_user_data(), account-db.php) + het WP-
 *   gebruikersaccount zelf (e-mail/naam gescrambled, wachtwoord vervangen).
 * - Raakt NIET aan bestelling-PII (de billing- en shipping-ordermeta) — dat
 *   valt onder WooCommerce's eigen wettelijke bewaartermijn en hoort bij
 *   WordPress' eigen "Persoonlijke gegevens wissen"-tool (Gereedschappen),
 *   niet iets om hier stilzwijgend te herbouwen.
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

    // Vereist een actieve sessie als DEZELFDE klant — een doorgestuurde/
    // onderschepte e-maillink kan zo niet los van de eigen browsersessie
    // van de klant gebruikt worden om het account te verwijderen.
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

    // 2) Het WP-gebruikersaccount zelf anonimiseren — bewust GEEN
    // wp_delete_user(): dat zou ook bestellingen loskoppelen van hun klant
    // (customer_id), wat de order-geschiedenis/boekhouding van de winkelier
    // zou beschadigen. Anonimiseren behoudt de koppeling, alleen de
    // persoonsgegevens zelf verdwijnen.
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
