<?php
/**
 * MK Cart Popup — Account: Dashboard + Bestellingen (Fase 1, stap 4)
 *
 * Los bestand van account-frontend.php/account-profile.php, zelfde reden
 * (zie feedback-god-files-memory): elke view zijn eigen bestand.
 *
 * Gebruikt uitsluitend wc_get_orders()/WC_Order_Query (nooit rechtstreeks
 * SQL tegen wp_posts of een hardgecodeerde HPOS-tabelnaam) — zie
 * Account-plan, sectie 14, zodat HPOS- en legacy-opslag beide gewoon blijven
 * werken.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'mkcp_account_fragment_handlers', function( $handlers ) {
    $handlers['dashboard'] = 'mkcp_account_render_fragment_dashboard';
    $handlers['orders']    = 'mkcp_account_render_fragment_orders';
    return $handlers;
} );

/** Instelbaar via admin/views/settings-page.php (account_orders_per_page), voorheen een hardcoded constante. */
function mkcp_account_orders_per_page(): int {
    $cfg = mkcp_account_config();
    $n   = isset( $cfg['account_orders_per_page'] ) ? (int) $cfg['account_orders_per_page'] : 10;
    return $n > 0 ? $n : 10;
}


// ── Helpers ────────────────────────────────────────────────────────────────

/** Orders die de klant nog "onderweg" ziet — bepaalt de dashboard-prioriteit. */
function mkcp_account_get_active_order( int $user_id ) {
    $orders = wc_get_orders( [
        'customer_id' => $user_id,
        'status'      => [ 'wc-processing', 'wc-on-hold' ],
        'limit'       => 1,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ] );
    return $orders ? $orders[0] : null;
}

function mkcp_account_get_last_order( int $user_id ) {
    $orders = wc_get_orders( [
        'customer_id' => $user_id,
        'limit'       => 1,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ] );
    return $orders ? $orders[0] : null;
}

function mkcp_account_order_status_badge( WC_Order $order ): string {
    return '<span class="mkcp-order-badge mkcp-order-badge--' . esc_attr( $order->get_status() ) . '">'
        . '<span class="mkcp-order-badge__dot" aria-hidden="true"></span>'
        . esc_html( wc_get_order_status_name( $order->get_status() ) )
        . '</span>';
}

/**
 * Vier kerncijfers voor de bento-statistiekenrij bovenaan het Dashboard (en
 * hergebruikt op Accountgegevens/Bestellingen) — bewust met een korte
 * transient-cache: dit telt/sommeert alle bestellingen van de klant, en
 * zonder cache zou dat op elke dashboard-load opnieuw gebeuren, ook al
 * verandert het aantal bestellingen meestal niet tussen twee paginaladingen
 * in dezelfde sessie.
 */
function mkcp_account_get_dashboard_stats( int $user_id ): array {
    $cache_key = 'mkcp_acc_stats_' . $user_id;
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) return $cached;

    $order_query = wc_get_orders( [
        'customer_id' => $user_id,
        'limit'       => -1,
        'return'      => 'ids',
        'status'      => array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-cancelled', 'wc-failed', 'wc-trash' ] ),
    ] );

    $total_spent = 0.0;
    foreach ( $order_query as $order_id ) {
        $o = wc_get_order( $order_id );
        if ( $o ) $total_spent += (float) $o->get_total();
    }

    $wishlist_count = 0;
    foreach ( mkcp_account_get_wishlists( $user_id ) as $wl ) {
        $wishlist_count += count( mkcp_account_get_wishlist_items( $wl->id ) );
    }

    $stats = [
        'order_count'    => count( $order_query ),
        'total_spent'    => $total_spent,
        'wishlist_count' => $wishlist_count,
        'address_count'  => function_exists( 'mkcp_account_get_addresses' ) ? count( mkcp_account_get_addresses( $user_id ) ) : 0,
    ];

    set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );
    return $stats;
}

/** Cache-invalidatie: elke actie die één van de vier cijfers hierboven raakt, moet 'm ongeldig maken. */
function mkcp_account_clear_dashboard_stats_cache( int $user_id ): void {
    delete_transient( 'mkcp_acc_stats_' . $user_id );
}

/**
 * Drie-staps trackerbalk (Besteld → In verwerking → Afgerond) — bewust GEEN
 * vierde "Verzonden"/"Onderweg"-tussenstap zoals het oorspronkelijke ontwerp
 * had: WooCommerce kent standaard geen aparte "verzonden"-orderstatus naast
 * "in verwerking" (en deze plugin registreert er ook geen), dus een 4e stap
 * zou niet op echte data gebaseerd kunnen zijn. Geeft '' terug voor
 * geannuleerd/mislukt/terugbetaald — daar past geen voortgangsbalk bij, de
 * status-badge alleen volstaat.
 */
