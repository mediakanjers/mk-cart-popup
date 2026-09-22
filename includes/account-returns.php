<?php
/**
 * MK Cart Popup — Account: Retour-aanvraag (Fase 1, stap 6b)
 *
 * Eigen bestand (zie "god file"-notitie in account-profile.php). Injecteert
 * het retourknop/-formulier in account-orders.php via het
 * mkcp_account_order_return_item-filter, zodat de bestanden elkaar niet
 * hoeven te kennen. Registreert ook het "Retourneren"-tabblad-fragment.
 *
 * Retourtermijn instelbaar via settings-page.php (account_return_window_days,
 * zie mkcp_account_return_window_days()); redenen zijn nog een vaste lijst.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Aantal dagen na "voltooid" dat een retour nog aangevraagd mag worden. */
function mkcp_account_return_window_days(): int {
    $cfg = mkcp_account_config();
    $days = isset( $cfg['account_return_window_days'] ) ? (int) $cfg['account_return_window_days'] : 14;
    return $days > 0 ? $days : 14;
}

function mkcp_account_return_reasons(): array {
    return [
        'defect'           => __( 'Product is beschadigd of defect', 'mk-cart-popup' ),
        'wrong_item'       => __( 'Verkeerd artikel ontvangen', 'mk-cart-popup' ),
        'not_as_described' => __( 'Anders dan verwacht', 'mk-cart-popup' ),
        'changed_mind'     => __( 'Ik heb me bedacht', 'mk-cart-popup' ),
        'other'            => __( 'Anders', 'mk-cart-popup' ),
    ];
}


// ── Helpers ────────────────────────────────────────────────────────────────

function mkcp_account_order_return_eligible( WC_Order $order ): bool {
    if ( $order->get_status() !== 'completed' ) return false;

    $completed_at = $order->get_date_completed() ?: $order->get_date_created();
    if ( ! $completed_at ) return false;

    $deadline = $completed_at->getTimestamp() + ( mkcp_account_return_window_days() * DAY_IN_SECONDS );
    return time() <= $deadline;
}

/** Retour-aanvragen van deze order, geïndexeerd op order_item_id, voor snelle lookup in de item-lijst. */
function mkcp_account_get_return_requests_for_order( int $order_id ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_return_requests';
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE order_id = %d ORDER BY requested_at DESC",
        $order_id
    ) );

    $by_item = [];
    foreach ( $rows as $row ) {
        $by_item[ (int) $row->order_item_id ][] = $row;
    }
    return $by_item;
}

function mkcp_account_return_status_label( string $status ): string {
    $labels = [
        'pending'   => __( 'In behandeling', 'mk-cart-popup' ),
        'approved'  => __( 'Goedgekeurd', 'mk-cart-popup' ),
        'rejected'  => __( 'Afgewezen', 'mk-cart-popup' ),
        'completed' => __( 'Afgehandeld', 'mk-cart-popup' ),
        'refunded'  => __( 'Terugbetaald', 'mk-cart-popup' ),
    ];
    return $labels[ $status ] ?? $status;
}

/**
 * Visuele stappen-tracker (ook hergebruikt in het adminscherm). "Afgewezen"
 * krijgt een eigen korte 2-staps-tak (Aangevraagd → Afgewezen) i.p.v. de
 * normale "op weg naar terugbetaald"-balk.
 */
function mkcp_account_render_return_progress( string $status ): string {
    if ( $status === 'rejected' ) {
        return '<div class="mkcp-return-progress mkcp-return-progress--rejected">'
            . '<span class="mkcp-return-progress__step is-done"><span class="mkcp-return-progress__dot"></span><span class="mkcp-return-progress__label">' . esc_html__( 'Aangevraagd', 'mk-cart-popup' ) . '</span></span>'
            . '<span class="mkcp-return-progress__line is-filled is-rejected"></span>'
            . '<span class="mkcp-return-progress__step is-done is-rejected"><span class="mkcp-return-progress__dot"></span><span class="mkcp-return-progress__label">' . esc_html__( 'Afgewezen', 'mk-cart-popup' ) . '</span></span>'
            . '</div>';
    }

    $steps = [
        __( 'Aangevraagd', 'mk-cart-popup' ),
        __( 'Goedgekeurd', 'mk-cart-popup' ),
        $status === 'refunded' ? __( 'Terugbetaald', 'mk-cart-popup' ) : __( 'Afgehandeld', 'mk-cart-popup' ),
    ];
    $current = 1;
    if ( in_array( $status, [ 'approved', 'completed', 'refunded' ], true ) ) $current = 2;
    if ( in_array( $status, [ 'completed', 'refunded' ], true ) ) $current = 3;

    $html = '<div class="mkcp-return-progress">';
    foreach ( $steps as $i => $label ) {
        $step = $i + 1;
        if ( $step > 1 ) {
            $html .= '<span class="mkcp-return-progress__line' . ( ( $step - 1 ) < $current ? ' is-filled' : '' ) . '"></span>';
        }
        $html .= '<span class="mkcp-return-progress__step' . ( $step <= $current ? ' is-done' : '' ) . ( $step === $current ? ' is-current' : '' ) . '">'
            . '<span class="mkcp-return-progress__dot"></span><span class="mkcp-return-progress__label">' . esc_html( $label ) . '</span></span>';
    }
    $html .= '</div>';
    return $html;
}

