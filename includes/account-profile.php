<?php
/**
 * MK Cart Popup — Account: Accountgegevens + Adressen (Fase 1, stap 3)
 *
 * Los bestand van includes/account-frontend.php (dat blijft het routing-/
 * dispatcher-fundament) om te voorkomen dat Account hetzelfde "god file"-
 * patroon krijgt als checkout-frontend.php/settings-page.php — elke view
 * krijgt hier zijn eigen bestand.
 *
 * Registreert twee fragmenten ("profile", "addresses") via het
 * mkcp_account_fragment_handlers-filter, plus de bijbehorende muterende
 * AJAX-acties. Elke muterende actie herhaalt dezelfde drie checks als de
 * fragmentdispatcher (nonce, mkcp_account_is_active(), eigendom) — nooit via
 * is_account_page(), zie de toelichting in account-frontend.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'mkcp_account_fragment_handlers', function( $handlers ) {
    $handlers['profile']   = 'mkcp_account_render_fragment_profile';
    $handlers['addresses'] = 'mkcp_account_render_fragment_addresses';
    return $handlers;
} );


// ── Helpers: adresboek ────────────────────────────────────────────────────────

/**
 * Praktisch misbruikplafond — zie Account-plan, sectie 15. Instelbaar via
 * admin/views/settings-page.php (account_max_addresses), voorheen een
 * hardcoded constante.
 */
function mkcp_account_max_addresses(): int {
    $cfg = mkcp_account_config();
    $max = isset( $cfg['account_max_addresses'] ) ? (int) $cfg['account_max_addresses'] : 20;
    return $max > 0 ? $max : 20;
}

function mkcp_account_get_addresses( int $user_id ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_addresses';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d ORDER BY is_default_billing DESC, is_default_shipping DESC, id ASC",
        $user_id
    ) );
}

/** Geeft alleen een adres terug als het ook echt van $user_id is — de eigendomscheck. */
function mkcp_account_get_owned_address( int $address_id, int $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mkcp_addresses';
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
        $address_id, $user_id
    ) );
}


/**
 * Telefoonnummer alleen verplicht maken in Accountgegevens als het dat ook
 * echt is op de checkout — anders kan een klant hier een profiel opslaan
 * zonder telefoonnummer en alsnog vastlopen bij het afrekenen, wat het hele
 * punt van dit veld hier ondermijnt. Leest WooCommerce's eigen
 * billing_phone-vereiste rechtstreeks uit (i.p.v. hier een losse instelling
 * te dupliceren) zodat dit automatisch meebeweegt als de checkout-vereiste
 * ooit verandert (WooCommerce-instelling, een filter van een andere plugin,
 * enz.) — nooit hardcoded true/false.
 */
function mkcp_account_is_phone_required(): bool {
    if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) return false;
    $fields = WC()->checkout()->get_checkout_fields();
    return ! empty( $fields['billing']['billing_phone']['required'] );
}


// ── Fragment: Accountgegevens ─────────────────────────────────────────────────

