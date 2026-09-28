/**
 * MK Cart Popup — Checkout Cross-sell
 *
 * "Toevoegen"-knop bij de cross-sell-suggesties onder het orderoverzicht.
 * Voegt het product via WooCommerce's eigen wc-ajax add_to_cart-endpoint toe
 * (zelfde endpoint als de winkelwagenpopup, zie cart-popup.js) en ververst
 * daarna het orderoverzicht via WooCommerce's eigen 'update_checkout'-event —
 * dat rendert #order_review (incl. deze cross-sell-strip) opnieuw op de
 * server, dus het zojuist toegevoegde product verdwijnt vanzelf uit de
 * suggesties (mkcp_get_crosssell_products() sluit cart-items al uit) en de
 * totalen/verzendkosten kloppen meteen weer.
 */
( function () {
    'use strict';

    if ( typeof jQuery === 'undefined' ) return;
    var $ = jQuery;

    var wcAjaxUrl = ( typeof wc_add_to_cart_params !== 'undefined' && wc_add_to_cart_params.wc_ajax_url )
        ? wc_add_to_cart_params.wc_ajax_url.replace( '%%endpoint%%', 'add_to_cart' )
        : '/?wc-ajax=add_to_cart';

    // Zelfde vinkje als de "Toevoegen"-knop in de winkelwagen-popup
    // (CHECK_SVG, assets/cart-popup.js) — herbruikt hier zodat het +-icoon
    // even zichtbaar wisselt naar een vinkje ná het toevoegen, in plaats van
    // dat de knop stilzwijgend alleen maar 'is-added' krijgt terwijl de rij
    // toch al binnen een paar honderd ms verdwijnt (mkcp_get_crosssell_products()
    // sluit cart-items uit, dus 'update_checkout' hieronder rendert dit
    // blokje meteen zonder het product).
    var CHECK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

    $( document ).on( 'click', '.js-mkcp-co-crosssell-atc', function () {
        var $btn      = $( this );
        var productId = $btn.data( 'product-id' );

        if ( ! productId || $btn.prop( 'disabled' ) ) return;
        $btn.prop( 'disabled', true ).addClass( 'is-loading' );

        $.ajax( {
            url: wcAjaxUrl,
            type: 'POST',
            data: { product_id: productId, quantity: 1 },
            success: function ( res ) {
                if ( res && res.fragments ) {
                    $btn.removeClass( 'is-loading' ).addClass( 'is-added' ).html( CHECK_SVG );
                    // Even laten staan zodat het vinkje ook echt gezien wordt,
                    // vóórdat het orderoverzicht ververst en dit blokje (het
                    // product is nu immers al in de winkelwagen) verdwijnt.
                    setTimeout( function () {
                        $( document.body ).trigger( 'update_checkout' );
                    }, 700 );
                } else {
                    $btn.prop( 'disabled', false ).removeClass( 'is-loading' );
                }
            },
            error: function () {
                $btn.prop( 'disabled', false ).removeClass( 'is-loading' );
            }
        } );
    } );

} )();