/** Admin-instelbare uitleg over het retourproces, met {dagen} als placeholder voor de retourtermijn. */
function mkcp_account_return_process_text(): string {
    $cfg  = mkcp_account_config();
    $text = $cfg['account_return_process_text'] ?? '';
    if ( $text === '' ) return '';
    return strtr( $text, [ '{dagen}' => (string) mkcp_account_return_window_days() ] );
}


// ── Meelezen met WooCommerce's eigen terugbetaling ──────────────────────────
//
// Bewust GEEN wc_create_refund() aanroepen — terugbetalen is een afspraak
// tussen winkelier en klant. Dit volgt alleen: zodra de winkelier zelf via
// WooCommerce's "Terugbetalen"-knop een refund verwerkt, zet dit de
// bijbehorende retour-aanvraag op "refunded" + meldt het aan de klant.
add_action( 'woocommerce_order_refunded',          'mkcp_returns_sync_from_wc_refund', 10, 2 );
add_action( 'woocommerce_order_partially_refunded', 'mkcp_returns_sync_from_wc_refund', 10, 2 );

function mkcp_returns_sync_from_wc_refund( $order_id, $refund_id ) {
    $order  = wc_get_order( $order_id );
    $refund = wc_get_order( $refund_id );
    if ( ! $order || ! $refund instanceof WC_Order_Refund ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_return_requests';

    // Order-item-ID's waar deze refund aan gekoppeld is — WooCommerce zet dit
    // als _refunded_item_id-meta op elk refund-regelitem (wc_create_refund()).
    $refunded_item_ids = [];
    foreach ( $refund->get_items() as $refund_item ) {
        $original_id = $refund_item->get_meta( '_refunded_item_id' );
        if ( $original_id ) $refunded_item_ids[] = (int) $original_id;
    }

    $target_requests = [];

    if ( $refunded_item_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $refunded_item_ids ), '%d' ) );
        $target_requests = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d AND status IN ('pending','approved') AND order_item_id IN ({$placeholders})",
            array_merge( [ $order_id ], $refunded_item_ids )
        ) );
    }

    // Fallback voor niet-itemized refunds (alleen een totaalbedrag, geen
    // regel-koppeling): alleen syncen bij precies één open aanvraag op deze
    // bestelling, anders te onzeker welke het zou moeten zijn.
    if ( ! $target_requests ) {
        $open = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d AND status IN ('pending','approved')",
            $order_id
        ) );
        if ( count( $open ) === 1 ) $target_requests = $open;
    }

    if ( ! $target_requests ) return;

    $amount = wc_price( $refund->get_amount() );

    foreach ( $target_requests as $req ) {
        $wpdb->update(
            $table,
            [
                'status'      => 'refunded',
                'resolved_at' => current_time( 'mysql' ),
                'admin_note'  => sprintf(
                    /* translators: %s: teruggestort bedrag */
                    __( 'Automatisch gekoppeld: WooCommerce-terugbetaling van %s gedetecteerd.', 'mk-cart-popup' ),
                    wp_strip_all_tags( $amount )
                ),
            ],
            [ 'id' => $req->id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        $item      = $order->get_item( (int) $req->order_item_id );
        $item_name = $item ? $item->get_name() : __( 'je bestelling', 'mk-cart-popup' );

        $order->add_order_note( sprintf(
            'Retour-aanvraag automatisch op "Terugbetaald" gezet — WooCommerce-refund van %s gekoppeld aan %s.',
            wp_strip_all_tags( $amount ),
            $item_name
        ) );

        if ( function_exists( 'mkcp_account_add_notification' ) ) {
            mkcp_account_add_notification(
                (int) $req->user_id,
                'return_update',
                __( 'Je retour is terugbetaald', 'mk-cart-popup' ),
                sprintf(
                    /* translators: 1: productnaam, 2: bedrag */
                    __( 'Je retour van %1$s is terugbetaald: %2$s.', 'mk-cart-popup' ),
                    $item_name,
                    wp_strip_all_tags( $amount )
                ),
                '#/returns',
                'return_request',
                (int) $req->order_item_id
            );
        }
    }

    if ( function_exists( 'mkcp_account_admin_clear_stats_cache' ) ) mkcp_account_admin_clear_stats_cache();
}


// ── Helper: heeft deze bestelling een openstaande retour-aanvraag? ─────────
//
// Voor de retour-indicator in account-orders.php — bewust alleen "open"
// (pending/approved); afgehandeld/afgewezen/terugbetaald horen in het
// Retourneren-tabblad, niet als ruis in de bestellingenlijst.
function mkcp_account_order_has_open_return( int $order_id ): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_return_requests';
    $count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND status IN ('pending','approved')",
        $order_id
    ) );
    return (int) $count > 0;
}