function mkcp_account_render_fragment_profile(): string {
    $user = wp_get_current_user();
    $phone = get_user_meta( $user->ID, 'mkcp_phone', true );
    $dob   = get_user_meta( $user->ID, 'mkcp_date_of_birth', true );
    $optin = get_user_meta( $user->ID, 'mkcp_newsletter_optin', true );

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <div class="mkcp-account-view__header">
            <h1><?php esc_html_e( 'Accountgegevens', 'mk-cart-popup' ); ?></h1>
        </div>

        <?php
        // Compacte samenvatting bovenaan — hergebruikt de Dashboard-
        // statistieken (account-orders.php) i.p.v. een eigen berekening, en
        // vult meteen de anders lege ruimte boven de twee formulieren.
        $mkcp_profile_initials = mb_strtoupper( mb_substr( $user->first_name ?: $user->display_name, 0, 1 ) . mb_substr( $user->last_name, 0, 1 ) );
        $mkcp_profile_since    = $user->user_registered ? mysql2date( 'Y', $user->user_registered ) : '';
        $mkcp_profile_stats    = function_exists( 'mkcp_account_get_dashboard_stats' ) ? mkcp_account_get_dashboard_stats( $user->ID ) : null;
        ?>
        <div class="mkcp-dash-card mkcp-profile-summary">
            <span class="mkcp-profile-summary__avatar"><?php echo esc_html( $mkcp_profile_initials ); ?></span>
            <div class="mkcp-profile-summary__info">
                <span class="mkcp-profile-summary__name"><?php echo esc_html( $user->first_name ? $user->first_name . ' ' . $user->last_name : $user->display_name ); ?></span>
                <span class="mkcp-profile-summary__email"><?php echo esc_html( $user->user_email ); ?></span>
            </div>
            <?php if ( $mkcp_profile_stats ) : ?>
                <div class="mkcp-profile-summary__stats">
                    <span><strong><?php echo esc_html( number_format_i18n( $mkcp_profile_stats['order_count'] ) ); ?></strong> <?php esc_html_e( 'bestellingen', 'mk-cart-popup' ); ?></span>
                    <?php if ( $mkcp_profile_since ) : ?>
                        <span><?php
                            printf(
                                /* translators: %s: jaartal */
                                esc_html__( 'klant sinds %s', 'mk-cart-popup' ),
                                esc_html( $mkcp_profile_since )
                            );
                        ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mkcp-dash-grid">
            <div class="mkcp-dash-grid__col">

                <form class="mkcp-account-form mkcp-dash-card" id="mkcp-profile-form" novalidate>
                    <h2>
                        <span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                        <?php esc_html_e( 'Persoonlijke gegevens', 'mk-cart-popup' ); ?>
                    </h2>
                    <div class="mkcp-account-form-row-group">
                        <div class="mkcp-account-form-row">
                            <label for="mkcp-profile-first-name"><?php esc_html_e( 'Voornaam', 'mk-cart-popup' ); ?></label>
                            <input type="text" id="mkcp-profile-first-name" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>">
                        </div>
                        <div class="mkcp-account-form-row">
                            <label for="mkcp-profile-last-name"><?php esc_html_e( 'Achternaam', 'mk-cart-popup' ); ?></label>
                            <input type="text" id="mkcp-profile-last-name" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>">
                        </div>
                    </div>
                    <div class="mkcp-account-form-row">
                        <label for="mkcp-profile-email"><?php esc_html_e( 'E-mailadres', 'mk-cart-popup' ); ?></label>
                        <input type="email" id="mkcp-profile-email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>">
                    </div>
                    <div class="mkcp-account-form-row mkcp-account-form-row--conditional" id="mkcp-profile-current-password-row" hidden>
                        <label for="mkcp-profile-current-password"><?php esc_html_e( 'Huidig wachtwoord (nodig om je e-mailadres te wijzigen)', 'mk-cart-popup' ); ?></label>
                        <input type="password" id="mkcp-profile-current-password" name="current_password" autocomplete="current-password">
                    </div>
                    <?php $phone_required = mkcp_account_is_phone_required(); ?>
                    <div class="mkcp-account-form-row-group">
                        <div class="mkcp-account-form-row">
                            <label for="mkcp-profile-phone">
                                <?php esc_html_e( 'Telefoonnummer', 'mk-cart-popup' ); ?>
                                <?php if ( $phone_required ) : ?><span class="mkcp-account-form-required" title="<?php esc_attr_e( 'Verplicht — ook nodig om af te rekenen', 'mk-cart-popup' ); ?>">*</span><?php endif; ?>
                            </label>
                            <input type="tel" id="mkcp-profile-phone" name="phone" value="<?php echo esc_attr( $phone ); ?>" <?php echo $phone_required ? 'required' : ''; ?>>
                        </div>
                        <div class="mkcp-account-form-row">
                            <label for="mkcp-profile-dob"><?php esc_html_e( 'Geboortedatum', 'mk-cart-popup' ); ?></label>
                            <input type="date" id="mkcp-profile-dob" name="date_of_birth" value="<?php echo esc_attr( $dob ); ?>">
                        </div>
                    </div>
                    <div class="mkcp-profile-preferences">
                        <span class="mkcp-profile-preferences__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M4 4l8 8 8-8"/></svg></span>
                        <div class="mkcp-account-form-row mkcp-account-form-row--checkbox">
                            <label>
                                <input type="checkbox" name="newsletter_optin" value="1" <?php checked( $optin, '1' ); ?>>
                                <?php esc_html_e( 'Ik ontvang graag de nieuwsbrief', 'mk-cart-popup' ); ?>
                            </label>
                        </div>
                    </div>

                    <div class="mkcp-account-form-actions">
                        <button type="submit" class="mkcp-btn mkcp-btn--primary"><?php esc_html_e( 'Opslaan', 'mk-cart-popup' ); ?></button>
                        <span class="mkcp-account-form-status" data-form-status="profile" role="status" aria-live="polite"></span>
                    </div>
                </form>

            </div>

            <div class="mkcp-dash-grid__col">

                <form class="mkcp-account-form mkcp-dash-card" id="mkcp-password-form" novalidate>
                    <h2>
                        <span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                        <?php esc_html_e( 'Wachtwoord wijzigen', 'mk-cart-popup' ); ?>
                    </h2>
                    <div class="mkcp-account-form-row">
                        <label for="mkcp-pw-current"><?php esc_html_e( 'Huidig wachtwoord', 'mk-cart-popup' ); ?></label>
                        <input type="password" id="mkcp-pw-current" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="mkcp-account-form-row">
                        <label for="mkcp-pw-new"><?php esc_html_e( 'Nieuw wachtwoord', 'mk-cart-popup' ); ?></label>
                        <input type="password" id="mkcp-pw-new" name="new_password" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="mkcp-account-form-row">
                        <label for="mkcp-pw-confirm"><?php esc_html_e( 'Bevestig nieuw wachtwoord', 'mk-cart-popup' ); ?></label>
                        <input type="password" id="mkcp-pw-confirm" name="new_password_confirm" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="mkcp-account-form-actions">
                        <button type="submit" class="mkcp-btn mkcp-btn--primary"><?php esc_html_e( 'Wachtwoord wijzigen', 'mk-cart-popup' ); ?></button>
                        <span class="mkcp-account-form-status" data-form-status="password" role="status" aria-live="polite"></span>
                    </div>
                </form>

                <?php
                // Zelfservice-verwijderflow leeft in includes/account-gdpr.php
                // — dit is puur de knop die 'm aanvraagt (stap 1, verstuurt de
                // bevestigingsmail). function_exists()-guard is hier vooral
                // toekomstbestendig (dat bestand is altijd geladen), maar
                // voorkomt een fatal error als dat ooit niet zo is.
                if ( function_exists( 'mkcp_account_delete_confirm_url' ) ) :
                    ?>
                    <div class="mkcp-dash-card mkcp-danger-zone">
                        <h2>
                            <span class="mkcp-dash-card__icon mkcp-dash-card__icon--danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></span>
                            <?php esc_html_e( 'Account verwijderen', 'mk-cart-popup' ); ?>
                        </h2>
                        <p><?php esc_html_e( 'Hiermee verwijderen we je persoonsgegevens, adresboek, wishlists en meldingen definitief. Bestellingen blijven om boekhoudkundige redenen bewaard, maar niet meer aan jouw naam gekoppeld. Dit kan niet ongedaan worden gemaakt.', 'mk-cart-popup' ); ?></p>
                        <button type="button" class="mkcp-btn mkcp-btn--danger" id="mkcp-account-delete-request"><?php esc_html_e( 'Account verwijderen', 'mk-cart-popup' ); ?></button>
                        <span class="mkcp-account-form-status" data-form-status="delete-account" role="status" aria-live="polite"></span>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// ── Fragment: Adressen ────────────────────────────────────────────────────────

/**
 * $show_actions=false wordt gebruikt op het Dashboard (mkcp_account_render_
 * fragment_dashboard) — de Bewerken/Verwijderen-knoppen sturen naar
 * #mkcp-address-form, dat alleen op de Adressen-fragment zelf in de DOM
 * staat; zonder deze parameter zouden die knoppen daar stilzwijgend niets
 * doen.
 */
function mkcp_account_render_address_card( $address, bool $show_actions = true ): string {
    $label = $address->label !== '' ? $address->label : __( 'Adres', 'mk-cart-popup' );

    // De volledige veldset staat als JSON in een data-attribuut, zodat de
    // "Bewerken"-knop het formulier client-side kan voorinvullen zonder een
    // extra AJAX-rondje ("adres ophalen") nodig te hebben.
    $fields = [
        'id', 'label', 'is_business', 'company', 'vat_number', 'first_name', 'last_name',
        'address_1', 'address_2', 'postcode', 'city', 'country', 'phone',
        'is_default_billing', 'is_default_shipping',
    ];
    $data = [];
    foreach ( $fields as $field ) $data[ $field ] = $address->$field ?? '';

    // Zelfde icoon-taal (huis/gebouw) als de adreskaarten op de checkout-
    // adreskiezer (includes/checkout-address-picker.php) — letterlijk
    // dezelfde onderliggende data, dus ook visueel hetzelfde soort kaart.
    $icon = ! empty( $address->is_business )
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="10" height="18"/><path d="M14 8h6v13h-6"/><line x1="7" y1="7" x2="7" y2="7.01"/><line x1="7" y1="11" x2="7" y2="11.01"/><line x1="7" y1="15" x2="7" y2="15.01"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>';

    $is_default = ! empty( $address->is_default_billing ) || ! empty( $address->is_default_shipping );

    ob_start();
    ?>
    <div class="mkcp-address-card<?php echo $is_default ? ' mkcp-address-card--default' : ''; ?>" data-address-id="<?php echo esc_attr( $address->id ); ?>" data-address="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
        <span class="mkcp-address-card__icon"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
        <div class="mkcp-address-card__main">
            <?php
            // Titel staat als EERSTE item in een flex-rij, badges erna —
            // niet absoluut gepositioneerd (dat overlapte de titel op
            // smallere kaarten, want beide claimden dezelfde hoogte zonder
            // van elkaars breedte te weten) en niet boven de titel gestapeld
            // (dat liet de titel verspringen tussen kaarten met/zonder
            // badges). Met flex-wrap vallen badges vanzelf naar een eigen
            // regel als ze niet naast de titel passen — de titel zelf blijft
            // altijd het eerste, dus altijd op dezelfde plek.
            ?>
            <div class="mkcp-address-card__header">
                <strong><?php echo esc_html( $label ); ?></strong>
                <?php if ( $is_default ) : ?>
                    <span class="mkcp-address-card__badges">
                        <?php if ( ! empty( $address->is_default_billing ) ) : ?>
                            <span class="mkcp-address-badge"><?php esc_html_e( 'Standaard factuur', 'mk-cart-popup' ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $address->is_default_shipping ) ) : ?>
                            <span class="mkcp-address-badge"><?php esc_html_e( 'Standaard verzending', 'mk-cart-popup' ); ?></span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="mkcp-address-card__body">
                <?php if ( $address->is_business && $address->company ) : ?><?php echo esc_html( $address->company ); ?><br><?php endif; ?>
                <?php echo esc_html( trim( $address->first_name . ' ' . $address->last_name ) ); ?><br>
                <?php echo esc_html( $address->address_1 ); ?><?php echo $address->address_2 ? ', ' . esc_html( $address->address_2 ) : ''; ?><br>
                <?php echo esc_html( trim( $address->postcode . ' ' . $address->city ) ); ?><br>
                <?php echo esc_html( $address->country ); ?>
            </p>
            <?php if ( $show_actions ) : ?>
                <div class="mkcp-address-card__actions">
                    <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-address-edit"><?php esc_html_e( 'Bewerken', 'mk-cart-popup' ); ?></button>
                    <button type="button" class="mkcp-btn mkcp-btn--text js-mkcp-address-delete"><?php esc_html_e( 'Verwijderen', 'mk-cart-popup' ); ?></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function mkcp_account_render_address_form_fields( $address = null ): string {
    $countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
    $get = function( $field, $default = '' ) use ( $address ) {
        return $address->$field ?? $default;
    };
    ob_start();
    ?>
    <input type="hidden" name="address_id" value="<?php echo esc_attr( $get( 'id', 0 ) ); ?>">
    <div class="mkcp-account-form-row">
        <label><?php esc_html_e( 'Label (bv. Thuis, Werk)', 'mk-cart-popup' ); ?></label>
        <input type="text" name="label" value="<?php echo esc_attr( $get( 'label' ) ); ?>">
    </div>
    <div class="mkcp-account-form-row mkcp-account-form-row--checkbox">
        <label><input type="checkbox" name="is_business" value="1" <?php checked( $get( 'is_business' ), 1 ); ?>> <?php esc_html_e( 'Zakelijk adres', 'mk-cart-popup' ); ?></label>
    </div>
    <div class="mkcp-account-form-row-group">
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Bedrijfsnaam', 'mk-cart-popup' ); ?></label>
            <input type="text" name="company" value="<?php echo esc_attr( $get( 'company' ) ); ?>">
        </div>
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'BTW-nummer', 'mk-cart-popup' ); ?></label>
            <input type="text" name="vat_number" value="<?php echo esc_attr( $get( 'vat_number' ) ); ?>">
        </div>
    </div>
    <div class="mkcp-account-form-row-group">
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Voornaam', 'mk-cart-popup' ); ?></label>
            <input type="text" name="first_name" value="<?php echo esc_attr( $get( 'first_name' ) ); ?>" required>
        </div>
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Achternaam', 'mk-cart-popup' ); ?></label>
            <input type="text" name="last_name" value="<?php echo esc_attr( $get( 'last_name' ) ); ?>" required>
        </div>
    </div>
    <div class="mkcp-account-form-row">
        <label><?php esc_html_e( 'Adresregel 1', 'mk-cart-popup' ); ?></label>
        <input type="text" name="address_1" value="<?php echo esc_attr( $get( 'address_1' ) ); ?>" required>
    </div>
    <div class="mkcp-account-form-row">
        <label><?php esc_html_e( 'Adresregel 2', 'mk-cart-popup' ); ?></label>
        <input type="text" name="address_2" value="<?php echo esc_attr( $get( 'address_2' ) ); ?>">
    </div>
    <div class="mkcp-account-form-row-group">
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Postcode', 'mk-cart-popup' ); ?></label>
            <input type="text" name="postcode" value="<?php echo esc_attr( $get( 'postcode' ) ); ?>" required>
        </div>
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Plaats', 'mk-cart-popup' ); ?></label>
            <input type="text" name="city" value="<?php echo esc_attr( $get( 'city' ) ); ?>" required>
        </div>
    </div>
    <div class="mkcp-account-form-row-group">
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Land', 'mk-cart-popup' ); ?></label>
            <select name="country">
                <?php foreach ( $countries as $code => $name ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $get( 'country', 'NL' ), $code ); ?>><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mkcp-account-form-row">
            <label><?php esc_html_e( 'Telefoonnummer', 'mk-cart-popup' ); ?></label>
            <input type="tel" name="phone" value="<?php echo esc_attr( $get( 'phone' ) ); ?>">
        </div>
    </div>
    <div class="mkcp-account-form-row mkcp-account-form-row--checkbox">
        <label><input type="checkbox" name="is_default_billing" value="1" <?php checked( $get( 'is_default_billing' ), 1 ); ?>> <?php esc_html_e( 'Standaard factuuradres', 'mk-cart-popup' ); ?></label>
    </div>
    <div class="mkcp-account-form-row mkcp-account-form-row--checkbox">
        <label><input type="checkbox" name="is_default_shipping" value="1" <?php checked( $get( 'is_default_shipping' ), 1 ); ?>> <?php esc_html_e( 'Standaard verzendadres', 'mk-cart-popup' ); ?></label>
    </div>
    <?php
    return ob_get_clean();
}

