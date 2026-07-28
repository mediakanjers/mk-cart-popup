<?php
/**
 * MK Cart Popup — Verlaten winkelwagen herinneringen
 *
 * Werkt voor ingelogde gebruikers én gasten. Gasten worden alleen gevolgd
 * zodra ze hun e-mailadres invullen bij het afrekenen (anders hebben we geen
 * adres om naartoe te mailen). Vereist actieve WP Cron.
 * Verstuurt één herinneringsmail per verlaten winkelwagen na de ingestelde vertraging.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MKCP_AC_DB_VERSION', '1.2' );

// ── Database installatie ───────────────────────────────────────────────────────

function mkcp_ac_install_table() {
    global $wpdb;
    $installed = get_option( 'mkcp_ac_db_version' );
    if ( $installed === MKCP_AC_DB_VERSION ) return;

    $table            = $wpdb->prefix . 'mkcp_abandoned_carts';
    $suppressed_table = $wpdb->prefix . 'mkcp_ac_suppressed';
    $charset          = $wpdb->get_charset_collate();

    // 1.0 → 1.1: de oude tabel had een UNIQUE KEY op user_id (geen gasten
    // mogelijk, want die zouden allemaal user_id=0 delen) en geen
    // tracking_key-kolom. dbDelta wijzigt/verwijdert bestaande keys niet, dus
    // die stap — en het vullen van tracking_key vóórdat de nieuwe UNIQUE KEY
    // erop komt — doen we hier expliciet.
    if ( $installed && version_compare( $installed, '1.1', '<' ) ) {
        $has_column = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'tracking_key'" );
        if ( ! $has_column ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN tracking_key VARCHAR(64) NOT NULL DEFAULT '' AFTER user_id" );
        }
        $wpdb->query( "UPDATE {$table} SET tracking_key = CONCAT('user_', user_id) WHERE tracking_key = ''" );

        $has_old_index = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'user_id'" );
        if ( $has_old_index ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX user_id" );
        }
    }

    $sql = "CREATE TABLE {$table} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        tracking_key  VARCHAR(64)     NOT NULL DEFAULT '',
        user_email    VARCHAR(200)    NOT NULL DEFAULT '',
        cart_hash     VARCHAR(32)     NOT NULL DEFAULT '',
        cart_total    DECIMAL(10,2)   NOT NULL DEFAULT 0,
        cart_updated_at  DATETIME     NOT NULL,
        reminder_sent_at DATETIME     DEFAULT NULL,
        checked_out   TINYINT(1)      NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY tracking_key (tracking_key),
        KEY checked_reminder (checked_out, reminder_sent_at)
    ) {$charset};";

    // Losse, kleine tabel voor permanente afmeldingen — bewust niet in de
    // hoofdtabel, want die wordt straks periodiek opgeschoond en een
    // afmelding moet blijven staan. Alleen de cron-verstuurcheck en de
    // afmeldlink zelf raken deze tabel, dus geen impact op reguliere
    // pagina's (admin of frontend).
    $suppressed_sql = "CREATE TABLE {$suppressed_table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email      VARCHAR(200)    NOT NULL DEFAULT '',
        created_at DATETIME        NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY email (email)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    dbDelta( $suppressed_sql );

    update_option( 'mkcp_ac_db_version', MKCP_AC_DB_VERSION );
}

add_action( 'plugins_loaded', function() {
    if ( get_option( 'mkcp_ac_db_version' ) !== MKCP_AC_DB_VERSION ) {
        mkcp_ac_install_table();
    }
} );

// Eenmalige migratie: vertraging stond tot nu toe in hele uren, voortaan in
// minuten (voor de "per half uur instelbaar"-optie). Zonder deze stap zou een
// bestaande waarde van bv. "24" (24 uur) plots als "24 minuten" gelezen worden.
add_action( 'plugins_loaded', function() {
    if ( get_option( 'mkcp_ac_delay_migrated' ) ) return;

    $settings = get_option( 'mkcp_settings', [] );
    if ( isset( $settings['abandoned_cart_delay'] ) ) {
        $settings['abandoned_cart_delay'] = max( 30, intval( $settings['abandoned_cart_delay'] ) * 60 );
        update_option( 'mkcp_settings', $settings );
    }
    update_option( 'mkcp_ac_delay_migrated', 1 );
} );


// ── Tracking-sleutel ─────────────────────────────────────────────────────────
//
// Ingelogde klanten: 'user_{id}'. Gasten: 'guest_{WC-sessie-customer-id}' —
// dezelfde ID die WooCommerce zelf gebruikt om een gast-winkelwagen te
// herkennen tussen requests (cookie-gebaseerd).

function mkcp_ac_get_tracking_key(): string {
    if ( is_user_logged_in() ) {
        return 'user_' . get_current_user_id();
    }
    if ( ! WC()->session ) return '';
    $customer_id = WC()->session->get_customer_id();
    return $customer_id ? 'guest_' . $customer_id : '';
}


// ── Cart tracking ──────────────────────────────────────────────────────────────

add_action( 'woocommerce_cart_updated', 'mkcp_ac_track_cart' );

function mkcp_ac_track_cart() {
    $config = mkcp_config();
    if ( empty( $config['abandoned_cart_enabled'] ) ) return;

    $tracking_key = mkcp_ac_get_tracking_key();
    if ( ! $tracking_key ) return;

    $cart = WC()->cart;
    if ( ! $cart || $cart->is_empty() ) {
        mkcp_ac_mark_checked_out( $tracking_key );
        return;
    }

    if ( is_user_logged_in() ) {
        $user = get_userdata( get_current_user_id() );
        mkcp_ac_upsert( $tracking_key, get_current_user_id(), $user ? $user->user_email : '', $cart );
        return;
    }

    // Gasten: alleen bijwerken als er al een rij bestaat — die ontstaat pas
    // zodra de gast een e-mailadres achterlaat via mkcp_ac_capture_guest_email().
    // Zonder e-mailadres kunnen we toch geen herinnering versturen.
    global $wpdb;
    $table  = $wpdb->prefix . 'mkcp_abandoned_carts';
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE tracking_key = %s", $tracking_key
    ) );
    if ( $exists ) {
        mkcp_ac_upsert( $tracking_key, 0, '', $cart );
    }
}

/**
 * Insert/update de tracking-rij voor $tracking_key. Een leeg $email laat een
 * eventueel al opgeslagen e-mailadres ongemoeid (zodat de reguliere
 * cart-update van een gast het adres niet overschrijft met leeg).
 */