// ── Retour-aanvragen van een klant, voor het "Retourneren"-tabblad ─────────

function mkcp_account_get_return_requests_for_user( int $user_id ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_return_requests';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d ORDER BY requested_at DESC",
        $user_id
    ) );
}


// ── UI: retour-blok per bestelitem, in een eigen "Retourneren"-kaart ────────
//
// account-orders.php roept dit aan via mkcp_account_order_return_item en zet
// het resultaat in een eigen kaart náást de reviews-kaart. Bewust een EIGEN
// filter i.p.v. het gedeelde mkcp_account_order_item_extra (dat is nu alleen
// nog voor de review-UI) — retouren en reviews hebben elk hun eigen kaart.

add_filter( 'mkcp_account_order_return_item', function( string $html, WC_Order $order, $item ) {
    $existing_by_item = mkcp_account_get_return_requests_for_order( $order->get_id() );
    $item_id          = $item->get_id();

    // De naam/aantal-regel per product wordt centraal gerenderd door de
    // aanroeper (account-orders.php, gedeeld met de review-actie hieronder)
    // — dit filter levert alleen nog de knop/status/formulier zelf.
    if ( ! empty( $existing_by_item[ $item_id ] ) ) {
        $latest = $existing_by_item[ $item_id ][0];
        return $html
            . '<span class="mkcp-return-status mkcp-return-status--' . esc_attr( $latest->status ) . '">'
            . esc_html__( 'Retour: ', 'mk-cart-popup' ) . esc_html( mkcp_account_return_status_label( $latest->status ) )
            . '</span>';
    }

    // Module uitgezet: bestaande retour-status (hierboven) blijft gewoon
    // zichtbaar (dat is geschiedenis, geen nieuwe actie), maar de "Retour
    // aanvragen"-knop voor NIEUWE aanvragen verdwijnt.
    if ( ! mkcp_account_module_enabled( 'returns' ) ) {
        return $html;
    }

    if ( ! mkcp_account_order_return_eligible( $order ) ) {
        return $html;
    }

    ob_start();
    ?>
    <label class="mkcp-return-item__select">
        <input type="checkbox" class="mkcp-checkbox js-mkcp-return-bulk-select" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-item-name="<?php echo esc_attr( $item->get_name() ); ?>" aria-label="<?php esc_attr_e( 'Selecteren voor retour', 'mk-cart-popup' ); ?>">
    </label>
    <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-return-open" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-max-qty="<?php echo esc_attr( $item->get_quantity() ); ?>">
        <?php esc_html_e( 'Retour aanvragen', 'mk-cart-popup' ); ?>
    </button>
    <form class="mkcp-return-form js-mkcp-return-form" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-item-id="<?php echo esc_attr( $item_id ); ?>" hidden>
        <div class="mkcp-form-modal__header">
            <h3><?php esc_html_e( 'Retour aanvragen', 'mk-cart-popup' ); ?></h3>
            <button type="button" class="mkcp-form-modal__close js-mkcp-return-cancel" aria-label="<?php esc_attr_e( 'Sluiten', 'mk-cart-popup' ); ?>">&times;</button>
        </div>
        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
        <input type="hidden" name="order_item_id" value="<?php echo esc_attr( $item_id ); ?>">
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Aantal', 'mk-cart-popup' ); ?></label>
            <select name="quantity">
                <?php for ( $q = 1; $q <= $item->get_quantity(); $q++ ) : ?>
                    <option value="<?php echo esc_attr( $q ); ?>"><?php echo esc_html( $q ); ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Reden', 'mk-cart-popup' ); ?></label>
            <select name="reason">
                <?php foreach ( mkcp_account_return_reasons() as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Toelichting (optioneel)', 'mk-cart-popup' ); ?></label>
            <textarea name="reason_note" rows="2"></textarea>
        </div>
        <div class="mkcp-account-form-actions">
            <button type="submit" class="mkcp-btn mkcp-btn--primary"><?php esc_html_e( 'Aanvraag versturen', 'mk-cart-popup' ); ?></button>
            <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-return-cancel"><?php esc_html_e( 'Annuleren', 'mk-cart-popup' ); ?></button>
            <span class="mkcp-account-form-status" data-form-status="return" role="status" aria-live="polite"></span>
        </div>
    </form>
    <?php
    return $html . ob_get_clean();
}, 10, 3 );


// ── AJAX: retour-aanvraag versturen ───────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_return_request', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }
    if ( ! mkcp_account_module_enabled( 'returns' ) ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user_id       = get_current_user_id();
    $order_id      = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $order_item_id = isset( $_POST['order_item_id'] ) ? absint( $_POST['order_item_id'] ) : 0;
    $quantity      = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;
    $reason        = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
    $reason_note   = isset( $_POST['reason_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason_note'] ) ) : '';

    $order = $order_id ? wc_get_order( $order_id ) : null;

    // Eigendomscheck: nooit vertrouwen dat een gepost order_id/order_item_id
    // ook echt bij deze klant hoort — zelfde patroon als overal elders in de
    // Account-omgeving (adresboek, wishlist, bestellingen).
    if ( ! $order || (int) $order->get_customer_id() !== $user_id ) {
        wp_send_json_error( [ 'code' => 'not_found' ], 404 );
    }

    $item = $order->get_item( $order_item_id );
    if ( ! $item ) {
        wp_send_json_error( [ 'code' => 'item_not_found' ], 404 );
    }

    if ( ! mkcp_account_order_return_eligible( $order ) ) {
        wp_send_json_error( [ 'code' => 'not_eligible', 'message' => __( 'Deze bestelling valt niet meer binnen de retourtermijn.', 'mk-cart-popup' ) ], 400 );
    }

    if ( $quantity > $item->get_quantity() ) {
        wp_send_json_error( [ 'code' => 'invalid_quantity', 'message' => __( 'Ongeldig aantal.', 'mk-cart-popup' ) ], 400 );
    }

    if ( ! array_key_exists( $reason, mkcp_account_return_reasons() ) ) {
        wp_send_json_error( [ 'code' => 'invalid_reason', 'message' => __( 'Kies een geldige reden.', 'mk-cart-popup' ) ], 400 );
    }

    // Geen dubbele aanvraag voor hetzelfde item — de UI verbergt de knop al
    // zodra er een aanvraag bestaat, maar de server moet dit zelf ook
    // afdwingen (de knop verbergen is UX, geen beveiliging).
    $existing = mkcp_account_get_return_requests_for_order( $order_id );
    if ( ! empty( $existing[ $order_item_id ] ) ) {
        wp_send_json_error( [ 'code' => 'already_requested', 'message' => __( 'Voor dit artikel is al een retour aangevraagd.', 'mk-cart-popup' ) ], 409 );
    }

    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'mkcp_return_requests', [
        'order_id'      => $order_id,
        'order_item_id' => $order_item_id,
        'user_id'       => $user_id,
        'quantity'      => $quantity,
        'reason'        => $reason,
        'reason_note'   => $reason_note,
        'status'        => 'pending',
        'requested_at'  => current_time( 'mysql' ),
    ] );

    // Zichtbare admin-signalering op de bestelling zelf (Account-plan, sectie
    // 13 — de "goedkope order-meta-vlag" die het bestaande admin-orderlijst-
    // kolom-patroon van bezorgdatum/afhaal-gereed spiegelt).
    $order->update_meta_data( '_mkcp_has_return_request', '1' );
    $order->save();

    if ( function_exists( 'mkcp_account_add_notification' ) ) {
        mkcp_account_add_notification(
            $user_id,
            'return_update',
            __( 'Retouraanvraag ontvangen', 'mk-cart-popup' ),
            sprintf(
                /* translators: %s: ordernummer */
                __( 'We hebben je retouraanvraag voor bestelling #%s ontvangen en behandelen deze zo snel mogelijk.', 'mk-cart-popup' ),
                $order->get_order_number()
            ),
            '#/orders/' . $order_id,
            'return_request',
            $order_item_id
        );
    }

    // Winkelier op de hoogte stellen: bewust GEEN nieuwe WC-orderstatus (zie
    // mkcp_returns_add_order_column() hieronder) — een ordernotitie + e-mail
    // volstaat en raakt de fulfillment-status van de order niet aan.
    $reason_label = mkcp_account_return_reasons()[ $reason ] ?? $reason;
    $order->add_order_note( sprintf(
        'Retour aangevraagd door klant: %1$d× %2$s. Reden: %3$s.%4$s',
        $quantity,
        $item->get_name(),
        $reason_label,
        $reason_note ? ' Toelichting: ' . $reason_note : ''
    ) );

    mkcp_returns_notify_admin( $order, $item, $quantity, $reason_label, $reason_note );

    wp_send_json_success( [
        'html'         => mkcp_account_render_order_detail( $order_id ),
        'meta'         => [ 'fragment' => 'orders' ],
        // Zonder dit blijft het rode "1"-badge op Meldingen pas na een harde
        // reload verschijnen — client heeft dit nodig voor updateNavBadge()
        // (assets/account.js) zonder volledige page reload.
        'unread_count' => mkcp_account_get_unread_notifications_count( $user_id ),
    ] );
} );


