<?php
/**
 * MK Cart Popup — Account: Productreviews vanuit bestelling-detail
 *
 * Eigen bestand (zie de "god file"-notitie in account-profile.php) — koppelt
 * zich net als includes/account-returns.php via het mkcp_account_order_item_
 * extra-filter aan de order-item-rij in account-orders.php, zonder dat dat
 * bestand iets van reviews hoeft te weten.
 *
 * Geen eigen reviewsysteem: dit schrijft rechtstreeks naar WordPress/
 * WooCommerce's eigen wp_comments-tabel (comment_type='review'), precies
 * zoals WooCommerce's eigen "Beoordeling achterlaten"-formulier op de
 * productpagina dat ook doet — inclusief de standaard wc_customer_bought_
 * product()-check (alleen daadwerkelijk gekochte producten mogen
 * beoordeeld worden) en de normale WordPress-commentmoderatie (een review
 * verschijnt pas publiek zodra 'ie is goedgekeurd, tenzij de site auto-
 * goedkeuring heeft ingesteld — geen aparte moderatieregel hier nodig).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function mkcp_account_user_has_reviewed( int $user_id, int $product_id ): bool {
    $existing = get_comments( [
        'post_id' => $product_id,
        'user_id' => $user_id,
        'type'    => 'review',
        'count'   => true,
    ] );
    return (int) $existing > 0;
}

add_filter( 'mkcp_account_order_item_extra', function( string $html, WC_Order $order, $item ) {
    // Alleen bij een afgeronde bestelling — vóór levering een review vragen
    // slaat nergens op, en wc_customer_bought_product() alleen dekt dat niet
    // af (die kijkt naar "betaald", niet naar "afgerond/geleverd").
    if ( $order->get_status() !== 'completed' ) return $html;

    $product = $item->get_product();
    if ( ! $product ) return $html;

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return $html;

    if ( ! wc_customer_bought_product( $order->get_billing_email(), $user_id, $product->get_id() ) ) return $html;
    if ( ! comments_open( $product->get_id() ) ) return $html;

    if ( mkcp_account_user_has_reviewed( $user_id, $product->get_id() ) ) {
        return $html . '<span class="mkcp-review-status"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
            . esc_html__( 'Je hebt dit product al beoordeeld', 'mk-cart-popup' ) . '</span>';
    }

    ob_start();
    ?>
    <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-review-open" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
        <?php esc_html_e( 'Schrijf een review', 'mk-cart-popup' ); ?>
    </button>
    <form class="mkcp-review-form js-mkcp-review-form" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" hidden>
        <div class="mkcp-form-modal__header">
            <h3><?php esc_html_e( 'Schrijf een review', 'mk-cart-popup' ); ?></h3>
            <button type="button" class="mkcp-form-modal__close js-mkcp-review-cancel" aria-label="<?php esc_attr_e( 'Sluiten', 'mk-cart-popup' ); ?>">&times;</button>
        </div>
        <input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>">
        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
        <div class="mkcp-review-form__stars" role="radiogroup" aria-label="<?php esc_attr_e( 'Beoordeling', 'mk-cart-popup' ); ?>">
            <?php for ( $i = 5; $i >= 1; $i-- ) : ?>
                <input type="radio" name="rating" id="mkcp-review-star-<?php echo esc_attr( $product->get_id() . '-' . $i ); ?>" value="<?php echo esc_attr( $i ); ?>" <?php checked( $i, 5 ); ?>>
                <label for="mkcp-review-star-<?php echo esc_attr( $product->get_id() . '-' . $i ); ?>" title="<?php echo esc_attr( $i ); ?>">★</label>
            <?php endfor; ?>
        </div>
        <textarea name="content" rows="3" placeholder="<?php esc_attr_e( 'Wat vond je van dit product?', 'mk-cart-popup' ); ?>" required></textarea>
        <div class="mkcp-account-form-actions">
            <button type="submit" class="mkcp-btn mkcp-btn--primary"><?php esc_html_e( 'Review plaatsen', 'mk-cart-popup' ); ?></button>
            <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-review-cancel"><?php esc_html_e( 'Annuleren', 'mk-cart-popup' ); ?></button>
            <span class="mkcp-account-form-status" data-form-status="review" role="status" aria-live="polite"></span>
        </div>
    </form>
    <?php
    return $html . ob_get_clean();
}, 10, 3 );


// ── AJAX: review plaatsen ─────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_review_submit', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user       = wp_get_current_user();
    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $rating     = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
    $content    = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

    $product = $product_id ? wc_get_product( $product_id ) : null;
    if ( ! $product ) wp_send_json_error( [ 'code' => 'invalid_product' ], 400 );

    // Zelfde eigendomscheck als de review-knop zelf verbergt — de server
    // moet dit sowieso zelf afdwingen, de verborgen knop is UX, geen
    // beveiliging.
    if ( ! wc_customer_bought_product( $user->user_email, $user->ID, $product_id ) ) {
        wp_send_json_error( [ 'code' => 'not_purchased', 'message' => __( 'Je kunt alleen producten beoordelen die je hebt gekocht.', 'mk-cart-popup' ) ], 403 );
    }
    if ( mkcp_account_user_has_reviewed( $user->ID, $product_id ) ) {
        wp_send_json_error( [ 'code' => 'already_reviewed', 'message' => __( 'Je hebt dit product al beoordeeld.', 'mk-cart-popup' ) ], 409 );
    }
    if ( $rating < 1 || $rating > 5 ) {
        wp_send_json_error( [ 'code' => 'invalid_rating', 'message' => __( 'Kies een geldige beoordeling.', 'mk-cart-popup' ) ], 400 );
    }
    if ( $content === '' ) {
        wp_send_json_error( [ 'code' => 'missing_content', 'message' => __( 'Vul een toelichting in.', 'mk-cart-popup' ) ], 400 );
    }

    // 'comment_approved' bewust niet meegeven — wp_new_comment() bepaalt de
    // goedkeuringsstatus zelf altijd via wp_allow_comment() (WordPress' eigen
    // moderatie-instellingen), een meegegeven waarde wordt daar toch door
    // overschreven.
    $comment_id = wp_new_comment( [
        'comment_post_ID'      => $product_id,
        'comment_content'      => $content,
        'comment_type'         => 'review',
        'user_id'              => $user->ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
    ], true );

    if ( is_wp_error( $comment_id ) ) {
        wp_send_json_error( [ 'code' => 'comment_failed', 'message' => $comment_id->get_error_message() ], 400 );
    }

    update_comment_meta( $comment_id, 'rating', $rating );
    update_comment_meta( $comment_id, 'verified', 1 );

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $order    = $order_id ? wc_get_order( $order_id ) : null;

    // Order-detail opnieuw renderen (net als de retour-aanvraag-flow) zodat
    // de klant meteen de "al beoordeeld"-status ziet i.p.v. het formulier —
    // alleen mogelijk/zinvol als de review vanuit een eigen order kwam en
    // die order ook echt van deze klant is.
    if ( $order && (int) $order->get_customer_id() === $user->ID ) {
        wp_send_json_success( [
            'message' => __( 'Bedankt voor je review!', 'mk-cart-popup' ),
            'html'    => mkcp_account_render_order_detail( $order_id ),
        ] );
    }

    wp_send_json_success( [ 'message' => __( 'Bedankt voor je review!', 'mk-cart-popup' ) ] );
} );
