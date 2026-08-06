<?php
/**
 * MK Cart Popup — Account: admin-statistieken + retour-beheer (Fase 1, stap 7)
 *
 * Eigen bestand (zie de "god file"-notitie in account-profile.php) — de
 * winkelier-kant van Account: het statistiekenblokje bovenaan de module-
 * instellingen én het retour-aanvragen-overzicht (eigen tabblad, "Retouren")
 * in admin/views/settings-page.php.
 *
 * Bewust GEEN "actief in de laatste 30 dagen"-cijfer (zoals het oorspron-
 * kelijke Account-plan, sectie 10, wel noemt) — dat vereist een eigen
 * login-tracking-mechanisme (een wp_login-hook die een timestamp bijhoudt)
 * dat nergens anders in deze plugin bestaat. Een cijfer tonen dat we niet
 * eerlijk kunnen onderbouwen past niet bij hoe de rest van Account is
 * gebouwd (zie de 3-staps i.p.v. 4-staps besteltracker, de generieke
 * mijlpaal-voortgangsbalk i.p.v. een verzonnen gratis-verzendingsbelofte).
 * In plaats daarvan: "klanten met een Account-profiel" — hard afgeleid uit
 * data die al bestaat (heeft een wishlist-item of adres opgeslagen).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MKCP_ACCOUNT_ADMIN_STATS_TRANSIENT = 'mkcp_account_admin_stats';

function mkcp_account_admin_clear_stats_cache(): void {
    delete_transient( MKCP_ACCOUNT_ADMIN_STATS_TRANSIENT );
}

/**
 * @return array{
 *     active_customers:int,
 *     wishlist_items_total:int,
 *     wishlist_conversion_pct:float,
 *     open_returns:int,
 *     total_returns:int,
 *     top_wishlisted:array<int,array{product_id:int,name:string,count:int}>
 * }
 */
function mkcp_account_admin_get_stats(): array {
    $cached = get_transient( MKCP_ACCOUNT_ADMIN_STATS_TRANSIENT );
    if ( is_array( $cached ) ) return $cached;

    global $wpdb;
    $wishlists_table = $wpdb->prefix . 'mkcp_wishlists';
    $items_table     = $wpdb->prefix . 'mkcp_wishlist_items';
    $addresses_table = $wpdb->prefix . 'mkcp_addresses';
    $returns_table   = $wpdb->prefix . 'mkcp_return_requests';

    $active_customers = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT user_id) FROM (
            SELECT user_id FROM {$wishlists_table}
            UNION
            SELECT user_id FROM {$addresses_table}
        ) t"
    );

    $wishlist_items_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$items_table}" );

    $total_returns = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$returns_table} WHERE user_id != 0" );
    $open_returns  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$returns_table} WHERE status = 'pending' AND user_id != 0" );

    // Top 10 meest-gewishlist'e producten — variatie en hoofdproduct samen
    // geteld onder het hoofdproduct-ID, anders versnipperen dezelfde jas in
    // drie maten naar drie losse, lagere tellingen.
    $top_rows = $wpdb->get_results(
        "SELECT product_id, COUNT(*) as cnt FROM {$items_table} GROUP BY product_id ORDER BY cnt DESC LIMIT 10"
    );
    $top_wishlisted = [];
    foreach ( $top_rows as $row ) {
        $product = wc_get_product( (int) $row->product_id );
        if ( ! $product ) continue;
        $top_wishlisted[] = [
            'product_id' => (int) $row->product_id,
            'name'       => $product->get_name(),
            'count'      => (int) $row->cnt,
        ];
    }

    // Wishlist → aankoop-conversie: van alle wishlist-items, welk percentage
    // is door diezelfde klant ooit daadwerkelijk gekocht? Item-voor-item via
    // wc_customer_bought_product() (dezelfde, al elders in de plugin
    // vertrouwde WooCommerce-kernfunctie, zie account-reviews.php) — geen
    // eigen SQL-benadering van "gekocht" die per WC-versie/HPOS kan afwijken.
    $conversion_rows = $wpdb->get_results(
        "SELECT i.product_id, w.user_id FROM {$items_table} i
         INNER JOIN {$wishlists_table} w ON w.id = i.wishlist_id"
    );
    $wishlist_conversion_pct = 0.0;
    if ( $conversion_rows ) {
        $bought = 0;
        foreach ( $conversion_rows as $row ) {
            if ( wc_customer_bought_product( '', (int) $row->user_id, (int) $row->product_id ) ) {
                $bought++;
            }
        }
        $wishlist_conversion_pct = round( ( $bought / count( $conversion_rows ) ) * 100, 1 );
    }

    $stats = [
        'active_customers'        => $active_customers,
        'wishlist_items_total'    => $wishlist_items_total,
        'wishlist_conversion_pct' => $wishlist_conversion_pct,
        'open_returns'            => $open_returns,
        'total_returns'           => $total_returns,
        'top_wishlisted'          => $top_wishlisted,
    ];

    // 15 min — lang genoeg om herhaalde instellingenpagina-bezoeken niet
    // telkens opnieuw te laten rekenen, kort genoeg dat het nooit dagenlang
    // stale blijft (dit is een winkelier-dashboard, geen klant-gezicht
    // scherm waar de al bestaande striktere invalidatie-discipline van
    // mkcp_account_get_dashboard_stats() voor nodig is).
    set_transient( MKCP_ACCOUNT_ADMIN_STATS_TRANSIENT, $stats, 15 * MINUTE_IN_SECONDS );

    return $stats;
}


