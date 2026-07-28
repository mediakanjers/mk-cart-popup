<?php
/**
 * MK Cart Popup — Bedankt-pagina (next-level, premium)
 *
 * Verrijkt de WooCommerce-bedankpagina (order-received): persoonlijke heading,
 * grote bezorg-/afhaal-banner (+ afhaallocatie-kaartje bij afhalen), een
 * "wat gebeurt er nu"-stappenstrip, cross-sell op basis van de bestelling,
 * een factuur-downloadknop (als WooCommerce PDF Invoices & Packing Slips
 * actief is) en een vertrouwenselementen-footer.
 *
 * Vervangt de kleine "Afhalen: ..." / "Gewenste bezorgdatum: ..."-regels uit
 * includes/pickup.php en includes/delivery-date.php — die krijgen een guard
 * (mkcp_thankyou_enabled()) zodat ze niet dubbel op met de nieuwe banner
 * komen te staan.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Config ─────────────────────────────────────────────────────────────────────

function mkcp_thankyou_enabled(): bool {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return false;
    return ! empty( $cfg['thankyou_enabled'] );
}

function mkcp_thankyou_config(): array {
    $cfg = mkcp_checkout_config();
    return [
        'heading_template'   => (string) $cfg['thankyou_heading_template'],
        'crosssell_enabled'  => ! empty( $cfg['thankyou_crosssell_enabled'] ),
        'crosssell_title'    => (string) $cfg['thankyou_crosssell_title'],
        'invoice_enabled'    => ! empty( $cfg['thankyou_invoice_enabled'] ),
        'trust_return_text'  => (string) $cfg['thankyou_trust_return_text'],
        'trust_return_url'   => (string) $cfg['thankyou_trust_return_url'],
        'trust_contact_text' => (string) $cfg['thankyou_trust_contact_text'],
    ];
}

/** Kleine, vaste iconenset voor deze pagina — los van het admin-$icons-array (dat bestaat alleen op de instellingenpagina). */
function mkcp_ty_icon( string $name ): string {
    $attrs = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
    $paths = [
        'map-pin'  => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'truck'    => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'route'    => '<polygon points="3 11 22 2 13 21 11 13 3 11"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'package'  => '<path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'mail'     => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/>',
    ];
    if ( ! isset( $paths[ $name ] ) ) return '';
    return '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . '>' . $paths[ $name ] . '</svg>';
}


// ── 1. Persoonlijke heading ───────────────────────────────────────────────────
// Vervangt WooCommerce's eigen "Bedankt, je bestelling is ontvangen"-tekst via
// de hook die daar al voor bestaat — geen nieuw element nodig, hergebruikt de
// succesmelding-styling die de vorige ronde al kreeg.

add_filter( 'woocommerce_thankyou_order_received_text', function( $message, $order ) {
    if ( ! mkcp_thankyou_enabled() || ! $order ) return $message;

    $cfg      = mkcp_thankyou_config();
    $voornaam = $order->get_billing_first_name() ?: __( 'daar', 'mk-cart-popup' );
    $text     = strtr( $cfg['heading_template'], [ '{voornaam}' => $voornaam ] );

    return $text !== '' ? $text : $message;
}, 10, 2 );


// ── 2-4, 7. Banner + afhaallocatie-kaartje + factuurknop + stappenstrip ───────
// Deze render-functies geven hun HTML terug als string (i.p.v. hem direct te
// echoën) — nodig om ze hieronder in de juiste kolom (hoofd- of zijkolom) van
// de echte CSS Grid-lay-out te kunnen plaatsen.

function mkcp_ty_render_banner( WC_Order $order, bool $is_pickup, string $pickup_date, string $delivery_date ): string {
    if ( $is_pickup ) {
        $date  = $pickup_date;
        $slot  = (string) $order->get_meta( '_mkcp_pickup_slot' );
        $icon  = 'map-pin';
        $label = __( 'Klaar om af te halen', 'mk-cart-popup' );
    } else {
        $date  = $delivery_date;
        $slot  = (string) $order->get_meta( '_mkcp_delivery_slot' );
        $icon  = 'truck';
        $label = __( 'Wordt bezorgd', 'mk-cart-popup' );
    }
    if ( ! $date || ! function_exists( 'mkcp_dd_format_date' ) ) return '';
    ob_start();
    ?>
    <div class="mkcp-ty-banner">
        <span class="mkcp-ty-banner__icon"><?php echo mkcp_ty_icon( $icon ); ?></span>
        <span class="mkcp-ty-banner__text">
            <strong><?php echo esc_html( $label ); ?></strong>
            <?php echo esc_html( mkcp_dd_format_date( $date ) . ( $slot ? ', ' . $slot : '' ) ); ?>
        </span>
    </div>
    <?php
    return ob_get_clean();
}

