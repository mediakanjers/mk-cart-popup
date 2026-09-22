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
                    $btn.addClass( 'is-added' );
                    $( document.body ).trigger( 'update_checkout' );
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
