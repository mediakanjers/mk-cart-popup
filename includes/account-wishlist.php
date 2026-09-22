<?php
/**
 * MK Cart Popup — Account: Wishlist
 *
 * Bevat de "Wishlist"-view (meerdere lijsten, delen via publieke link) + het
 * bijbehorende publieke, niet-ingelogde leesvenster voor gedeelde lijsten.
 *
 * Prijsdaling-/voorraadmeldingen: dagelijkse cron (prijs) + realtime
 * voorraad-hook, helemaal onderaan dit bestand — leunt op mkcp_account_
 * add_notification() uit account-notifications.php.
 *
 * Levert twee AJAX-bouwstenen die ook buiten de Account-tab gebruikt worden:
 * mkcp_account_wishlist_item_add (voegt altijd toe) en mkcp_account_wishlist_
 * toggle (aan/uit op de standaardlijst) — die laatste voedt het hart-icoon op
 * product-/archiefpagina's, zie includes/wishlist-icon.php + assets/.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'mkcp_account_fragment_handlers', function( $handlers ) {
    $handlers['wishlist'] = 'mkcp_account_render_fragment_wishlist';
    return $handlers;
} );


// ── Helpers ────────────────────────────────────────────────────────────────

function mkcp_account_generate_share_token(): string {
    return bin2hex( random_bytes( 16 ) );
}

function mkcp_account_get_wishlists( int $user_id ): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_wishlists WHERE user_id = %d ORDER BY is_default DESC, sort_order ASC, id ASC",
        $user_id
    ) );
}

function mkcp_account_get_owned_wishlist( int $wishlist_id, int $user_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_wishlists WHERE id = %d AND user_id = %d",
        $wishlist_id, $user_id
    ) );
}

/** Maakt de standaardlijst aan bij het allereerste gebruik — nooit een lege staat zonder lijst. */
function mkcp_account_get_or_create_default_wishlist( int $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_wishlists';

    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d AND is_default = 1 LIMIT 1",
        $user_id
    ) );
    if ( $existing ) return $existing;

    $now = current_time( 'mysql' );
    $wpdb->insert( $table, [
        'user_id'    => $user_id,
        'name'       => __( 'Mijn verlanglijst', 'mk-cart-popup' ),
        'visibility' => 'private',
        'share_token'=> '',
        'is_default' => 1,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ] );

    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ) );
}

function mkcp_account_get_wishlist_items( int $wishlist_id ): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_wishlist_items WHERE wishlist_id = %d ORDER BY added_at DESC",
        $wishlist_id
    ) );
}

/** Alle product-ID's die deze klant heeft bewaard — één query per request (object-cache-baar), zodat een hartje-icoon op een archiefpagina niet N keer los hoeft te bevragen. */
function mkcp_account_get_wishlisted_product_ids( int $user_id ): array {
    static $cache = [];
    if ( isset( $cache[ $user_id ] ) ) return $cache[ $user_id ];

    $cache_key = 'mkcp_wishlisted_' . $user_id;
    $cached    = wp_cache_get( $cache_key, 'mkcp_wishlist' );
    if ( is_array( $cached ) ) return $cache[ $user_id ] = $cached;

    global $wpdb;
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT i.product_id FROM {$wpdb->prefix}mkcp_wishlist_items i
         INNER JOIN {$wpdb->prefix}mkcp_wishlists w ON w.id = i.wishlist_id
         WHERE w.user_id = %d",
        $user_id
    ) );
    $ids = array_map( 'intval', $ids );
    wp_cache_set( $cache_key, $ids, 'mkcp_wishlist', 60 );

    return $cache[ $user_id ] = $ids;
}

function mkcp_account_wishlist_contains_product( int $user_id, int $product_id ): bool {
    return in_array( $product_id, mkcp_account_get_wishlisted_product_ids( $user_id ), true );
}

function mkcp_account_wishlist_clear_product_cache( int $user_id ): void {
    wp_cache_delete( 'mkcp_wishlisted_' . $user_id, 'mkcp_wishlist' );
}

/** Item + wishlist samen opgehaald, met eigendomscheck via de wishlist. */
function mkcp_account_get_owned_wishlist_item( int $item_id, int $user_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT i.* FROM {$wpdb->prefix}mkcp_wishlist_items i
         INNER JOIN {$wpdb->prefix}mkcp_wishlists w ON w.id = i.wishlist_id
         WHERE i.id = %d AND w.user_id = %d",
        $item_id, $user_id
    ) );
}


// ── Fragment: Wishlist ────────────────────────────────────────────────────────

/**
 * Kaart-layout (afbeelding boven, naam/prijs eronder, iconknoppen als eigen rij),
 * zelfde "shop"-gevoel als de Dashboard-productkaarten. De twee interactieve
 * knoppen (winkelwagen/verwijderen) kunnen niet in een <a> genest worden — vandaar
 * een aparte link (afbeelding+naam+prijs) plús een losse actie-rij.
 */
/** Expliciete bevestigingszin van de ingestelde streefprijs (i.p.v. alleen het bedrag in het veld) — bij laden en live na opslaan (assets/account.js). */
function mkcp_account_wishlist_target_price_confirm_text( string $email, $target_price, bool $in_stock = true ): string {
    // Uitverkocht: prijs-georiënteerde tekst is verwarrend, dus aparte tekst
    // (de meldingslogica zelf stuurt sowieso al een losse voorraad-melding,
    // zie mkcp_account_wishlist_notify_back_in_stock()).
    if ( ! $in_stock ) {
        return sprintf(
            /* translators: %s: e-mailadres */
            __( 'Je krijgt een melding op %s zodra dit product weer op voorraad is.', 'mk-cart-popup' ),
            $email
        );
    }
    if ( $target_price === null || $target_price === '' ) {
        return sprintf(
            /* translators: %s: e-mailadres */
            __( 'Je krijgt een melding op %s bij elke prijsdaling.', 'mk-cart-popup' ),
            $email
        );
    }
    return sprintf(
        /* translators: 1: e-mailadres, 2: ingestelde prijs */
        __( 'Je krijgt een melding op %1$s zodra de prijs %2$s of lager is.', 'mk-cart-popup' ),
        $email,
        // wc_price() geeft HTML-entities terug (&euro;, &nbsp;); esc_html() (PHP)
        // en .textContent (JS) decoderen die niet — zonder html_entity_decode()
        // zou de klant letterlijk "&euro;" zien i.p.v. "€".
        html_entity_decode( wp_strip_all_tags( wc_price( (float) $target_price ) ), ENT_QUOTES, 'UTF-8' )
    );
}