function mkcp_account_render_order_progress( WC_Order $order ): string {
    $status = $order->get_status();
    if ( $status === 'completed' ) {
        $current = 3;
    } elseif ( $status === 'processing' ) {
        $current = 2;
    } elseif ( in_array( $status, [ 'pending', 'on-hold' ], true ) ) {
        $current = 1;
    } else {
        return '';
    }

    $steps = [
        __( 'Besteld', 'mk-cart-popup' ),
        __( 'In verwerking', 'mk-cart-popup' ),
        __( 'Afgerond', 'mk-cart-popup' ),
    ];

    ob_start();
    ?>
    <div class="mkcp-order-progress">
        <?php foreach ( $steps as $i => $label ) : $step = $i + 1; ?>
            <?php if ( $step > 1 ) : ?><span class="mkcp-order-progress__line<?php echo ( $step - 1 ) < $current ? ' is-filled' : ''; ?>"></span><?php endif; ?>
            <span class="mkcp-order-progress__step<?php echo $step <= $current ? ' is-done' : ''; ?><?php echo $step === $current ? ' is-current' : ''; ?>">
                <span class="mkcp-order-progress__dot"></span>
                <span class="mkcp-order-progress__label"><?php echo esc_html( $label ); ?></span>
            </span>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/** Compacte productkaart — gedeeld door de wishlist-preview en de aanbevelingen-widget op het dashboard. */
function mkcp_account_render_product_card_compact( WC_Product $product ): string {
    ob_start();
    ?>
    <a class="mkcp-dash-product" href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>">
        <span class="mkcp-dash-product__thumb"><?php echo wp_kses_post( $product->get_image( [ 160, 160 ] ) ); ?></span>
        <span class="mkcp-dash-product__name"><?php echo esc_html( $product->get_name() ); ?></span>
        <span class="mkcp-dash-product__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
    </a>
    <?php
    return ob_get_clean();
}

/**
 * "Aanbevolen voor jou" — gebaseerd op WooCommerce's eigen related-products-
 * logica (gedeelde categorieën/tags met het laatst bestelde product), zoals
 * het Account-plan voorschrijft ("hergebruik van bestaande infrastructuur
 * i.p.v. een nieuwe aanbevelingsengine bouwen"). Vult aan met de best
 * verkopende producten wanneer dat te weinig oplevert (nieuwe klant zonder
 * bestelhistorie) — nooit een half-leeg of leeg rijtje tonen.
 */
function mkcp_account_get_dashboard_recommendations( int $user_id, int $limit = 4 ): array {
    $last = mkcp_account_get_last_order( $user_id );
    $ids  = [];

    if ( $last ) {
        foreach ( $last->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( ! $product_id ) continue;
            $ids = array_merge( $ids, wc_get_related_products( $product_id, $limit ) );
            if ( count( $ids ) >= $limit ) break;
        }
    }

    $ids = array_values( array_unique( array_filter( $ids ) ) );

    if ( count( $ids ) < $limit ) {
        $fallback_products = wc_get_products( [
            'status'  => 'publish',
            'limit'   => $limit * 2,
            'orderby' => 'popularity',
            'exclude' => $ids,
        ] );
        foreach ( $fallback_products as $product ) {
            if ( count( $ids ) >= $limit ) break;
            $ids[] = $product->get_id();
        }
    }

    $ids = array_slice( array_unique( $ids ), 0, $limit );

    $products = [];
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( $product && $product->is_visible() ) $products[] = $product;
    }
    return $products;
}


// ── Fragment: Dashboard ───────────────────────────────────────────────────────

