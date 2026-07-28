/**
 * MK Cart Popup — Gast-e-mailadres vastleggen tijdens het afrekenen.
 *
 * Alleen ingeladen voor niet-ingelogde bezoekers op de checkout-pagina (zie
 * mk-cart-popup.php). Stuurt het e-mailadres naar de server zodra het veld
 * de focus verliest en er een geldig adres in staat, zodat een verlaten
 * winkelwagen ook voor gasten gevolgd kan worden.
 */
(function ( $ ) {
    'use strict';

    if ( typeof mkcp_ac_params === 'undefined' ) return;

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var lastSent = '';

    function captureEmail() {
        var email = $.trim( $( '#billing_email' ).val() || '' );
        if ( ! EMAIL_RE.test( email ) || email === lastSent ) return;
        lastSent = email;

        $.post( mkcp_ac_params.ajax_url, {
            action: 'mkcp_ac_capture_guest_email',
            nonce : mkcp_ac_params.nonce,
            email : email
        } );
    }

    $( document.body ).on( 'blur', '#billing_email', captureEmail );

} )( jQuery );