function mkcp_account_render_wishlist_item( $item ): string {
    $product   = wc_get_product( $item->variation_id ?: $item->product_id );
    $can_buy   = $product && $product->is_purchasable() && $product->is_in_stock();
    // Eén "waarschuw mij"-bel i.p.v. twee losse toggles voor prijsdaling vs.
    // weer-op-voorraad (de klant wil gewoon "laat het me weten") — de AJAX-handler
    // hieronder zet beide kolommen tegelijk aan/uit, de cron/hook kijkt zelf welke van toepassing is.
    $notify_on = ! empty( $item->notify_price_drop ) || ! empty( $item->notify_back_in_stock );

    // Uitstaand + al eerder verstuurd = automatisch uitgeschakeld (niet de klant zelf) — dat kort uitleggen.
    $notify_was_auto_disabled = ! $notify_on && ( ! empty( $item->last_notified_price_at ) || ! empty( $item->last_notified_stock_at ) );

    ob_start();
    ?>
    <div class="mkcp-wishlist-item" data-item-id="<?php echo esc_attr( $item->id ); ?>">
        <label class="mkcp-wishlist-item__select">
            <input type="checkbox" class="mkcp-checkbox js-mkcp-wishlist-select" value="<?php echo esc_attr( $item->id ); ?>" aria-label="<?php esc_attr_e( 'Selecteren', 'mk-cart-popup' ); ?>">
        </label>
        <a class="mkcp-wishlist-item__link" href="<?php echo $product ? esc_url( get_permalink( $product->get_id() ) ) : '#'; ?>">
            <span class="mkcp-wishlist-item__thumb">
                <?php echo $product ? wp_kses_post( $product->get_image( [ 200, 200 ] ) ) : ''; ?>
                <?php if ( $product && ! $product->is_purchasable() ) : ?>
                    <span class="mkcp-wishlist-item__unavailable"><?php esc_html_e( 'Niet meer beschikbaar', 'mk-cart-popup' ); ?></span>
                <?php elseif ( $product && ! $product->is_in_stock() ) : ?>
                    <span class="mkcp-wishlist-item__unavailable"><?php esc_html_e( 'Uitverkocht', 'mk-cart-popup' ); ?></span>
                <?php endif; ?>
            </span>
            <span class="mkcp-wishlist-item__name"><?php echo $product ? esc_html( $product->get_name() ) : esc_html__( 'Product niet meer beschikbaar', 'mk-cart-popup' ); ?></span>
            <?php if ( $product ) : ?><span class="mkcp-wishlist-item__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span><?php endif; ?>
            <?php
            // Altijd zichtbare voorraadstatus (i.p.v. alleen een badge over de afbeelding) —
            // in één oogopslag zichtbaar welke items wel/niet leverbaar zijn.
            if ( $product ) :
                if ( $can_buy ) :
                    ?><span class="mkcp-wishlist-item__stock mkcp-wishlist-item__stock--in"><span class="mkcp-wishlist-item__stock-dot" aria-hidden="true"></span><?php esc_html_e( 'Op voorraad', 'mk-cart-popup' ); ?></span><?php
                elseif ( ! $product->is_purchasable() ) :
                    ?><span class="mkcp-wishlist-item__stock mkcp-wishlist-item__stock--out"><span class="mkcp-wishlist-item__stock-dot" aria-hidden="true"></span><?php esc_html_e( 'Niet meer beschikbaar', 'mk-cart-popup' ); ?></span><?php
                else :
                    ?><span class="mkcp-wishlist-item__stock mkcp-wishlist-item__stock--out"><span class="mkcp-wishlist-item__stock-dot" aria-hidden="true"></span><?php esc_html_e( 'Uitverkocht', 'mk-cart-popup' ); ?></span><?php
                endif;
            endif;
            ?>
        </a>
        <span class="mkcp-wishlist-item__actions">
            <?php if ( $can_buy ) : ?>
                <button type="button" class="mkcp-icon-btn js-mkcp-wishlist-to-cart" aria-label="<?php esc_attr_e( 'Naar winkelwagen', 'mk-cart-popup' ); ?>" title="<?php esc_attr_e( 'Naar winkelwagen', 'mk-cart-popup' ); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
                </button>
            <?php endif; ?>
            <?php
            // Expliciet het e-mailadres vermelden (i.p.v. alleen "je krijgt een e-mail")
            // — relevant bij meerdere e-mailadressen of twijfel over het account.
            $notify_email = wp_get_current_user()->user_email;
            $notify_off_text = sprintf(
                /* translators: %s: e-mailadres van de klant */
                $notify_was_auto_disabled
                    ? __( 'Melding is automatisch uitgezet nadat je hierover al een melding kreeg. Klik om opnieuw aan te zetten.', 'mk-cart-popup' )
                    : __( 'Melding aanzetten: je krijgt een e-mail op %s zodra de prijs van dit product daalt, of zodra het weer op voorraad is.', 'mk-cart-popup' ),
                $notify_email
            );
            $notify_on_text  = sprintf(
                /* translators: %s: e-mailadres van de klant */
                __( 'Melding staat aan — je krijgt bericht op %s bij een prijsdaling of als het weer op voorraad is. Klik om uit te zetten.', 'mk-cart-popup' ),
                $notify_email
            );
            ?>
            <button type="button" class="mkcp-icon-btn js-mkcp-wishlist-notify<?php echo $notify_on ? ' is-active' : ''; ?>" aria-label="<?php echo esc_attr( $notify_on ? $notify_on_text : $notify_off_text ); ?>" title="<?php echo esc_attr( $notify_on ? $notify_on_text : $notify_off_text ); ?>" aria-pressed="<?php echo $notify_on ? 'true' : 'false'; ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="<?php echo $notify_on ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1112 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 21a2 2 0 004 0"/></svg>
            </button>
            <button type="button" class="mkcp-icon-btn mkcp-icon-btn--danger js-mkcp-wishlist-remove" aria-label="<?php esc_attr_e( 'Verwijderen', 'mk-cart-popup' ); ?>" title="<?php esc_attr_e( 'Verwijderen', 'mk-cart-popup' ); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
            </button>
        </span>
        <?php if ( $notify_was_auto_disabled ) : ?>
            <small class="mkcp-wishlist-item__auto-off-note"><?php esc_html_e( 'Melding is automatisch uitgezet nadat je hierover een melding kreeg — zet de bel opnieuw aan om weer gewaarschuwd te worden.', 'mk-cart-popup' ); ?></small>
        <?php endif; ?>
        <?php if ( $notify_on && $product ) :
            // Optioneel: eigen gewenste-prijsgrens i.p.v. "waarschuw bij elke daling"
            // (leeg = terug naar "elke daling" t.o.v. price_at_add).
            //
            // Bewust type="text" i.p.v. type="number": een natief number-veld accepteert
            // meestal alleen een punt als decimaalteken (NL-komma wordt ongeldig — de
            // "blijft op centen hangen"-klacht) en reageert op muiswiel-scroll door de
            // waarde stilletjes te wijzigen bij focus. inputmode="decimal" geeft op mobiel
            // alsnog een numeriek toetsenbord; komma/punt-normalisatie in account.js én server-side.
            $target_value = ( $item->target_price !== null && $item->target_price !== '' )
                ? number_format( (float) $item->target_price, 2, ',', '' )
                : '';
            ?>
            <span class="mkcp-wishlist-item__target-price">
                <label>
                    <span class="mkcp-wishlist-item__target-price-label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        <?php esc_html_e( 'Meld bij prijs vanaf', 'mk-cart-popup' ); ?>
                    </span>
                    <span class="mkcp-wishlist-item__target-price-field">
                        <span class="mkcp-wishlist-item__target-price-currency"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
                        <input
                            type="text"
                            inputmode="decimal"
                            class="js-mkcp-wishlist-target-price"
                            value="<?php echo esc_attr( $target_value ); ?>"
                            placeholder="<?php echo esc_attr( number_format( (float) $item->price_at_add, 2, ',', '' ) ); ?>"
                            aria-label="<?php esc_attr_e( 'Gewenste prijs — leeg laten om bij elke prijsdaling gemeld te worden', 'mk-cart-popup' ); ?>"
                        >
                    </span>
                </label>
                <small class="mkcp-wishlist-item__target-price-confirm js-mkcp-wishlist-target-price-confirm" data-email="<?php echo esc_attr( $notify_email ); ?>">
                    <?php echo esc_html( mkcp_account_wishlist_target_price_confirm_text( $notify_email, $item->target_price, $can_buy ) ); ?>
                </small>
            </span>
            <span class="mkcp-wishlist-item__notify-channels">
                <label>
                    <input type="checkbox" class="mkcp-checkbox js-mkcp-wishlist-notify-channel" data-item-id="<?php echo esc_attr( $item->id ); ?>" data-channel="email" <?php checked( ! empty( $item->notify_via_email ) ); ?>>
                    <?php esc_html_e( 'E-mail', 'mk-cart-popup' ); ?>
                </label>
                <label>
                    <input type="checkbox" class="mkcp-checkbox js-mkcp-wishlist-notify-channel" data-item-id="<?php echo esc_attr( $item->id ); ?>" data-channel="dashboard" <?php checked( ! empty( $item->notify_via_dashboard ) ); ?>>
                    <?php esc_html_e( 'Melding in dashboard', 'mk-cart-popup' ); ?>
                </label>
            </span>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/** $all_wishlists is optioneel — alleen nodig voor de "Verplaatsen naar"-bulk-actie; zonder verschijnt simpelweg geen verplaats-optie. */
function mkcp_account_render_wishlist_list( $wishlist, array $items, array $all_wishlists = [] ): string {
    $share_url = $wishlist->visibility === 'shared' && $wishlist->share_token
        ? add_query_arg( 'mkcp_wishlist', $wishlist->share_token, home_url( '/' ) )
        : '';
    $other_wishlists = array_filter( $all_wishlists, function( $w ) use ( $wishlist ) { return (int) $w->id !== (int) $wishlist->id; } );

    ob_start();
    ?>
    <div class="mkcp-wishlist-list" data-wishlist-id="<?php echo esc_attr( $wishlist->id ); ?>">
        <div class="mkcp-wishlist-list__header">
            <h2>
                <?php echo esc_html( $wishlist->name ); ?>
                <span class="mkcp-wishlist-count-badge"><?php
                    printf(
                        /* translators: %d: aantal items */
                        esc_html( _n( '%d item', '%d items', count( $items ), 'mk-cart-popup' ) ),
                        count( $items )
                    );
                ?></span>
            </h2>
            <div class="mkcp-wishlist-list__actions">
                <?php if ( ! empty( $items ) ) : ?>
                    <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-wishlist-select-all" data-wishlist-id="<?php echo esc_attr( $wishlist->id ); ?>"><?php esc_html_e( 'Alles selecteren', 'mk-cart-popup' ); ?></button>
                <?php endif; ?>
                <label class="mkcp-switch">
                    <input type="checkbox" class="js-mkcp-wishlist-share-toggle" <?php checked( $wishlist->visibility, 'shared' ); ?>>
                    <span class="mkcp-switch__track" aria-hidden="true"></span>
                    <?php esc_html_e( 'Delen via link', 'mk-cart-popup' ); ?>
                </label>
                <?php if ( ! $wishlist->is_default ) : ?>
                    <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-wishlist-delete"><?php esc_html_e( 'Lijst verwijderen', 'mk-cart-popup' ); ?></button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( ! empty( $items ) ) : ?>
            <p class="mkcp-wishlist-hint">
                <span class="mkcp-wishlist-hint__icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1112 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 21a2 2 0 004 0"/></svg></span>
                <?php esc_html_e( 'Zet de bel bij een product aan om automatisch een e-mail te krijgen zodra de prijs daalt of het weer op voorraad is.', 'mk-cart-popup' ); ?>
            </p>
        <?php endif; ?>

        <?php if ( $share_url ) : ?>
            <div class="mkcp-wishlist-share-url">
                <input type="text" readonly value="<?php echo esc_attr( $share_url ); ?>" class="js-mkcp-wishlist-share-input">
                <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-wishlist-share-copy"><?php esc_html_e( 'Kopiëren', 'mk-cart-popup' ); ?></button>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $items ) ) : ?>
            <?php // Verborgen tot iets geselecteerd is via de checkboxes hieronder (account.js) — selecteren IS de trigger. ?>
            <div class="mkcp-wishlist-bulkbar" id="mkcp-wishlist-bulkbar-<?php echo esc_attr( $wishlist->id ); ?>" data-wishlist-id="<?php echo esc_attr( $wishlist->id ); ?>" hidden>
                <span class="mkcp-wishlist-bulkbar__count"><span class="js-mkcp-wishlist-bulk-count">0</span> <?php esc_html_e( 'geselecteerd', 'mk-cart-popup' ); ?></span>
                <span class="mkcp-wishlist-bulkbar__actions">
                    <button type="button" class="mkcp-btn mkcp-btn--secondary js-mkcp-wishlist-bulk-cart"><?php esc_html_e( 'Naar winkelwagen', 'mk-cart-popup' ); ?></button>
                    <?php if ( $other_wishlists ) : ?>
                        <label class="mkcp-wishlist-bulkbar__move">
                            <?php esc_html_e( 'Verplaatsen naar', 'mk-cart-popup' ); ?>
                            <select class="js-mkcp-wishlist-bulk-move-target">
                                <option value=""><?php esc_html_e( '— kies een lijst —', 'mk-cart-popup' ); ?></option>
                                <?php foreach ( $other_wishlists as $ow ) : ?>
                                    <option value="<?php echo esc_attr( $ow->id ); ?>"><?php echo esc_html( $ow->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-wishlist-bulk-delete"><?php esc_html_e( 'Verwijderen', 'mk-cart-popup' ); ?></button>
                </span>
            </div>
        <?php endif; ?>

        <?php if ( empty( $items ) ) : ?>
            <div class="mkcp-dash-empty-inline">
                <span class="mkcp-dash-empty-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.35-9.5-8.5C1 8 2 4.5 5.5 4.5c2 0 3.5 1.5 4.5 3 1-1.5 2.5-3 4.5-3 3.5 0 4.5 3.5 3 7C19 15.65 12 20 12 20z"/></svg></span>
                <p class="mkcp-dash-empty__title"><?php esc_html_e( 'Nog niets bewaard in deze lijst', 'mk-cart-popup' ); ?></p>
                <p><?php esc_html_e( 'Bewaar producten die je leuk vindt om ze later snel terug te vinden.', 'mk-cart-popup' ); ?></p>
                <a class="mkcp-btn mkcp-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Ontdek producten', 'mk-cart-popup' ); ?></a>
            </div>
        <?php else : ?>
            <div class="mkcp-dash-scroller">
                <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--prev" aria-label="<?php esc_attr_e( 'Vorige', 'mk-cart-popup' ); ?>">&#8249;</button>
                <div class="mkcp-wishlist-items">
                    <?php foreach ( $items as $item ) echo mkcp_account_render_wishlist_item( $item ); ?>
                </div>
                <button type="button" class="mkcp-dash-scroller__nav mkcp-dash-scroller__nav--next" aria-label="<?php esc_attr_e( 'Volgende', 'mk-cart-popup' ); ?>">&#8250;</button>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function mkcp_account_render_fragment_wishlist(): string {
    $user_id   = get_current_user_id();
    mkcp_account_get_or_create_default_wishlist( $user_id ); // garandeert minstens 1 lijst
    $wishlists = mkcp_account_get_wishlists( $user_id );

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <div class="mkcp-account-view__header">
            <h1><?php esc_html_e( 'Wishlist', 'mk-cart-popup' ); ?></h1>
        </div>

        <?php foreach ( $wishlists as $wishlist ) : ?>
            <?php echo mkcp_account_render_wishlist_list( $wishlist, mkcp_account_get_wishlist_items( $wishlist->id ), $wishlists ); ?>
        <?php endforeach; ?>

        <form class="mkcp-account-form mkcp-wishlist-new-form" id="mkcp-wishlist-new-form">
            <div class="mkcp-account-form-row">
                <label for="mkcp-wishlist-new-name"><?php esc_html_e( 'Nieuwe lijst (bv. "Verjaardag")', 'mk-cart-popup' ); ?></label>
                <input type="text" id="mkcp-wishlist-new-name" name="name" required>
            </div>
            <div class="mkcp-account-form-actions">
                <button type="submit" class="mkcp-btn mkcp-btn--secondary"><?php esc_html_e( '+ Lijst toevoegen', 'mk-cart-popup' ); ?></button>
                <span class="mkcp-account-form-status" data-form-status="wishlist-new" role="status" aria-live="polite"></span>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}


// ── AJAX: nieuwe lijst ─────────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_wishlist_create', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id = get_current_user_id();
    $name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    if ( $name === '' ) wp_send_json_error( [ 'code' => 'missing_name' ], 400 );

    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert( $wpdb->prefix . 'mkcp_wishlists', [
        'user_id'    => $user_id,
        'name'       => $name,
        'visibility' => 'private',
        'share_token'=> '',
        'is_default' => 0,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ] );

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist() ] );
} );


