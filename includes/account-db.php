<?php
/**
 * MK Cart Popup — Account database (Fase 1, stap 2)
 *
 * Vijf tabellen voor de Account-omgeving: wishlists (+items), adresboek,
 * notificaties en retour-aanvragen. Volgt het dbDelta-migratiepatroon van
 * includes/abandoned-cart.php — geen FOREIGN KEY-constraints, integriteit op
 * applicatieniveau.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MKCP_ACCOUNT_DB_VERSION', '1.2' );


function mkcp_account_install_tables() {
    global $wpdb;
    $installed = get_option( 'mkcp_account_db_version' );
    if ( $installed === MKCP_ACCOUNT_DB_VERSION ) return;

    $charset = $wpdb->get_charset_collate();

    $wishlists_table      = $wpdb->prefix . 'mkcp_wishlists';
    $wishlist_items_table = $wpdb->prefix . 'mkcp_wishlist_items';
    $addresses_table      = $wpdb->prefix . 'mkcp_addresses';
    $notifications_table  = $wpdb->prefix . 'mkcp_notifications';
    $returns_table        = $wpdb->prefix . 'mkcp_return_requests';

    $wishlists_sql = "CREATE TABLE {$wishlists_table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT UNSIGNED NOT NULL,
        name        VARCHAR(191)    NOT NULL DEFAULT '',
        visibility  VARCHAR(20)     NOT NULL DEFAULT 'private',
        share_token VARCHAR(32)     NOT NULL DEFAULT '',
        is_default  TINYINT(1)      NOT NULL DEFAULT 0,
        sort_order  INT             NOT NULL DEFAULT 0,
        created_at  DATETIME        NOT NULL,
        updated_at  DATETIME        NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY share_token (share_token),
        KEY user_id (user_id)
    ) {$charset};";

    // KEY product_id: de dagelijkse prijsdaling-cron moet efficiënt "wie
    // heeft product X gewishlist" kunnen beantwoorden zonder alle wishlists
    // te doorlopen.
    //
    // 1.0 → 1.1: target_price toegevoegd. 1.1 → 1.2: notify_via_email/
    // notify_via_dashboard toegevoegd (voorheen één vlag voor beide kanalen),
    // default 1 zodat bestaand gedrag ongewijzigd blijft — puur nieuwe
    // kolommen, dbDelta voegt ze toe zonder bestaande data te raken.
    $wishlist_items_sql = "CREATE TABLE {$wishlist_items_table} (
        id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wishlist_id             BIGINT UNSIGNED NOT NULL,
        product_id              BIGINT UNSIGNED NOT NULL,
        variation_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
        note                    VARCHAR(500)    NOT NULL DEFAULT '',
        price_at_add            DECIMAL(10,2)   NOT NULL DEFAULT 0,
        target_price            DECIMAL(10,2)   DEFAULT NULL,
        notify_price_drop       TINYINT(1)      NOT NULL DEFAULT 0,
        notify_back_in_stock    TINYINT(1)      NOT NULL DEFAULT 0,
        notify_via_email        TINYINT(1)      NOT NULL DEFAULT 1,
        notify_via_dashboard    TINYINT(1)      NOT NULL DEFAULT 1,
        last_notified_price_at  DATETIME        DEFAULT NULL,
        last_notified_stock_at  DATETIME        DEFAULT NULL,
        added_at                DATETIME        NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY wishlist_product_variation (wishlist_id, product_id, variation_id),
        KEY wishlist_id (wishlist_id),
        KEY product_id (product_id)
    ) {$charset};";

    $addresses_sql = "CREATE TABLE {$addresses_table} (
        id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id             BIGINT UNSIGNED NOT NULL,
        label               VARCHAR(100)    NOT NULL DEFAULT '',
        type                VARCHAR(20)     NOT NULL DEFAULT 'both',
        is_business         TINYINT(1)      NOT NULL DEFAULT 0,
        company             VARCHAR(200)    NOT NULL DEFAULT '',
        vat_number          VARCHAR(30)     NOT NULL DEFAULT '',
        first_name          VARCHAR(100)    NOT NULL DEFAULT '',
        last_name           VARCHAR(100)    NOT NULL DEFAULT '',
        address_1           VARCHAR(200)    NOT NULL DEFAULT '',
        address_2           VARCHAR(200)    NOT NULL DEFAULT '',
        postcode            VARCHAR(20)     NOT NULL DEFAULT '',
        city                VARCHAR(100)    NOT NULL DEFAULT '',
        state               VARCHAR(100)    NOT NULL DEFAULT '',
        country             VARCHAR(2)      NOT NULL DEFAULT '',
        phone               VARCHAR(30)     NOT NULL DEFAULT '',
        is_default_billing  TINYINT(1)      NOT NULL DEFAULT 0,
        is_default_shipping TINYINT(1)      NOT NULL DEFAULT 0,
        created_at          DATETIME        NOT NULL,
        updated_at          DATETIME        NOT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id)
    ) {$charset};";

    // Index (user_id, is_read, created_at) dekt zowel de "ongelezen"-badge
    // als de gesorteerde lijstweergave.
    $notifications_sql = "CREATE TABLE {$notifications_table} (
        id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id              BIGINT UNSIGNED NOT NULL,
        type                 VARCHAR(30)     NOT NULL DEFAULT '',
        title                VARCHAR(200)    NOT NULL DEFAULT '',
        body                 TEXT            NOT NULL,
        url                  VARCHAR(500)    NOT NULL DEFAULT '',
        related_object_type  VARCHAR(30)     NOT NULL DEFAULT '',
        related_object_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        is_read              TINYINT(1)      NOT NULL DEFAULT 0,
        created_at           DATETIME        NOT NULL,
        PRIMARY KEY (id),
        KEY user_unread_created (user_id, is_read, created_at)
    ) {$charset};";

    $returns_sql = "CREATE TABLE {$returns_table} (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id       BIGINT UNSIGNED NOT NULL,
        order_item_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
        quantity       INT             NOT NULL DEFAULT 1,
        reason         VARCHAR(100)    NOT NULL DEFAULT '',
        reason_note    VARCHAR(1000)   NOT NULL DEFAULT '',
        status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
        requested_at   DATETIME        NOT NULL,
        resolved_at    DATETIME        DEFAULT NULL,
        resolved_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        admin_note     VARCHAR(1000)   NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY user_status (user_id, status),
        KEY status (status)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $wishlists_sql );
    dbDelta( $wishlist_items_sql );
    dbDelta( $addresses_sql );
    dbDelta( $notifications_sql );
    dbDelta( $returns_sql );

    update_option( 'mkcp_account_db_version', MKCP_ACCOUNT_DB_VERSION );
}

add_action( 'plugins_loaded', function() {
    if ( get_option( 'mkcp_account_db_version' ) !== MKCP_ACCOUNT_DB_VERSION ) {
        mkcp_account_install_tables();
    }
} );


// ── Opschoning bij het verwijderen van een gebruiker ──────────────────────────
// Zonder deze hook zou een verwijderde klant wishlists/adressen/notificaties
// achterlaten die aan niemand meer gekoppeld zijn (GDPR). Retour-aanvragen
// worden geanonimiseerd i.p.v. verwijderd — de boekhoudkundige koppeling aan
// order_id moet blijven bestaan.

/**
 * Verwijdert/anonimiseert alle Account-eigen data van een klant — gedeeld
 * door de admin-geïnitieerde delete_user-hook hieronder ÉN de zelfservice
 * "Account verwijderen"-flow (includes/account-gdpr.php).
 */
