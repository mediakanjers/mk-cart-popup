/**
 * MK Cart Popup — Checkout: wisselende teksten op het "Bestelling
 * verwerken…"-laadscherm.
 *
 * De overlay zelf is WooCommerce's eigen blockUI (form.woocommerce-checkout
 * > .blockUI.blockOverlay, zie checkout.scss) — hier alleen het
 * data-mkcp-text-attribuut bijhouden zolang die overlay bestaat, met een
 * korte fade (.is-swapping, CSS-transition) tussen elke wissel.
 */
( function () {
    'use strict';

    if ( typeof mkcpLoadingMessages === 'undefined' ) return;

    var messages = ( mkcpLoadingMessages.messages || [] ).filter( function ( m ) { return m && m.trim(); } );
    if ( ! messages.length ) messages = [ 'Bestelling verwerken…' ];

    var SELECTOR  = 'form.woocommerce-checkout > .blockUI.blockOverlay';
    var INTERVAL  = 2200;
    var timer     = null;
    var index     = 0;

    function setText( el, text ) {
        el.setAttribute( 'data-mkcp-text', text );
    }

    function cycle( el ) {
        if ( messages.length < 2 ) return; // niets om tussen te wisselen
        el.classList.add( 'is-swapping' );
        setTimeout( function () {
            if ( ! document.body.contains( el ) ) return;
            index = ( index + 1 ) % messages.length;
            setText( el, messages[ index ] );
            el.classList.remove( 'is-swapping' );
        }, 250 );
    }

    function start( el ) {
        index = 0;
        setText( el, messages[ 0 ] );
        if ( timer ) clearInterval( timer );
        timer = setInterval( function () {
            if ( ! document.body.contains( el ) ) {
                clearInterval( timer );
                timer = null;
                return;
            }
            cycle( el );
        }, INTERVAL );
    }

    function stop() {
        if ( timer ) { clearInterval( timer ); timer = null; }
    }

    if ( window.MutationObserver ) {
        var observer = new MutationObserver( function () {
            var el = document.querySelector( SELECTOR );
            if ( el && ! timer ) {
                start( el );
            } else if ( ! el && timer ) {
                stop();
            }
        } );
        observer.observe( document.body, { childList: true, subtree: true } );
    }

} )();