// ── AJAX: lijst verwijderen ────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_wishlist_delete', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id      = get_current_user_id();
    $wishlist_id  = isset( $_POST['wishlist_id'] ) ? absint( $_POST['wishlist_id'] ) : 0;
    $wishlist     = $wishlist_id ? mkcp_account_get_owned_wishlist( $wishlist_id, $user_id ) : null;

    if ( ! $wishlist ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );
    if ( $wishlist->is_default ) wp_send_json_error( [ 'code' => 'cannot_delete_default', 'message' => __( 'De standaardlijst kan niet verwijderd worden.', 'mk-cart-popup' ) ], 400 );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'mkcp_wishlist_items', [ 'wishlist_id' => $wishlist->id ], [ '%d' ] );
    $wpdb->delete( $wpdb->prefix . 'mkcp_wishlists', [ 'id' => $wishlist->id ], [ '%d' ] );
    mkcp_account_wishlist_clear_product_cache( $user_id );
    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist() ] );
} );


// ── AJAX: delen aan/uit ────────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_wishlist_share_toggle', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id     = get_current_user_id();
    $wishlist_id = isset( $_POST['wishlist_id'] ) ? absint( $_POST['wishlist_id'] ) : 0;
    $enabled     = ! empty( $_POST['enabled'] );
    $wishlist    = $wishlist_id ? mkcp_account_get_owned_wishlist( $wishlist_id, $user_id ) : null;

    if ( ! $wishlist ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );

    global $wpdb;
    $data = [ 'visibility' => $enabled ? 'shared' : 'private', 'updated_at' => current_time( 'mysql' ) ];
    // Token blijft staan zodra 'ie eenmaal bestaat — uitzetten verbergt 'm
    // simpelweg weer (visibility='private'), zodat opnieuw aanzetten dezelfde
    // link teruggeeft i.p.v. eerder gedeelde links stilzwijgend te breken.
    if ( $enabled && ! $wishlist->share_token ) {
        $data['share_token'] = mkcp_account_generate_share_token();
    }
    $wpdb->update( $wpdb->prefix . 'mkcp_wishlists', $data, [ 'id' => $wishlist->id ] );

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist() ] );
} );


