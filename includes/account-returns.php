<?php
/**
 * MK Cart Popup — Account: Retour-aanvraag (Fase 1, stap 6b)
 *
 * Eigen bestand (zie de "god file"-notitie in account-profile.php) — dit
 * bestand kent alleen retour-aanvragen. De UI zelf leeft NIET in een eigen
 * route/fragment (zie Account-plan, IA-sectie 3: retouren horen bij
 * "Bestellingen", geen los hoofdmenu-item), maar wordt in de order-detail-
 * weergave van account-orders.php geïnjecteerd via het
 * mkcp_account_order_detail_after_items-filter hieronder — zo hoeft
 * account-orders.php niets van dit bestand te weten (en andersom).
 *
 * Retourtermijn is instelbaar via admin/views/settings-page.php (data-panel=
 * "account-modules", account_return_window_days) — zie
 * mkcp_account_return_window_days() hieronder. Toegestane redenen zijn nog
 * wel een vaste lijst (niet in scope van de admin-instellingenpas).
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
    ];
    return $labels[ $status ] ?? $status;
}


// ── UI: retour-blok per bestelitem, in een eigen "Retourneren"-kaart ────────
//
// account-orders.php roept dit aan via het mkcp_account_order_return_item-
// filter en zet de resultaten in een eigen kaart ná Adresgegevens, náást de
// (eveneens eigen) reviews-kaart — zie de toelichting daar. Bewust een
// EIGEN filter i.p.v. het gedeelde mkcp_account_order_item_extra (dat is nu
// alleen nog voor de review-UI, account-reviews.php) — retouren en reviews
// staan in twee losse kaarten, elk met hun eigen lijst van producten.

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

    wp_send_json_success( [
        'html' => mkcp_account_render_order_detail( $order_id ),
        'meta' => [ 'fragment' => 'orders' ],
    ] );
} );