function mkcp_ac_upsert( string $tracking_key, int $user_id, string $email, WC_Cart $cart ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_abandoned_carts';

    $cart_hash  = md5( wp_json_encode( $cart->get_cart() ) );
    $cart_total = (float) $cart->get_cart_contents_total();
    $now        = current_time( 'mysql' );

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, cart_hash, user_email FROM {$table} WHERE tracking_key = %s",
        $tracking_key
    ) );

    if ( $existing ) {
        $email_to_store = $email !== '' ? $email : $existing->user_email;

        if ( $existing->cart_hash !== $cart_hash ) {
            // Cart changed → reset reminder so they can get a new one.
            // reminder_sent_at must be set to SQL NULL; wpdb->update() converts
            // PHP null to empty string '', so we use a raw query here.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table}
                 SET user_email=%s, cart_hash=%s, cart_total=%f, cart_updated_at=%s,
                     reminder_sent_at=NULL, checked_out=0
                 WHERE tracking_key=%s",
                $email_to_store, $cart_hash, $cart_total, $now, $tracking_key
            ) );
        } else {
            $wpdb->update( $table,
                [ 'user_email' => $email_to_store, 'cart_total' => $cart_total, 'checked_out' => 0 ],
                [ 'tracking_key' => $tracking_key ]
            );
        }
        return;
    }

    $wpdb->insert( $table, [
        'user_id'        => $user_id,
        'tracking_key'   => $tracking_key,
        'user_email'     => $email,
        'cart_hash'      => $cart_hash,
        'cart_total'     => $cart_total,
        'cart_updated_at'=> $now,
    ] );
}