// ── AJAX: item toevoegen ───────────────────────────────────────────────────────
//
// Herbruikbare bouwsteen: voegt altijd toe (het aan/uit-schakelen dat het
// hart-icoon nodig heeft zit in mkcp_account_wishlist_toggle hieronder).

add_action( 'wp_ajax_mkcp_account_wishlist_item_add', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id      = get_current_user_id();
    $product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
    $wishlist_id  = isset( $_POST['wishlist_id'] ) ? absint( $_POST['wishlist_id'] ) : 0;

    $product = wc_get_product( $variation_id ?: $product_id );
    if ( ! $product ) wp_send_json_error( [ 'code' => 'invalid_product' ], 400 );

    // Variatie moet ook echt bij dit hoofdproduct horen — anders belandt er een
    // inconsistente rij in de tabel die later nergens meer fatsoenlijk te
    // renderen is (product X met een variatie van product Y).
    if ( $variation_id && ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) ) {
        wp_send_json_error( [ 'code' => 'invalid_variation' ], 400 );
    }

    $wishlist = $wishlist_id ? mkcp_account_get_owned_wishlist( $wishlist_id, $user_id ) : null;
    if ( ! $wishlist ) $wishlist = mkcp_account_get_or_create_default_wishlist( $user_id );

    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}mkcp_wishlist_items
            (wishlist_id, product_id, variation_id, note, price_at_add, notify_price_drop, notify_back_in_stock, added_at)
         VALUES (%d, %d, %d, '', %f, 0, 0, %s)",
        $wishlist->id, $product_id, $variation_id, (float) $product->get_price(), current_time( 'mysql' )
    ) );
    mkcp_account_wishlist_clear_product_cache( $user_id );
    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist() ] );
} );


// ── AJAX: hart-icoon aan/uit (product-/archiefpagina's) ─────────────────────────
//
// Los van mkcp_account_wishlist_item_add (voegt altijd toe): dit schakelt — wat
// een hart-icoon nodig heeft. Werkt altijd op de standaardlijst; een andere lijst
// kiezen kan alleen via de Wishlist-tab zelf (bewust de simpele variant).

