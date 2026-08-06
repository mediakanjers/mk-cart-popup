<?php
/**
 * MK Cart Popup — Account: Notificaties (Fase 1, stap 6a)
 *
 * Los bestand van account-orders.php/account-returns.php (zie de "god file"-
 * notitie in account-profile.php) — dit bestand kent alleen wp_mkcp_notifications
 * en heeft verder geen weet van bestellingen/retouren, ook al vullen die
 * systemen deze tabel straks (via mkcp_account_add_notification()).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'mkcp_account_fragment_handlers', function( $handlers ) {
    $handlers['notifications'] = 'mkcp_account_render_fragment_notifications';
    return $handlers;
} );


// ── Helpers ────────────────────────────────────────────────────────────────

function mkcp_account_get_notifications( int $user_id, int $limit = 50 ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_notifications';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
        $user_id, $limit
    ) );
}

function mkcp_account_get_unread_notifications_count( int $user_id ): int {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_notifications';
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
        $user_id
    ) );
}

/** Geeft een notificatie alleen terug als 'ie ook echt van $user_id is. */
function mkcp_account_get_owned_notification( int $notification_id, int $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_notifications';
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
        $notification_id, $user_id
    ) );
}

/**
 * Eén centraal aanmaakpunt — bedoeld om vanuit andere systemen (wishlist-
 * prijsdaling/voorraad-cron, retour-statuswijziging, ...) aangeroepen te
 * worden zodra die functionaliteit er is. Nu al hier gedefinieerd (i.p.v.
 * pas wanneer de eerste aanroeper bestaat) zodat elk systeem straks tegen
 * dezelfde, ene functie-signatuur bouwt i.p.v. zelf een variant te verzinnen.
 */
function mkcp_account_add_notification( int $user_id, string $type, string $title, string $body = '', string $url = '', string $related_object_type = '', int $related_object_id = 0 ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'mkcp_notifications', [
        'user_id'             => $user_id,
        'type'                => $type,
        'title'               => $title,
        'body'                => $body,
        'url'                 => $url,
        'related_object_type' => $related_object_type,
        'related_object_id'   => $related_object_id,
        'is_read'             => 0,
        'created_at'          => current_time( 'mysql' ),
    ] );
}


// ── Fragment: Notificaties ────────────────────────────────────────────────────