function mkcp_ac_mark_checked_out( string $tracking_key ) {
    if ( ! $tracking_key ) return;
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'mkcp_abandoned_carts',
        [ 'checked_out' => 1 ],
        [ 'tracking_key' => $tracking_key ]
    );
}

// Mark as checked out when order is placed — zowel ingelogd als gast.
add_action( 'woocommerce_checkout_order_processed', function() {
    mkcp_ac_mark_checked_out( mkcp_ac_get_tracking_key() );
} );


// ── AJAX: gast-e-mailadres vastleggen tijdens het afrekenen ────────────────────
//
// Alleen nopriv: het script dat dit aanroept wordt uitsluitend geladen voor
// niet-ingelogde bezoekers op de checkout-pagina (zie mk-cart-popup.php).

add_action( 'wp_ajax_nopriv_mkcp_ac_capture_guest_email', 'mkcp_ac_capture_guest_email' );

function mkcp_ac_capture_guest_email() {
    check_ajax_referer( 'mkcp_ac_guest_email', 'nonce' );

    $config = mkcp_config();
    if ( empty( $config['abandoned_cart_enabled'] ) ) {
        wp_send_json_error();
    }

    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( ! is_email( $email ) ) {
        wp_send_json_error();
    }

    $cart = WC()->cart;
    if ( ! $cart || $cart->is_empty() ) {
        wp_send_json_error();
    }

    $tracking_key = mkcp_ac_get_tracking_key();
    if ( ! $tracking_key ) {
        wp_send_json_error();
    }

    mkcp_ac_upsert( $tracking_key, 0, $email, $cart );

    wp_send_json_success();
}


// ── Permanente afmelding ("nooit meer een herinnering") ────────────────────────
//
// Los van de tracking-tabel: die wordt periodiek opgeschoond, een afmelding
// moet blijven staan. Het token is een HMAC over het e-mailadres met de
// site-eigen salt (wp_salt) — geen aparte DB-lookup nodig om een klik te
// verifiëren, alleen om 'm daarna weg te schrijven.

function mkcp_ac_unsub_token( string $email ): string {
    return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
}

function mkcp_ac_unsubscribe_url( string $email ): string {
    return add_query_arg( [
        'action' => 'mkcp_ac_unsubscribe',
        'email'  => rawurlencode( $email ),
        'token'  => mkcp_ac_unsub_token( $email ),
    ], admin_url( 'admin-post.php' ) );
}

function mkcp_ac_suppress_email( string $email ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}mkcp_ac_suppressed (email, created_at) VALUES (%s, %s)",
        strtolower( trim( $email ) ), current_time( 'mysql' )
    ) );
}

add_action( 'admin_post_nopriv_mkcp_ac_unsubscribe', 'mkcp_ac_handle_unsubscribe' );
add_action( 'admin_post_mkcp_ac_unsubscribe',        'mkcp_ac_handle_unsubscribe' );

function mkcp_ac_handle_unsubscribe() {
    $email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
    $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
    $ok    = $email && $token && hash_equals( mkcp_ac_unsub_token( $email ), $token );

    if ( $ok ) {
        mkcp_ac_suppress_email( $email );
    }

    $site_name = get_bloginfo( 'name' );
    $message   = $ok
        ? __( 'Je bent afgemeld. Je ontvangt geen verlaten-winkelwagen-herinneringen meer van dit e-mailadres.', 'mk-cart-popup' )
        : __( 'Deze afmeldlink is ongeldig of verlopen.', 'mk-cart-popup' );

    header( 'Content-Type: text/html; charset=utf-8' );
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#111;max-width:480px;margin:80px auto;padding:0 16px;text-align:center">'
        . '<h2 style="margin-bottom:16px">' . esc_html( $site_name ) . '</h2>'
        . '<p style="line-height:1.6">' . esc_html( $message ) . '</p>'
        . '<p style="margin-top:24px"><a href="' . esc_url( home_url( '/' ) ) . '">&larr; ' . esc_html__( 'Terug naar de website', 'mk-cart-popup' ) . '</a></p>'
        . '</body></html>';
    exit;
}