add_action( 'wp_ajax_mkcp_account_wishlist_toggle', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id    = get_current_user_id();
    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    if ( ! $product_id || ! wc_get_product( $product_id ) ) wp_send_json_error( [ 'code' => 'invalid_product' ], 400 );

    global $wpdb;
    $wishlist   = mkcp_account_get_or_create_default_wishlist( $user_id );
    $is_in_list = mkcp_account_wishlist_contains_product( $user_id, $product_id );

    if ( $is_in_list ) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mkcp_wishlist_items WHERE wishlist_id = %d AND product_id = %d",
            $wishlist->id, $product_id
        ) );
    } else {
        $product = wc_get_product( $product_id );
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}mkcp_wishlist_items
                (wishlist_id, product_id, variation_id, note, price_at_add, notify_price_drop, notify_back_in_stock, added_at)
             VALUES (%d, %d, 0, '', %f, 0, 0, %s)",
            $wishlist->id, $product_id, (float) $product->get_price(), current_time( 'mysql' )
        ) );
    }
    mkcp_account_wishlist_clear_product_cache( $user_id );
    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [ 'in_wishlist' => ! $is_in_list ] );
} );


// ── AJAX: item verwijderen ──────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_wishlist_item_remove', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id = get_current_user_id();
    $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
    $item    = $item_id ? mkcp_account_get_owned_wishlist_item( $item_id, $user_id ) : null;

    if ( ! $item ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'mkcp_wishlist_items', [ 'id' => $item->id ], [ '%d' ] );
    mkcp_account_wishlist_clear_product_cache( $user_id );
    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist() ] );
} );


// ── AJAX: prijsdaling-/voorraadmelding aan/uit ────────────────────────────────

add_action( 'wp_ajax_mkcp_account_wishlist_item_notify_toggle', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id = get_current_user_id();
    $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
    $item    = $item_id ? mkcp_account_get_owned_wishlist_item( $item_id, $user_id ) : null;

    if ( ! $item ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );

    $enable = empty( $item->notify_price_drop ) && empty( $item->notify_back_in_stock ) ? 1 : 0;

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'mkcp_wishlist_items',
        [ 'notify_price_drop' => $enable, 'notify_back_in_stock' => $enable ],
        [ 'id' => $item->id ],
        [ '%d', '%d' ],
        [ '%d' ]
    );

    // Ook een melding in het meldingencentrum, niet alleen de bevestigingstekst op de pagina zelf.
    if ( $enable && function_exists( 'mkcp_account_add_notification' ) ) {
        $product = wc_get_product( $item->variation_id ?: $item->product_id );
        mkcp_account_add_notification(
            $user_id,
            'wishlist',
            __( 'Melding ingesteld', 'mk-cart-popup' ),
            sprintf(
                /* translators: %s: productnaam */
                __( 'Je krijgt voortaan bericht over "%s" bij een prijsdaling of als het weer op voorraad is.', 'mk-cart-popup' ),
                $product ? $product->get_name() : __( 'dit product', 'mk-cart-popup' )
            ),
            '#/wishlist',
            'product',
            $product ? $product->get_id() : 0
        );
    }

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist() ] );
} );


// ── AJAX: gewenste prijsgrens instellen ───────────────────────────────────────
//
// Optionele verfijning op de aan/uit-toggle hierboven: "waarschuw pas vanaf déze
// prijs" i.p.v. elke daling. Leeg/niet-numeriek = "geen grens" (cron valt terug op
// elke daling t.o.v. price_at_add). Geen volledige fragment-herrender als response
// (zou focus uit het invoerveld halen) — alleen de opgeslagen waarde terug, zie account.js.

add_action( 'wp_ajax_mkcp_account_wishlist_item_target_price', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id = get_current_user_id();
    $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
    $item    = $item_id ? mkcp_account_get_owned_wishlist_item( $item_id, $user_id ) : null;

    if ( ! $item ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );

    $raw = isset( $_POST['target_price'] ) ? sanitize_text_field( wp_unslash( $_POST['target_price'] ) ) : '';
    $raw = str_replace( ',', '.', $raw );

    $user_email = wp_get_current_user()->user_email;
    $product    = wc_get_product( $item->variation_id ?: $item->product_id );
    $in_stock   = $product && $product->is_purchasable() && $product->is_in_stock();

    global $wpdb;
    if ( $raw === '' || ! is_numeric( $raw ) ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}mkcp_wishlist_items SET target_price = NULL WHERE id = %d",
            $item->id
        ) );
        wp_send_json_success( [
            'target_price'    => null,
            'confirm_text'    => mkcp_account_wishlist_target_price_confirm_text( $user_email, null, $in_stock ),
        ] );
        return;
    }

    $target_price = max( 0, round( (float) $raw, 2 ) );
    $wpdb->update(
        $wpdb->prefix . 'mkcp_wishlist_items',
        [ 'target_price' => $target_price ],
        [ 'id' => $item->id ],
        [ '%f' ],
        [ '%d' ]
    );

    wp_send_json_success( [
        'target_price'         => $target_price,
        // Kant-en-klaar met komma opgemaakt — account.js zet dit direct in het veld terug.
        'target_price_display' => number_format( $target_price, 2, ',', '' ),
        // Bevestigingszin live bijwerken zonder de hele kaart te herrenderen (focus zou verloren gaan).
        'confirm_text'          => mkcp_account_wishlist_target_price_confirm_text( $user_email, $target_price, $in_stock ),
    ] );
} );


// ── AJAX: e-mail- vs. dashboardmelding los in-/uitschakelen ───────────

add_action( 'wp_ajax_mkcp_account_wishlist_item_notify_channel', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id = get_current_user_id();
    $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
    $item    = $item_id ? mkcp_account_get_owned_wishlist_item( $item_id, $user_id ) : null;
    if ( ! $item ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );

    $channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
    $column  = [ 'email' => 'notify_via_email', 'dashboard' => 'notify_via_dashboard' ][ $channel ] ?? '';
    if ( ! $column ) wp_send_json_error( [ 'code' => 'invalid_channel' ], 400 );

    $enabled = ! empty( $_POST['enabled'] ) ? 1 : 0;

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'mkcp_wishlist_items',
        [ $column => $enabled ],
        [ 'id' => $item->id ],
        [ '%d' ],
        [ '%d' ]
    );

    wp_send_json_success( [ 'channel' => $channel, 'enabled' => (bool) $enabled ] );
} );


// ── AJAX: naar winkelwagen ───────────────────────────────────────────────────────

/** Dunne wikkel om de gedeelde "Elke"-attributen-helper uit mk-cart-popup.php: [] = niets te fixen, false = klant moet zelf kiezen. */
function mkcp_account_wishlist_variation_attributes( int $variation_id ) {
    if ( ! $variation_id || ! function_exists( 'mkcp_resolve_any_attribute_variation' ) ) return [];
    return mkcp_resolve_any_attribute_variation( $variation_id );
}

