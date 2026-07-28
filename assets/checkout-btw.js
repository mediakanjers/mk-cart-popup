/**
 * MK Cart Popup — Checkout BTW Switch
 *
 * Mirrors the popup's BTW switch using the same localStorage key
 * (mkcp_btw_pref) so both stay in sync across the session.
 *
 * Tax row labels ("BTW 21%") are wrapped in dual spans by initTaxRowLabels()
 * so CSS can toggle between "Waarvan BTW 21%" and "Nog bij te tellen BTW 21%"
 * without a WooCommerce template override.
 */
( function () {
    'use strict';

    var PREF_KEY = 'mkcp_btw_pref';
    var LOCK_KEY = 'mkcp_btw_locked_by_vat';
    var BODY     = document.body;
    var CLS_INCL = 'is-btw-incl';
    var CLS_EXCL = 'is-btw-excl';

    // Vergrendeling (bv. bij BTW-verlegging na een geldig BTW-nummer, zie
    // includes/checkout-frontend.php) — de knoppen blijven dan uitgeschakeld
    // en tonen dus de eigen :disabled-stijl (mk-cart-popup__btw-opt in
    // cart-popup.scss), ongeacht wat een klik of een AJAX-refresh nog zou
    // proberen te doen.
    var locked        = false;
    var prefBeforeLock = null;

    function setButtonsDisabled( disabled ) {
        document.querySelectorAll( '.js-mkcp-btw' ).forEach( function ( btn ) {
            btn.disabled = disabled;
        } );
    }

    function getPref() {
        try { return localStorage.getItem( PREF_KEY ) || 'incl'; } catch (e) { return 'incl'; }
    }

    function setPref( val ) {
        try { localStorage.setItem( PREF_KEY, val ); } catch (e) {}
    }

    // Fix thead/tfoot alignment when a theme adds extra columns (thumbnail, quantity)
    // to the checkout review-order table. The standard WC template has 2 columns;
    // themes may add more. Without this, "SUBTOTAAL" header and tfoot values land
    // in column 2 while the actual product-total cells are in column 4.
    function fixReviewTableColspans() {
        var table = document.querySelector( '#order_review .woocommerce-checkout-review-order-table' );
        if ( ! table ) return;

        var maxCols = 0;
        table.querySelectorAll( 'tbody tr' ).forEach( function ( row ) {
            var cols = 0;
            Array.from( row.cells ).forEach( function ( cell ) { cols += cell.colSpan || 1; } );
            if ( cols > maxCols ) maxCols = cols;
        } );

        if ( maxCols <= 2 ) return;

        // For each thead/tfoot row with fewer cells than maxCols, widen the
        // first cell so the last cell (price) lands in the rightmost column.
        table.querySelectorAll( 'thead tr, tfoot tr' ).forEach( function ( row ) {
            if ( row.cells.length < 1 ) return;
            var currentCols = Array.from( row.cells ).reduce( function ( n, c ) { return n + ( c.colSpan || 1 ); }, 0 );
            var extra = maxCols - currentCols;
            if ( extra > 0 ) {
                row.cells[0].colSpan = ( row.cells[0].colSpan || 1 ) + extra;
            }
        } );
    }

    // Wrap the WooCommerce tax row <th> text with dual label spans so CSS can
    // toggle between "Waarvan BTW X%" (incl) and "Nog bij te tellen BTW X%" (excl).
    // Idempotent — skips rows that are already wrapped.
    function initTaxRowLabels() {
        document.querySelectorAll( '#order_review .tax-rate th' ).forEach( function ( th ) {
            if ( th.querySelector( '.mkcp-btw-label-incl' ) ) return;
            var orig = th.textContent.trim();
            th.innerHTML =
                '<span class="mkcp-btw-label-incl">Waarvan ' + orig + '</span>' +
                '<span class="mkcp-btw-label-excl">Nog bij te tellen ' + orig + '</span>';
        } );
    }

    function applyPref( animate ) {
        var pref = getPref();

        if ( animate ) {
            document.querySelectorAll( '.mkcp-co-price' ).forEach( function ( el ) {
                el.style.opacity = '0';
            } );
        }

        function apply() {
            BODY.classList.remove( CLS_INCL, CLS_EXCL );
            BODY.classList.add( pref === 'excl' ? CLS_EXCL : CLS_INCL );

            document.querySelectorAll( '.js-mkcp-btw' ).forEach( function ( btn ) {
                btn.classList.toggle( 'is-active', btn.dataset.pref === pref );
            } );

            if ( animate ) {
                document.querySelectorAll( '.mkcp-co-price' ).forEach( function ( el ) {
                    el.style.opacity = '';
                } );
            }
        }

        if ( animate ) {
            setTimeout( apply, 130 );
        } else {
            apply();
        }
    }

    // Click on BTW pill buttons.
    document.addEventListener( 'click', function ( e ) {
        if ( locked ) return;
        var btn = e.target.closest( '.js-mkcp-btw' );
        if ( ! btn ) return;
        setPref( btn.dataset.pref );
        applyPref( true );
    } );

    // Publieke, minimale API zodat andere checkout-integraties (bv. de
    // BTW-verlegging bij een geldig BTW-nummer) de prijsweergave kunnen
    // forceren zonder de private pref-state hier te hoeven kennen/dupliceren.
    window.mkcpBtwSwitch = {
        lock: function ( pref ) {
            if ( ! locked ) prefBeforeLock = getPref();
            locked = true;
            setPref( pref || 'excl' );
            try { localStorage.setItem( LOCK_KEY, '1' ); } catch (e) {}
            applyPref( true );
            setButtonsDisabled( true );
        },
        unlock: function () {
            if ( ! locked ) return;
            locked = false;
            setButtonsDisabled( false );
            try { localStorage.removeItem( LOCK_KEY ); } catch (e) {}
            setPref( prefBeforeLock || 'incl' );
            applyPref( true );
            prefBeforeLock = null;
        }
    };

    // Vangnet voor als de BTW-verlegging zelf niet (meer) draait op deze
    // pagina — bv. omdat een winkelier de "BTW-integratie" uitzet, de
    // VAT-plugin deactiveert, of het BTW-nummerveld hier simpelweg niet
    // bestaat. mkcp_btw_locked_by_vat overleeft dan als wees in localStorage
    // (gezet in een eerdere sessie toen de vergrendeling wél actief was) en
    // niets zou 'm ooit meer opheffen: de klant zit dan voorgoed vast op
    // excl. BTW. Een vergrendeling mag nooit langer leven dan het script dat
    // 'm kan opheffen: draait de BTW-verlegging niet (geen
    // window.mkcpVatIntegrationActive-marker, gezet door het inline script
    // in includes/checkout-frontend.php dat vóór dit bestand print), dan
    // wordt de lock hier onvoorwaardelijk opgeruimd — óók als het veld nog
    // een waarde bevat, want niets gaat die waarde nog valideren of
    // ontgrendelen. Alleen mét draaiende integratie laten we een gevuld veld
    // met rust: de integratie bevestigt of ontgrendelt dan zelf.
    function reconcileStaleVatLock() {
        var wasLocked;
        try { wasLocked = localStorage.getItem( LOCK_KEY ) === '1'; } catch (e) { wasLocked = false; }
        if ( ! wasLocked ) return;

        if ( window.mkcpVatIntegrationActive ) {
            var vatInput = document.getElementById( 'billing_eu_vat_number' );
            if ( vatInput && vatInput.value.trim() ) return; // aan de VAT-integratie zelf om dit te bevestigen/ontgrendelen
        }

        try { localStorage.removeItem( LOCK_KEY ); } catch (e) {}
        setPref( 'incl' );
    }

    // Sync when popup changes pref in another tab or on the same page.
    window.addEventListener( 'storage', function ( e ) {
        if ( e.key === PREF_KEY ) applyPref( true );
    } );

    // After WooCommerce AJAX refreshes the order review (address/shipping change),
    // re-init labels and re-apply preference so prices stay in the right mode.
    if ( window.jQuery ) {
        jQuery( document.body ).on( 'updated_checkout', function () {
            fixReviewTableColspans();
            initTaxRowLabels();
            applyPref( false );
        } );
    }

    // Initial apply.
    function init() {
        reconcileStaleVatLock();
        fixReviewTableColspans();
        initTaxRowLabels();
        applyPref( false );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