function mkcp_account_render_notification_row( $n ): string {
    // Relatieve tijd ("2 uur geleden") voor recente meldingen — voelt veel
    // meer als een "levend" meldingencentrum dan een kale datum/tijd-stempel.
    // Ouder dan een week: relatieve tijd wordt onduidelijk ("1 week geleden"
    // zegt weinig), dan gewoon de volledige datum tonen.
    $created_ts = mysql2date( 'U', $n->created_at );
    $is_recent  = ( time() - $created_ts ) < WEEK_IN_SECONDS;
    $created    = $is_recent
        ? sprintf(
            /* translators: %s: relatieve tijdsduur, bv. "2 uur" */
            __( '%s geleden', 'mk-cart-popup' ),
            human_time_diff( $created_ts, current_time( 'timestamp' ) )
        )
        : mysql2date( get_option( 'date_format' ), $n->created_at );
    ob_start();
    // Icoon per type i.p.v. één kale stip — bell blijft de fallback voor
    // toekomstige types die hier nog niet apart onderscheiden zijn.
    $icons = [
        'price_drop'    => '<path d="M12 20V8M6 14l6 6 6-6"/><path d="M6 4h12"/>',
        'back_in_stock' => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/>',
        'order_status'  => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>',
        'return_update' => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
    ];
    $icon = $icons[ $n->type ] ?? '<path d="M6 8a6 6 0 1112 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 21a2 2 0 004 0"/>';
    ?>
    <div class="mkcp-notif<?php echo empty( $n->is_read ) ? ' is-unread' : ''; ?>" data-notification-id="<?php echo esc_attr( $n->id ); ?>" data-url="<?php echo esc_attr( $n->url ); ?>" data-type="<?php echo esc_attr( $n->type ); ?>">
        <span class="mkcp-notif__icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput ?></svg></span>
        <span class="mkcp-notif__body">
            <span class="mkcp-notif__title"><?php echo esc_html( $n->title ); ?></span>
            <?php if ( $n->body !== '' ) : ?><span class="mkcp-notif__text"><?php echo esc_html( $n->body ); ?></span><?php endif; ?>
            <span class="mkcp-notif__date"><?php echo esc_html( $created ); ?></span>
        </span>
        <?php if ( empty( $n->is_read ) ) : ?>
            <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-notif-read"><?php esc_html_e( 'Markeer als gelezen', 'mk-cart-popup' ); ?></button>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * "Vandaag" / "Deze week" / "Eerder" — de lijst is al DESC op datum
 * gesorteerd, dus de buckets komen vanzelf aaneengesloten uit zonder dat de
 * items zelf opnieuw gesorteerd hoeven te worden.
 */
function mkcp_account_notification_date_bucket( string $created_at ): string {
    $ts               = mysql2date( 'U', $created_at );
    $today_start      = strtotime( 'today', current_time( 'timestamp' ) );
    $week_start       = strtotime( '-6 days', $today_start );
    if ( $ts >= $today_start ) return __( 'Vandaag', 'mk-cart-popup' );
    if ( $ts >= $week_start )  return __( 'Deze week', 'mk-cart-popup' );
    return __( 'Eerder', 'mk-cart-popup' );
}

/**
 * Labels voor de type-filterchips — dezelfde vier types als de iconen in
 * mkcp_account_render_notification_row(). Alleen types die daadwerkelijk in
 * de lijst van de klant voorkomen krijgen een chip (zie hieronder); een
 * klant die nooit een retour heeft aangevraagd hoeft geen "Retouren"-filter
 * te zien die toch altijd leeg zou zijn.
 */
function mkcp_account_notification_type_labels(): array {
    return [
        'price_drop'    => __( 'Prijsdaling', 'mk-cart-popup' ),
        'back_in_stock' => __( 'Voorraad', 'mk-cart-popup' ),
        'order_status'  => __( 'Bestellingen', 'mk-cart-popup' ),
        'return_update' => __( 'Retouren', 'mk-cart-popup' ),
    ];
}

function mkcp_account_render_fragment_notifications(): string {
    $user_id       = get_current_user_id();
    $notifications = mkcp_account_get_notifications( $user_id );
    $unread        = mkcp_account_get_unread_notifications_count( $user_id );

    // Welke types komen er daadwerkelijk voor in de lijst van deze klant —
    // bepaalt welke extra filterchips (naast Alles/Ongelezen) zinvol zijn.
    $present_types = [];
    foreach ( $notifications as $n ) $present_types[ $n->type ] = true;

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <div class="mkcp-account-view__header">
            <h1><?php esc_html_e( 'Meldingen', 'mk-cart-popup' ); ?></h1>
            <?php if ( $unread > 0 ) : ?>
                <button type="button" class="mkcp-btn mkcp-btn--secondary" id="mkcp-notif-mark-all"><?php esc_html_e( 'Alles als gelezen markeren', 'mk-cart-popup' ); ?></button>
            <?php endif; ?>
        </div>

        <?php if ( empty( $notifications ) ) : ?>
            <p class="mkcp-account-empty"><?php esc_html_e( 'Je hebt nog geen meldingen. Zodra er bijvoorbeeld een prijsdaling is op een product van je wishlist, of de status van een retouraanvraag wijzigt, verschijnt dat hier.', 'mk-cart-popup' ); ?></p>
        <?php else : ?>
            <div class="mkcp-notif-tabs" role="tablist">
                <button type="button" class="mkcp-notif-tab is-active js-mkcp-notif-tab" data-filter="all"><?php esc_html_e( 'Alles', 'mk-cart-popup' ); ?></button>
                <button type="button" class="mkcp-notif-tab js-mkcp-notif-tab" data-filter="unread"><?php esc_html_e( 'Ongelezen', 'mk-cart-popup' ); ?></button>
                <?php foreach ( mkcp_account_notification_type_labels() as $type_key => $type_label ) : ?>
                    <?php if ( ! empty( $present_types[ $type_key ] ) ) : ?>
                        <button type="button" class="mkcp-notif-tab js-mkcp-notif-tab" data-filter="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="mkcp-notif-list" id="mkcp-notif-list">
                <?php
                $current_bucket = null;
                foreach ( $notifications as $n ) :
                    $bucket = mkcp_account_notification_date_bucket( $n->created_at );
                    if ( $bucket !== $current_bucket ) :
                        $current_bucket = $bucket;
                        ?>
                        <p class="mkcp-notif-group-label"><?php echo esc_html( $bucket ); ?></p>
                    <?php endif; ?>
                    <?php echo mkcp_account_render_notification_row( $n ); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ── AJAX: één melding als gelezen markeren ────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_notif_read', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $notif_id = isset( $_POST['notification_id'] ) ? absint( $_POST['notification_id'] ) : 0;
    $existing = $notif_id ? mkcp_account_get_owned_notification( $notif_id, $user_id ) : null;

    if ( ! $existing ) {
        wp_send_json_error( [ 'code' => 'not_found' ], 404 );
    }

    $wpdb->update( $wpdb->prefix . 'mkcp_notifications', [ 'is_read' => 1 ], [ 'id' => $existing->id ], [ '%d' ], [ '%d' ] );

    wp_send_json_success( [ 'unread_count' => mkcp_account_get_unread_notifications_count( $user_id ) ] );
} );


// ── AJAX: alles als gelezen markeren ──────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_notif_read_all', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $wpdb->update( $wpdb->prefix . 'mkcp_notifications', [ 'is_read' => 1 ], [ 'user_id' => $user_id, 'is_read' => 0 ], [ '%d' ], [ '%d', '%d' ] );

    wp_send_json_success( [
        'html'         => mkcp_account_render_fragment_notifications(),
        'unread_count' => 0,
        'meta'         => [ 'fragment' => 'notifications' ],
    ] );
} );


// ── Opschoning: oude, gelezen meldingen ───────────────────────────────────────
//
// Standaard UIT (account_notification_retention_days = 0, zie account-
// frontend.php's mkcp_account_defaults()) — meldingen bleven tot nu toe voor
// altijd staan. Alleen als een winkelier hier bewust een aantal dagen
// instelt, gaat deze dagelijkse cron gelezen meldingen ouder dan dat aantal
// dagen verwijderen. Ongelezen meldingen worden NOOIT automatisch
// opgeruimd — een klant moet ze altijd nog kunnen zien/lezen.

add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'mkcp_account_notifications_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'mkcp_account_notifications_cleanup' );
    }
} );

add_action( 'mkcp_account_notifications_cleanup', function() {
    $cfg  = mkcp_account_config();
    $days = isset( $cfg['account_notification_retention_days'] ) ? (int) $cfg['account_notification_retention_days'] : 0;
    if ( $days <= 0 ) return;

    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}mkcp_notifications WHERE is_read = 1 AND created_at < %s",
        gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
    ) );
} );