function mkcp_account_render_fragment_addresses(): string {
    $user_id      = get_current_user_id();
    $addresses    = mkcp_account_get_addresses( $user_id );
    $count        = count( $addresses );
    $at_max       = $count >= mkcp_account_max_addresses();

    ob_start();
    ?>
    <div class="mkcp-account-view">
        <div class="mkcp-account-view__header">
            <h1><?php esc_html_e( 'Adressen', 'mk-cart-popup' ); ?></h1>
        </div>

        <?php if ( $count > 0 ) : ?>
            <p class="mkcp-address-count">
                <?php
                printf(
                    /* translators: 1: aantal opgeslagen adressen, 2: maximum aantal */
                    esc_html( _n( '%1$d van %2$d adres opgeslagen', '%1$d van %2$d adressen opgeslagen', $count, 'mk-cart-popup' ) ),
                    (int) $count,
                    mkcp_account_max_addresses()
                );
                ?>
            </p>
        <?php endif; ?>

        <?php if ( 0 === $count ) : ?>
            <p class="mkcp-account-notice"><?php esc_html_e( 'Nog geen adres opgeslagen — voeg je eerste adres toe voor een snellere checkout.', 'mk-cart-popup' ); ?></p>
        <?php elseif ( $at_max ) : ?>
            <p class="mkcp-account-notice"><?php esc_html_e( 'Je hebt het maximum aantal adressen bereikt.', 'mk-cart-popup' ); ?></p>
        <?php endif; ?>

        <div class="mkcp-address-list" id="mkcp-address-list">
            <?php foreach ( $addresses as $address ) echo mkcp_account_render_address_card( $address ); ?>

            <?php if ( ! $at_max ) : ?>
                <button type="button" class="mkcp-address-card mkcp-address-card--add" id="mkcp-address-add-toggle">
                    <span class="mkcp-address-card--add__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span>
                    <span class="mkcp-address-card--add__label"><?php esc_html_e( 'Adres toevoegen', 'mk-cart-popup' ); ?></span>
                </button>
            <?php endif; ?>
        </div>

        <form class="mkcp-account-form mkcp-dash-card" id="mkcp-address-form" hidden>
            <h2>
                <span class="mkcp-dash-card__icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-7.2 7-12a7 7 0 10-14 0c0 4.8 7 12 7 12z"/><circle cx="12" cy="11" r="2"/></svg></span>
                <span class="js-mkcp-address-form-title"><?php esc_html_e( 'Nieuw adres', 'mk-cart-popup' ); ?></span>
            </h2>
            <?php echo mkcp_account_render_address_form_fields(); ?>
            <div class="mkcp-account-form-actions">
                <button type="submit" class="mkcp-btn mkcp-btn--primary"><?php esc_html_e( 'Adres opslaan', 'mk-cart-popup' ); ?></button>
                <button type="button" class="mkcp-btn mkcp-btn--text" id="mkcp-address-cancel"><?php esc_html_e( 'Annuleren', 'mk-cart-popup' ); ?></button>
                <span class="mkcp-account-form-status" data-form-status="address" role="status" aria-live="polite"></span>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}