function mkcp_ty_render_pickup_card( WC_Order $order ): string {
    if ( ! function_exists( 'mkcp_pickup_location_for_rate' ) ) return '';

    $rate_id = (string) $order->get_meta( '_mkcp_pickup_rate_id' );
    if ( ! $rate_id ) return '';

    $loc = mkcp_pickup_location_for_rate( $rate_id );
    if ( ! $loc || empty( $loc['address'] ) ) return '';

    $maps_url = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $loc['address'] );
    ob_start();
    ?>
    <div class="mkcp-ty-pickup-card">
        <div class="mkcp-ty-pickup-card__info">
            <strong><?php echo esc_html( $loc['location_label'] ?? __( 'Afhaallocatie', 'mk-cart-popup' ) ); ?></strong>
            <p><?php echo nl2br( esc_html( $loc['address'] ) ); ?></p>
        </div>
        <a href="<?php echo esc_url( $maps_url ); ?>" target="_blank" rel="noopener" class="mkcp-ty-pickup-card__route">
            <?php echo mkcp_ty_icon( 'route' ); ?>
            <?php esc_html_e( 'Route uitzetten', 'mk-cart-popup' ); ?>
        </a>
    </div>
    <?php
    return ob_get_clean();
}

function mkcp_ty_render_invoice_button( WC_Order $order ): string {
    if ( ! function_exists( 'WPO_WCPDF' ) ) return '';

    $cfg = mkcp_thankyou_config();
    if ( ! $cfg['invoice_enabled'] ) return '';

    $wpo = WPO_WCPDF();
    if ( ! isset( $wpo->endpoint ) || ! method_exists( $wpo->endpoint, 'get_document_link' ) ) return '';

    // Respecteert de eigen toegangsinstelling van WPO_WCPDF ("Documenten →
    // Factuur → toegang beperken tot ingelogde gebruikers" vs. "iedereen met
    // de order-sleutel"). Bij "alleen ingelogde gebruikers" (de default)
    // levert dit voor gastbestellingen bewust geen link — dat is een
    // bewuste beveiligingskeuze van die plugin, niet iets om hier stilzwijgend
    // te omzeilen. Wil de winkelier de knop ook voor gasten, dan zet die dat
    // zelf om naar "iedereen met de order-sleutel" in de WPO_WCPDF-instellingen.
    $url = $wpo->endpoint->get_document_link( $order, 'invoice', [ 'thankyou' => 'true' ] );
    if ( ! $url ) return '';
    ob_start();
    ?>
    <a href="<?php echo esc_url( $url ); ?>" class="mkcp-ty-invoice-btn" target="_blank" rel="noopener">
        <?php echo mkcp_ty_icon( 'download' ); ?>
        <?php esc_html_e( 'Factuur downloaden', 'mk-cart-popup' ); ?>
    </a>
    <?php
    return ob_get_clean();
}

function mkcp_ty_render_steps( bool $is_pickup, bool $is_delivery ): string {
    $last_label = $is_pickup
        ? __( 'Afgehaald', 'mk-cart-popup' )
        : ( $is_delivery ? __( 'Bezorgd', 'mk-cart-popup' ) : __( 'Verzonden', 'mk-cart-popup' ) );

    $steps = [
        [ 'icon' => 'check',   'label' => __( 'Bevestigd', 'mk-cart-popup' ) ],
        [ 'icon' => 'package', 'label' => __( "We bereiden 'm voor", 'mk-cart-popup' ) ],
        [ 'icon' => $is_pickup ? 'map-pin' : 'truck', 'label' => $last_label ],
    ];
    $last_index = count( $steps ) - 1;
    ob_start();
    ?>
    <div class="mkcp-ty-steps">
        <?php foreach ( $steps as $i => $step ) : ?>
        <div class="mkcp-ty-steps__step<?php echo $i === 0 ? ' is-active' : ''; ?>">
            <span class="mkcp-ty-steps__icon"><?php echo mkcp_ty_icon( $step['icon'] ); ?></span>
            <span class="mkcp-ty-steps__label"><?php echo esc_html( $step['label'] ); ?></span>
        </div>
        <?php if ( $i < $last_index ) : ?>
        <span class="mkcp-ty-steps__arrow" aria-hidden="true">›</span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ── Twee-koloms lay-out (hoofdkolom + zijkolom) ───────────────────────────
// WooCommerce's eigen thank-you-template zet het orderoverzicht en eventuele
// betaalinstructies (bv. BACS-bankgegevens) rechtstreeks neer, vóórdat de
// woocommerce_thankyou-hook vuurt — daar bestaat geen losse, aanroepbare
// functie voor. Dat stukje wordt daarom heel kort gebufferd (tussen de
// woocommerce_before_thankyou-hook en het moment dat woocommerce_thankyou
// zelf vuurt), zodat het hieronder als string in de zijkolom geplaatst kan
// worden.
//
// De besteldetails-tabel + klantgegevens roepen we zelf aan
// (woocommerce_order_details_table() — de functie achter WooCommerce's eigen
// prioriteit-10-hook, die hieronder wordt verwijderd) i.p.v. te vertrouwen op
// hook-volgorde: zo krijgen we die HTML gegarandeerd en direct als string
// terug, zonder aannames over wélke prioriteit vóór of ná onze eigen content
// vuurt.
//
// Resultaat: .mkcp-ty-columns heeft maar twee grid-items — de hoofd- en de
// zijkolom-container zelf — niet de losse kaartjes daarbinnen. Elke
// container is vanbinnen gewoon normale block-flow en heeft dus een eigen,
// onafhankelijke hoogte. Dat is precies wat de vorige grid-poging miste: met
// de losse kaartjes zelf als grid-items deelden hoofd- en zijkolom impliciet
// dezelfde rijen, en werd elke rij zo hoog als het langste item erin — met
// lege ruimte onder de kortere buur tot gevolg.

add_action( 'wp', function() {
    if ( ! mkcp_thankyou_enabled() ) return;
    if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) return;
    remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
} );

