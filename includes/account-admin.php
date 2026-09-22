<?php
/**
 * MK Cart Popup — Account: admin-statistieken + retour-beheer (Fase 1, stap 7)
 *
 * Winkelier-kant van Account: statistiekenblokje boven de module-instellingen
 * én het retour-aanvragen-overzicht ("Retouren"-tabblad) in
 * admin/views/settings-page.php. Zie de "god file"-notitie in account-profile.php.
 *
 * Bewust GEEN "actief in de laatste 30 dagen"-cijfer: vereist een eigen
 * login-tracking-mechanisme dat nergens anders in de plugin bestaat, en een
 * cijfer tonen dat we niet eerlijk kunnen onderbouwen past niet bij Account.
 * In plaats daarvan: "klanten met een Account-profiel" — afgeleid uit data
 * die al bestaat (heeft een wishlist-item of adres opgeslagen).
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

    // Wishlist → aankoop-conversie via wc_customer_bought_product() (zelfde
    // WC-kernfunctie als account-reviews.php) i.p.v. eigen SQL-benadering van
    // "gekocht" die per WC-versie/HPOS kan afwijken.
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

    // 15 min: lang genoeg om herhaalde instellingenpagina-bezoeken niet
    // telkens opnieuw te laten rekenen, kort genoeg om nooit dagenlang stale
    // te blijven. Winkelier-dashboard, dus minder strikt dan de invalidatie
    // van mkcp_account_get_dashboard_stats() (klant-gezicht scherm).
    set_transient( MKCP_ACCOUNT_ADMIN_STATS_TRANSIENT, $stats, 15 * MINUTE_IN_SECONDS );

    return $stats;
}


// ── Retour-aanvragen: overzicht + goed-/afkeuren ──────────────────────────────
// Winkelier-kant van de klant-retouraanvraag (includes/account-returns.php):
// zien wat binnenkomt en goedkeuren/afwijzen/afhandelen. Géén automatische
// retourlabel-/vervoerdersintegratie — apart, groter project.

function mkcp_account_admin_return_statuses(): array {
    return [
        'pending'   => __( 'In behandeling', 'mk-cart-popup' ),
        'approved'  => __( 'Goedgekeurd', 'mk-cart-popup' ),
        'rejected'  => __( 'Afgewezen', 'mk-cart-popup' ),
        'completed' => __( 'Afgehandeld', 'mk-cart-popup' ),
        // Alleen automatisch gezet via WooCommerce's "Terugbetalen"-actie
        // (mkcp_returns_sync_from_wc_refund(), account-returns.php) — niet
        // klikbaar in het actiepaneel, dient alleen om de status te tonen.
        'refunded'  => __( 'Terugbetaald', 'mk-cart-popup' ),
    ];
}

/**
 * Anonimiseerde aanvragen (user_id = 0, zie mkcp_account_purge_user_data() in
 * account-db.php) blijven buiten dit overzicht — order-context is via het
 * bestelling-scherm zelf nog terug te vinden.
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
 * $status_filter=null leest de huidige waarde uit de query-string; de
 * AJAX-handler geeft 'm expliciet mee (admin-ajax.php-POST heeft geen
 * bruikbare query-string) zodat het filter na Goedkeuren/Afwijzen/Voltooien
 * niet terugspringt naar "Alle statussen".
 */
