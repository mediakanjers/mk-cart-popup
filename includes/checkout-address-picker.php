<?php
/**
 * MK Cart Popup — Checkout: adreskeuze uit het Account-adresboek
 *
 * Toont voor ingelogde klanten met opgeslagen adressen compacte keuzekaarten
 * boven de factuur-/verzendvelden — kies een adres en de volledige veldenset
 * klapt dicht (Bol.com-stijl); "Nieuw adres" of nogmaals klikken klapt weer open.
 * Eigen bestand: op zichzelf staande UX bovenop adresboek + checkout-velden,
 * zonder dat een van beide van het andere hoeft te weten.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


function mkcp_addr_picker_active(): bool {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return false;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;
    if ( ! function_exists( 'mkcp_checkout_config' ) ) return false;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return false;

    // Zelfde gate als de Account-omgeving zelf — het adresboek waar dit uit
    // put is onderdeel van diezelfde premium Account-ervaring.
    if ( ! function_exists( 'mkcp_account_is_active' ) || ! mkcp_account_is_active() ) return false;

    return true;
}

function mkcp_render_checkout_address_picker( string $context ) {
    if ( ! mkcp_addr_picker_active() ) return;
    if ( ! function_exists( 'mkcp_account_get_addresses' ) ) return;

    $addresses = mkcp_account_get_addresses( get_current_user_id() );
    if ( empty( $addresses ) ) return;

    $default_key = $context === 'shipping' ? 'is_default_shipping' : 'is_default_billing';
    $fields      = [
        'id', 'label', 'is_business', 'company', 'vat_number', 'first_name', 'last_name',
        'address_1', 'address_2', 'postcode', 'city', 'country', 'phone',
        'is_default_billing', 'is_default_shipping',
    ];

    // Zelfde iconenstijl als de rest van de checkout; huis vs. gebouw-icoon
    // maakt zakelijk/particulier al zichtbaar vóór er tekst gelezen is.
    $icon_home = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>';
    $icon_biz  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="10" height="18"/><path d="M14 8h6v13h-6"/><line x1="7" y1="7" x2="7" y2="7.01"/><line x1="7" y1="11" x2="7" y2="11.01"/><line x1="7" y1="15" x2="7" y2="15.01"/></svg>';
    $icon_plus = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    $icon_check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

    // Horizontaal scrollende viewport + pijltjesnavigatie i.p.v. een grid dat
    // kaarten smaller maakt bij meer dan drie — zelfde patroon/klassen-conventie
    // als de bezorgdatum-kaarten (.mkcp-dd-track/.mkcp-dd-nav in delivery-date.php).
    echo '<div class="mkcp-addr-picker" data-context="' . esc_attr( $context ) . '">';
    echo '<div class="mkcp-addr-picker__track">';
    echo '<button type="button" class="mkcp-addr-picker__nav mkcp-addr-picker__nav--prev" aria-label="' . esc_attr__( 'Vorige adressen', 'mk-cart-popup' ) . '">&#8249;</button>';
    echo '<div class="mkcp-addr-picker__viewport"><div class="mkcp-addr-picker__list">';

    foreach ( $addresses as $address ) {
        $data = [];
        foreach ( $fields as $field ) $data[ $field ] = $address->$field ?? '';

        $label      = $address->label !== '' ? $address->label : __( 'Adres', 'mk-cart-popup' );
        $is_default = ! empty( $address->$default_key );
        $name       = trim( $address->first_name . ' ' . $address->last_name );
        $street     = trim( $address->address_1 . ( $address->address_2 !== '' ? ' ' . $address->address_2 : '' ) );
        $city_line  = trim( $address->postcode . ' ' . $address->city );

        printf(
            '<button type="button" class="mkcp-addr-picker__card%s" data-address="%s" aria-pressed="false">'
            . '<span class="mkcp-addr-picker__card-icon">%s</span>'
            . '<span class="mkcp-addr-picker__card-body">'
            . '%s'
            . '<span class="mkcp-addr-picker__card-title">%s</span>'
            . '<span class="mkcp-addr-picker__card-name">%s</span>'
            . '<span class="mkcp-addr-picker__card-sub">%s</span>'
            . '<span class="mkcp-addr-picker__card-sub">%s</span>'
            . '</span>'
            . '<span class="mkcp-addr-picker__card-check">%s</span>'
            . '</button>',
            $is_default ? ' is-default' : '',
            esc_attr( wp_json_encode( $data ) ),
            ! empty( $address->is_business ) ? $icon_biz : $icon_home, // phpcs:ignore WordPress.Security.EscapeOutput
            $is_default ? '<span class="mkcp-addr-picker__card-badge">' . esc_html__( 'Standaard', 'mk-cart-popup' ) . '</span>' : '',
            esc_html( $label ),
            esc_html( $name ),
            esc_html( $street ),
            esc_html( $city_line ),
            $icon_check // phpcs:ignore WordPress.Security.EscapeOutput
        );
    }

    printf(
        '<button type="button" class="mkcp-addr-picker__card mkcp-addr-picker__new" data-address="" aria-pressed="false">'
        . '<span class="mkcp-addr-picker__card-icon">%s</span>'
        . '<span class="mkcp-addr-picker__card-body">'
        . '<span class="mkcp-addr-picker__card-title">%s</span>'
        . '</span>'
        . '<span class="mkcp-addr-picker__card-check">%s</span>'
        . '</button>',
        $icon_plus, // phpcs:ignore WordPress.Security.EscapeOutput
        esc_html__( 'Nieuw adres', 'mk-cart-popup' ),
        $icon_check // phpcs:ignore WordPress.Security.EscapeOutput
    );

    echo '</div></div>';
    echo '<button type="button" class="mkcp-addr-picker__nav mkcp-addr-picker__nav--next" aria-label="' . esc_attr__( 'Volgende adressen', 'mk-cart-popup' ) . '">&#8250;</button>';
    echo '</div></div>';
}

add_action( 'wp', function() {
    if ( ! mkcp_addr_picker_active() ) return;

    add_action( 'woocommerce_before_checkout_billing_form', function() {
        mkcp_render_checkout_address_picker( 'billing' );
    } );
    add_action( 'woocommerce_before_checkout_shipping_form', function() {
        mkcp_render_checkout_address_picker( 'shipping' );
    } );

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            // Best-effort splitsing van "Straatnaam 12A" in straat/nummer/
            // toevoeging — het adresboek heeft geen gesplitste velden, de
            // postcode-checker-velden verwachten die wél als ze bestaan.
            function mkcpSplitAddress1( addr1 ) {
                var m = /^(.*\D)\s*(\d+)\s*([a-zA-Z][a-zA-Z0-9\-\/]*)?$/.exec( ( addr1 || '' ).trim() );
                if ( ! m ) return { street: addr1 || '', number: '', suffix: '' };
                return { street: m[1].trim(), number: m[2], suffix: ( m[3] || '' ).trim() };
            }

            function mkcpSetField( id, value ) {
                var el = document.getElementById( id );
                if ( ! el ) return;
                if ( window.jQuery ) {
                    jQuery( el ).val( value ).trigger( 'change' );
                } else {
                    el.value = value;
                    el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                }
                el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            }

            function mkcpApplyAddress( prefix, data ) {
                mkcpSetField( prefix + '_first_name', data.first_name || '' );
                mkcpSetField( prefix + '_last_name', data.last_name || '' );
                mkcpSetField( prefix + '_company', data.company || '' );
                mkcpSetField( prefix + '_country', data.country || 'NL' );
                mkcpSetField( prefix + '_postcode', data.postcode || '' );
                mkcpSetField( prefix + '_city', data.city || '' );
                if ( prefix === 'billing' ) {
                    mkcpSetField( 'billing_phone', data.phone || '' );
                    mkcpSetField( 'billing_eu_vat_number', data.vat_number || '' );
                }

                // Gesplitste postcode-checker-velden vullen i.p.v. adres_1/2 als ze bestaan.
                if ( document.getElementById( prefix + '_street_name' ) && document.getElementById( prefix + '_house_number' ) ) {
                    var parts = mkcpSplitAddress1( data.address_1 );
                    mkcpSetField( prefix + '_street_name', parts.street );
                    mkcpSetField( prefix + '_house_number', parts.number );
                    mkcpSetField( prefix + '_house_number_suffix', parts.suffix );
                } else {
                    mkcpSetField( prefix + '_address_1', data.address_1 || '' );
                    mkcpSetField( prefix + '_address_2', data.address_2 || '' );
                }

                if ( window.mkcpUpdateFloatingLabels ) window.mkcpUpdateFloatingLabels();
            }

            function mkcpCollapseFields( prefix, collapse ) {
                var selector = prefix === 'shipping'
                    ? '.woocommerce-shipping-fields__field-wrapper'
                    : '.woocommerce-billing-fields__field-wrapper';
                var wrapper = document.querySelector( selector );
                if ( ! wrapper ) return;
                wrapper.classList.toggle( 'mkcp-fields-collapsed', !! collapse );
            }

            function mkcpInitPicker( picker ) {
                if ( picker.dataset.mkcpBound ) return;
                picker.dataset.mkcpBound = '1';

                var prefix = picker.getAttribute( 'data-context' ) === 'shipping' ? 'shipping' : 'billing';
                var cards  = picker.querySelectorAll( '.mkcp-addr-picker__card' );

                function mkcpDeselectAll() {
                    cards.forEach( function ( c ) {
                        c.classList.remove( 'is-selected' );
                        c.setAttribute( 'aria-pressed', 'false' );
                    } );
                }

                cards.forEach( function ( card ) {
                    card.addEventListener( 'click', function () {
                        var isNew      = card.classList.contains( 'mkcp-addr-picker__new' );
                        var isSelected = card.classList.contains( 'is-selected' );

                        // Nogmaals klikken op een al-actieve bestaande kaart = "toch
                        // zelf aanpassen": selectie opheffen, velden weer volledig tonen.
                        if ( isSelected && ! isNew ) {
                            mkcpDeselectAll();
                            mkcpCollapseFields( prefix, false );
                            return;
                        }

                        mkcpDeselectAll();
                        card.classList.add( 'is-selected' );
                        card.setAttribute( 'aria-pressed', 'true' );

                        // "block: nearest" i.p.v. default "center": anders scrollt de
                        // hele pagina verticaal mee. "inline: nearest" scrollt de
                        // kaartenstrook net genoeg om de kaart volledig in beeld te krijgen.
                        card.scrollIntoView( { behavior: 'smooth', inline: 'nearest', block: 'nearest' } );

                        if ( isNew ) {
                            // Velden leegmaken i.p.v. vorig adres te laten staan.
                            mkcpApplyAddress( prefix, {} );
                            mkcpCollapseFields( prefix, false );
                            return;
                        }

                        var raw = card.getAttribute( 'data-address' );
                        if ( raw ) {
                            try { mkcpApplyAddress( prefix, JSON.parse( raw ) ); } catch ( e ) {}
                        }
                        mkcpCollapseFields( prefix, true );

                        if ( window.jQuery ) jQuery( document.body ).trigger( 'update_checkout' );
                    } );
                } );

                // Standaardadres direct voorgeselecteerd, scheelt een klik.
                var defaultCard = picker.querySelector( '.mkcp-addr-picker__card.is-default' );
                if ( defaultCard ) defaultCard.click();

                // Pijltjesnavigatie, zelfde opzet als .mkcp-dd-nav bij bezorgdatum-kaarten.
                var viewport = picker.querySelector( '.mkcp-addr-picker__viewport' );
                var prevBtn  = picker.querySelector( '.mkcp-addr-picker__nav--prev' );
                var nextBtn  = picker.querySelector( '.mkcp-addr-picker__nav--next' );
                if ( viewport && ( prevBtn || nextBtn ) ) {
                    var SCROLL_STEP = 270; // kaartbreedte (260px) + gap (10px)

                    function updateNavState() {
                        if ( prevBtn ) prevBtn.disabled = viewport.scrollLeft <= 4;
                        if ( nextBtn ) nextBtn.disabled = viewport.scrollLeft >= ( viewport.scrollWidth - viewport.clientWidth - 4 );
                    }

                    if ( prevBtn ) prevBtn.addEventListener( 'click', function () {
                        viewport.scrollBy( { left: -SCROLL_STEP, behavior: 'smooth' } );
                    } );
                    if ( nextBtn ) nextBtn.addEventListener( 'click', function () {
                        viewport.scrollBy( { left: SCROLL_STEP, behavior: 'smooth' } );
                    } );

                    viewport.addEventListener( 'scroll', updateNavState );
                    window.addEventListener( 'resize', updateNavState );
                    updateNavState();
                }
            }

            function mkcpInitAllPickers() {
                document.querySelectorAll( '.mkcp-addr-picker' ).forEach( mkcpInitPicker );
            }

            setTimeout( mkcpInitAllPickers, 150 );
            if ( window.jQuery ) {
                jQuery( document.body ).on( 'updated_checkout', function () {
                    setTimeout( mkcpInitAllPickers, 80 );
                } );
            }
        })();
        </script>
        <?php
    }, 20 );
}, 5 );