// ── WP Cron scheduling ────────────────────────────────────────────────────────

add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['mkcp_ac_interval'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __( 'Elke 15 minuten (MK Cart Popup)', 'mk-cart-popup' ),
    ];
    return $schedules;
} );

add_action( 'init', function() {
    $config = mkcp_config();
    if ( empty( $config['abandoned_cart_enabled'] ) ) {
        if ( wp_next_scheduled( 'mkcp_ac_cron' ) ) {
            wp_clear_scheduled_hook( 'mkcp_ac_cron' );
        }
        return;
    }

    // Reschedule op de fijnere interval als een oudere versie 'hourly' had ingesteld.
    $event = wp_get_scheduled_event( 'mkcp_ac_cron' );
    if ( ! $event || $event->schedule !== 'mkcp_ac_interval' ) {
        wp_clear_scheduled_hook( 'mkcp_ac_cron' );
        wp_schedule_event( time(), 'mkcp_ac_interval', 'mkcp_ac_cron' );
    }
} );

add_action( 'mkcp_ac_cron', 'mkcp_ac_send_reminders' );


// ── Reminder versturen ────────────────────────────────────────────────────────

function mkcp_ac_send_reminders() {
    $config = mkcp_config();
    if ( empty( $config['abandoned_cart_enabled'] ) ) return;

    // Transient lock voorkomt dubbele mails als twee cron-processen tegelijk draaien
    if ( get_transient( 'mkcp_ac_cron_lock' ) ) return;
    set_transient( 'mkcp_ac_cron_lock', 1, 5 * MINUTE_IN_SECONDS );

    $delay_minutes = max( 30, intval( $config['abandoned_cart_delay'] ?? 60 ) );
    // current_time('mysql') slaat op in WordPress-timezone; gebruik dezelfde basis voor de cutoff
    $cutoff = current_time( 'mysql', false );
    $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $cutoff ) - $delay_minutes * MINUTE_IN_SECONDS );

    global $wpdb;
    $table            = $wpdb->prefix . 'mkcp_abandoned_carts';
    $suppressed_table = $wpdb->prefix . 'mkcp_ac_suppressed';

    // LEFT JOIN tegen de (kleine) afmeldtabel — draait alleen hier, elke 15
    // minuten in de achtergrond, dus geen impact op admin- of bezoekerspagina's.
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.* FROM {$table} t
         LEFT JOIN {$suppressed_table} s ON s.email = t.user_email
         WHERE t.checked_out = 0
           AND t.reminder_sent_at IS NULL
           AND t.user_email != ''
           AND t.cart_updated_at <= %s
           AND s.email IS NULL",
        $cutoff
    ) );

    foreach ( $rows as $row ) {
        // Markeer direct als verzonden vóór het versturen om race conditions te voorkomen
        $updated = $wpdb->update(
            $table,
            [ 'reminder_sent_at' => current_time( 'mysql' ) ],
            [ 'id' => $row->id, 'reminder_sent_at' => null ]
        );
        if ( $updated ) {
            mkcp_ac_send_email( $row, $config );
        }
    }

    delete_transient( 'mkcp_ac_cron_lock' );
}