add_action( 'wp_ajax_mkcp_account_wishlist_item_to_cart', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id = get_current_user_id();
    $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
    $item    = $item_id ? mkcp_account_get_owned_wishlist_item( $item_id, $user_id ) : null;

    if ( ! $item ) wp_send_json_error( [ 'code' => 'not_found' ], 404 );

    $variation_id = (int) $item->variation_id;
    if ( $variation_id ) {
        $variation_product = wc_get_product( $variation_id );
        $product_id = $variation_product ? $variation_product->get_parent_id() : 0;
    } else {
        $product_id = (int) $item->product_id;
    }

    // Zelfde "Elke"-attributen-fix als bij het normale add-to-cart-formulier:
    // zonder expliciete waarden weigert WC_Cart::add_to_cart() zo'n variatie.
    // In de wishlist is alleen de variatie bewaard, geen keuzes — vandaar de
    // terugval op de standaardattributen van het hoofdproduct in de helper.
    $variation = mkcp_account_wishlist_variation_attributes( $variation_id );
    if ( false === $variation ) {
        wp_send_json_error( [ 'code' => 'options_required', 'message' => __( 'Kies eerst de opties voor dit product op de productpagina.', 'mk-cart-popup' ) ], 400 );
    }

    $added = WC()->cart->add_to_cart( $product_id, 1, $variation_id ?: 0, $variation );
    if ( ! $added ) {
        wp_send_json_error( [ 'code' => 'add_to_cart_failed', 'message' => __( 'Kon niet worden toegevoegd (niet meer beschikbaar).', 'mk-cart-popup' ) ], 400 );
    }

    wp_send_json_success( [
        'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', [] ),
        'cart_hash' => WC()->cart->get_cart_hash(),
    ] );
} );


// ── AJAX: bulk-acties (meerdere geselecteerde items tegelijk) ────────────────
//
// Alle drie doen dezelfde eigendomscheck (nooit een gepost item_id blind
// vertrouwen), maar gebatcht: één query die de geposte ID's terugbrengt tot de
// items die écht van deze klant zijn, daarna één schrijfquery over die
// gevalideerde subset i.p.v. twee queries per item.

function mkcp_account_wishlist_bulk_item_ids(): array {
    $raw = isset( $_POST['item_ids'] ) ? (array) wp_unslash( $_POST['item_ids'] ) : [];
    $ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
    return array_slice( $ids, 0, 50 ); // zelfde soort praktisch plafond als het adresboek — geen onbeperkte lus op een geposte array.
}

/** Gebatchte variant van mkcp_account_get_owned_wishlist_item(): alleen de items uit $item_ids die van deze klant zijn. */
function mkcp_account_get_owned_wishlist_items( array $item_ids, int $user_id ): array {
    if ( ! $item_ids ) return [];

    global $wpdb;
    $placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT i.* FROM {$wpdb->prefix}mkcp_wishlist_items i
         INNER JOIN {$wpdb->prefix}mkcp_wishlists w ON w.id = i.wishlist_id
         WHERE i.id IN ({$placeholders}) AND w.user_id = %d",
        array_merge( $item_ids, [ $user_id ] )
    ) );
}

add_action( 'wp_ajax_mkcp_account_wishlist_bulk_delete', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id  = get_current_user_id();
    $item_ids = mkcp_account_wishlist_bulk_item_ids();
    if ( ! $item_ids ) wp_send_json_error( [ 'code' => 'no_items' ], 400 );

    global $wpdb;
    $owned_ids = array_map( 'intval', wp_list_pluck( mkcp_account_get_owned_wishlist_items( $item_ids, $user_id ), 'id' ) );
    $deleted   = count( $owned_ids );
    if ( $owned_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $owned_ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}mkcp_wishlist_items WHERE id IN ({$placeholders})",
            $owned_ids
        ) );
    }

    mkcp_account_wishlist_clear_product_cache( $user_id );
    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist(), 'deleted' => $deleted ] );
} );

add_action( 'wp_ajax_mkcp_account_wishlist_bulk_move', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id           = get_current_user_id();
    $item_ids          = mkcp_account_wishlist_bulk_item_ids();
    $target_wishlist_id = isset( $_POST['target_wishlist_id'] ) ? absint( $_POST['target_wishlist_id'] ) : 0;
    $target             = $target_wishlist_id ? mkcp_account_get_owned_wishlist( $target_wishlist_id, $user_id ) : null;

    if ( ! $item_ids || ! $target ) wp_send_json_error( [ 'code' => 'invalid_request' ], 400 );

    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_wishlist_items';

    // De doellijst kan hetzelfde product al bevatten (UNIQUE KEY wishlist_id+
    // product_id+variation_id) — dan is "verplaatsen" in de praktijk gewoon
    // "hier weghalen, daar stond het al", geen fout. Eén keer de inhoud van de
    // doellijst ophalen volstaat om dat voor de hele selectie te bepalen.
    $in_target = [];
    foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT product_id, variation_id FROM {$table} WHERE wishlist_id = %d", $target->id ) ) as $row ) {
        $in_target[ $row->product_id . ':' . $row->variation_id ] = true;
    }

    $to_move   = [];
    $duplicate = [];
    foreach ( mkcp_account_get_owned_wishlist_items( $item_ids, $user_id ) as $item ) {
        if ( (int) $item->wishlist_id === (int) $target->id ) continue;
        if ( isset( $in_target[ $item->product_id . ':' . $item->variation_id ] ) ) {
            $duplicate[] = (int) $item->id;
        } else {
            $to_move[] = (int) $item->id;
        }
    }

    if ( $to_move ) {
        $placeholders = implode( ',', array_fill( 0, count( $to_move ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET wishlist_id = %d WHERE id IN ({$placeholders})",
            array_merge( [ $target->id ], $to_move )
        ) );
    }
    if ( $duplicate ) {
        $placeholders = implode( ',', array_fill( 0, count( $duplicate ), '%d' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $duplicate ) );
    }

    $moved = count( $to_move ) + count( $duplicate );

    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [ 'html' => mkcp_account_render_fragment_wishlist(), 'moved' => $moved ] );
} );

add_action( 'wp_ajax_mkcp_account_wishlist_bulk_to_cart', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    if ( ! mkcp_account_is_active() ) wp_send_json_error( [ 'code' => 'not_available' ], 403 );

    $user_id  = get_current_user_id();
    $item_ids = mkcp_account_wishlist_bulk_item_ids();
    if ( ! $item_ids ) wp_send_json_error( [ 'code' => 'no_items' ], 400 );

    $owned = mkcp_account_get_owned_wishlist_items( $item_ids, $user_id );

    $added  = 0;
    $failed = count( $item_ids ) - count( $owned );
    foreach ( $owned as $item ) {
        $variation_id = (int) $item->variation_id;
        if ( $variation_id ) {
            $variation_product = wc_get_product( $variation_id );
            $product_id = $variation_product ? $variation_product->get_parent_id() : 0;
        } else {
            $product_id = (int) $item->product_id;
        }

        // Zelfde "Elke"-attributen-fix als bij het normale add-to-cart-formulier.
        $variation = mkcp_account_wishlist_variation_attributes( $variation_id );
        if ( false === $variation ) { $failed++; continue; }

        if ( $product_id && WC()->cart->add_to_cart( $product_id, 1, $variation_id ?: 0, $variation ) ) {
            $added++;
        } else {
            $failed++;
        }
    }

    wp_send_json_success( [
        'added'     => $added,
        'failed'    => $failed,
        'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', [] ),
        'cart_hash' => WC()->cart->get_cart_hash(),
    ] );
} );