// ── Retour-aanvragen: overzicht + goed-/afkeuren ──────────────────────────────
//
// Klanten kunnen al een retour aanvragen (includes/account-returns.php) —
// dit is de ontbrekende winkelier-kant: zien wat er binnenkomt en
// goedkeuren/afwijzen/afhandelen. Bewust géén bulk-acties (Account-plan,
// sectie 11: retour-aanvragen worden doorgaans één voor één beoordeeld, en
// bulk vergroot het risico op per-ongeluk-fouten) en géén geautomatiseerde
// retourlabel-/vervoerdersintegratie (sectie 17: dat is een apart, groter
// services-integratieproject).

function mkcp_account_admin_return_statuses(): array {
    return [
        'pending'   => __( 'In behandeling', 'mk-cart-popup' ),
        'approved'  => __( 'Goedgekeurd', 'mk-cart-popup' ),
        'rejected'  => __( 'Afgewezen', 'mk-cart-popup' ),
        'completed' => __( 'Afgehandeld', 'mk-cart-popup' ),
    ];
}

/**
 * Anonimiseerde aanvragen (user_id = 0, zie mkcp_account_purge_user_data() in
 * account-db.php) blijven bewust buiten dit overzicht — er is niemand meer
 * om over te informeren en de bijbehorende order-context is voor de
 * winkelier via het bestelling-scherm zelf nog steeds terug te vinden.
 */
function mkcp_account_admin_get_return_requests( string $status = '' ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_return_requests';

    if ( $status && array_key_exists( $status, mkcp_account_admin_return_statuses() ) ) {
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id != 0 AND status = %s ORDER BY requested_at DESC LIMIT 100",
            $status
        ) );
    }

    return $wpdb->get_results( "SELECT * FROM {$table} WHERE user_id != 0 ORDER BY requested_at DESC LIMIT 100" );
}

/**
 * $status_filter=null leest de huidige waarde uit de query-string (normale
 * paginalading); de AJAX-handler hieronder geeft 'm expliciet mee (een
 * admin-ajax.php-POST heeft geen bruikbare query-string) zodat de her-
 * renderde tabel na Goedkeuren/Afwijzen/Voltooien in hetzelfde filter blijft
 * staan i.p.v. terug te springen naar "Alle statussen".
 */