// ── AJAX: retour-aanvraag versturen voor meerdere producten in 1x ──────────
//
// Zelfde checks als de single-item-handler, nu in een lus — één gezamenlijke
// reden voor de hele selectie. Elk item krijgt zijn volledige bestelde
// aantal mee (voor een afwijkend aantal: de losse "Retour aanvragen"-knop).
add_action( 'wp_ajax_mkcp_account_return_request_bulk', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() || ! mkcp_account_module_enabled( 'returns' ) ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user_id      = get_current_user_id();
    $order_id     = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $item_ids     = isset( $_POST['item_ids'] ) && is_array( $_POST['item_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['item_ids'] ) ) : [];
    $reason       = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
    $reason_note  = isset( $_POST['reason_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason_note'] ) ) : '';

    $order = $order_id ? wc_get_order( $order_id ) : null;
    if ( ! $order || (int) $order->get_customer_id() !== $user_id ) {
        wp_send_json_error( [ 'code' => 'not_found' ], 404 );
    }
    if ( ! mkcp_account_order_return_eligible( $order ) ) {
        wp_send_json_error( [ 'code' => 'not_eligible', 'message' => __( 'Deze bestelling valt niet meer binnen de retourtermijn.', 'mk-cart-popup' ) ], 400 );
    }
    if ( ! array_key_exists( $reason, mkcp_account_return_reasons() ) ) {
        wp_send_json_error( [ 'code' => 'invalid_reason', 'message' => __( 'Kies een geldige reden.', 'mk-cart-popup' ) ], 400 );
    }
    // Praktisch plafond, zelfde geest als de andere bulk-acties in Account
    // (wishlist) — voorkomt een onbedoeld enorme POST/lus.
    $item_ids = array_slice( array_unique( array_filter( $item_ids ) ), 0, 50 );
    if ( ! $item_ids ) {
        wp_send_json_error( [ 'code' => 'no_items', 'message' => __( 'Selecteer minstens één product.', 'mk-cart-popup' ) ], 400 );
    }

    $existing     = mkcp_account_get_return_requests_for_order( $order_id );
    $reason_label = mkcp_account_return_reasons()[ $reason ];
    $added        = [];
    $notify_items = [];

    global $wpdb;
    foreach ( $item_ids as $item_id ) {
        $item = $order->get_item( $item_id );
        if ( ! $item ) continue; // hoort niet bij deze order — negeren, niet hard falen op de hele batch
        if ( ! empty( $existing[ $item_id ] ) ) continue; // al aangevraagd

        $wpdb->insert( $wpdb->prefix . 'mkcp_return_requests', [
            'order_id'      => $order_id,
            'order_item_id' => $item_id,
            'user_id'       => $user_id,
            'quantity'      => $item->get_quantity(),
            'reason'        => $reason,
            'reason_note'   => $reason_note,
            'status'        => 'pending',
            'requested_at'  => current_time( 'mysql' ),
        ] );

        $order->add_order_note( sprintf(
            'Retour aangevraagd door klant (bulk): %1$d× %2$s. Reden: %3$s.%4$s',
            $item->get_quantity(),
            $item->get_name(),
            $reason_label,
            $reason_note ? ' Toelichting: ' . $reason_note : ''
        ) );
        // Niet hier mailen: één bulk-aanvraag hoort één bericht aan de
        // winkelier op te leveren, geen mail per product (zie na de lus).
        $notify_items[] = $item;

        $added[] = $item->get_name();
    }

    if ( $notify_items ) {
        mkcp_returns_notify_admin( $order, $notify_items, 0, $reason_label, $reason_note );
    }

    if ( ! $added ) {
        wp_send_json_error( [ 'code' => 'already_requested', 'message' => __( 'Voor deze producten is al een retour aangevraagd.', 'mk-cart-popup' ) ], 409 );
    }

    $order->update_meta_data( '_mkcp_has_return_request', '1' );
    $order->save();

    if ( function_exists( 'mkcp_account_add_notification' ) ) {
        mkcp_account_add_notification(
            $user_id,
            'return_update',
            __( 'Retouraanvraag ontvangen', 'mk-cart-popup' ),
            sprintf(
                /* translators: 1: aantal producten, 2: ordernummer */
                _n(
                    'We hebben je retouraanvraag voor %1$d product uit bestelling #%2$s ontvangen en behandelen deze zo snel mogelijk.',
                    'We hebben je retouraanvraag voor %1$d producten uit bestelling #%2$s ontvangen en behandelen deze zo snel mogelijk.',
                    count( $added ),
                    'mk-cart-popup'
                ),
                count( $added ),
                $order->get_order_number()
            ),
            '#/orders/' . $order_id,
            'return_request',
            $order_id
        );
    }

    wp_send_json_success( [
        'html'         => mkcp_account_render_order_detail( $order_id ),
        'meta'         => [ 'fragment' => 'orders' ],
        'unread_count' => mkcp_account_get_unread_notifications_count( $user_id ),
    ] );
} );