function mkcp_account_render_fragment_dashboard(): string {
    $user      = wp_get_current_user();
    $user_id   = $user->ID;
    $active    = mkcp_account_get_active_order( $user_id );
    $last      = $active ? null : mkcp_account_get_last_order( $user_id );
    $highlight = $active ?: $last;

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <div class="mkcp-dash-header">
            <div>
                <h1><?php
                    printf(
                        /* translators: %s: voornaam van de klant */
                        esc_html__( 'Welkom terug, %s', 'mk-cart-popup' ),
                        esc_html( $user->first_name ?: $user->display_name )
                    );
                ?></h1>
                <p class="mkcp-dash-header__sub"><?php esc_html_e( 'Hier is een overzicht van je account.', 'mk-cart-popup' ); ?></p>
            </div>
        </div>

        <?php $stats = mkcp_account_get_dashboard_stats( $user_id ); ?>
        <div class="mkcp-dash-stats">
            <div class="mkcp-dash-stat">
                <span class="mkcp-dash-stat__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/></svg></span>
                <span class="mkcp-dash-stat__value"><?php echo esc_html( number_format_i18n( $stats['order_count'] ) ); ?></span>
                <span class="mkcp-dash-stat__label"><?php esc_html_e( 'bestellingen', 'mk-cart-popup' ); ?></span>
            </div>
            <div class="mkcp-dash-stat">
                <span class="mkcp-dash-stat__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                <span class="mkcp-dash-stat__value"><?php echo wp_kses_post( wc_price( $stats['total_spent'] ) ); ?></span>
                <span class="mkcp-dash-stat__label"><?php esc_html_e( 'uitgegeven', 'mk-cart-popup' ); ?></span>
            </div>
            <div class="mkcp-dash-stat">
                <span class="mkcp-dash-stat__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2 4.5 5.5 4.5c2 0 3.5 1.5 4.5 3 1-1.5 2.5-3 4.5-3 3.5 0 4.5 3.5 3 7C19 15.65 12 20 12 20z"/></svg></span>
                <span class="mkcp-dash-stat__value"><?php echo esc_html( number_format_i18n( $stats['wishlist_count'] ) ); ?></span>
                <span class="mkcp-dash-stat__label"><?php esc_html_e( 'op je wishlist', 'mk-cart-popup' ); ?></span>
            </div>
            <div class="mkcp-dash-stat">
                <span class="mkcp-dash-stat__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.2 7-12a7 7 0 10-14 0c0 4.8 7 12 7 12z"/><circle cx="12" cy="11" r="2"/></svg></span>
                <span class="mkcp-dash-stat__value"><?php echo esc_html( number_format_i18n( $stats['address_count'] ) ); ?></span>
                <span class="mkcp-dash-stat__label"><?php esc_html_e( 'adressen', 'mk-cart-popup' ); ?></span>
            </div>
        </div>

        <?php if ( $highlight ) :
            $progress = mkcp_account_render_order_progress( $highlight );
            ?>
            <div class="mkcp-dash-card mkcp-dash-card--order<?php echo $active ? ' is-active' : ''; ?>">
                <?php if ( $active ) : ?>
                    <div class="mkcp-dash-order__eyebrow"><?php esc_html_e( 'Onderweg', 'mk-cart-popup' ); ?></div>
                <?php endif; ?>
                <h2><?php echo $active ? esc_html__( 'Je bestelling is onderweg', 'mk-cart-popup' ) : esc_html__( 'Je laatste bestelling', 'mk-cart-popup' ); ?></h2>
                <p class="mkcp-dash-order__meta">
                    <?php
                    printf(
                        /* translators: 1: ordernummer, 2: status-badge (HTML) */
                        wp_kses_post( __( 'Bestelling #%1$s — %2$s', 'mk-cart-popup' ) ),
                        esc_html( $highlight->get_order_number() ),
                        mkcp_account_order_status_badge( $highlight )
                    );
                    ?>
                </p>

                <?php
                // Bezorg-/afhaaldatum die de klant zelf tijdens checkout koos
                // (includes/delivery-date.php / includes/pickup.php) — al
                // aanwezig als order-meta, hier voor het eerst ook op het
                // Dashboard zichtbaar i.p.v. alleen ergens diep in het
                // besteloverzicht.
                $delivery_date = $highlight->get_meta( '_mkcp_delivery_date' );
                $pickup_date   = $highlight->get_meta( '_mkcp_pickup_date' );
                if ( $delivery_date || $pickup_date ) :
                    $eta_ts    = mysql2date( 'U', ( $delivery_date ?: $pickup_date ) . ' 00:00:00' );
                    $eta_label = $delivery_date ? __( 'Verwachte levering', 'mk-cart-popup' ) : __( 'Ophalen op', 'mk-cart-popup' );
                    ?>
                    <p class="mkcp-dash-order__eta">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?php echo esc_html( $eta_label ); ?>: <strong><?php echo esc_html( date_i18n( 'l j F', $eta_ts ) ); ?></strong>
                    </p>
                <?php endif; ?>

                <?php
                $thumbs = [];
                foreach ( $highlight->get_items() as $order_item ) {
                    $item_product = $order_item->get_product();
                    if ( $item_product ) $thumbs[] = $item_product;
                    if ( count( $thumbs ) >= 2 ) break;
                }
                ?>
                <?php if ( $thumbs ) : ?>
                    <div class="mkcp-dash-order__thumbs">
                        <?php foreach ( $thumbs as $thumb_product ) : ?>
                            <span class="mkcp-dash-order__thumb"><?php echo wp_kses_post( $thumb_product->get_image( [ 56, 56 ] ) ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php echo $progress; // phpcs:ignore WordPress.Security.EscapeOutput ?>

                <a class="mkcp-btn mkcp-btn--primary js-mkcp-route" href="#/orders/<?php echo esc_attr( $highlight->get_id() ); ?>">
                    <?php echo $active ? esc_html__( 'Bestelling volgen', 'mk-cart-popup' ) : esc_html__( 'Bekijk bestelling', 'mk-cart-popup' ); ?>
                </a>
            </div>
        <?php else : ?>
            <div class="mkcp-dash-card mkcp-dash-card--empty">
                <span class="mkcp-dash-empty-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/></svg>
                </span>
                <p class="mkcp-dash-empty__title"><?php esc_html_e( 'Nog geen bestelling geplaatst', 'mk-cart-popup' ); ?></p>
                <p><?php esc_html_e( 'Zodra je een bestelling plaatst, volg je hier eenvoudig de status.', 'mk-cart-popup' ); ?></p>
                <a class="mkcp-btn mkcp-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Begin met winkelen', 'mk-cart-popup' ); ?></a>
            </div>
        <?php endif; ?>

        <?php
        $contact_page = get_page_by_path( 'contact' );
        $contact_url  = $contact_page ? get_permalink( $contact_page ) : '';
        ?>
        <div class="mkcp-dash-quickactions">
            <a class="mkcp-dash-quickaction" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
                <span class="mkcp-dash-quickaction__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
                <span><?php esc_html_e( 'Nieuwe bestelling', 'mk-cart-popup' ); ?></span>
            </a>
            <a class="mkcp-dash-quickaction js-mkcp-route" href="#/wishlist">
                <span class="mkcp-dash-quickaction__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2 4.5 5.5 4.5c2 0 3.5 1.5 4.5 3 1-1.5 2.5-3 4.5-3 3.5 0 4.5 3.5 3 7C19 15.65 12 20 12 20z"/></svg></span>
                <span><?php esc_html_e( 'Wishlist', 'mk-cart-popup' ); ?></span>
            </a>
            <a class="mkcp-dash-quickaction js-mkcp-route" href="#/addresses/new">
                <span class="mkcp-dash-quickaction__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.2 7-12a7 7 0 10-14 0c0 4.8 7 12 7 12z"/><line x1="12" y1="7" x2="12" y2="12"/><line x1="9.5" y1="9.5" x2="14.5" y2="9.5"/></svg></span>
                <span><?php esc_html_e( 'Adres toevoegen', 'mk-cart-popup' ); ?></span>
            </a>
            <?php if ( $contact_url ) : ?>
                <a class="mkcp-dash-quickaction" href="<?php echo esc_url( $contact_url ); ?>">
                    <span class="mkcp-dash-quickaction__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v12H8l-4 4V4z"/></svg></span>
                    <span><?php esc_html_e( 'Contact', 'mk-cart-popup' ); ?></span>
                </a>
            <?php endif; ?>
        </div>

        <div class="mkcp-dash-grid">
            <div class="mkcp-dash-grid__col">

                <div class="mkcp-dash-card">
                    <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2 4.5 5.5 4.5c2 0 3.5 1.5 4.5 3 1-1.5 2.5-3 4.5-3 3.5 0 4.5 3.5 3 7C19 15.65 12 20 12 20z"/></svg></span><?php esc_html_e( 'Wishlist', 'mk-cart-popup' ); ?></h2>
                    <?php
                    $wishlists      = mkcp_account_get_wishlists( $user_id );
                    $default_wl     = $wishlists[0] ?? null;
                    $wishlist_items = $default_wl ? array_slice( mkcp_account_get_wishlist_items( $default_wl->id ), 0, 4 ) : [];
                    ?>
                    <?php if ( empty( $wishlist_items ) ) : ?>
                        <div class="mkcp-dash-empty-inline">
                            <span class="mkcp-dash-empty-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2 4.5 5.5 4.5c2 0 3.5 1.5 4.5 3 1-1.5 2.5-3 4.5-3 3.5 0 4.5 3.5 3 7C19 15.65 12 20 12 20z"/></svg></span>
                            <p class="mkcp-dash-empty__title"><?php esc_html_e( 'Je wishlist is nog leeg', 'mk-cart-popup' ); ?></p>
                            <p><?php esc_html_e( 'Bewaar producten die je leuk vindt om ze later snel terug te vinden.', 'mk-cart-popup' ); ?></p>
                            <a class="mkcp-btn mkcp-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Ontdek producten', 'mk-cart-popup' ); ?></a>
                        </div>
                    <?php else : ?>
                        <div class="mkcp-dash-scroller">
                            <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--prev" aria-label="<?php esc_attr_e( 'Vorige', 'mk-cart-popup' ); ?>">&#8249;</button>
                            <div class="mkcp-dash-product-scroller">
                                <?php foreach ( $wishlist_items as $wl_item ) :
                                    $wl_product = wc_get_product( $wl_item->variation_id ?: $wl_item->product_id );
                                    if ( ! $wl_product ) continue;
                                    echo mkcp_account_render_product_card_compact( $wl_product ); // phpcs:ignore WordPress.Security.EscapeOutput
                                endforeach; ?>
                            </div>
                            <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--next" aria-label="<?php esc_attr_e( 'Volgende', 'mk-cart-popup' ); ?>">&#8250;</button>
                        </div>
                        <a class="mkcp-btn mkcp-btn--text js-mkcp-route" href="#/wishlist"><?php esc_html_e( 'Bekijk alles →', 'mk-cart-popup' ); ?></a>
                    <?php endif; ?>
                </div>

                <?php $recommendations = mkcp_account_get_dashboard_recommendations( $user_id, 8 ); ?>
                <?php if ( $recommendations ) : ?>
                    <div class="mkcp-dash-card">
                        <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/></svg></span><?php esc_html_e( 'Aanbevolen voor jou', 'mk-cart-popup' ); ?></h2>
                        <div class="mkcp-dash-scroller">
                            <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--prev" aria-label="<?php esc_attr_e( 'Vorige', 'mk-cart-popup' ); ?>">&#8249;</button>
                            <div class="mkcp-dash-product-scroller">
                                <?php foreach ( $recommendations as $reco_product ) echo mkcp_account_render_product_card_compact( $reco_product ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </div>
                            <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--next" aria-label="<?php esc_attr_e( 'Volgende', 'mk-cart-popup' ); ?>">&#8250;</button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                // "Recent bekeken producten" — server-side is hier niets van
                // bekend (localStorage, zie assets/wishlist-icon.js); dit is
                // puur een lege plek die account.js na het laden vult via een
                // eigen AJAX-rondje met de ID's uit localStorage. Standaard
                // verborgen (JS haalt 'm leeg als er niets te tonen is).
                ?>
                <div class="mkcp-dash-card" id="mkcp-recently-viewed-card" hidden>
                    <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/></svg></span><?php esc_html_e( 'Onlangs bekeken', 'mk-cart-popup' ); ?></h2>
                    <div class="mkcp-dash-scroller">
                        <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--prev" aria-label="<?php esc_attr_e( 'Vorige', 'mk-cart-popup' ); ?>">&#8249;</button>
                        <div class="mkcp-dash-product-scroller" id="mkcp-recently-viewed-list"></div>
                        <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--next" aria-label="<?php esc_attr_e( 'Volgende', 'mk-cart-popup' ); ?>">&#8250;</button>
                    </div>
                </div>

            </div>

            <div class="mkcp-dash-grid__col">

                <div class="mkcp-dash-card">
                    <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.2 7-12a7 7 0 10-14 0c0 4.8 7 12 7 12z"/><circle cx="12" cy="11" r="2"/></svg></span><?php esc_html_e( 'Adressen', 'mk-cart-popup' ); ?></h2>
                    <?php
                    $addresses       = function_exists( 'mkcp_account_get_addresses' ) ? mkcp_account_get_addresses( $user_id ) : [];
                    $default_address = null;
                    foreach ( $addresses as $addr ) {
                        if ( ! empty( $addr->is_default_billing ) ) { $default_address = $addr; break; }
                    }
                    $default_address = $default_address ?: ( $addresses[0] ?? null );
                    ?>
                    <?php if ( ! $default_address ) : ?>
                        <div class="mkcp-dash-empty-inline">
                            <span class="mkcp-dash-empty-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.2 7-12a7 7 0 10-14 0c0 4.8 7 12 7 12z"/><circle cx="12" cy="11" r="2"/></svg></span>
                            <p class="mkcp-dash-empty__title"><?php esc_html_e( 'Nog geen adres opgeslagen', 'mk-cart-popup' ); ?></p>
                            <p><?php esc_html_e( 'Voeg een adres toe voor een snellere checkout.', 'mk-cart-popup' ); ?></p>
                            <a class="mkcp-btn mkcp-btn--primary js-mkcp-route" href="#/addresses/new"><?php esc_html_e( 'Adres toevoegen', 'mk-cart-popup' ); ?></a>
                        </div>
                    <?php else : ?>
                        <?php echo mkcp_account_render_address_card( $default_address, false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <a class="mkcp-btn mkcp-btn--text js-mkcp-route" href="#/addresses"><?php esc_html_e( 'Beheer adressen →', 'mk-cart-popup' ); ?></a>
                    <?php endif; ?>
                </div>

                <?php echo mkcp_account_render_rewards_widget( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Read-only integratie met WooCommerce Points and Rewards (Account-plan,
 * besluit 3 — GEEN eigen puntenledger, alleen deze plugin's eigen saldo
 * uitlezen). class_exists()-guard: op geen van de drie referentiesites staat
 * deze plugin actief, dus dit widgetje blijft daar simpelweg verborgen —
 * exact het gedrag dat het plan hiervoor voorschrijft, geen foutmelding.
 *
 * Bewust GEEN link naar de plugin's eigen inwissel-UI (zoals het plan
 * oorspronkelijk voorstelde): die UI leeft op een endpoint van WooCommerce's
 * eigen "Mijn account"-pagina, maar onze eigen template_include-override
 * (account-frontend.php) neemt is_account_page() voor ingelogde premium-
 * klanten volledig over — een link daarheen zou gewoon weer in DIT dashboard
 * uitkomen, niet in de punten-inwisselpagina. Een eigen "escape hatch"-route
 * daarvoor is losstaand vervolgwerk, geen aanname die je hier stil kunt
 * wegmoffelen achter een kapotte link.
 */
function mkcp_account_render_rewards_widget( int $user_id ): string {
    if ( function_exists( 'mkcp_account_module_enabled' ) && ! mkcp_account_module_enabled( 'rewards' ) ) {
        return '';
    }
    if ( ! class_exists( 'WC_Points_Rewards_Manager' ) || ! method_exists( 'WC_Points_Rewards_Manager', 'get_users_points' ) ) {
        return '';
    }

    $points = (int) WC_Points_Rewards_Manager::get_users_points( $user_id );
    $value  = method_exists( 'WC_Points_Rewards_Manager', 'get_points_value' )
        ? WC_Points_Rewards_Manager::get_points_value( $points )
        : '';

    // Voortgangsbalk naar het eerstvolgende ronde honderdtal — bewust GEEN
    // "X punten tot gratis verzending"-belofte zoals het oorspronkelijke
    // ontwerp toonde: dat is een specifieke bedrijfsregel die nergens in
    // deze plugin of in WC Points and Rewards zelf bestaat. Een generieke
    // voortgangsindicator geeft wel hetzelfde soort "bijna bij de volgende
    // mijlpaal"-gevoel, zonder een niet-bestaande beloning te beloven.
    $next_milestone = ( intdiv( $points, 100 ) + 1 ) * 100;
    $progress_pct   = $next_milestone > 0 ? min( 100, round( ( $points / $next_milestone ) * 100 ) ) : 0;

    // Tier-label — puur cosmetisch/gamification, geen bestaande WC Points and
    // Rewards-functionaliteit (die plugin kent zelf geen tiers). Drempels nu
    // instelbaar (admin/views/settings-page.php) i.p.v. hardcoded — het was
    // sowieso al een verzonnen bedrijfsregel, die hoort bij de winkelier
    // thuis, niet in de code.
    $ac_cfg = mkcp_account_config();
    $silver_threshold = isset( $ac_cfg['account_rewards_tier_silver_threshold'] ) ? (int) $ac_cfg['account_rewards_tier_silver_threshold'] : 100;
    $gold_threshold    = isset( $ac_cfg['account_rewards_tier_gold_threshold'] ) ? (int) $ac_cfg['account_rewards_tier_gold_threshold'] : 500;
    if ( $points >= $gold_threshold ) {
        $tier = __( 'Goud', 'mk-cart-popup' );
    } elseif ( $points >= $silver_threshold ) {
        $tier = __( 'Zilver', 'mk-cart-popup' );
    } else {
        $tier = __( 'Brons', 'mk-cart-popup' );
    }

    ob_start();
    ?>
    <div class="mkcp-dash-card mkcp-dash-card--rewards">
        <div class="mkcp-dash-rewards__head">
            <span class="mkcp-dash-rewards__icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/></svg>
            </span>
            <span>
                <span class="mkcp-dash-rewards__balance"><?php
                    printf(
                        /* translators: %s: aantal punten */
                        esc_html__( '%s punten', 'mk-cart-popup' ),
                        esc_html( number_format_i18n( $points ) )
                    );
                ?></span>
                <span class="mkcp-dash-rewards__tier mkcp-dash-rewards__tier--<?php echo esc_attr( strtolower( $tier ) ); ?>"><?php echo esc_html( $tier ); ?></span>
                <?php if ( $value !== '' ) : ?>
                    <span class="mkcp-dash-rewards__value"><?php
                        printf(
                            /* translators: %s: geldwaarde van de punten */
                            esc_html__( '(t.w.v. %s)', 'mk-cart-popup' ),
                            wp_kses_post( $value )
                        );
                    ?></span>
                <?php endif; ?>
                <span class="mkcp-dash-rewards__next"><?php
                    printf(
                        /* translators: %s: aantal punten tot de volgende mijlpaal */
                        esc_html__( 'Nog %s punten tot je volgende mijlpaal', 'mk-cart-popup' ),
                        esc_html( number_format_i18n( $next_milestone - $points ) )
                    );
                ?></span>
            </span>
        </div>
        <div class="mkcp-dash-rewards__bar">
            <span class="mkcp-dash-rewards__bar-fill" style="width:<?php echo esc_attr( $progress_pct ); ?>%"></span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// ── Fragment: Bestellingen (lijst + detail) ───────────────────────────────────

function mkcp_account_render_fragment_orders(): string {
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    if ( $order_id ) {
        return mkcp_account_render_order_detail( $order_id );
    }
    return mkcp_account_render_order_list();
}

/** Statusfilter-chip-waarde → echte WooCommerce order-statussen. */
function mkcp_account_order_filter_statuses( string $filter ): array {
    $map = [
        'processing' => [ 'wc-pending', 'wc-on-hold', 'wc-processing' ],
        'completed'  => [ 'wc-completed' ],
        'cancelled'  => [ 'wc-cancelled', 'wc-failed', 'wc-refunded' ],
    ];
    return $map[ $filter ] ?? [];
}

function mkcp_account_render_order_list(): string {
    $user_id = get_current_user_id();
    $page    = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
    $filter  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
    $search  = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

    $query_args = [
        'customer_id' => $user_id,
        'limit'       => mkcp_account_orders_per_page(),
        'paged'       => $page,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'paginate'    => true,
    ];
    $filter_statuses = mkcp_account_order_filter_statuses( $filter );
    if ( $filter_statuses ) $query_args['status'] = $filter_statuses;
    // WC_Order_Query's 's'-parameter doorzoekt ordernummer + factuurnaam/
    // e-mailadres (zowel bij HPOS als de legacy post-opslag) — geen losse
    // SQL-zoekquery nodig. Zoeken op productnaam zou wc_order_product_lookup
    // vergen (Account-plan, sectie 6) en is bewust nog niet meegenomen.
    if ( $search !== '' ) $query_args['s'] = $search;

    $query       = wc_get_orders( $query_args );
    $orders      = $query->orders;
    $total_pages = max( 1, (int) $query->max_num_pages );
    $stats       = mkcp_account_get_dashboard_stats( $user_id );

    $filters = [
        ''            => __( 'Alles', 'mk-cart-popup' ),
        'processing'  => __( 'In behandeling', 'mk-cart-popup' ),
        'completed'   => __( 'Voltooid', 'mk-cart-popup' ),
        'cancelled'   => __( 'Geannuleerd', 'mk-cart-popup' ),
    ];

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <div class="mkcp-account-view__header">
            <h1><?php esc_html_e( 'Bestellingen', 'mk-cart-popup' ); ?></h1>
        </div>

        <?php if ( $stats['order_count'] > 0 ) : ?>
            <p class="mkcp-dash-header__sub mkcp-order-list-summary">
                <?php
                printf(
                    /* translators: 1: aantal bestellingen, 2: totaalbedrag (HTML) */
                    wp_kses_post( __( '%1$s bestellingen in totaal · %2$s uitgegeven', 'mk-cart-popup' ) ),
                    esc_html( number_format_i18n( $stats['order_count'] ) ),
                    wp_kses_post( wc_price( $stats['total_spent'] ) )
                );
                ?>
            </p>
        <?php endif; ?>

        <?php if ( $stats['order_count'] > 0 ) : ?>
            <div class="mkcp-order-toolbar">
                <div class="mkcp-order-filters">
                    <?php foreach ( $filters as $value => $label ) : ?>
                        <button type="button" class="mkcp-order-filter-chip js-mkcp-orders-filter<?php echo $filter === $value ? ' is-active' : ''; ?>" data-status="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="mkcp-order-search">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="search" id="mkcp-orders-search" placeholder="<?php esc_attr_e( 'Zoek op ordernummer of naam…', 'mk-cart-popup' ); ?>" value="<?php echo esc_attr( $search ); ?>">
                </div>
            </div>
        <?php endif; ?>

        <?php if ( empty( $orders ) ) : ?>
            <div class="mkcp-dash-card mkcp-dash-card--empty">
                <span class="mkcp-dash-empty-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/></svg></span>
                <?php if ( $filter !== '' || $search !== '' ) : ?>
                    <p class="mkcp-dash-empty__title"><?php esc_html_e( 'Geen bestellingen gevonden', 'mk-cart-popup' ); ?></p>
                    <p><?php esc_html_e( 'Probeer een ander filter of een andere zoekterm.', 'mk-cart-popup' ); ?></p>
                <?php else : ?>
                    <p class="mkcp-dash-empty__title"><?php esc_html_e( 'Nog geen bestelling geplaatst', 'mk-cart-popup' ); ?></p>
                    <p><?php esc_html_e( 'Zodra je een bestelling plaatst, vind je die hier terug.', 'mk-cart-popup' ); ?></p>
                    <a class="mkcp-btn mkcp-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Begin met winkelen', 'mk-cart-popup' ); ?></a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="mkcp-order-list">
                <?php foreach ( $orders as $order ) :
                    $row_thumbs = [];
                    foreach ( $order->get_items() as $row_item ) {
                        $row_product = $row_item->get_product();
                        if ( $row_product ) $row_thumbs[] = $row_product;
                        if ( count( $row_thumbs ) >= 3 ) break;
                    }
                    ?>
                    <a class="mkcp-order-row js-mkcp-route" href="#/orders/<?php echo esc_attr( $order->get_id() ); ?>">
                        <span class="mkcp-order-row__thumbs">
                            <?php if ( $row_thumbs ) : ?>
                                <?php foreach ( $row_thumbs as $row_thumb ) : ?>
                                    <span class="mkcp-order-row__thumb"><?php echo wp_kses_post( $row_thumb->get_image( [ 44, 44 ] ) ); ?></span>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <span class="mkcp-order-row__thumb mkcp-order-row__thumb--placeholder"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/></svg></span>
                            <?php endif; ?>
                        </span>
                        <span class="mkcp-order-row__main">
                            <span class="mkcp-order-row__number">#<?php echo esc_html( $order->get_order_number() ); ?></span>
                            <span class="mkcp-order-row__date">
                                <?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
                                · <?php
                                printf(
                                    /* translators: %d: aantal producten */
                                    esc_html( _n( '%d product', '%d producten', $order->get_item_count(), 'mk-cart-popup' ) ),
                                    (int) $order->get_item_count()
                                );
                                ?>
                            </span>
                        </span>
                        <span class="mkcp-order-row__total"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
                        <?php echo mkcp_account_order_status_badge( $order ); ?>
                        <span class="mkcp-order-row__chevron"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ( $total_pages > 1 ) : ?>
                <nav class="mkcp-pager" aria-label="<?php esc_attr_e( 'Paginering bestellingen', 'mk-cart-popup' ); ?>">
                    <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                        <button type="button" class="mkcp-pager__link js-mkcp-orders-page <?php echo $p === $page ? 'is-active' : ''; ?>" data-page="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></button>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function mkcp_account_render_order_detail( int $order_id ): string {
    $user_id = get_current_user_id();
    $order   = wc_get_order( $order_id );

    // Eigendomscheck: een bestelling die niet van deze klant is, wordt
    // nooit getoond — ook niet als het order_id gewoon geraden wordt.
    if ( ! $order || (int) $order->get_customer_id() !== $user_id ) {
        ob_start();
        ?>
        <div class="mkcp-account-view">
            <p class="mkcp-account-notice mkcp-account-notice--error"><?php esc_html_e( 'Deze bestelling kon niet gevonden worden.', 'mk-cart-popup' ); ?></p>
            <a class="mkcp-btn mkcp-btn--text js-mkcp-route" href="#/orders"><?php esc_html_e( '← Terug naar bestellingen', 'mk-cart-popup' ); ?></a>
        </div>
        <?php
        return ob_get_clean();
    }

    $can_reorder = in_array( $order->get_status(), [ 'completed', 'processing', 'on-hold' ], true );

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <a class="mkcp-btn mkcp-btn--text js-mkcp-route" href="#/orders"><?php esc_html_e( '← Terug naar bestellingen', 'mk-cart-popup' ); ?></a>

        <div class="mkcp-account-view__header">
            <h1>
                <?php
                printf(
                    /* translators: %s: ordernummer */
                    esc_html__( 'Bestelling #%s', 'mk-cart-popup' ),
                    esc_html( $order->get_order_number() )
                );
                ?>
            </h1>
            <?php if ( $can_reorder ) : ?>
                <button type="button" class="mkcp-btn mkcp-btn--primary js-mkcp-reorder" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
                    <?php esc_html_e( 'Opnieuw bestellen', 'mk-cart-popup' ); ?>
                </button>
            <?php endif; ?>
        </div>
        <p class="mkcp-dash-order__meta"><?php echo mkcp_account_order_status_badge( $order ); ?> · <?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></p>
        <?php if ( $can_reorder ) : ?>
            <span class="mkcp-account-form-status" data-form-status="reorder" role="status" aria-live="polite"></span>
        <?php endif; ?>

        <?php $detail_progress = mkcp_account_render_order_progress( $order ); ?>
        <?php if ( $detail_progress ) : ?>
            <div class="mkcp-dash-card">
                <?php echo $detail_progress; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        <?php endif; ?>

        <div class="mkcp-dash-card">
            <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/></svg></span><?php esc_html_e( 'Producten', 'mk-cart-popup' ); ?></h2>
            <div class="mkcp-order-items">
                <?php foreach ( $order->get_items() as $item ) :
                    $product = $item->get_product();
                    ?>
                    <div class="mkcp-order-item">
                        <span class="mkcp-order-item__thumb"><?php echo $product ? wp_kses_post( $product->get_image( [ 60, 60 ] ) ) : ''; ?></span>
                        <span class="mkcp-order-item__name"><?php echo esc_html( $item->get_name() ); ?> × <?php echo esc_html( $item->get_quantity() ); ?></span>
                        <span class="mkcp-order-item__total"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="mkcp-order-total"><?php esc_html_e( 'Totaal:', 'mk-cart-popup' ); ?> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p>

            <?php if ( $order->get_payment_method_title() || $order->get_shipping_method() ) : ?>
                <div class="mkcp-order-methods">
                    <?php if ( $order->get_payment_method_title() ) : ?>
                        <span class="mkcp-order-methods__item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            <?php echo esc_html( $order->get_payment_method_title() ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $order->get_shipping_method() ) : ?>
                        <span class="mkcp-order-methods__item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                            <?php echo esc_html( $order->get_shipping_method() ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // Puur doorlinken naar de bestaande PDF-download-URL van WooCommerce
            // PDF Invoices & Packing Slips (indien actief) — geen eigen PDF-
            // generatie, exact zoals het Account-plan voorschrijft (sectie 6).
            // shortcode_exists()-guard: op sites zonder die plugin doet dit
            // stilzwijgend niets, geen kapotte link.
            if ( shortcode_exists( 'wcpdf_download_pdf' ) ) :
                ?>
                <div class="mkcp-order-invoice">
                    <?php echo do_shortcode( '[wcpdf_download_pdf id="' . absint( $order->get_id() ) . '" type="invoice" title="' . esc_attr__( 'Factuur downloaden', 'mk-cart-popup' ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mkcp-dash-card">
            <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.2 7-12a7 7 0 10-14 0c0 4.8 7 12 7 12z"/><circle cx="12" cy="11" r="2"/></svg></span><?php esc_html_e( 'Adresgegevens', 'mk-cart-popup' ); ?></h2>
            <div class="mkcp-order-addresses">
                <div class="mkcp-order-address">
                    <span class="mkcp-order-address__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="10" height="18"/><path d="M14 8h6v13h-6"/><line x1="7" y1="7" x2="7" y2="7.01"/><line x1="7" y1="11" x2="7" y2="11.01"/><line x1="7" y1="15" x2="7" y2="15.01"/></svg></span>
                    <div>
                        <h3><?php esc_html_e( 'Factuuradres', 'mk-cart-popup' ); ?></h3>
                        <p><?php echo wp_kses_post( $order->get_formatted_billing_address() ?: esc_html__( 'Niet opgegeven', 'mk-cart-popup' ) ); ?></p>
                    </div>
                </div>
                <?php if ( $order->has_shipping_address() ) : ?>
                    <div class="mkcp-order-address">
                        <span class="mkcp-order-address__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg></span>
                        <div>
                            <h3><?php esc_html_e( 'Verzendadres', 'mk-cart-popup' ); ?></h3>
                            <p><?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Retouren en reviews staan in twee LOSSE kaarten náást elkaar onder
        // Adresgegevens (i.p.v. samengevoegd in één kaart, of verspreid per
        // productregel bij de producten-kaart hierboven — beide eerdere
        // opzetten, op verzoek weer uit elkaar getrokken). Elke kaart bouwt
        // zijn eigen lijst door zijn eigen filter per item aan te roepen
        // (mkcp_account_order_return_item resp. mkcp_account_order_item_
        // extra) en toont zichzelf alleen als er voor minstens één item ook
        // echt iets te doen valt — een lege kaart voor een bestelling die
        // toch al niet meer retourneerbaar/beoordeelbaar is, is nutteloze ruis.
        $mkcp_order_extra_list = function( string $filter_tag, WC_Order $order ): array {
            $rows = [];
            foreach ( $order->get_items() as $extra_item ) {
                $action = apply_filters( $filter_tag, '', $order, $extra_item );
                if ( $action ) {
                    $rows[] = '<div class="mkcp-return-item"><div class="mkcp-return-item__name">'
                        . esc_html( $extra_item->get_name() ) . ' × ' . esc_html( $extra_item->get_quantity() )
                        . '</div><div class="mkcp-return-item__actions">' . $action . '</div></div>';
                }
            }
            return $rows;
        };
        $return_items = $mkcp_order_extra_list( 'mkcp_account_order_return_item', $order );
        $review_items = $mkcp_order_extra_list( 'mkcp_account_order_item_extra', $order );
        ?>
        <?php if ( $return_items || $review_items ) : ?>
            <div class="mkcp-order-extras-grid">
                <?php if ( $return_items ) : ?>
                    <div class="mkcp-dash-card">
                        <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg></span><?php esc_html_e( 'Retourneren', 'mk-cart-popup' ); ?></h2>
                        <?php echo implode( '', $return_items ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </div>
                <?php endif; ?>
                <?php if ( $review_items ) : ?>
                    <div class="mkcp-dash-card">
                        <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span><?php esc_html_e( 'Reviews', 'mk-cart-popup' ); ?></h2>
                        <?php echo implode( '', $review_items ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        // Alleen klantzichtbare notities (type=customer) — dit zijn precies
        // de statusupdates die de winkelier zelf al naar de klant stuurt
        // (bv. via WooCommerce's eigen "notitie naar klant"-functie), hier nu
        // ook zichtbaar als tijdlijn i.p.v. alleen als losse e-mail. Geen
        // nieuwe opslag nodig — dit leunt volledig op WooCommerce-core.
        $customer_notes = wc_get_order_notes( [ 'order_id' => $order->get_id(), 'type' => 'customer' ] );
        ?>
        <?php if ( $customer_notes ) : ?>
            <div class="mkcp-dash-card">
                <h2><span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg></span><?php esc_html_e( 'Updates', 'mk-cart-popup' ); ?></h2>
                <div class="mkcp-order-timeline">
                    <?php foreach ( $customer_notes as $note ) : ?>
                        <div class="mkcp-order-timeline__item">
                            <span class="mkcp-order-timeline__dot" aria-hidden="true"></span>
                            <span class="mkcp-order-timeline__body">
                                <span class="mkcp-order-timeline__text"><?php echo wp_kses_post( nl2br( esc_html( $note->content ) ) ); ?></span>
                                <span class="mkcp-order-timeline__date"><?php echo esc_html( wc_format_datetime( $note->date_created, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ── AJAX: "Recent bekeken producten" ophalen ──────────────────────────────────
//
// account.js stuurt de ID's die 'ie uit localStorage haalde (zie assets/
// wishlist-icon.js) — deze handler valideert/filtert ze gewoon tegen echte,
// zichtbare producten en rendert dezelfde compacte kaart als de andere
// Dashboard-productwidgets, geen aparte template nodig.

add_action( 'wp_ajax_mkcp_account_recently_viewed', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $raw_ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : [];
    $ids     = array_slice( array_filter( array_map( 'absint', $raw_ids ) ), 0, 12 );

    $html = '';
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( $product && $product->is_visible() ) {
            $html .= mkcp_account_render_product_card_compact( $product );
        }
    }

    wp_send_json_success( [ 'html' => $html ] );
} );


// ── AJAX: opnieuw bestellen ────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_reorder', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user_id  = get_current_user_id();
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $order    = $order_id ? wc_get_order( $order_id ) : null;

    if ( ! $order || (int) $order->get_customer_id() !== $user_id ) {
        wp_send_json_error( [ 'code' => 'not_found' ], 404 );
    }

    $added   = [];
    $skipped = [];

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        $name    = $item->get_name();

        // Product/variatie bestaat niet meer, of is niet meer koopbaar
        // (uitverkocht, verwijderd) — duidelijk overslaan i.p.v. de hele
        // reorder te laten mislukken (Account-plan, journey 2.8).
        if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            $skipped[] = $name;
            continue;
        }

        $variation_id = $product->is_type( 'variation' ) ? $product->get_id() : 0;
        $product_id   = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        $variation    = $variation_id ? $product->get_attributes() : [];

        $added_key = WC()->cart->add_to_cart( $product_id, $item->get_quantity(), $variation_id, $variation );
        if ( ! $added_key ) {
            $skipped[] = $name;
        } else {
            $added[] = $name;
        }
    }

    if ( empty( $added ) ) {
        wp_send_json_error( [
            'code'    => 'nothing_added',
            'message' => __( 'Geen van de producten uit deze bestelling kon worden toegevoegd (niet meer beschikbaar).', 'mk-cart-popup' ),
        ], 400 );
    }

    $message = sprintf(
        /* translators: 1: aantal toegevoegd, 2: totaal aantal producten */
        __( '%1$d van %2$d producten toegevoegd aan je winkelwagen.', 'mk-cart-popup' ),
        count( $added ),
        count( $added ) + count( $skipped )
    );
    if ( $skipped ) {
        $message .= ' ' . sprintf(
            /* translators: %s: kommagescheiden productnamen */
            __( 'Niet meer beschikbaar: %s.', 'mk-cart-popup' ),
            implode( ', ', $skipped )
        );
    }

    // Zelfde fragments-vorm als een normale wc-ajax=add_to_cart-respons, zodat
    // de client de bestaande 'added_to_cart'-event-flow van cart-popup.js kan
    // hergebruiken om de drawer te openen — geen nieuw open-mechanisme nodig.
    wp_send_json_success( [
        'message'   => $message,
        'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', [] ),
        'cart_hash' => WC()->cart->get_cart_hash(),
    ] );
} );