// ── AJAX: profiel opslaan ─────────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_save_profile', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user_id    = get_current_user_id();
    $first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
    $last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
    $email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
    $dob        = isset( $_POST['date_of_birth'] ) ? sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ) ) : '';
    $optin      = ! empty( $_POST['newsletter_optin'] ) ? '1' : '0';

    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'code' => 'invalid_email', 'message' => __( 'Ongeldig e-mailadres.', 'mk-cart-popup' ) ], 400 );
    }

    // Nooit alleen op het "required"-attribuut in de HTML vertrouwen — dat
    // is UX, geen beveiliging/validatie. Zelfde voorwaarde als op checkout.
    if ( $phone === '' && mkcp_account_is_phone_required() ) {
        wp_send_json_error( [ 'code' => 'missing_phone', 'message' => __( 'Telefoonnummer is verplicht (ook nodig om af te rekenen).', 'mk-cart-popup' ) ], 400 );
    }

    $user = get_userdata( $user_id );

    // E-mailwijziging vereist herbevestiging met het huidige wachtwoord —
    // bemoeilijkt accountovername via een gestolen sessie (Account-plan, sectie 8).
    if ( $email !== $user->user_email ) {
        $current_password = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
        if ( ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
            wp_send_json_error( [ 'code' => 'wrong_password', 'message' => __( 'Huidig wachtwoord onjuist — e-mailadres niet gewijzigd.', 'mk-cart-popup' ) ], 403 );
        }
        $existing = email_exists( $email );
        if ( $existing && (int) $existing !== $user_id ) {
            wp_send_json_error( [ 'code' => 'email_in_use', 'message' => __( 'Dit e-mailadres is al bij een ander account in gebruik.', 'mk-cart-popup' ) ], 409 );
        }
        wp_update_user( [ 'ID' => $user_id, 'user_email' => $email ] );
    }

    wp_update_user( [
        'ID'         => $user_id,
        'first_name' => $first_name,
        'last_name'  => $last_name,
    ] );

    update_user_meta( $user_id, 'mkcp_phone', $phone );
    update_user_meta( $user_id, 'mkcp_date_of_birth', $dob );
    update_user_meta( $user_id, 'mkcp_newsletter_optin', $optin );

    wp_send_json_success( [ 'message' => __( 'Opgeslagen.', 'mk-cart-popup' ) ] );
} );