// ── Fragment: Retourneren-overzicht ─────────────────────────────────────────

add_filter( 'mkcp_account_fragment_handlers', function( $handlers ) {
    $handlers['returns'] = 'mkcp_account_render_fragment_returns';
    return $handlers;
} );

function mkcp_account_render_fragment_returns(): string {
    $user_id  = get_current_user_id();
    $requests = mkcp_account_get_return_requests_for_user( $user_id );

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <div class="mkcp-account-view__header">
            <h1><?php esc_html_e( 'Retourneren', 'mk-cart-popup' ); ?></h1>
        </div>

        <?php if ( empty( $requests ) ) : ?>
            <p class="mkcp-account-notice"><?php esc_html_e( 'Je hebt nog geen retour aangevraagd.', 'mk-cart-popup' ); ?></p>
        <?php else : ?>
            <?php $mkcp_return_process_text = mkcp_account_return_process_text(); ?>
            <?php if ( $mkcp_return_process_text ) : ?>
                <p class="mkcp-account-notice mkcp-account-notice--info"><?php echo esc_html( $mkcp_return_process_text ); ?></p>
            <?php endif; ?>
            <div class="mkcp-return-list">
                <?php foreach ( $requests as $req ) :
                    $order = wc_get_order( (int) $req->order_id );
                    if ( ! $order ) continue;
                    $item      = $order->get_item( (int) $req->order_item_id );
                    $item_name = $item ? $item->get_name() : __( 'Onbekend product', 'mk-cart-popup' );
                    $product   = $item ? $item->get_product() : null;
                    ?>
                    <a class="mkcp-return-row js-mkcp-route" href="#/orders/<?php echo esc_attr( $order->get_id() ); ?>">
                        <span class="mkcp-return-row__thumb">
                            <?php if ( $product ) : ?>
                                <?php echo wp_kses_post( $product->get_image( [ 44, 44 ] ) ); ?>
                            <?php else : ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/></svg>
                            <?php endif; ?>
                        </span>
                        <span class="mkcp-return-row__main">
                            <span class="mkcp-return-row__product"><?php echo esc_html( $item_name ); ?> <?php echo $req->quantity > 1 ? '× ' . esc_html( $req->quantity ) : ''; ?></span>
                            <span class="mkcp-return-row__meta">
                                <?php
                                printf(
                                    /* translators: 1: ordernummer, 2: reden, 3: datum */
                                    esc_html__( 'Bestelling #%1$s · %2$s · %3$s', 'mk-cart-popup' ),
                                    esc_html( $order->get_order_number() ),
                                    esc_html( mkcp_account_return_reasons()[ $req->reason ] ?? $req->reason ),
                                    esc_html( date_i18n( get_option( 'date_format' ), strtotime( $req->requested_at ) ) )
                                );
                                ?>
                            </span>
                            <?php if ( ! empty( $req->admin_note ) && in_array( $req->status, [ 'rejected', 'completed', 'refunded' ], true ) ) : ?>
                                <span class="mkcp-return-row__note"><?php echo esc_html( $req->admin_note ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="mkcp-return-status mkcp-return-status--<?php echo esc_attr( $req->status ); ?>">
                            <?php echo esc_html( mkcp_account_return_status_label( $req->status ) ); ?>
                        </span>
                        <?php echo mkcp_account_render_return_progress( $req->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ── Winkelier informeren bij een nieuwe retour-aanvraag ─────────────────────
//
// Hergebruikt WooCommerce's "Nieuwe bestelling"-e-mailontvanger (Instellingen
// → E-mails → Nieuwe bestelling) — geen aparte instelling nodig.
/**
 * $items is óf één orderregel — met een expliciet $quantity, want een losse
 * aanvraag kan over een deel van het bestelde aantal gaan — óf een array
 * orderregels uit een bulk-aanvraag, die elk hun volledige bestelde aantal
 * retourneren ($quantity is dan niet van toepassing). Bewust één mail per
 * aanvraag: bij 5 producten in één bulk-retour hoort de winkelier één bericht
 * te krijgen, geen vijf.
 */
function mkcp_returns_notify_admin( WC_Order $order, $items, int $quantity, string $reason_label, string $reason_note ): void {
    $to = '';
    if ( function_exists( 'WC' ) && WC()->mailer() ) {
        $emails = WC()->mailer()->get_emails();
        if ( isset( $emails['WC_Email_New_Order'] ) ) {
            $to = $emails['WC_Email_New_Order']->get_recipient();
        }
    }
    if ( ! $to ) $to = get_option( 'admin_email' );
    if ( ! is_email( $to ) ) return;

    $site_name = get_bloginfo( 'name', 'raw' );
    $subject   = sprintf(
        /* translators: 1: sitenaam, 2: ordernummer */
        __( '[%1$s] Retour aangevraagd voor bestelling #%2$s', 'mk-cart-popup' ),
        $site_name,
        $order->get_order_number()
    );

    $lines = [];
    foreach ( is_array( $items ) ? $items : [ $items ] as $line_item ) {
        $line_quantity = is_array( $items ) ? (int) $line_item->get_quantity() : $quantity;
        $lines[]       = $line_quantity . '× ' . $line_item->get_name();
    }

    $body  = sprintf(
        /* translators: 1: ordernummer, 2: reden */
        __( 'Uit bestelling #%1$s is het volgende aangemeld voor retour. Reden: %2$s.', 'mk-cart-popup' ),
        $order->get_order_number(),
        $reason_label
    );
    $body .= "\n\n" . implode( "\n", $lines );
    if ( $reason_note !== '' ) {
        $body .= "\n\n" . sprintf( __( 'Toelichting van de klant: %s', 'mk-cart-popup' ), $reason_note );
    }
    $body .= "\n\n" . admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );

    $html  = '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#111;max-width:560px;margin:0 auto;padding:32px 16px">';
    $html .= '<h2 style="margin-bottom:8px">' . esc_html( $site_name ) . '</h2>';
    $html .= '<p style="line-height:1.7">' . nl2br( esc_html( $body ) ) . '</p>';
    $html .= '</body></html>';

    $set_html_type = static function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $set_html_type );
    wp_mail( $to, $subject, $html, [ 'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>' ] );
    remove_filter( 'wp_mail_content_type', $set_html_type );
}


// ── Admin orderlijst: "Retour?"-kolom ───────────────────────────────────────
//
// Zelfde stramien als includes/pickup-ready.php — dubbele hook-registratie
// voor klassieke én HPOS-orderlijsten. Leest live uit wp_mkcp_return_requests
// i.p.v. de losstaande, nooit-teruggezette _mkcp_has_return_request-vlag.

add_filter( 'manage_edit-shop_order_columns',           'mkcp_returns_add_order_column' );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'mkcp_returns_add_order_column' );