function mkcp_ac_send_email( object $row, array $config ): bool {
    $to      = $row->user_email;
    if ( ! is_email( $to ) ) return false;

    $user     = $row->user_id ? get_userdata( $row->user_id ) : false;
    $voornaam = $user ? ( trim( $user->first_name ) ?: trim( $user->display_name ) ?: __( 'daar', 'mk-cart-popup' ) ) : __( 'daar', 'mk-cart-popup' );
    $cart_url = wc_get_cart_url();

    $subject = $config['abandoned_cart_subject']
        ?? __( 'Je hebt nog iets in je winkelwagen!', 'mk-cart-popup' );
    $body    = $config['abandoned_cart_body']
        ?? __( 'Hé {voornaam}, je hebt nog producten in je winkelwagen. Kom je ze nog even ophalen?', 'mk-cart-popup' );

    $subject = str_replace( [ '{voornaam}', '{winkelwagen_url}' ], [ $voornaam, $cart_url ], $subject );
    $body    = str_replace( [ '{voornaam}', '{winkelwagen_url}' ], [ $voornaam, esc_url( $cart_url ) ], $body );

    $site_name    = get_bloginfo( 'name' );
    $button_url   = $cart_url;
    $unsub_url    = mkcp_ac_unsubscribe_url( $to );

    $html  = '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#111;max-width:560px;margin:0 auto;padding:32px 16px">';
    $html .= '<h2 style="margin-bottom:8px">' . esc_html( $site_name ) . '</h2>';
    $html .= '<p style="line-height:1.7">' . nl2br( esc_html( $body ) ) . '</p>';
    $html .= '<p style="margin-top:24px">';
    $html .= '<a href="' . esc_url( $button_url ) . '" style="display:inline-block;background:#2563eb;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600">Ga naar mijn winkelwagen &rarr;</a>';
    $html .= '</p>';
    $html .= '<p style="margin-top:32px;font-size:12px;color:#888">Je ontvangt dit bericht omdat je producten in je winkelwagen hebt achtergelaten op ' . esc_html( $site_name ) . '. Dit is een eenmalige mail — je e-mailadres is niet toegevoegd aan een mailinglijst. '
        . '<a href="' . esc_url( $unsub_url ) . '" style="color:#888">Wil je hier nooit meer een herinnering over ontvangen?</a></p>';
    $html .= '</body></html>';

    // Named callback zodat remove_filter() werkt (anonieme functies zijn niet verwijderbaar)
    $set_html_type = static function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $set_html_type );
    $sent = wp_mail( $to, $subject, $html, [ 'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>' ] );
    remove_filter( 'wp_mail_content_type', $set_html_type );

    return (bool) $sent;
}


// ── Admin: testmail versturen ───────────────────────────────────────────────────
//
// Bouwt een synthetische rij (geen DB-schrijfactie, raakt de tracking-tabel
// niet) en stuurt 'm door dezelfde mkcp_ac_send_email() als de echte cron —
// zo test je precies de mail die een klant ook zou krijgen.

add_action( 'wp_ajax_mkcp_ac_send_test_email', 'mkcp_ac_ajax_send_test_email' );

function mkcp_ac_ajax_send_test_email() {
    check_ajax_referer( 'mkcp_test_email', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Geen toegang.', 'mk-cart-popup' ) ] );
    }

    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'message' => __( 'Ongeldig e-mailadres.', 'mk-cart-popup' ) ] );
    }

    $row = (object) [
        'user_id'    => get_current_user_id(),
        'user_email' => $email,
    ];

    $sent = mkcp_ac_send_email( $row, mkcp_config() );

    if ( $sent ) {
        wp_send_json_success( [ 'message' => __( 'Testmail verzonden! Controleer je inbox.', 'mk-cart-popup' ) ] );
    } else {
        wp_send_json_error( [ 'message' => __( 'Versturen mislukt. Controleer je SMTP-instellingen.', 'mk-cart-popup' ) ] );
    }
}


// ── Admin melding: WP Cron uitgeschakeld ──────────────────────────────────────

add_action( 'admin_notices', function() {
    $config = mkcp_config();
    if ( empty( $config['abandoned_cart_enabled'] ) ) return;

    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'mkcp' ) === false ) return;

    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
        echo '<div class="notice notice-error"><p>'
            . '<strong>MK Cart Popup — Verlaten winkelwagen:</strong> '
            . 'WP Cron is uitgeschakeld (<code>DISABLE_WP_CRON</code> staat op <code>true</code> in <code>wp-config.php</code>). '
            . 'Herinneringsmails worden <strong>niet verstuurd</strong> totdat WP Cron actief is of een externe cron-taak is ingesteld.'
            . '</p></div>';
    }
} );


// Deactivatie-hook wordt vanuit mk-cart-popup.php geregistreerd (zie aldaar)
