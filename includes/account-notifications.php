<?php
/**
 * MK Cart Popup — Account: Notificaties
 *
 * Los bestand van account-orders.php/account-returns.php — kent alleen
 * wp_mkcp_notifications en heeft verder geen weet van bestellingen/retouren,
 * ook al vullen die systemen deze tabel (via mkcp_account_add_notification()).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'mkcp_account_fragment_handlers', function( $handlers ) {
    $handlers['notifications'] = 'mkcp_account_render_fragment_notifications';
    return $handlers;
} );


// ── Helpers ────────────────────────────────────────────────────────────────

function mkcp_account_get_notifications( int $user_id, int $limit = 50, int $offset = 0 ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_notifications';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $user_id, $limit, $offset
    ) );
}

function mkcp_account_get_notifications_total( int $user_id ): int {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_notifications';
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
        $user_id
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
 * Eén centraal aanmaakpunt, bedoeld voor andere systemen (wishlist-
 * prijsdaling/voorraad-cron, retour-statuswijziging, ...) — zodat elk systeem
 * tegen dezelfde functie-signatuur bouwt i.p.v. zelf een variant te verzinnen.
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
    // Relatieve tijd ("2 uur geleden") voor recente meldingen; ouder dan een
    // week is relatieve tijd onduidelijk, dan de volledige datum tonen.
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
        'wishlist'      => '<path d="M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.6l-1-1a5.5 5.5 0 00-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 000-7.8z"/>',
        'review'        => '<polygon points="12 2 15 9 22 9.5 17 14.5 18.5 21.5 12 17.8 5.5 21.5 7 14.5 2 9.5 9 9"/>',
    ];
    $icon = $icons[ $n->type ] ?? '<path d="M18 8a6 6 0 00-12 0c0 4.5-1.5 6.5-2 7h16c-.5-.5-2-2.5-2-7z"/><path d="M13.7 3a2 2 0 00-3.4 0"/><path d="M9 18a3 3 0 006 0"/>';
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
 * "Vandaag" / "Deze week" / "Eerder" — lijst is al DESC gesorteerd, dus de
 * buckets komen vanzelf aaneengesloten uit.
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
 * Labels voor de type-filterchips. Alleen types die daadwerkelijk voorkomen
 * in de lijst van de klant krijgen een chip (zie hieronder) — geen lege filters.
 */
function mkcp_account_notification_type_labels(): array {
    return [
        'price_drop'    => __( 'Prijsdaling', 'mk-cart-popup' ),
        'back_in_stock' => __( 'Voorraad', 'mk-cart-popup' ),
        'order_status'  => __( 'Bestellingen', 'mk-cart-popup' ),
        'return_update' => __( 'Retouren', 'mk-cart-popup' ),
        'wishlist'      => __( 'Wishlist', 'mk-cart-popup' ),
        'review'        => __( 'Reviews', 'mk-cart-popup' ),
    ];
}

function mkcp_account_render_fragment_notifications(): string {
    $user_id       = get_current_user_id();
    $notifications = mkcp_account_get_notifications( $user_id );
    $unread        = mkcp_account_get_unread_notifications_count( $user_id );
    // Harde LIMIT 50 maakte oudere meldingen onbereikbaar (filtertabjes filteren
    // alleen binnen die 50) — "Toon meer" haalt de volgende 50 op via AJAX.
    $notifications_total = mkcp_account_get_notifications_total( $user_id );

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

            <?php if ( $notifications_total > count( $notifications ) ) : ?>
                <div class="mkcp-notif-load-more-wrap">
                    <button type="button" class="mkcp-btn mkcp-btn--secondary js-mkcp-notif-load-more" data-offset="<?php echo esc_attr( count( $notifications ) ); ?>"><?php esc_html_e( 'Toon meer', 'mk-cart-popup' ); ?></button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ── AJAX: volgende pagina meldingen ophalen (F1) ────────────────────────────

add_action( 'wp_ajax_mkcp_account_notifications_load_more', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id = get_current_user_id();
    $offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

    $notifications = mkcp_account_get_notifications( $user_id, 50, $offset );
    $total         = mkcp_account_get_notifications_total( $user_id );

    ob_start();
    $current_bucket = null;
    foreach ( $notifications as $n ) :
        $bucket = mkcp_account_notification_date_bucket( $n->created_at );
        if ( $bucket !== $current_bucket ) :
            $current_bucket = $bucket;
            ?>
            <p class="mkcp-notif-group-label"><?php echo esc_html( $bucket ); ?></p>
        <?php endif; ?>
        <?php echo mkcp_account_render_notification_row( $n ); ?>
    <?php endforeach;
    $html = ob_get_clean();

    wp_send_json_success( [
        'html'     => $html,
        'has_more' => $total > ( $offset + count( $notifications ) ),
        'next_offset' => $offset + count( $notifications ),
    ] );
} );


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
// Standaard UIT (account_notification_retention_days = 0). Alleen als een
// winkelier bewust een aantal dagen instelt, verwijdert deze dagelijkse cron
// gelezen meldingen ouder dan dat. Ongelezen meldingen worden NOOIT
// automatisch opgeruimd.

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