function mkcp_account_admin_render_returns_panel( ?string $status_filter = null ): string {
    if ( $status_filter === null ) {
        $status_filter = isset( $_GET['mkcp_return_status'] ) ? sanitize_key( wp_unslash( $_GET['mkcp_return_status'] ) ) : '';
    }
    $requests = mkcp_account_admin_get_return_requests( $status_filter );
    $statuses = mkcp_account_admin_return_statuses();
    $reasons  = function_exists( 'mkcp_account_return_reasons' ) ? mkcp_account_return_reasons() : [];

    // Per order groeperen i.p.v. één platte rij per product. $requests komt
    // al DESC op requested_at binnen, dus groepen blijven vanzelf op
    // recentheid gesorteerd — geen aparte sortering nodig.
    $groups = [];
    foreach ( $requests as $req ) {
        $groups[ $req->order_id ][] = $req;
    }

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
            <?php if ( empty( $groups ) ) : ?>
                <p style="color:var(--mkcp-ui-text3);font-size:13px;margin:0">
                    <?php echo $status_filter ? esc_html__( 'Geen retour-aanvragen met deze status.', 'mk-cart-popup' ) : esc_html__( 'Nog geen retour-aanvragen ontvangen.', 'mk-cart-popup' ); ?>
                </p>
            <?php else : ?>
                <div id="mkcp-return-bulkbar" class="mkcp-return-admin-bulkbar" hidden>
                    <span><span class="js-mkcp-return-admin-bulk-count">0</span> geselecteerd</span>
                    <input type="text" class="mkcp-input mkcp-input--sm js-mkcp-return-admin-bulk-note" placeholder="Notitie (optioneel, voor alle geselecteerde)">
                    <button type="button" class="mkcp-btn mkcp-btn--secondary js-mkcp-return-admin-bulk-action" data-status="approved">Geselecteerde goedkeuren</button>
                    <button type="button" class="mkcp-btn mkcp-btn--secondary js-mkcp-return-admin-bulk-action" data-status="rejected">Geselecteerde afwijzen</button>
                </div>

                <?php foreach ( $groups as $order_id => $group_requests ) :
                    $order      = wc_get_order( $order_id );
                    $user       = get_userdata( $group_requests[0]->user_id );
                    $order_link = $order ? get_edit_post_link( $order->get_id() ) : '';
                    if ( ! $order_link && $order && method_exists( $order, 'get_edit_order_url' ) ) $order_link = $order->get_edit_order_url();
                    $group_has_actionable = (bool) array_filter( $group_requests, fn( $r ) => in_array( $r->status, [ 'pending', 'approved' ], true ) );

                    // Volledig afgeronde orders worden ingeklapt (<details>) om
                    // eindeloos scrollen door al-afgehandelde orders te voorkomen.
                    // Nog-actionable orders blijven altijd volledig zichtbaar.
                    $group_resolved = ! $group_has_actionable;
                    if ( $group_resolved ) {
                        $distinct_statuses = array_unique( array_map( fn( $r ) => $r->status, $group_requests ) );
                        $group_summary_label = count( $distinct_statuses ) === 1
                            ? ( $statuses[ reset( $distinct_statuses ) ] ?? reset( $distinct_statuses ) )
                            : __( 'Afgehandeld', 'mk-cart-popup' );
                    }
                    $group_tag = $group_resolved ? 'details' : 'div';
                ?>
                <<?php echo $group_tag; ?> class="mkcp-return-admin-order" style="border:1px solid var(--mkcp-ui-border);border-radius:10px;margin-bottom:12px;overflow:hidden">
                    <<?php echo $group_resolved ? 'summary' : 'div'; ?> style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--mkcp-ui-bg-alt, rgba(0,0,0,.02));border-bottom:1px solid var(--mkcp-ui-border);font-size:12.5px<?php echo $group_resolved ? ';cursor:pointer' : ''; ?>">
                        <?php if ( $group_has_actionable ) : ?>
                            <input type="checkbox" class="js-mkcp-return-admin-select-order" aria-label="Alle producten van deze order selecteren">
                        <?php endif; ?>
                        <div style="flex:1">
                            <?php if ( $order ) : ?>
                                <a href="<?php echo esc_url( $order_link ); ?>" target="_blank" style="color:var(--mkcp-ui-accent);text-decoration:none;font-weight:600">#<?php echo esc_html( $order->get_order_number() ); ?></a>
                            <?php else : ?>
                                <span style="color:var(--mkcp-ui-text3);font-weight:600">#<?php echo esc_html( $order_id ); ?> (verwijderd)</span>
                            <?php endif; ?>
                            — <?php echo $user ? esc_html( $user->display_name ) . ' <span style="color:var(--mkcp-ui-text3)">(' . esc_html( $user->user_email ) . ')</span>' : esc_html__( 'Onbekend', 'mk-cart-popup' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        </div>
                        <?php if ( $group_resolved ) : ?>
                            <span class="mkcp-status-pill mkcp-status-pill--on"><?php echo esc_html( $group_summary_label ); ?></span>
                        <?php endif; ?>
                        <span style="color:var(--mkcp-ui-text3);white-space:nowrap"><?php echo esc_html( mysql2date( 'd-m-Y', $group_requests[0]->requested_at ) ); ?></span>
                    </<?php echo $group_resolved ? 'summary' : 'div'; ?>>
                    <?php
                    // table-layout:fixed + procentuele breedtes: elke order heeft
                    // z'n eigen <table>, en met table-layout:auto (vorige situatie)
                    // berekende elke tabel kolombreedtes onafhankelijk, waardoor
                    // kolommen niet verticaal gelijk liepen tussen orders.
                    // Afgeronde orders krijgen geen actiekolom (altijd leeg), zodat
                    // de statuskolom netjes tegen de rechterrand uitlijnt.
                    ?>
                    <table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:12.5px">
                        <colgroup>
                            <col style="width:28px">
                            <?php if ( $group_resolved ) : ?>
                                <col style="width:38%">
                                <col style="width:38%">
                                <col style="width:auto">
                            <?php else : ?>
                                <col style="width:26%">
                                <col style="width:26%">
                                <col style="width:16%">
                                <col style="width:32%">
                            <?php endif; ?>
                        </colgroup>
                        <tbody>
                            <?php foreach ( $group_requests as $req ) :
                                $item = $order ? $order->get_item( $req->order_item_id ) : null;
                            ?>
                            <tr class="js-mkcp-return-admin-row" style="border-top:1px solid var(--mkcp-ui-border)" data-return-id="<?php echo esc_attr( $req->id ); ?>">
                                <td style="padding:8px 0 8px 12px;vertical-align:top">
                                    <?php if ( in_array( $req->status, [ 'pending', 'approved' ], true ) ) : ?>
                                        <input type="checkbox" class="js-mkcp-return-admin-select" aria-label="Selecteren voor bulk-actie">
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px;vertical-align:top;overflow-wrap:break-word">
                                    <?php echo esc_html( $item ? $item->get_name() . ' × ' . $req->quantity : __( 'Artikel niet meer gevonden', 'mk-cart-popup' ) ); ?>
                                </td>
                                <td style="padding:8px;vertical-align:top;color:var(--mkcp-ui-text2);overflow-wrap:break-word">
                                    <?php echo esc_html( $reasons[ $req->reason ] ?? $req->reason ); ?>
                                    <?php if ( $req->reason_note ) : ?>
                                        <div style="color:var(--mkcp-ui-text3);font-style:italic">"<?php echo esc_html( $req->reason_note ); ?>"</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px 12px 8px 8px;vertical-align:top<?php echo $group_resolved ? ';text-align:right' : ''; ?>">
                                    <span class="mkcp-status-pill <?php echo $req->status === 'pending' ? 'mkcp-status-pill--off' : 'mkcp-status-pill--on'; ?>"><?php echo esc_html( $statuses[ $req->status ] ?? $req->status ); ?></span>
                                    <?php if ( $req->admin_note ) : ?>
                                        <div style="color:var(--mkcp-ui-text3);margin-top:4px"><?php echo esc_html( $req->admin_note ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if ( ! $group_resolved ) : ?>
                                <td style="padding:8px 12px 8px 8px;vertical-align:top">
                                    <?php if ( in_array( $req->status, [ 'pending', 'approved' ], true ) ) : ?>
                                        <div style="display:flex;flex-direction:column;gap:6px">
                                            <?php // Notitie geldt voor élke actie hieronder, niet alleen goedkeuren. ?>
                                            <input type="text" class="mkcp-input mkcp-input--sm js-mkcp-return-note" placeholder="Notitie (optioneel)" style="font-size:11.5px;width:100%;box-sizing:border-box">
                                            <div style="display:flex;gap:6px;flex-wrap:wrap">
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
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </<?php echo $group_tag; ?>>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Gedeelde statusovergang-logica voor zowel de losse als de bulk-AJAX-actie
 * hieronder, zodat de statusguard (afgewezen kan niet alsnog goedgekeurd
 * worden, e.d.) op één plek staat.
 *
 * @return array{success:bool, code?:string, req?:object}
 */
function mkcp_account_admin_apply_return_status( int $id, string $status, string $note ): array {
    if ( ! array_key_exists( $status, mkcp_account_admin_return_statuses() ) || $status === 'pending' ) {
        return [ 'success' => false, 'code' => 'invalid_status' ];
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_return_requests';
    $req   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id != 0", $id ) ) : null;
    if ( ! $req ) return [ 'success' => false, 'code' => 'not_found' ];

    // Serverside statusguard: de UI verbergt de knoppen al buiten 'pending'/
    // 'approved', maar zonder dit kon een directe POST een afgewezen of
    // afgehandelde aanvraag alsnog omzetten.
    $allowed_transitions = [
        'pending'  => [ 'approved', 'rejected' ],
        'approved' => [ 'completed', 'rejected' ],
    ];
    if ( ! in_array( $status, $allowed_transitions[ $req->status ] ?? [], true ) ) {
        return [ 'success' => false, 'code' => 'invalid_transition' ];
    }

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

    // Klant meteen op de hoogte via het bestaande 'return_update'-notificatietype.
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

    return [ 'success' => true, 'req' => $req ];
}

add_action( 'wp_ajax_mkcp_account_admin_return_update', function() {
    check_ajax_referer( 'mkcp_account_admin_returns', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'code' => 'forbidden' ], 403 );

    $id            = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
    $note          = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
    $status_filter = isset( $_POST['status_filter'] ) ? sanitize_key( wp_unslash( $_POST['status_filter'] ) ) : '';

    $result = mkcp_account_admin_apply_return_status( $id, $status, $note );
    if ( ! $result['success'] ) {
        wp_send_json_error( [ 'code' => $result['code'] ], 400 );
    }

    wp_send_json_success( [
        'html' => mkcp_account_admin_render_returns_panel( $status_filter ),
    ] );
} );

// Bulk-variant: zelfde statusguard per ID, één gedeelde notitie voor de hele
// selectie (zelfde ontwerp als de klant-eigen bulk-retouraanvraag, account-returns.php).
add_action( 'wp_ajax_mkcp_account_admin_return_update_bulk', function() {
    check_ajax_referer( 'mkcp_account_admin_returns', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'code' => 'forbidden' ], 403 );

    $ids           = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : [];
    $status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
    $note          = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
    $status_filter = isset( $_POST['status_filter'] ) ? sanitize_key( wp_unslash( $_POST['status_filter'] ) ) : '';

    if ( ! $ids ) wp_send_json_error( [ 'code' => 'no_ids' ], 400 );

    $applied = 0;
    $skipped = 0;
    foreach ( $ids as $id ) {
        $result = mkcp_account_admin_apply_return_status( $id, $status, $note );
        if ( $result['success'] ) {
            $applied++;
        } else {
            $skipped++;
        }
    }

    wp_send_json_success( [
        'html'    => mkcp_account_admin_render_returns_panel( $status_filter ),
        'applied' => $applied,
        'skipped' => $skipped,
    ] );
} );