// ── Publieke, niet-ingelogde weergave van een gedeelde lijst ────────────────────
//
// Geen rewrite-endpoint/flush_rewrite_rules nodig — een simpele querystring-
// parameter op de homepage-URL volstaat, zelfde voorkeur als de hash-routing binnen Account zelf.

add_action( 'template_redirect', function() {
    if ( ! isset( $_GET['mkcp_wishlist'] ) ) return;

    $token = sanitize_text_field( wp_unslash( $_GET['mkcp_wishlist'] ) );
    global $wpdb;
    $wishlist = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mkcp_wishlists WHERE share_token = %s AND visibility = 'shared'",
        $token
    ) );

    nocache_headers();
    header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

    if ( ! $wishlist ) {
        status_header( 404 );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Lijst niet gevonden', 'mk-cart-popup' ) . '</title></head><body style="font-family:sans-serif;text-align:center;padding:4em;">'
            . '<p>' . esc_html__( 'Deze wishlist bestaat niet (meer), of wordt niet langer gedeeld.', 'mk-cart-popup' ) . '</p></body></html>';
        exit;
    }

    $items = mkcp_account_get_wishlist_items( $wishlist->id );
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html( $wishlist->name ); ?> — <?php bloginfo( 'name' ); ?></title>
    <?php wp_head(); ?>
    <style>
        body { max-width: 720px; margin: 3em auto; padding: 0 1.25em; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .mkcp-shared-item { display: grid; grid-template-columns: 60px 1fr auto; align-items: center; gap: 1em; padding: 0.75em 0; border-bottom: 1px solid #e8e8e8; }
        .mkcp-shared-item img { border-radius: 8px; }
        .mkcp-shared-unavailable { color: #888; font-size: 0.85em; }
    </style>
    </head>
    <body <?php body_class(); ?>>
    <h1><?php echo esc_html( $wishlist->name ); ?></h1>
    <p><?php esc_html_e( 'Gedeelde verlanglijst', 'mk-cart-popup' ); ?></p>
    <?php if ( empty( $items ) ) : ?>
        <p><?php esc_html_e( 'Deze lijst is nog leeg.', 'mk-cart-popup' ); ?></p>
    <?php else : ?>
        <?php foreach ( $items as $item ) :
            $product = wc_get_product( $item->variation_id ?: $item->product_id );
            if ( ! $product ) continue;
            ?>
            <div class="mkcp-shared-item">
                <span><?php echo wp_kses_post( $product->get_image( [ 60, 60 ] ) ); ?></span>
                <span>
                    <?php echo esc_html( $product->get_name() ); ?><br>
                    <?php echo wp_kses_post( $product->get_price_html() ); ?>
                    <?php if ( ! $product->is_in_stock() ) : ?>
                        <div class="mkcp-shared-unavailable"><?php esc_html_e( 'Uitverkocht', 'mk-cart-popup' ); ?></div>
                    <?php endif; ?>
                </span>
                <span>
                    <?php // add_to_cart_url() is voor AJAX-knoppen (?add-to-cart=ID), geen werkende link op zichzelf — hier de echte productpagina. ?>
                    <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>"><?php esc_html_e( 'Bekijk product', 'mk-cart-popup' ); ?></a>
                </span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
    exit;
} );


// ── Prijsdaling-/voorraadmeldingen ───────────────────────────────────────────
//
// Twee aparte mechanismen: prijs verandert niet "even", dus een dagelijkse cron
// (goedkoper dan op elke prijswijziging haken); voorraad moet wél meteen gemeld
// worden, vandaar de directe woocommerce_(variation_)set_stock-hook.

add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'mkcp_account_wishlist_price_check' ) ) {
        wp_schedule_event( time(), 'daily', 'mkcp_account_wishlist_price_check' );
    }
} );

/**
 * Zelfde opzet als mkcp_pu_ready_send_email()/mkcp_ac_send_email(): inline-HTML
 * wrap, wp_mail_content_type via een named callback (remove_filter werkt niet op
 * anonieme functies). Naast de in-app melding, want die zie je pas bij toevallig inloggen.
 */
function mkcp_account_wishlist_send_notify_email( int $user_id, string $subject, string $body_text, string $cta_url = '' ): bool {
    // Losse schakelaar, bewust niet gekoppeld aan account_notifications_enabled
    // (het hele meldingencentrum-tabblad) — winkelier kan meldingencentrum en
    // e-mails onafhankelijk van elkaar aan/uit zetten.
    if ( ! mkcp_account_module_enabled( 'wishlist_emails' ) ) return false;

    $user = get_userdata( $user_id );
    if ( ! $user || ! is_email( $user->user_email ) ) return false;

    // 'raw' i.p.v. 'display': zonder filter-argument levert get_bloginfo() al
    // HTML-geëscapete tekst, en esc_html() hieronder zou dat dubbel escapen.
    $site_name = get_bloginfo( 'name', 'raw' );
    $html  = '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#111;max-width:560px;margin:0 auto;padding:32px 16px">';
    $html .= '<h2 style="margin-bottom:8px">' . esc_html( $site_name ) . '</h2>';
    $html .= '<p style="line-height:1.7">' . nl2br( esc_html( $body_text ) ) . '</p>';
    if ( $cta_url ) {
        $html .= '<p><a href="' . esc_url( $cta_url ) . '" style="display:inline-block;padding:10px 20px;background:#2e7d32;color:#fff;text-decoration:none;border-radius:6px">'
            . esc_html__( 'Bekijk je wishlist', 'mk-cart-popup' ) . '</a></p>';
    }
    $html .= '</body></html>';

    $set_html_type = static function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $set_html_type );
    $sent = wp_mail( $user->user_email, $subject, $html, [ 'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>' ] );
    remove_filter( 'wp_mail_content_type', $set_html_type );

    return (bool) $sent;
}

/** Absolute link naar de wishlist-tab, voor gebruik buiten een al-geladen Account-pagina (bv. in e-mails). */
function mkcp_account_wishlist_url(): string {
    $page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'myaccount' ) : 0;
    $base    = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
    return $base . '#/wishlist';
}