// ── AJAX: wachtwoord wijzigen ─────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_change_password', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user_id  = get_current_user_id();
    $user     = get_userdata( $user_id );
    $current  = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
    $new      = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
    $confirm  = isset( $_POST['new_password_confirm'] ) ? (string) wp_unslash( $_POST['new_password_confirm'] ) : '';

    if ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
        wp_send_json_error( [ 'code' => 'wrong_password', 'message' => __( 'Huidig wachtwoord onjuist.', 'mk-cart-popup' ) ], 403 );
    }
    if ( strlen( $new ) < 8 ) {
        wp_send_json_error( [ 'code' => 'password_too_short', 'message' => __( 'Nieuw wachtwoord moet minimaal 8 tekens zijn.', 'mk-cart-popup' ) ], 400 );
    }
    if ( $new !== $confirm ) {
        wp_send_json_error( [ 'code' => 'password_mismatch', 'message' => __( 'De wachtwoorden komen niet overeen.', 'mk-cart-popup' ) ], 400 );
    }

    wp_set_password( $new, $user_id );

    // wp_set_password() vernietigt alle sessies van deze gebruiker, inclusief
    // de huidige — zonder dit zou de klant zichzelf per ongeluk uitloggen
    // door zijn eigen wachtwoord te wijzigen. Zelfde aanpak als WooCommerce's
    // eigen "Wachtwoord wijzigen"-veld op de standaard accountpagina.
    wp_set_auth_cookie( $user_id, true );

    wp_send_json_success( [ 'message' => __( 'Wachtwoord gewijzigd.', 'mk-cart-popup' ) ] );
} );