function mkcp_account_purge_user_data( int $user_id ): void {
    global $wpdb;

    $wishlist_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}mkcp_wishlists WHERE user_id = %d",
        $user_id
    ) );
    if ( $wishlist_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $wishlist_ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mkcp_wishlist_items WHERE wishlist_id IN ({$placeholders})",
            $wishlist_ids
        ) );
    }
    $wpdb->delete( $wpdb->prefix . 'mkcp_wishlists', [ 'user_id' => $user_id ], [ '%d' ] );
    $wpdb->delete( $wpdb->prefix . 'mkcp_addresses', [ 'user_id' => $user_id ], [ '%d' ] );
    $wpdb->delete( $wpdb->prefix . 'mkcp_notifications', [ 'user_id' => $user_id ], [ '%d' ] );
    // Anonimiseren i.p.v. verwijderen: koppeling aan order_id moet blijven bestaan.
    $wpdb->update( $wpdb->prefix . 'mkcp_return_requests', [ 'user_id' => 0 ], [ 'user_id' => $user_id ], [ '%d' ], [ '%d' ] );

    // Productreviews: naam en e-mailadres staan als losse kolommen op de
    // comment (niet dynamisch via user_id opgehaald), dus zonder deze stap
    // blijven ze na verwijdering gewoon onder de review staan. De reviewTEKST
    // zelf blijft: die heeft waarde voor andere klanten en is geen
    // persoonsgegeven van de auteur.
    $comment_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d",
        $user_id
    ) );
    if ( $comment_ids ) {
        $wpdb->update(
            $wpdb->comments,
            [
                'comment_author'       => __( 'Voormalige klant', 'mk-cart-popup' ),
                'comment_author_email' => '',
                'comment_author_url'   => '',
                'comment_author_IP'    => '',
                'user_id'              => 0,
            ],
            [ 'user_id' => $user_id ],
            [ '%s', '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );
        // Direct-SQL-update laat de comment-cache staan: die zou anders de oude
        // naam/e-mail blijven serveren tot de cache vanzelf vervalt.
        clean_comment_cache( $comment_ids );
    }
}

add_action( 'delete_user', function( $user_id ) {
    mkcp_account_purge_user_data( (int) $user_id );
} );