add_action( 'woocommerce_before_thankyou', function() {
    if ( ! mkcp_thankyou_enabled() ) return;
    ob_start();
} );

add_action( 'woocommerce_thankyou', function( $order_id ) {
    if ( ! mkcp_thankyou_enabled() ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        if ( ob_get_level() ) ob_end_clean();
        return;
    }

    $pre_html = ob_get_level() ? ob_get_clean() : '';

    // De succes-/foutmelding (WooCommerce's eigen <p class="...order-received">,
    // of bij een mislukte betaling "...order-failed") staat altijd als eerste
    // in $pre_html — die splitsen we eraf zodat hij straks volledig-breed
    // boven de kolommen komt te staan i.p.v. in de zijkolom. Geen match?
    // Dan liever niets afsplitsen dan risico op verminkte HTML: alles blijft
    // dan gewoon (onopgesplitst) in de zijkolom staan.
    $success_html = '';
    if ( preg_match( '/^.*?<p\b[^>]*\bwoocommerce-thankyou-order-(received|failed)\b[^>]*>.*?<\/p>/s', $pre_html, $m ) ) {
        $success_html = $m[0];
        $pre_html     = substr( $pre_html, strlen( $m[0] ) );
    }

    $pickup_date   = (string) $order->get_meta( '_mkcp_pickup_date' );
    $delivery_date = (string) $order->get_meta( '_mkcp_delivery_date' );
    $is_pickup     = $pickup_date !== '';
    $is_delivery   = ! $is_pickup && $delivery_date !== '';

    // Stappenstrip krijgt, net als de succesmelding en cross-sell, bewust
    // géén plek in een van de twee kolommen: als eigen, volledig-brede
    // sectie direct onder de succesmelding valt hij niet meer terug tot de
    // breedte van de hoofd- of zijkolom.
    $steps_html = mkcp_ty_render_steps( $is_pickup, $is_delivery );

    $main = '';
    if ( function_exists( 'woocommerce_order_details_table' ) ) {
        ob_start();
        woocommerce_order_details_table( $order_id );
        $main .= ob_get_clean();
    }

    $cfg = mkcp_thankyou_config();

    // Cross-sell krijgt bewust géén plek in de hoofdkolom: als eigen,
    // volledig-brede sectie ná de twee kolommen (net als de succesmelding
    // erboven en de vertrouwensfooter eronder) kan het productengrid over de
    // volle 1080px breedte tonen i.p.v. beperkt tot de smallere hoofdkolom.
    $crosssell_html = $cfg['crosssell_enabled'] ? mkcp_ty_render_crosssell( $order, $cfg ) : '';

    // Bezorg-/afhaalbanner staat in de zijkolom, vóór de bestelgegevens
    // (orderoverzicht + evt. betaalinstructies) — hoort inhoudelijk bij
    // "over deze bestelling", niet bij de hoofdkolom.
    $sidebar = '';
    if ( $is_pickup || $is_delivery ) {
        $sidebar .= mkcp_ty_render_banner( $order, $is_pickup, $pickup_date, $delivery_date );
    }
    $sidebar .= $pre_html; // orderoverzicht + evt. betaalinstructies/bankgegevens
    if ( $is_pickup ) {
        $sidebar .= mkcp_ty_render_pickup_card( $order );
    }
    $sidebar .= mkcp_ty_render_invoice_button( $order );

    echo $success_html;
    echo $steps_html;
    echo '<div class="mkcp-ty-columns">';
    echo '<div class="mkcp-ty-columns__main">' . $main . '</div>';
    echo '<div class="mkcp-ty-columns__sidebar">' . $sidebar . '</div>';
    echo '</div>';
    echo $crosssell_html;
    echo mkcp_ty_render_trust( $cfg );
}, 5 );


