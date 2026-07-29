<?php
/**
 * MK Cart Popup — drawer template
 *
 * Rendered on every page (except /cart) via wp_footer, and returned as a
 * WooCommerce fragment (#mk-cart-popup) so the DOM updates after every
 * add-to-cart, qty change, or item removal without a full page reload.
 *
 * @package MK Cart Popup
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$config = mkcp_config();
$cart   = WC()->cart;

if ( ! $config['enabled'] ) return;

$cart_count        = $cart->get_cart_contents_count();
$cart_taxes_raw    = $cart->get_cart_contents_taxes(); // array keyed by rate_id
$cart_subtotal_raw  = (float) $cart->get_cart_contents_total(); // excl. tax
$cart_subtotal_incl = $cart_subtotal_raw + (float) array_sum( $cart_taxes_raw );

// Free-shipping threshold: match WooCommerce's own comparison base.
// WC compares min_amount against the cart subtotal incl. or excl. tax
// depending on the store's cart display setting — mirror that here.
$threshold           = mkcp_get_free_shipping_threshold();
$threshold_base      = 'incl' === get_option( 'woocommerce_tax_display_cart' )
    ? $cart_subtotal_incl
    : $cart_subtotal_raw;
$remaining           = ( $threshold > 0 ) ? max( 0.0, $threshold - $threshold_base ) : 0.0;
$free_shipping_met   = ( $threshold > 0 && $remaining <= 0 );
$progress_pct        = ( $threshold > 0 ) ? min( 100, round( ( $threshold_base / $threshold ) * 100 ) ) : 0;

// Minimum order check
$min_order         = (float) ( $config['min_order_amount'] ?? 0 );
$min_order_met     = ( $min_order <= 0 || $cart_subtotal_raw >= $min_order );
$min_remaining     = ( $min_order > 0 ) ? max( 0.0, $min_order - $cart_subtotal_raw ) : 0.0;

$btw_split = ! empty( $config['btw_split'] );

// Eerstvolgende bezorgdatum (premium) — hergebruikt de bezorgdatum-planner die
// al op de checkoutpagina draait (includes/delivery-date.php); geen los
// datum-algoritme voor de winkelwagen.
//
// De winkelwagen heeft nog geen gekozen verzendmethode (die kiest de klant
// pas op de checkoutpagina), maar de ALGEMENE standaardinstellingen zonder
// rate_id gebruiken kan een verkeerde datum tonen zodra verzendmethodes hun
// eigen regel hebben (bv. een andere lead_days) — precies wat hier gebeurde.
// Prioriteit: (1) een al eerder gekozen methode uit de WC-sessie, zoals de
// checkoutpagina die ook leest, (2) anders de eerste methode met een eigen
// regel, wat een betere benadering is dan de algemene defaults, (3) anders
// alsnog de algemene defaults.
$delivery_next_date_label = null;
if ( ! empty( $config['delivery_preview_enabled'] ) && function_exists( 'mkcp_dd_enabled' ) && mkcp_dd_enabled() ) {
    $delivery_rate_id = function_exists( 'mkcp_dd_current_rate_id' ) ? mkcp_dd_current_rate_id() : null;
    if ( ! $delivery_rate_id ) {
        foreach ( (array) ( mkcp_checkout_config()['delivery_date_shipping_rules'] ?? [] ) as $rule_rate_id => $rule ) {
            if ( ! empty( $rule['enabled'] ) ) {
                $delivery_rate_id = $rule_rate_id;
                break;
            }
        }
    }
    $delivery_dates = mkcp_dd_available_dates( $delivery_rate_id );
    if ( ! empty( $delivery_dates[0] ) ) {
        $delivery_next_date_label = mkcp_dd_format_date( $delivery_dates[0] );
    }
}

// Drawer-positie (Instellingen → Styling, premium) — 'right' is de default en
// heeft geen eigen modifier-klasse nodig (zie cart-popup.scss).
$position       = $config['style_position'] ?? 'right';
$position_class = ( function_exists( 'mkcp_license_has' ) && mkcp_license_has( 'premium' ) && in_array( $position, [ 'left', 'center' ], true ) )
    ? ' mk-cart-popup--pos-' . $position
    : '';

// Mobiele app-ervaring (Instellingen → Styling, premium): bottom-sheet + gestures
// op telefoons. De klasse activeert de sheet-CSS (<720px) en cart-popup-mobile.js.
$app_mode = function_exists( 'mkcp_license_has' ) && mkcp_license_has( 'premium' ) && ! empty( $config['mobile_app_experience'] );
?>

<div id="mk-cart-popup" class="mk-cart-popup<?php echo $btw_split ? ' has-btw-switch' : ''; ?><?php echo esc_attr( $position_class ); ?><?php echo $app_mode ? ' mk-cart-popup--app' : ''; ?>" aria-hidden="true" inert data-cart-count="<?php echo (int) $cart_count; ?>">

    <div class="mk-cart-popup__backdrop"></div>

    <?php do_action( 'mkcp_before_drawer', $config ); ?>

    <div class="mk-cart-popup__drawer" role="dialog" aria-modal="true"
        aria-label="<?php echo esc_attr( $config['title'] ); ?>">

        <?php if ( $app_mode ) : ?>
        <!-- Sleep-handgreep (alleen zichtbaar in bottom-sheet-modus op telefoons) -->
        <div class="mk-cart-popup__grabber" aria-hidden="true"></div>
        <?php endif; ?>

        <!-- Toast: toegevoegd / bijgewerkt / fout (shown by JS) -->
        <div class="mk-cart-popup__added-toast js-mkcp-added-toast" aria-live="polite" role="status">
            <span class="js-mkcp-added-toast-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" focusable="false">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </span>
            <span class="js-mkcp-added-toast-text"></span>
        </div>

        <!-- Header -->
        <div class="mk-cart-popup__header">
            <div class="mk-cart-popup__title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                <?php echo esc_html( $config['title'] ); ?>
            </div>
            <div class="mk-cart-popup__header-actions">
                <?php if ( function_exists( 'mkcp_license_has' ) && mkcp_license_has( 'premium' ) && ! empty( $config['style_expand_enabled'] ) ) : ?>
                <button class="mk-cart-popup__expand js-mkcp-expand-toggle" type="button" aria-pressed="false" aria-label="<?php esc_attr_e( 'Volledig scherm', 'mk-cart-popup' ); ?>">
                    <svg class="mk-cart-popup__expand-icon-expand" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                    </svg>
                    <svg class="mk-cart-popup__expand-icon-collapse" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>
                    </svg>
                </button>
                <?php endif; ?>
                <button class="mk-cart-popup__close" aria-label="<?php esc_attr_e( 'Sluiten', 'mk-cart-popup' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </div>

        <?php do_action( 'mkcp_after_header', $config ); ?>

        <div class="mk-cart-popup__body">

        <?php if ( $cart_count > 0 ) : ?>

            <?php mkcp_render_zone( 'above-items', $config['blocks'] ); ?>

            <!-- Split-layout (volledig-scherm review-modus, premium): producten
                 links, samenvatting + cross-sell rechts. In normale modus zijn
                 deze wrappers display:contents (zie cart-popup.scss) — geen
                 enkel visueel verschil, puur een CSS-haakje voor .is-expanded. -->
            <div class="mk-cart-popup__content">
            <div class="mk-cart-popup__col-items">

            <!-- Column headers -->
            <div class="mk-cart-popup__items-header">
                <span><?php echo esc_html( $config['col_product'] ); ?></span>
                <span><?php echo esc_html( $config['col_total'] ); ?></span>
            </div>

            <?php do_action( 'mkcp_before_items', $config, $cart ); ?>

            <!-- Item list -->
            <div class="mk-cart-popup__items">

                <?php foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) :
                    /** @var WC_Product $product */
                    $product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                    if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) continue;

                    $quantity  = $cart_item['quantity'];
                    $name      = $product->get_name();
                    $permalink = $product->get_permalink();
                    // woocommerce_single (i.p.v. _thumbnail) i.v.m. de grotere kaart-foto
                    // in de split-layout — anders wordt de kleine bron pixelig uitgerekt.
                    $thumbnail = $product->get_image( 'woocommerce_single' );
                    $min_qty   = apply_filters( 'woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product );

                    // Stock limit for + button
                    $stock_qty = $product->managing_stock() ? $product->get_stock_quantity() : null;
                    $max_qty   = ( $stock_qty !== null && ! $product->backorders_allowed() ) ? (int) $stock_qty : 0;
                    $at_max    = ( $max_qty > 0 && $quantity >= $max_qty );

                    // Low-stock badge
                    $stock_threshold   = (int) ( $config['stock_threshold'] ?? 5 );
                    $show_stock_badge  = ! empty( $config['stock_indicator'] )
                        && $product->managing_stock()
                        && $stock_qty !== null
                        && $stock_qty > 0
                        && $stock_qty <= $stock_threshold;

                    if ( $btw_split ) :
                        $unit_excl  = wc_get_price_excluding_tax( $product, [ 'qty' => 1 ] );
                        $unit_incl  = wc_get_price_including_tax( $product, [ 'qty' => 1 ] );
                        $total_excl = wc_get_price_excluding_tax( $product, [ 'qty' => $quantity ] );
                        $total_incl = wc_get_price_including_tax( $product, [ 'qty' => $quantity ] );
                        $analytics_price = round( $unit_excl, 2 );
                    else :
                        $unit_price      = wc_get_price_to_display( $product, [ 'qty' => 1 ] );
                        $total_price     = wc_get_price_to_display( $product, [ 'qty' => $quantity ] );
                        $analytics_price = round( $unit_price, 2 );
                    endif;

                    // Analytics data (GA4 ecommerce item format)
                    $analytics_data = wp_json_encode( [
                        'id'    => $product->get_id(),
                        'sku'   => $product->get_sku(),
                        'name'  => $product->get_name(),
                        'price' => $analytics_price,
                        'qty'   => $quantity,
                    ] );

                    // Undo data for re-adding after removal
                    $undo_data = wp_json_encode( [
                        'product_id'   => $product->get_id(),
                        'qty'          => $quantity,
                        'variation_id' => $cart_item['variation_id'] ?? 0,
                        'variation'    => $cart_item['variation']    ?? [],
                    ] );
                ?>

                <div class="mk-cart-popup__item"
                     data-key="<?php echo esc_attr( $cart_item_key ); ?>"
                     data-product="<?php echo esc_attr( $analytics_data ); ?>"
                     data-undo="<?php echo esc_attr( $undo_data ); ?>">

                    <a class="mk-cart-popup__item-image" href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true">
                        <?php echo wp_kses_post( $thumbnail ); ?>
                    </a>

                    <div class="mk-cart-popup__item-info">

                        <div class="mk-cart-popup__item-name-wrap" data-name="<?php echo esc_attr( $name ); ?>">
                            <a class="mk-cart-popup__item-name" href="<?php echo esc_url( $permalink ); ?>">
                                <?php echo esc_html( $name ); ?>
                            </a>
                        </div>

                        <?php if ( function_exists( 'mkcp_shipping_choice_is_active' ) && mkcp_shipping_choice_is_active()
                            && function_exists( 'mkcp_cart_item_is_pickup_only' ) && mkcp_cart_item_is_pickup_only( $cart_item_key ) ) : ?>
                        <div class="mk-cart-popup__pickup-only">
                            <span class="mkcp-sc-info" tabindex="0">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                <span class="mkcp-sc-tooltip"><?php esc_html_e( 'Dit product kan alleen worden opgehaald, niet verzonden.', 'mk-cart-popup' ); ?></span>
                            </span>
                            <span class="mk-cart-popup__pickup-only-text"><?php esc_html_e( 'Alleen afhalen', 'mk-cart-popup' ); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php echo wc_get_formatted_cart_item_data( $cart_item ); ?>

                        <?php if ( $show_stock_badge ) : ?>
                        <div class="mk-cart-popup__stock-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?php echo esc_html( sprintf( _n( 'Nog maar %d op voorraad', 'Nog maar %d op voorraad', $stock_qty, 'mk-cart-popup' ), $stock_qty ) ); ?>
                        </div>
                        <?php endif; ?>

                        <div class="mk-cart-popup__item-price">
                            <?php if ( $btw_split ) : ?>
                                <span class="price-excl-tax"><?php echo wc_price( $unit_excl ); ?> <span class="tax"><?php echo esc_html( $config['label_excl_tax'] ); ?></span></span>
                                <span class="price-incl-tax"><?php echo wc_price( $unit_incl ); ?> <span class="tax"><?php echo esc_html( $config['label_incl_tax'] ); ?></span></span>
                            <?php else : ?>
                                <?php echo wc_price( $unit_price ); ?>
                            <?php endif; ?>
                        </div>

                        <div class="mk-cart-popup__item-actions">

                            <div class="mk-cart-popup__qty" role="group"
                                aria-label="<?php esc_attr_e( 'Quantity', 'woocommerce' ); ?>">
                                <button class="mk-cart-popup__qty-btn mk-cart-popup__qty-btn--min"
                                    data-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                    aria-label="<?php esc_attr_e( 'Decrease quantity', 'woocommerce' ); ?>">−</button>
                                <input class="mk-cart-popup__qty-input" type="number"
                                    value="<?php echo esc_attr( $quantity ); ?>"
                                    min="<?php echo esc_attr( max( 1, $min_qty ) ); ?>"
                                    <?php if ( $max_qty > 0 ) : ?>max="<?php echo esc_attr( $max_qty ); ?>"<?php endif; ?>
                                    data-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                    aria-label="<?php esc_attr_e( 'Quantity', 'woocommerce' ); ?>">
                                <button class="mk-cart-popup__qty-btn mk-cart-popup__qty-btn--plus<?php echo $at_max ? ' is-disabled' : ''; ?>"
                                    data-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                    <?php if ( $at_max ) : ?>disabled aria-disabled="true"<?php endif; ?>
                                    aria-label="<?php esc_attr_e( 'Increase quantity', 'woocommerce' ); ?>">+</button>
                            </div>

                            <?php if ( ! empty( $config['save_for_later'] ) ) :
                                $thumb_url_save = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src();
                                $price_save     = html_entity_decode( strip_tags( $btw_split ? wc_price( $unit_incl ) : wc_price( $unit_price ) ), ENT_HTML5, 'UTF-8' );
                            ?>
                            <button class="mk-cart-popup__save-later js-mkcp-save-later"
                                data-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                data-undo="<?php echo esc_attr( $undo_data ); ?>"
                                data-name="<?php echo esc_attr( $name ); ?>"
                                data-thumb="<?php echo esc_url( $thumb_url_save ); ?>"
                                data-price="<?php echo esc_attr( $price_save ); ?>"
                                data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                                aria-label="<?php esc_attr_e( 'Bewaar voor later', 'mk-cart-popup' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <span><?php esc_html_e( 'Bewaar', 'mk-cart-popup' ); ?></span>
                            </button>
                            <?php endif; ?>

                            <button class="mk-cart-popup__item-remove" data-key="<?php echo esc_attr( $cart_item_key ); ?>"
                                aria-label="<?php esc_attr_e( 'Remove this item', 'woocommerce' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                    <path d="M10 11v6M14 11v6"/>
                                    <path d="M9 6V4h6v2"/>
                                </svg>
                            </button>

                        </div>

                    </div>

                    <?php do_action( 'mkcp_cart_item_after_info', $cart_item, $product, $cart_item_key, $config ); ?>

                    <div class="mk-cart-popup__item-col-price">
                        <?php if ( $btw_split ) : ?>
                            <span class="price-excl-tax"><?php echo wc_price( $total_excl ); ?></span>
                            <span class="price-incl-tax"><?php echo wc_price( $total_incl ); ?></span>
                        <?php else : ?>
                            <?php echo wc_price( $total_price ); ?>
                        <?php endif; ?>
                    </div>

                </div>

                <?php endforeach; ?>

            </div>

            <?php do_action( 'mkcp_after_items', $config, $cart ); ?>

            <?php mkcp_render_zone( 'below-items', $config['blocks'] ); ?>

            </div><!-- /.mk-cart-popup__col-items -->

            <!-- Cross-sell (kolom + scheidingslijn aanwezig zodra de functie aanstaat —
                 met een fallback-bericht als er (tijdelijk) geen producten zijn, i.p.v.
                 dat de kolom stil verdwijnt en de productlijst breder springt). -->
            <?php if ( ! empty( $config['crosssell_enabled'] ) ) :
                $crosssell_products = mkcp_get_crosssell_products(
                    (int) ( $config['crosssell_limit'] ?? 3 ),
                    $config['crosssell_mode'] ?? 'category'
                );
            ?>
            <div class="mk-cart-popup__divider mk-cart-popup__divider--cross" aria-hidden="true"></div>
            <div class="mk-cart-popup__col-cross">
            <?php if ( empty( $crosssell_products ) ) : ?>
            <div class="mk-cart-popup__crosssell-fallback">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?php esc_html_e( 'Je hebt alle suggesties al toegevoegd — mooi bezig.', 'mk-cart-popup' ); ?></span>
            </div>
            <?php else : ?>
            <div class="mk-cart-popup__crosssell">
                <div class="mk-cart-popup__crosssell-title">
                    <?php echo esc_html( $config['crosssell_title'] ?: __( 'Misschien ook interessant?', 'mk-cart-popup' ) ); ?>
                </div>
                <div class="mk-cart-popup__crosssell-track">
                <div class="mk-cart-popup__crosssell-list">
                    <?php foreach ( $crosssell_products as $cs_product ) :
                        $cs_is_simple = in_array( $cs_product->get_type(), [ 'simple', 'external' ], true );
                        $cs_url       = $cs_product->get_permalink();
                        $cs_name      = $cs_product->get_name();
                        $cs_price_raw = (float) wc_get_price_to_display( $cs_product );
                        $cs_price     = wc_price( $cs_price_raw );

                        // Vult dit product het gat tot gratis verzending? Alleen dán een
                        // onderschrift — geen verzonnen "vaak samen gekocht"-claims die
                        // niet op echte data zijn gebaseerd. Sortering naar voren (in de
                        // split-layout) gebeurt puur via CSS op dit attribuut, zodat de
                        // normale slider-volgorde ongemoeid blijft.
                        $cs_fits_gap = ( $threshold > 0 && $remaining > 0 && $cs_price_raw <= $remaining );
                    ?>
                    <div class="mk-cart-popup__crosssell-item"<?php echo $cs_fits_gap ? ' data-fits-gap="1"' : ''; ?>>
                        <a href="<?php echo esc_url( $cs_url ); ?>" class="mk-cart-popup__crosssell-img" tabindex="-1">
                            <?php // woocommerce_single (i.p.v. _thumbnail) i.v.m. de grotere kaart-foto
                                  // in de split-layout — anders wordt de kleine bron pixelig uitgerekt. ?>
                            <?php echo wp_kses_post( $cs_product->get_image( 'woocommerce_single' ) ); ?>
                        </a>
                        <div class="mk-cart-popup__crosssell-info">
                            <a href="<?php echo esc_url( $cs_url ); ?>" class="mk-cart-popup__crosssell-name">
                                <?php echo esc_html( $cs_name ); ?>
                            </a>
                            <?php if ( $cs_fits_gap ) : ?>
                            <span class="mk-cart-popup__crosssell-reason"><?php esc_html_e( 'Sluit het gat tot gratis verzending', 'mk-cart-popup' ); ?></span>
                            <?php endif; ?>
                            <span class="mk-cart-popup__crosssell-price"><?php echo $cs_price; ?></span>
                        </div>
                        <?php if ( $cs_is_simple && $cs_product->is_purchasable() ) : ?>
                        <button type="button"
                            class="mk-cart-popup__crosssell-atc js-mkcp-crosssell-atc"
                            data-product-id="<?php echo esc_attr( $cs_product->get_id() ); ?>"
                            data-product-name="<?php echo esc_attr( $cs_name ); ?>"
                            aria-label="<?php echo esc_attr( sprintf( __( '%s toevoegen', 'mk-cart-popup' ), $cs_name ) ); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" focusable="false"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </button>
                        <?php else : ?>
                        <a href="<?php echo esc_url( $cs_url ); ?>" class="mk-cart-popup__crosssell-view">
                            <?php esc_html_e( 'Bekijken', 'mk-cart-popup' ); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="mk-cart-popup__crosssell-nav mk-cart-popup__crosssell-nav--prev js-mkcp-cs-prev" aria-label="<?php esc_attr_e( 'Vorige', 'mk-cart-popup' ); ?>" hidden>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <button class="mk-cart-popup__crosssell-nav mk-cart-popup__crosssell-nav--next js-mkcp-cs-next" aria-label="<?php esc_attr_e( 'Volgende', 'mk-cart-popup' ); ?>" hidden>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                </div><!-- /.mk-cart-popup__crosssell-track -->
            </div>
            <?php endif; ?>

            <!-- USP's onderaan de cross-sell-kolom (alleen zichtbaar in de
                 split-layout, zie cart-popup.scss) — zelfde content als de
                 footer-versie hieronder, die in deze situatie wordt verborgen
                 zodra de kolom bestaat, zodat ze niet dubbel getoond worden. -->
            <?php if ( ! empty( $config['usps'] ) ) : ?>
            <div class="mk-cart-popup__usps mk-cart-popup__cross-usps">
                <div class="mk-cart-popup__cross-usps-title"><?php esc_html_e( 'Waarom klanten voor ons kiezen', 'mk-cart-popup' ); ?></div>
                <?php foreach ( $config['usps'] as $usp ) : ?>
                <span class="mk-cart-popup__usp">
                    <?php mkcp_icon( $usp['icon'] ?? 'check' ); ?>
                    <?php echo esc_html( $usp['text'] ); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            </div><!-- /.mk-cart-popup__col-cross -->
            <?php endif; ?>

            <div class="mk-cart-popup__divider mk-cart-popup__divider--side" aria-hidden="true"></div>
            <div class="mk-cart-popup__col-side">

            <!-- Incl/excl-BTW-pillen + gratis-verzendbalk staan hier zodat ze in de
                 split-layout in de samenvatting-kolom terechtkomen i.p.v. los boven de
                 productlijst. Beide hebben een vaste order (cart-popup.scss) zodat ze
                 in de normale (niet-gesplitste) modus toch bovenaan blijven staan. -->
            <?php if ( $btw_split ) : ?>
            <div class="mk-cart-popup__btw-switch">
                <span class="mk-cart-popup__btw-label"><?php esc_html_e( 'Prijzen tonen:', 'mk-cart-popup' ); ?></span>
                <div class="mk-cart-popup__btw-pills">
                    <button type="button" class="mk-cart-popup__btw-opt js-mkcp-btw" data-pref="incl"><?php esc_html_e( 'Incl. BTW', 'mk-cart-popup' ); ?></button>
                    <button type="button" class="mk-cart-popup__btw-opt js-mkcp-btw" data-pref="excl"><?php esc_html_e( 'Excl. BTW', 'mk-cart-popup' ); ?></button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $config['free_shipping_bar'] && $threshold > 0 ) : ?>
            <div class="mk-cart-popup__progress">
                <div class="mk-cart-popup__progress-text<?php echo $free_shipping_met ? ' mk-cart-popup__progress-text--success' : ''; ?>">
                    <?php if ( $free_shipping_met ) :
                        echo esc_html( $config['free_shipping_note'] );
                    else :
                        echo wp_kses_post( sprintf( $config['shipping_note'], wc_price( $remaining ) ) );
                    endif; ?>
                </div>
                <div class="mk-cart-popup__progress-bar" role="progressbar"
                    aria-valuenow="<?php echo esc_attr( $progress_pct ); ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="mk-cart-popup__progress-fill" style="width:<?php echo esc_attr( $progress_pct ); ?>%"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bezorgdatum-preview (premium, instelbaar bij Checkout → Eerstvolgende
                 bezorgdatum) — zelfde order-truc als de BTW-pillen/verzendbalk
                 hierboven, dus ook zichtbaar in de normale (niet-gesplitste) modus. -->
            <?php if ( $delivery_next_date_label ) : ?>
            <div class="mk-cart-popup__delivery-preview">
                <span class="mk-cart-popup__delivery-preview-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <span class="mk-cart-popup__delivery-preview-text">
                    <span class="mk-cart-popup__delivery-preview-label"><?php esc_html_e( 'Eerstvolgende bezorgdatum', 'mk-cart-popup' ); ?></span>
                    <span class="mk-cart-popup__delivery-preview-date"><?php echo esc_html( $delivery_next_date_label ); ?></span>
                    <span class="mk-cart-popup__delivery-preview-note"><?php esc_html_e( 'Definitieve datum kies je bij het afrekenen', 'mk-cart-popup' ); ?></span>
                </span>
            </div>
            <?php endif; ?>

            <?php do_action( 'mkcp_before_footer', $config, $cart ); ?>

            <!-- Footer -->
            <div class="mk-cart-popup__footer">

                <!-- Kortingscode (instelbaar aan/uit) -->
                <?php if ( ! empty( $config['show_coupon'] ) ) : ?>

                <!-- Applied coupons -->
                <?php $applied_coupons = $cart->get_applied_coupons(); ?>
                <?php if ( ! empty( $applied_coupons ) ) : ?>
                <div class="mk-cart-popup__applied-coupons">
                    <?php foreach ( $applied_coupons as $coupon_code ) :
                        $discount = $cart->get_coupon_discount_amount( $coupon_code, false );
                    ?>
                    <div class="mk-cart-popup__coupon-tag">
                        <span class="mk-cart-popup__coupon-tag-code"><?php echo esc_html( strtoupper( $coupon_code ) ); ?></span>
                        <?php if ( $discount > 0 ) : ?>
                        <span class="mk-cart-popup__coupon-tag-amount">−<?php echo wc_price( $discount ); ?></span>
                        <?php endif; ?>
                        <button type="button" class="mk-cart-popup__coupon-tag-remove js-mkcp-remove-coupon"
                            data-code="<?php echo esc_attr( $coupon_code ); ?>"
                            aria-label="<?php esc_attr_e( 'Kortingscode verwijderen', 'mk-cart-popup' ); ?>">×</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Coupon input -->
                <div class="mk-cart-popup__coupon">
                    <span class="mk-cart-popup__coupon-label"><?php esc_html_e( 'Kortingscode', 'mk-cart-popup' ); ?></span>
                    <div class="mk-cart-popup__coupon-row">
                        <input type="text" class="mk-cart-popup__coupon-input js-mkcp-coupon-input"
                               placeholder="<?php esc_attr_e( 'Kortingscode', 'mk-cart-popup' ); ?>"
                               aria-label="<?php esc_attr_e( 'Kortingscode invoeren', 'mk-cart-popup' ); ?>">
                        <button type="button" class="mk-cart-popup__coupon-btn js-mkcp-apply-coupon">
                            <?php esc_html_e( 'Toepassen', 'mk-cart-popup' ); ?>
                        </button>
                    </div>
                    <div class="mk-cart-popup__coupon-feedback" aria-live="polite"></div>
                </div>

                <?php endif; ?>

                <!-- Totals -->
                <?php
                    $subtotal_excl = $cart_subtotal_raw;
                    $subtotal_incl = $cart_subtotal_incl;
                    $btw_amount    = $subtotal_incl - $subtotal_excl;
                    // Only show a rate % when the cart has exactly one tax rate.
                    // With mixed rates the single percentage would be misleading.
                    $btw_rate_pct  = ( $btw_split && $subtotal_excl > 0 && count( $cart_taxes_raw ) === 1 )
                        ? (int) round( ( $btw_amount / $subtotal_excl ) * 100 )
                        : 0;
                ?>
                <div class="mk-cart-popup__totals">
                    <span class="mk-cart-popup__totals-label"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></span>
                    <span class="mk-cart-popup__totals-value">
                        <?php if ( $btw_split ) : ?>
                            <span class="price-excl-tax"><?php echo wc_price( $subtotal_excl ); ?> <span class="tax"><?php echo esc_html( $config['label_excl_tax'] ); ?></span></span>
                            <span class="price-incl-tax"><?php echo wc_price( $subtotal_incl ); ?> <span class="tax"><?php echo esc_html( $config['label_incl_tax'] ); ?></span></span>
                        <?php else : ?>
                            <?php echo wc_price( wc_prices_include_tax() ? $subtotal_incl : $subtotal_excl ); ?>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ( $btw_split && $btw_amount > 0.001 ) :
                    $rate_suffix   = $btw_rate_pct > 0 ? ' (' . $btw_rate_pct . '%)' : '';
                    $label_incl    = __( 'Waarvan BTW', 'mk-cart-popup' ) . $rate_suffix;
                    $label_excl    = __( 'Nog bij te tellen BTW', 'mk-cart-popup' ) . $rate_suffix;
                ?>
                <div class="mk-cart-popup__btw-row">
                    <span>
                        <span class="mkcp-btw-label-incl"><?php echo esc_html( $label_incl ); ?>:</span>
                        <span class="mkcp-btw-label-excl"><?php echo esc_html( $label_excl ); ?>:</span>
                    </span>
                    <span><?php echo wc_price( $btw_amount ); ?></span>
                </div>
                <?php endif; ?>

                <?php mkcp_render_zone( 'below-totals', $config['blocks'] ); ?>

                <!-- Minimum order warning -->
                <?php if ( $min_order > 0 && ! $min_order_met ) : ?>
                <div class="mk-cart-popup__min-order-warning" role="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?php echo wp_kses_post( sprintf(
                        __( 'Minimale bestelling: %1$s. Voeg nog %2$s toe.', 'mk-cart-popup' ),
                        wc_price( $min_order ),
                        wc_price( $min_remaining )
                    ) ); ?>
                </div>
                <?php endif; ?>

                <!-- Payment icons (uploaded images) -->
                <?php
                $payment_icons_raw     = (array) ( $config['payment_icons'] ?? [] );
                $payment_icons_display = array_filter( $payment_icons_raw, function( $i ) {
                    return is_array( $i ) && ! empty( $i['url'] );
                } );
                ?>
                <?php if ( ! empty( $payment_icons_display ) ) : ?>
                <div class="mk-cart-popup__payment-icons">
                    <?php foreach ( $payment_icons_display as $pi ) : ?>
                    <img src="<?php echo esc_url( $pi['url'] ); ?>" alt="<?php echo esc_attr( $pi['label'] ?? '' ); ?>" loading="lazy">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php mkcp_render_zone( 'below-payment', $config['blocks'] ); ?>

                <!-- Onzichtbare meetlat: markeert de natuurlijke positie van de knop
                     hierboven, zodat JS via IntersectionObserver kan bepalen of de
                     knop al zichtbaar is of dat de vastgeplakte kopie moet tonen. -->
                <div class="mk-cart-popup__ctas-sentinel" aria-hidden="true"></div>

                <!-- CTAs -->
                <div class="mk-cart-popup__ctas">
                    <a href="<?php echo $min_order_met ? esc_url( wc_get_checkout_url() ) : '#'; ?>"
                        class="mk-cart-popup__btn mk-cart-popup__btn--primary<?php echo $min_order_met ? '' : ' is-disabled'; ?>"
                        <?php echo ! $min_order_met ? 'aria-disabled="true"' : ''; ?>>
                        <?php echo esc_html( $config['btn_checkout'] ); ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </a>
                </div>

                <?php mkcp_render_zone( 'below-checkout', $config['blocks'] ); ?>

                <!-- Winkelmand bewaren: URL + mail -->
                <?php if ( ! empty( $config['save_cart_url'] ) || ! empty( $config['save_cart_email'] ) ) : ?>
                <div class="mk-cart-popup__share js-mkcp-share">
                    <button type="button" class="mk-cart-popup__share-heading js-mkcp-share-toggle" aria-expanded="false">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                        <?php esc_html_e( 'Deel winkelmand', 'mk-cart-popup' ); ?>
                        <svg class="mk-cart-popup__share-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="mk-cart-popup__share-body js-mkcp-share-body" style="display:none">
                        <?php if ( ! empty( $config['save_for_later'] ) ) : ?>
                        <div class="mk-cart-popup__share-scope" role="group" aria-label="<?php esc_attr_e( 'Wat wil je bewaren?', 'mk-cart-popup' ); ?>">
                            <button type="button" class="mk-cart-popup__scope-pill js-mkcp-scope-pill" data-scope="cart">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                                <?php esc_html_e( 'Winkelmand', 'mk-cart-popup' ); ?>
                            </button>
                            <button type="button" class="mk-cart-popup__scope-pill js-mkcp-scope-pill" data-scope="saved">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <?php esc_html_e( 'Bewaard', 'mk-cart-popup' ); ?>
                            </button>
                            <button type="button" class="mk-cart-popup__scope-pill is-active js-mkcp-scope-pill" data-scope="both">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" style="margin-left:-2px"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <?php esc_html_e( 'Alles', 'mk-cart-popup' ); ?>
                            </button>
                        </div>
                        <?php endif; ?>
                        <?php if ( ! empty( $config['save_cart_url'] ) ) : ?>
                        <div class="mk-cart-popup__share-url-wrap">
                            <button type="button" class="mk-cart-popup__share-btn js-mkcp-gen-url">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <?php esc_html_e( 'Link genereren', 'mk-cart-popup' ); ?>
                            </button>
                            <div class="mk-cart-popup__share-url-result js-mkcp-url-result" style="display:none">
                                <input type="text" class="mk-cart-popup__share-url-input js-mkcp-url-input" readonly
                                       aria-label="<?php esc_attr_e( 'Herstel-link', 'mk-cart-popup' ); ?>">
                                <button type="button" class="mk-cart-popup__share-copy js-mkcp-copy-url">
                                    <?php esc_html_e( 'Kopieer', 'mk-cart-popup' ); ?>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ( ! empty( $config['save_cart_email'] ) ) : ?>
                        <div class="mk-cart-popup__share-email-wrap">
                            <div class="mk-cart-popup__share-email-row">
                                <input type="email" class="mk-cart-popup__share-email-input js-mkcp-mail-input"
                                       placeholder="<?php esc_attr_e( 'jouw@email.nl', 'mk-cart-popup' ); ?>"
                                       aria-label="<?php esc_attr_e( 'E-mailadres', 'mk-cart-popup' ); ?>">
                                <button type="button" class="mk-cart-popup__share-btn js-mkcp-send-mail">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    <?php esc_html_e( 'Verstuur', 'mk-cart-popup' ); ?>
                                </button>
                            </div>
                            <div class="mk-cart-popup__share-feedback js-mkcp-mail-feedback" aria-live="polite"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- USP strip -->
                <?php if ( ! empty( $config['usps'] ) ) : ?>
                <div class="mk-cart-popup__usps">
                    <?php foreach ( $config['usps'] as $usp ) : ?>
                    <span class="mk-cart-popup__usp">
                        <?php mkcp_icon( $usp['icon'] ?? 'check' ); ?>
                        <?php echo esc_html( $usp['text'] ); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>

            <?php do_action( 'mkcp_after_footer', $config ); ?>

            </div><!-- /.mk-cart-popup__col-side -->
            </div><!-- /.mk-cart-popup__content -->

        <?php else : ?>

            <!-- Empty state -->
            <div class="mk-cart-popup__empty">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                <h2><?php echo esc_html( $config['empty_heading'] ); ?></h2>
                <button class="mk-cart-popup__btn mk-cart-popup__btn--primary js-mk-cart-close">
                    <?php echo esc_html( $config['empty_button'] ); ?>
                </button>
            </div>

        <?php endif; ?>

        <!-- Saved-for-later section (rendered by JS from localStorage) -->
        <?php if ( ! empty( $config['save_for_later'] ) ) : ?>
        <div class="mk-cart-popup__saved js-mkcp-saved-section" style="display:none">
            <div class="mk-cart-popup__saved-header">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <span><?php esc_html_e( 'Bewaard voor later', 'mk-cart-popup' ); ?></span>
                <span class="mk-cart-popup__saved-count js-mkcp-saved-count"></span>
            </div>
            <div class="mk-cart-popup__saved-list js-mkcp-saved-list"></div>
        </div>
        <?php endif; ?>


        </div><!-- /.mk-cart-popup__body -->

        <!-- Undo toast (hidden, shown by JS for 5 s after item removal) -->
        <div class="mk-cart-popup__undo-toast js-mkcp-undo-toast" aria-live="assertive" role="status">
            <span class="mk-cart-popup__undo-msg">
                <?php esc_html_e( 'Product verwijderd.', 'mk-cart-popup' ); ?>
            </span>
            <button type="button" class="mk-cart-popup__undo-action js-mkcp-undo-action">
                <?php esc_html_e( 'Ongedaan maken', 'mk-cart-popup' ); ?>
            </button>
            <span class="mk-cart-popup__undo-timer js-mkcp-undo-timer" aria-hidden="true"></span>
        </div>

    </div>

</div>