function mkcp_returns_add_order_column( $columns ) {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'order_status' ) {
            $new['mkcp_return'] = __( 'Retour?', 'mk-cart-popup' );
        }
    }
    if ( ! isset( $new['mkcp_return'] ) ) {
        $new['mkcp_return'] = __( 'Retour?', 'mk-cart-popup' );
    }
    return $new;
}

add_action( 'manage_shop_order_posts_custom_column',           'mkcp_returns_render_order_column', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'mkcp_returns_render_order_column', 10, 2 );

function mkcp_returns_render_order_column( $column, $order_or_id ) {
    if ( $column !== 'mkcp_return' ) return;

    $order_id = $order_or_id instanceof WC_Order ? $order_or_id->get_id() : (int) $order_or_id;
    global $wpdb;
    $statuses = $wpdb->get_col( $wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}mkcp_return_requests WHERE order_id = %d ORDER BY requested_at DESC",
        $order_id
    ) );
    if ( ! $statuses ) { echo '—'; return; }

    // Meest urgente/recente status leidend voor de weergave — een order met
    // meerdere retour-regels toont zo altijd waar aandacht nodig is,
    // i.p.v. willekeurig de eerste rij te pakken.
    $priority = [ 'pending' => 0, 'approved' => 1, 'rejected' => 2, 'completed' => 3, 'refunded' => 3 ];
    usort( $statuses, function( $a, $b ) use ( $priority ) {
        return ( $priority[ $a ] ?? 9 ) <=> ( $priority[ $b ] ?? 9 );
    } );
    echo esc_html( mkcp_account_return_status_label( $statuses[0] ) );
}