// ── 6. Cross-sell op basis van de bestelling ──────────────────────────────────
// Zelfde crosssells/category-vertakking als mkcp_get_crosssell_products()
// (config.php:990-1054), maar op basis van $order->get_items() i.p.v. de
// winkelwagen — de vrij-verzenden-gap-badge uit de winkelwagen-popup-versie
// is hier weggelaten, niet relevant na het afronden van de bestelling.

function mkcp_get_crosssell_products_for_order( WC_Order $order, int $limit = 3, string $mode = 'category' ): array {
    $in_order_ids = [];
    foreach ( $order->get_items() as $item ) {
        $product_id = $item->get_product_id();
        if ( $product_id ) $in_order_ids[] = $product_id;
    }
    $in_order_ids = array_unique( $in_order_ids );
    if ( empty( $in_order_ids ) ) return [];

    $candidate_ids = [];
    foreach ( $in_order_ids as $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) continue;

        if ( $mode === 'crosssells' ) {
            $ids = $product->get_cross_sell_ids();
        } else {
            $terms = get_the_terms( $product->get_id(), 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) continue;
            $query = new WP_Query( [
                'post_type'           => 'product',
                'posts_per_page'      => $limit * 4,
                'post__not_in'        => $in_order_ids,
                'tax_query'           => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => wp_list_pluck( $terms, 'term_id' ) ] ],
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
            ] );
            $ids = $query->posts;
        }
        $candidate_ids = array_merge( $candidate_ids, (array) $ids );
    }

    $candidate_ids = array_unique( array_diff( $candidate_ids, $in_order_ids ) );
    if ( empty( $candidate_ids ) ) return [];

    shuffle( $candidate_ids );
    $products = [];
    foreach ( array_slice( $candidate_ids, 0, $limit * 2 ) as $id ) {
        $p = wc_get_product( $id );
        if ( $p && $p->is_visible() && $p->is_in_stock() ) {
            $products[] = $p;
            if ( count( $products ) >= $limit ) break;
        }
    }
    return $products;
}

function mkcp_ty_render_crosssell( WC_Order $order, array $cfg ): string {
    $main_cfg = mkcp_config();
    $products = mkcp_get_crosssell_products_for_order(
        $order,
        (int) ( $main_cfg['crosssell_limit'] ?? 3 ),
        $main_cfg['crosssell_mode'] ?? 'category'
    );
    if ( empty( $products ) ) return '';
    ob_start();
    ?>
    <div class="mkcp-ty-crosssell">
        <h2 class="mkcp-ty-crosssell__title"><?php echo esc_html( $cfg['crosssell_title'] ?: __( 'Misschien voor je volgende bestelling?', 'mk-cart-popup' ) ); ?></h2>
        <div class="mkcp-ty-crosssell__grid">
            <?php foreach ( $products as $product ) : ?>
            <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="mkcp-ty-crosssell__item">
                <span class="mkcp-ty-crosssell__img"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?></span>
                <span class="mkcp-ty-crosssell__name"><?php echo esc_html( $product->get_name() ); ?></span>
                <span class="mkcp-ty-crosssell__price"><?php echo wc_price( wc_get_price_to_display( $product ) ); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// ── 8. Vertrouwenselementen-footer ─────────────────────────────────────────────
// Geen aparte aan/uit-schakelaar: leeg veld = niet getoond.

function mkcp_ty_render_trust( array $cfg ): string {
    if ( $cfg['trust_return_text'] === '' && $cfg['trust_contact_text'] === '' ) return '';
    ob_start();
    ?>
    <div class="mkcp-ty-trust">
        <?php if ( $cfg['trust_return_text'] !== '' ) : ?>
        <div class="mkcp-ty-trust__item">
            <?php echo mkcp_ty_icon( 'shield' ); ?>
            <?php if ( $cfg['trust_return_url'] !== '' ) : ?>
            <a href="<?php echo esc_url( $cfg['trust_return_url'] ); ?>"><?php echo esc_html( $cfg['trust_return_text'] ); ?></a>
            <?php else : ?>
            <span><?php echo esc_html( $cfg['trust_return_text'] ); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ( $cfg['trust_contact_text'] !== '' ) : ?>
        <div class="mkcp-ty-trust__item">
            <?php echo mkcp_ty_icon( 'mail' ); ?>
            <span><?php echo esc_html( $cfg['trust_contact_text'] ); ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