// ── AJAX: adres opslaan (aanmaken of bewerken) ────────────────────────────────

add_action( 'wp_ajax_mkcp_account_address_save', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    global $wpdb;
    $user_id    = get_current_user_id();
    $table      = $wpdb->prefix . 'mkcp_addresses';
    $address_id = isset( $_POST['address_id'] ) ? absint( $_POST['address_id'] ) : 0;

    // Eigendomscheck: een address_id die niet van deze klant is, wordt
    // stilzwijgend als "nieuw adres" behandeld i.p.v. een ander account te
    // laten overschrijven.
    $existing = $address_id ? mkcp_account_get_owned_address( $address_id, $user_id ) : null;

    if ( ! $existing ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
        if ( $count >= mkcp_account_max_addresses() ) {
            wp_send_json_error( [ 'code' => 'address_limit_reached', 'message' => __( 'Maximum aantal adressen bereikt.', 'mk-cart-popup' ) ], 400 );
        }
    }

    $data = [
        'user_id'             => $user_id,
        'label'               => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
        'type'                => 'both',
        'is_business'         => ! empty( $_POST['is_business'] ) ? 1 : 0,
        'company'             => isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '',
        'vat_number'          => isset( $_POST['vat_number'] ) ? sanitize_text_field( wp_unslash( $_POST['vat_number'] ) ) : '',
        'first_name'          => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
        'last_name'           => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
        'address_1'           => isset( $_POST['address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_1'] ) ) : '',
        'address_2'           => isset( $_POST['address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['address_2'] ) ) : '',
        'postcode'            => isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '',
        'city'                => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
        'state'               => '',
        'country'             => isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : '',
        'phone'               => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
        'is_default_billing'  => ! empty( $_POST['is_default_billing'] ) ? 1 : 0,
        'is_default_shipping' => ! empty( $_POST['is_default_shipping'] ) ? 1 : 0,
        'updated_at'          => current_time( 'mysql' ),
    ];

    if ( $data['first_name'] === '' || $data['last_name'] === '' || $data['address_1'] === '' || $data['postcode'] === '' || $data['city'] === '' ) {
        wp_send_json_error( [ 'code' => 'missing_fields', 'message' => __( 'Vul alle verplichte velden in.', 'mk-cart-popup' ) ], 400 );
    }

    // Maar één standaardadres per type — bij het instellen van een nieuwe
    // standaard wordt de vlag bij alle andere adressen van deze klant uitgezet.
    if ( $data['is_default_billing'] ) {
        $wpdb->update( $table, [ 'is_default_billing' => 0 ], [ 'user_id' => $user_id ], [ '%d' ], [ '%d' ] );
    }
    if ( $data['is_default_shipping'] ) {
        $wpdb->update( $table, [ 'is_default_shipping' => 0 ], [ 'user_id' => $user_id ], [ '%d' ], [ '%d' ] );
    }

    if ( $existing ) {
        $wpdb->update( $table, $data, [ 'id' => $existing->id ] );
    } else {
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $table, $data );
    }

    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [
        'html' => mkcp_account_render_fragment_addresses(),
        'meta' => [ 'fragment' => 'addresses' ],
    ] );
} );


// ── AJAX: adres verwijderen ───────────────────────────────────────────────────

add_action( 'wp_ajax_mkcp_account_address_delete', function() {
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }
    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $user_id    = get_current_user_id();
    $address_id = isset( $_POST['address_id'] ) ? absint( $_POST['address_id'] ) : 0;
    $existing   = $address_id ? mkcp_account_get_owned_address( $address_id, $user_id ) : null;

    if ( ! $existing ) {
        wp_send_json_error( [ 'code' => 'not_found' ], 404 );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'mkcp_addresses', [ 'id' => $existing->id, 'user_id' => $user_id ], [ '%d', '%d' ] );

    // Verwijderen van een adres dat aan een bestaande bestelling ten grondslag
    // ligt is veilig: WooCommerce slaat bij elke order zijn eigen adres-
    // snapshot op (billing_*/shipping_* order-meta), losstaand van dit
    // adresboek — zie Account-plan, sectie 9.
    if ( function_exists( 'mkcp_account_clear_dashboard_stats_cache' ) ) mkcp_account_clear_dashboard_stats_cache( $user_id );

    wp_send_json_success( [
        'html' => mkcp_account_render_fragment_addresses(),
        'meta' => [ 'fragment' => 'addresses' ],
    ] );
} );