// ── Admin bestelpagina: retour-aanvragen direct verwerken ──────────────────
// Naast de read-only kolom hierboven en het volledige retourenscherm
// (account-admin.php) kan de winkelier hier ook direct goedkeuren/afwijzen/
// voltooien — zelfde AJAX-actie (mkcp_account_admin_return_update) en labels.

add_action( 'woocommerce_admin_order_data_after_order_details', function( $order ) {
    if ( ! function_exists( 'mkcp_account_module_enabled' ) || ! mkcp_account_module_enabled( 'returns' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $requests = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_return_requests WHERE order_id = %d ORDER BY requested_at DESC",
        $order->get_id()
    ) );
    if ( ! $requests ) return;

    $statuses = function_exists( 'mkcp_account_admin_return_statuses' ) ? mkcp_account_admin_return_statuses() : [];
    $reasons  = mkcp_account_return_reasons();
    ?>
    <div class="mkcp-order-returns-box" id="mkcp-order-returns-box" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" style="clear:both;margin-top:12px;padding-top:12px;border-top:1px solid #eee">
        <h4 style="margin:0 0 8px;display:flex;align-items:center;gap:6px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
            <?php esc_html_e( 'Retour-aanvragen voor deze bestelling', 'mk-cart-popup' ); ?>
        </h4>
        <?php foreach ( $requests as $req ) :
            $item = $order->get_item( $req->order_item_id );
        ?>
            <div class="mkcp-order-return-row" data-return-id="<?php echo esc_attr( $req->id ); ?>" style="padding:8px 0;border-top:1px solid #f0f0f0;font-size:12.5px">
                <strong><?php echo esc_html( $item ? $item->get_name() . ' × ' . $req->quantity : __( 'Artikel niet meer gevonden', 'mk-cart-popup' ) ); ?></strong>
                <div style="color:#666;margin-top:2px">
                    <?php echo esc_html( $reasons[ $req->reason ] ?? $req->reason ); ?>
                    <?php if ( $req->reason_note ) : ?> — "<?php echo esc_html( $req->reason_note ); ?>"<?php endif; ?>
                </div>
                <div style="margin:4px 0">
                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:<?php echo $req->status === 'pending' ? '#f0f0f1' : '#d7f5df'; ?>;color:<?php echo $req->status === 'pending' ? '#555' : '#1e7e34'; ?>"><?php echo esc_html( $statuses[ $req->status ] ?? $req->status ); ?></span>
                </div>
                <?php if ( in_array( $req->status, [ 'pending', 'approved' ], true ) ) : ?>
                    <div style="display:flex;gap:6px;margin-top:4px">
                        <?php if ( $req->status === 'pending' ) : ?>
                            <button type="button" class="button button-small js-mkcp-order-return-action" data-status="approved"><?php esc_html_e( 'Goedkeuren', 'mk-cart-popup' ); ?></button>
                            <button type="button" class="button button-small js-mkcp-order-return-action" data-status="rejected"><?php esc_html_e( 'Afwijzen', 'mk-cart-popup' ); ?></button>
                        <?php else : ?>
                            <button type="button" class="button button-small js-mkcp-order-return-action" data-status="completed"><?php esc_html_e( 'Voltooien', 'mk-cart-popup' ); ?></button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( ! function_exists( 'wc_get_page_screen_id' ) || $hook !== wc_get_page_screen_id( 'shop-order' ) ) return;
    if ( ! function_exists( 'mkcp_account_module_enabled' ) || ! mkcp_account_module_enabled( 'returns' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    wp_enqueue_script( 'mkcp-order-returns', MKCP_URL . 'admin/assets/order-returns.js', [ 'jquery' ], MKCP_VER, true );
    wp_localize_script( 'mkcp-order-returns', 'mkcpOrderReturns', [
        'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'mkcp_account_admin_returns' ),
        'errorText' => __( 'Actie mislukt, probeer het opnieuw.', 'mk-cart-popup' ),
    ] );
} );