/** Plaatshouders voor de instelbare wishlist-e-mailsjablonen — zelfde {accolade}-conventie als mkcp_pu_ready_placeholders(). $new_price/$old_price blijven leeg voor de voorraad-mail, die kent geen prijs. */
function mkcp_account_wishlist_email_placeholders( WP_User $user, WC_Product $product, string $new_price = '', string $old_price = '' ): array {
    return [
        '{voornaam}'     => $user->first_name ?: __( 'daar', 'mk-cart-popup' ),
        '{achternaam}'   => $user->last_name,
        '{product_naam}' => $product->get_name(),
        '{nieuwe_prijs}' => $new_price,
        '{oude_prijs}'   => $old_price,
        // 'raw': zie mkcp_account_wishlist_send_notify_email() — voorkomt dubbel-escapen.
        '{winkel_naam}'  => get_bloginfo( 'name', 'raw' ),
        '{wishlist_url}' => mkcp_account_wishlist_url(),
    ];
}

add_action( 'mkcp_account_wishlist_price_check', function() {
    global $wpdb;
    $items = $wpdb->get_results(
        "SELECT i.*, w.user_id FROM {$wpdb->prefix}mkcp_wishlist_items i
         INNER JOIN {$wpdb->prefix}mkcp_wishlists w ON w.id = i.wishlist_id
         WHERE i.notify_price_drop = 1"
    );

    foreach ( $items as $item ) {
        $product = wc_get_product( $item->variation_id ?: $item->product_id );
        if ( ! $product ) continue;

        $current_price = (float) $product->get_price();
        if ( $current_price <= 0 ) continue;

        // Met target_price: pas waarschuwen zodra de prijs die grens bereikt/onderschrijdt.
        // Zonder: elke daling t.o.v. price_at_add.
        $has_target = $item->target_price !== null && $item->target_price !== '';
        if ( $has_target ) {
            if ( $current_price > (float) $item->target_price ) continue;
        } elseif ( $current_price >= (float) $item->price_at_add ) {
            continue;
        }

        $price_drop_title = $has_target ? __( 'Gewenste prijs bereikt', 'mk-cart-popup' ) : __( 'Prijsdaling op je wishlist', 'mk-cart-popup' );
        $price_drop_body  = sprintf(
            /* translators: 1: productnaam, 2: nieuwe prijs, 3: oude prijs */
            __( '%1$s is nu %2$s (was %3$s).', 'mk-cart-popup' ),
            $product->get_name(),
            wp_strip_all_tags( wc_price( $current_price ) ),
            wp_strip_all_tags( wc_price( $item->price_at_add ) )
        );

        if ( ! empty( $item->notify_via_dashboard ) && function_exists( 'mkcp_account_add_notification' ) ) {
            mkcp_account_add_notification(
                (int) $item->user_id,
                'price_drop',
                $price_drop_title,
                $price_drop_body,
                '#/wishlist',
                'product',
                $product->get_id()
            );
        }

        // E-mail via het instelbare sjabloon i.p.v. de vaste in-app-tekst hierboven —
        // winkelier schrijft zelf de e-mailtekst, in-app blijft kort/consistent. Los instelbaar per item.
        $email_user = ! empty( $item->notify_via_email ) ? get_userdata( (int) $item->user_id ) : null;
        if ( $email_user ) {
            $email_cfg    = mkcp_account_config();
            $placeholders = mkcp_account_wishlist_email_placeholders(
                $email_user,
                $product,
                wp_strip_all_tags( wc_price( $current_price ) ),
                wp_strip_all_tags( wc_price( $item->price_at_add ) )
            );
            mkcp_account_wishlist_send_notify_email(
                (int) $item->user_id,
                strtr( (string) $email_cfg['account_wishlist_price_email_subject'], $placeholders ),
                strtr( (string) $email_cfg['account_wishlist_price_email_body'], $placeholders ),
                mkcp_account_wishlist_url()
            );
        }

        // Eén "waarschuw mij"-bel voor prijs én voorraad samen, dus na een verstuurde
        // melding gaat die bel in zijn geheel weer uit (niet alleen de trigger-reden).
        // price_at_add blijft bijgewerkt zodat heractivering weer vanaf de huidige prijs telt.
        $wpdb->update(
            $wpdb->prefix . 'mkcp_wishlist_items',
            [
                'price_at_add'           => $current_price,
                'notify_price_drop'      => 0,
                'notify_back_in_stock'   => 0,
                'last_notified_price_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $item->id ],
            [ '%f', '%d', '%d', '%s' ],
            [ '%d' ]
        );
    }
} );

function mkcp_account_wishlist_notify_back_in_stock( $product ) {
    if ( ! ( $product instanceof WC_Product ) || ! $product->is_in_stock() ) return;

    global $wpdb;
    $product_id = $product->get_id();
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT i.*, w.user_id FROM {$wpdb->prefix}mkcp_wishlist_items i
         INNER JOIN {$wpdb->prefix}mkcp_wishlists w ON w.id = i.wishlist_id
         WHERE i.notify_back_in_stock = 1 AND (i.product_id = %d OR i.variation_id = %d)",
        $product_id, $product_id
    ) );
    if ( ! $items ) return;

    foreach ( $items as $item ) {
        $stock_title = __( 'Weer op voorraad', 'mk-cart-popup' );
        $stock_body  = sprintf(
            /* translators: %s: productnaam */
            __( '%s staat weer op voorraad.', 'mk-cart-popup' ),
            $product->get_name()
        );

        if ( ! empty( $item->notify_via_dashboard ) && function_exists( 'mkcp_account_add_notification' ) ) {
            mkcp_account_add_notification(
                (int) $item->user_id,
                'back_in_stock',
                $stock_title,
                $stock_body,
                '#/wishlist',
                'product',
                $product_id
            );
        }

        // E-mail via het instelbare sjabloon, zie de prijsdaling-cron hierboven. Los instelbaar per item.
        $email_user = ! empty( $item->notify_via_email ) ? get_userdata( (int) $item->user_id ) : null;
        if ( $email_user ) {
            $email_cfg    = mkcp_account_config();
            $placeholders = mkcp_account_wishlist_email_placeholders( $email_user, $product );
            mkcp_account_wishlist_send_notify_email(
                (int) $item->user_id,
                strtr( (string) $email_cfg['account_wishlist_stock_email_subject'], $placeholders ),
                strtr( (string) $email_cfg['account_wishlist_stock_email_body'], $placeholders ),
                mkcp_account_wishlist_url()
            );
        }
        // Eenmalige melding — anders zou elke volgende voorraad-mutatie opnieuw melden.
        // Beide vlaggen uit (niet alleen notify_back_in_stock), zelfde "één bel, één keer klaar"-gedrag als de prijs-cron.
        $wpdb->update(
            $wpdb->prefix . 'mkcp_wishlist_items',
            [ 'notify_back_in_stock' => 0, 'notify_price_drop' => 0, 'last_notified_stock_at' => current_time( 'mysql' ) ],
            [ 'id' => $item->id ],
            [ '%d', '%d', '%s' ],
            [ '%d' ]
        );
    }
}
add_action( 'woocommerce_product_set_stock', 'mkcp_account_wishlist_notify_back_in_stock' );
add_action( 'woocommerce_variation_set_stock', 'mkcp_account_wishlist_notify_back_in_stock' );