function mkcp_account_admin_render_returns_panel( ?string $status_filter = null ): string {
    if ( $status_filter === null ) {
        $status_filter = isset( $_GET['mkcp_return_status'] ) ? sanitize_key( wp_unslash( $_GET['mkcp_return_status'] ) ) : '';
    }
    $requests = mkcp_account_admin_get_return_requests( $status_filter );
    $statuses      = mkcp_account_admin_return_statuses();

    ob_start();
    ?>
    <div class="mkcp-glass">
        <div class="mkcp-glass-header" style="justify-content:space-between">
            <div style="display:flex;align-items:center;gap:10px">
                <div class="mkcp-header-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg></div>
                <h3>Retour-aanvragen</h3>
            </div>
            <select id="mkcp-return-status-filter" class="mkcp-input mkcp-input--sm" style="width:auto">
                <option value="">Alle statussen</option>
                <?php foreach ( $statuses as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status_filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mkcp-glass-body">
            <?php if ( empty( $requests ) ) : ?>
                <p style="color:var(--mkcp-ui-text3);font-size:13px;margin:0">
                    <?php echo $status_filter ? esc_html__( 'Geen retour-aanvragen met deze status.', 'mk-cart-popup' ) : esc_html__( 'Nog geen retour-aanvragen ontvangen.', 'mk-cart-popup' ); ?>
                </p>
            <?php else : ?>
                <table id="mkcp-return-table" style="width:100%;border-collapse:collapse;font-size:12.5px">
                    <thead>
                        <tr style="text-align:left;color:var(--mkcp-ui-text3);font-size:11px;text-transform:uppercase;letter-spacing:.3px">
                            <th style="padding:0 8px 8px 0;font-weight:600">Bestelling / product</th>
                            <th style="padding:0 8px 8px;font-weight:600">Klant</th>
                            <th style="padding:0 8px 8px;font-weight:600">Reden</th>
                            <th style="padding:0 8px 8px;font-weight:600">Aangevraagd</th>
                            <th style="padding:0 8px 8px;font-weight:600">Status</th>
                            <th style="padding:0 0 8px;font-weight:600">Actie</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $requests as $req ) :
                            $order      = wc_get_order( $req->order_id );
                            $item       = $order ? $order->get_item( $req->order_item_id ) : null;
                            $user       = get_userdata( $req->user_id );
                            $reasons    = function_exists( 'mkcp_account_return_reasons' ) ? mkcp_account_return_reasons() : [];
                            $order_link = $order ? get_edit_post_link( $order->get_id() ) : '';
                            if ( ! $order_link && $order && method_exists( $order, 'get_edit_order_url' ) ) $order_link = $order->get_edit_order_url();
                        ?>
                        <tr style="border-top:1px solid var(--mkcp-ui-border)" data-return-id="<?php echo esc_attr( $req->id ); ?>">
                            <td style="padding:8px 8px 8px 0;vertical-align:top">
                                <?php if ( $order ) : ?>
                                    <a href="<?php echo esc_url( $order_link ); ?>" target="_blank" style="color:var(--mkcp-ui-accent);text-decoration:none">#<?php echo esc_html( $order->get_order_number() ); ?></a>
                                <?php else : ?>
                                    <span style="color:var(--mkcp-ui-text3)">#<?php echo esc_html( $req->order_id ); ?> (verwijderd)</span>
                                <?php endif; ?>
                                <div style="color:var(--mkcp-ui-text2)"><?php echo esc_html( $item ? $item->get_name() . ' × ' . $req->quantity : __( 'Artikel niet meer gevonden', 'mk-cart-popup' ) ); ?></div>
                            </td>
                            <td style="padding:8px;vertical-align:top;color:var(--mkcp-ui-text2)">
                                <?php echo $user ? esc_html( $user->display_name ) . '<br><span style="color:var(--mkcp-ui-text3)">' . esc_html( $user->user_email ) . '</span>' : esc_html__( 'Onbekend', 'mk-cart-popup' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </td>
                            <td style="padding:8px;vertical-align:top;color:var(--mkcp-ui-text2)">
                                <?php echo esc_html( $reasons[ $req->reason ] ?? $req->reason ); ?>
                                <?php if ( $req->reason_note ) : ?>
                                    <div style="color:var(--mkcp-ui-text3);font-style:italic">"<?php echo esc_html( $req->reason_note ); ?>"</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px;vertical-align:top;color:var(--mkcp-ui-text3);white-space:nowrap">
                                <?php echo esc_html( mysql2date( 'd-m-Y', $req->requested_at ) ); ?>
                            </td>
                            <td style="padding:8px;vertical-align:top">
                                <span class="mkcp-status-pill <?php echo $req->status === 'pending' ? 'mkcp-status-pill--off' : 'mkcp-status-pill--on'; ?>"><?php echo esc_html( $statuses[ $req->status ] ?? $req->status ); ?></span>
                                <?php if ( $req->admin_note ) : ?>
                                    <div style="color:var(--mkcp-ui-text3);margin-top:4px"><?php echo esc_html( $req->admin_note ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px 0;vertical-align:top">
                                <?php if ( in_array( $req->status, [ 'pending', 'approved' ], true ) ) : ?>
                                    <div style="display:flex;flex-direction:column;gap:6px;min-width:150px">
                                        <input type="text" class="mkcp-input mkcp-input--sm js-mkcp-return-note" placeholder="Notitie (optioneel)" style="font-size:11.5px">
                                        <div style="display:flex;gap:6px">
                                            <?php if ( $req->status === 'pending' ) : ?>
                                                <button type="button" class="mkcp-btn mkcp-btn--secondary js-mkcp-return-action" data-status="approved" style="padding:4px 10px;font-size:11.5px">Goedkeuren</button>
                                                <button type="button" class="mkcp-btn mkcp-btn--secondary js-mkcp-return-action" data-status="rejected" style="padding:4px 10px;font-size:11.5px">Afwijzen</button>
                                            <?php else : ?>
                                                <button type="button" class="mkcp-btn mkcp-btn--secondary js-mkcp-return-action" data-status="completed" style="padding:4px 10px;font-size:11.5px">Voltooien</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_action( 'wp_ajax_mkcp_account_admin_return_update', function() {
    check_ajax_referer( 'mkcp_account_admin_returns', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'code' => 'forbidden' ], 403 );

    $id            = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
    $note          = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
    $status_filter = isset( $_POST['status_filter'] ) ? sanitize_key( wp_unslash( $_POST['status_filter'] ) ) : '';

    if ( ! array_key_exists( $status, mkcp_account_admin_return_statuses() ) || $status === 'pending' ) {
        wp_send_json_error( [ 'code' => 'invalid_status' ], 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_return_requests';
    $req   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id != 0", $id ) ) : null;
    if ( ! $req ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );

    $wpdb->update(
        $table,
        [
            'status'      => $status,
            'resolved_at' => current_time( 'mysql' ),
            'resolved_by' => get_current_user_id(),
            'admin_note'  => $note,
        ],
        [ 'id' => $req->id ],
        [ '%s', '%s', '%d', '%s' ],
        [ '%d' ]
    );

    if ( function_exists( 'mkcp_account_admin_clear_stats_cache' ) ) mkcp_account_admin_clear_stats_cache();

    // Klant meteen op de hoogte — hergebruikt het al bestaande 'return_update'-
    // notificatietype (includes/account-notifications.php kent het icoon al).
    if ( function_exists( 'mkcp_account_add_notification' ) ) {
        $labels = [
            'approved'  => __( 'Je retour-aanvraag is goedgekeurd.', 'mk-cart-popup' ),
            'rejected'  => __( 'Je retour-aanvraag is afgewezen.', 'mk-cart-popup' ),
            'completed' => __( 'Je retour is afgehandeld.', 'mk-cart-popup' ),
        ];
        mkcp_account_add_notification(
            (int) $req->user_id,
            'return_update',
            __( 'Update over je retour', 'mk-cart-popup' ),
            ( $labels[ $status ] ?? '' ) . ( $note ? ' ' . $note : '' ),
            '#/orders/' . (int) $req->order_id,
            'order',
            (int) $req->order_id
        );
    }

    wp_send_json_success( [
        'html' => mkcp_account_admin_render_returns_panel( $status_filter ),
    ] );
} );
