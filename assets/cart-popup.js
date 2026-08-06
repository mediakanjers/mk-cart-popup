/**
 * MK Cart Popup — JavaScript
 *
 * Handles open/close, forced AJAX add-to-cart for all product types,
 * quantity updates, item removal, coupon codes, undo-remove, and
 * optional GA4/GTM analytics events.
 *
 * Reads mkcp_params (ajax_url, nonce, btw_split, analytics, min_order,
 * undo_timeout) from wp_localize_script.
 */

// Eigen DOMContentLoaded-listener i.p.v. jQuery(function($){...}): jQuery's
// interne ready-wachtrij roept alle geregistreerde callbacks na elkaar aan in
// dezelfde loop, zonder per-callback try/catch. Gooit een ándere plugin/thema-
// script (bv. een cookie-banner met een verkeerd geregistreerd domein) een
// onafgevangen fout in zijn eigen ready-callback, dan breekt die loop af en
// draait onze hele initialisatie — inclusief de add-to-cart-onderschepping en
// de ?added-to-cart=-fallback hieronder — nooit. Native DOMContentLoaded-
// listeners worden door de browser wél los van elkaar aangeroepen, dus een
// crash elders kan deze niet meer meeslepen.
( function ( $ ) {

    function mkcpInit() {

    var POPUP    = '#mk-cart-popup';
    var OPEN_CLS = 'is-open';
    var BODY_CLS = 'mk-cart-open';

    // Fallback cart-icoon-selector wanneer de admin geen eigen CSS-selector
    // heeft ingevuld. Bewust ZONDER a[href*="cart"] — dat matchte in de
    // praktijk elke "Toevoegen aan winkelwagen"-link op een archiefpagina
    // (WooCommerce's eigen AJAX-knoppen hebben een href als
    // "?add-to-cart=123") en elke "Winkelwagen"-menu-/footer-link, waardoor
    // de badge op tientallen plekken tegelijk verscheen i.p.v. alleen op het
    // header-cart-icoon.
    var DEFAULT_CART_ICON_SELECTOR = '.cart-contents, .woocommerce-cart-link, [data-cart-count]';

    // Focus-trap/-restore (WAI-ARIA dialog pattern). FOCUSABLE_SELECTOR bepaalt
    // de Tab-cyclus binnen de drawer; lastFocusedTrigger onthoudt waar de focus
    // vandaan kwam zodat closePopup() 'm daar weer kan neerzetten.
    var FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var lastFocusedTrigger = null;

    var params    = ( typeof mkcp_params !== 'undefined' ) ? mkcp_params : {};
    var ajaxUrl   = params.ajax_url    || '';
    var nonce     = params.nonce       || '';
    var btwSplit  = params.btw_split   === '1';
    var analytics = params.analytics   === '1';
    var minOrder  = parseFloat( params.min_order  || 0 );
    var undoMs    = parseInt(   params.undo_timeout || 5000, 10 );

    var saveForLater      = params.save_for_later === '1';
    var SAVED_KEY         = 'mkcp_saved_items';
    var cartIconSelector  = params.cart_icon_selector  || '';
    var cartBadgePosition = params.cart_badge_position || 'top-right';

    var cartCountBadgeEnabled  = params.cart_count_badge_enabled === '1';
    var cartCountBadgeSelector = params.cart_count_badge_selector  || '';
    var cartCountBadgePosition = params.cart_count_badge_position || 'top-right';

    var wcStats           = params.analytics_wc_stats === '1';
    var debugMode         = params.analytics_debug    === '1';
    var shippingThreshold = parseFloat( params.shipping_threshold || 0 );

    // ── Fragment-cache automatisch verversen na een plugin-update ───────────
    //
    // WooCommerce's eigen fragment-cache (sessionStorage) ververst alleen
    // zodra de winkelwagen-INHOUD wijzigt, niet zodra deze plugin een update
    // krijgt — een langlopende browsertab kan daardoor een verouderde
    // drawer-HTML blijven tonen, ook na een harde refresh (sessionStorage
    // overleeft dat). Bij elke wijziging in MKCP_VER (dus bij elke
    // code-aanpassing) wordt de fragment-cache hier automatisch geleegd en
    // vraagt WooCommerce meteen verse fragments op — geen handmatige
    // console-actie meer nodig.
    var version = params.version || '';
    if ( version && window.localStorage && window.sessionStorage ) {
        var lastVerKey = 'mkcp_last_ver';
        if ( window.localStorage.getItem( lastVerKey ) !== version ) {
            Object.keys( window.sessionStorage ).forEach( function ( key ) {
                if ( key.indexOf( 'wc_fragments' ) === 0 ) window.sessionStorage.removeItem( key );
            } );
            window.localStorage.setItem( lastVerKey, version );
            $( document.body ).trigger( 'wc_fragment_refresh' );
        }
    }

    // ── Debug overlay (alleen voor beheerders als debug modus aan) ────────────

    if ( debugMode ) {
        var debugColors = { add_to_cart: '#22c55e', remove_from_cart: '#f87171', begin_checkout: '#60a5fa', view_cart: '#a78bfa', select_item: '#fb923c', apply_coupon: '#34d399', remove_coupon: '#f97316' };

        $( '<style>' +
            '.mkcp-debug-overlay{position:fixed;bottom:20px;right:20px;z-index:2147483647;width:320px;max-height:420px;' +
            'background:#0f172a;border-radius:10px;font-family:ui-monospace,monospace;font-size:11px;color:#e2e8f0;overflow:hidden;' +
            'box-shadow:0 12px 40px rgba(0,0,0,.6);display:flex;flex-direction:column}' +

            '.mkcp-debug-header{display:flex;align-items:center;gap:6px;padding:8px 12px;' +
            'background:#1e293b;border-bottom:1px solid #334155;user-select:none}' +
            '.mkcp-debug-header-dot{width:7px;height:7px;border-radius:50%;background:#38bdf8;flex-shrink:0}' +
            '.mkcp-debug-header-title{font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;flex:1}' +
            '.mkcp-debug-count{font-size:10px;color:#475569;margin-right:4px}' +
            '.mkcp-debug-clear{background:none;border:1px solid #334155;color:#64748b;cursor:pointer;font-size:10px;' +
            'padding:2px 7px;border-radius:4px;font-family:inherit;line-height:1.4}' +
            '.mkcp-debug-clear:hover{color:#e2e8f0;border-color:#475569}' +

            '.mkcp-debug-list{overflow-y:auto;flex:1;min-height:0;padding:6px 8px;display:flex;flex-direction:column;gap:4px}' +

            '.mkcp-debug-event{border-radius:6px;overflow:hidden;border-left:3px solid #334155;background:#1e293b;cursor:pointer}' +
            '.mkcp-debug-event-head{display:flex;align-items:center;gap:6px;padding:6px 8px}' +
            '.mkcp-debug-badge{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;' +
            'padding:2px 6px;border-radius:3px;color:#0f172a;flex-shrink:0}' +
            '.mkcp-debug-summary{flex:1;color:#94a3b8;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
            '.mkcp-debug-time{color:#475569;font-size:10px;flex-shrink:0}' +
            '.mkcp-debug-body{padding:0 8px 7px;display:none}' +
            '.mkcp-debug-body pre{margin:0;color:#a3e635;font-size:10px;line-height:1.5;white-space:pre-wrap;word-break:break-all}' +
            '.mkcp-debug-event.is-open .mkcp-debug-body{display:block}' +
        '</style>' ).appendTo( 'head' );

        $( 'body' ).append(
            '<div class="mkcp-debug-overlay js-mkcp-debug-overlay">' +
            '<div class="mkcp-debug-header">' +
                '<span class="mkcp-debug-header-dot"></span>' +
                '<span class="mkcp-debug-header-title">MK Analytics Debug</span>' +
                '<span class="mkcp-debug-count">0</span>' +
                '<button type="button" class="mkcp-debug-clear js-mkcp-debug-clear">leeg</button>' +
            '</div>' +
            '<div class="mkcp-debug-list js-mkcp-debug-list"></div>' +
            '</div>'
        );

        $( document ).on( 'click', '.mkcp-debug-event', function() {
            $( this ).toggleClass( 'is-open' );
        } );
    }

    $( document ).on( 'click', '.js-mkcp-debug-clear', function () {
        $( '.js-mkcp-debug-list' ).empty();
        $( '.mkcp-debug-count' ).text( '0' );
    } );

    // ── Debug overlay: sleepbaar via header ───────────────────────────────────
    if ( debugMode ) {
        var $dbg    = $( '.js-mkcp-debug-overlay' );
        var dbgKey  = 'mkcp_debug_pos';
        var dragging = false, dragOffX = 0, dragOffY = 0;

        // Herstel opgeslagen positie
        try {
            var savedPos = JSON.parse( localStorage.getItem( dbgKey ) || 'null' );
            if ( savedPos ) {
                $dbg.css( { bottom: 'auto', right: 'auto', top: savedPos.top + 'px', left: savedPos.left + 'px' } );
            }
        } catch(e) {}

        $dbg.find( '.mkcp-debug-header' ).css( 'cursor', 'move' ).on( 'mousedown', function( e ) {
            dragging = true;
            var rect  = $dbg[0].getBoundingClientRect();
            dragOffX  = e.clientX - rect.left;
            dragOffY  = e.clientY - rect.top;
            e.preventDefault();
        } );

        $( document ).on( 'mousemove.mkcpdebug', function( e ) {
            if ( ! dragging ) return;
            var left = e.clientX - dragOffX;
            var top  = e.clientY - dragOffY;
            $dbg.css( { bottom: 'auto', right: 'auto', top: top + 'px', left: left + 'px' } );
        } ).on( 'mouseup.mkcpdebug', function() {
            if ( ! dragging ) return;
            dragging = false;
            try {
                var rect = $dbg[0].getBoundingClientRect();
                localStorage.setItem( dbgKey, JSON.stringify( { top: Math.round(rect.top), left: Math.round(rect.left) } ) );
            } catch(e) {}
        } );
    }

    var undoTimer = null;
    var undoPending = null; // {product_id, qty, variation_id, variation}
    var undoDeadline = 0;   // Date.now()-moment waarop de undo-termijn afloopt
    var undoRemaining = 0;  // resterende ms tijdens een pauze (aanraking/hover)

    var addedToastTimer = null;


    // ── Toast (toegevoegd / bijgewerkt / fout) ───────────────────────────────

    var CHECK_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    var ERROR_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

    function hideToast() {
        var $toast = $( '.js-mkcp-added-toast' );
        $toast.addClass( 'is-hiding' );
        setTimeout( function () {
            $toast.removeClass( 'is-visible is-hiding is-error is-neutral' );
        }, 280 );
    }

    function showToast( message, type ) {
        var isError   = type === 'error';
        var isNeutral = type === 'neutral';
        var duration  = isError ? 4000 : 2500;
        var $toast    = $( '.js-mkcp-added-toast' );

        $toast.removeClass( 'is-visible is-hiding is-error is-neutral' );
        void $toast[0].offsetHeight;

        $toast[0].style.setProperty( '--mkcp-toast-dur', duration + 'ms' );
        $toast.find( '.js-mkcp-added-toast-icon' ).html( isError ? ERROR_ICON : CHECK_ICON );
        $toast.find( '.js-mkcp-added-toast-text' ).text( message );
        $toast.toggleClass( 'is-error', isError )
              .toggleClass( 'is-neutral', isNeutral )
              .addClass( 'is-visible' );

        clearTimeout( addedToastTimer );
        addedToastTimer = setTimeout( hideToast, duration );
    }

    function showAddedToast( name ) {
        showToast( name ? name + ' toegevoegd' : 'Product toegevoegd', 'success' );
        // Alle drie de add-paden (formulier, archief, cross-sell) komen hier
        // samen — cart-popup-mobile.js haakt hierop in voor haptische feedback.
        $( document.body ).trigger( 'mkcp_added_toast' );
    }


    // ── BTW / VAT price split ────────────────────────────────────────────────

    function syncBtw( animate ) {
        if ( ! btwSplit ) return;
        var pref        = localStorage.getItem( 'mkcp_btw_pref' ) || 'incl';
        var $popup      = $( POPUP );
        var $priceAreas = $popup.find( '.mk-cart-popup__item-col-price, .mk-cart-popup__item-price, .mk-cart-popup__totals-value' );

        function apply() {
            $popup.removeClass( 'is-btw-incl is-btw-excl' ).addClass( 'is-btw-' + pref );
            $popup.find( '.js-mkcp-btw' ).each( function () {
                $( this ).toggleClass( 'is-active', $( this ).data( 'pref' ) === pref );
            } );
            if ( animate ) {
                $priceAreas.css( 'opacity', '' ); // remove inline, CSS transition fades in
            }
        }

        if ( animate ) {
            $priceAreas.css( 'opacity', '0' );
            setTimeout( apply, 130 );
        } else {
            apply();
        }
    }

    $( document ).on( 'click', '.js-mkcp-btw', function () {
        localStorage.setItem( 'mkcp_btw_pref', $( this ).data( 'pref' ) );
        syncBtw( true );
    } );

    // Bridge: listen for the theme's own BTW switch (uses sessionStorage +
    // 'incl-btw'/'excl-btw' format) so both switches stay in sync.
    $( document ).on( 'change', '.btw-switch .switch', function () {
        var raw  = sessionStorage.getItem( 'btw_preference' ) || 'incl-btw';
        var pref = raw.indexOf( 'excl' ) !== -1 ? 'excl' : 'incl';
        localStorage.setItem( 'mkcp_btw_pref', pref );
        syncBtw( true );
    } );


    // ── Open / Close ─────────────────────────────────────────────────────────

    function openPopup( trigger ) {
        // Onthoud waar de focus vandaan kwam (add-to-cart-knop, "bekijk cart"-
        // link, ...) zodat closePopup() 'm daar straks weer kan neerzetten.
        // `trigger` (indien meegegeven) heeft voorrang boven document.activeElement:
        // bij een AJAX-add-to-cart wordt de knop tijdens de request gedisabled,
        // waardoor de browser 'm automatisch blurt naar <body> — tegen de tijd
        // dat de AJAX-success-callback openPopup() aanroept is activeElement dus
        // allang niet meer de knop, ook al staat die inmiddels weer enabled.
        var active = ( trigger && trigger.nodeType === 1 ) ? trigger : document.activeElement;
        lastFocusedTrigger = ( active && active !== document.body ) ? active : null;

        $( POPUP ).removeAttr( 'inert' ).addClass( OPEN_CLS ).attr( 'aria-hidden', 'false' );
        // Ook op <html> zetten, niet alleen <body>: CSS's overflow-propagatie
        // van body naar de viewport geldt alleen als <html> zélf geen eigen
        // overflow heeft. Zodra een thema (zoals hier) wél een overflow op
        // html zet, bepaalt html — niet body — de daadwerkelijke paginascroll
        // (document.scrollingElement), en blijft de achtergrond scrollbaar
        // ondanks body.mk-cart-open.
        $( 'html' ).addClass( BODY_CLS );
        $( 'body' ).addClass( BODY_CLS );
        syncBtw();
        renderSavedItems();
        csInitAll();
        initStickyCta();

        // Focus naar de dialoog zelf i.p.v. een specifieke knop: het eerste
        // knop-element is conditioneel (de uitklap-toggle bestaat alleen bij
        // premium + een aan-instelling), en dit laat een screenreader eerst de
        // aria-label van de dialoog aankondigen vóór de gebruiker verder tabt.
        var $drawer = $( POPUP ).find( '.mk-cart-popup__drawer' );
        if ( ! $drawer.attr( 'tabindex' ) ) $drawer.attr( 'tabindex', '-1' );
        $drawer.trigger( 'focus' );

        fireEvent( 'view_cart', { items: allItems() } );

        if ( wcStats && shippingThreshold > 0 ) {
            $.post( ajaxUrl, { action: 'mkcp_record_gap', nonce: nonce } );
        }
    }

    function closePopup() {
        $( POPUP ).removeClass( OPEN_CLS + ' is-expanded' ).attr( 'aria-hidden', 'true' ).attr( 'inert', '' );
        $( '.js-mkcp-expand-toggle' ).attr( 'aria-pressed', 'false' );
        $( 'html' ).removeClass( BODY_CLS );
        $( 'body' ).removeClass( BODY_CLS );

        // Focus terug naar de trigger — met een contains()-check, want die kan
        // intussen door een WooCommerce-fragment-refresh uit de DOM verdwenen zijn.
        if ( lastFocusedTrigger && document.body.contains( lastFocusedTrigger ) ) {
            lastFocusedTrigger.focus();
        }
        lastFocusedTrigger = null;
    }


    // ── Sticky checkout-knop ──────────────────────────────────────────────────
    //
    // De knop staat normaal na de totalen in de scrollbare body. Met veel
    // items in de cart valt hij buiten beeld. Een onzichtbare "meetlat"
    // (.mk-cart-popup__ctas-sentinel) markeert zijn natuurlijke plek; zodra
    // die niet meer zichtbaar is binnen de scrollcontainer, plakken we de
    // knop vast onderin de drawer (.is-pinned), zodat een klant niet hoeft
    // te scrollen om af te rekenen. Is de knop al in beeld, dan gebeurt er
    // niets — geen dubbele knop.
    var stickyCtaObserver = null;

    function initStickyCta() {
        if ( stickyCtaObserver ) {
            stickyCtaObserver.disconnect();
            stickyCtaObserver = null;
        }

        var sentinel = document.querySelector( POPUP + ' .mk-cart-popup__ctas-sentinel' );
        var ctas     = document.querySelector( POPUP + ' .mk-cart-popup__ctas' );
        var body     = document.querySelector( POPUP + ' .mk-cart-popup__body' );
        if ( ! sentinel || ! ctas || ! body || typeof IntersectionObserver === 'undefined' ) return;

        stickyCtaObserver = new IntersectionObserver( function ( entries ) {
            var entry = entries[ 0 ];
            if ( entry.isIntersecting ) {
                ctas.classList.remove( 'is-pinned' );
                body.style.paddingBottom = '';
            } else {
                ctas.classList.add( 'is-pinned' );
                body.style.paddingBottom = ctas.getBoundingClientRect().height + 'px';
            }
        }, { root: body, threshold: 0 } );

        stickyCtaObserver.observe( sentinel );
    }


    // ── Fragment helper ───────────────────────────────────────────────────────

    // Blijft ook meedraaien wanneer WooCommerce's eigen wc-cart-fragments.js
    // (los van onze eigen applyFragments()-aanroepen, bv. na het detecteren
    // van een gewijzigde cart-hash-cookie) zelfstandig een fragment-refresh
    // doet: #mk-cart-popup zit altijd in onze eigen woocommerce_add_to_cart_
    // fragments-filter (mk-cart-popup.php), dus WC's eigen replaceWith()
    // vervangt de drawer óók — maar dan zonder onze eigen "was 'ie open"-
    // herstellogica. Beide paden triggeren wel hetzelfde 'wc_fragments_
    // refreshed'-event (WC's eigen event, dat onze code hieronder bewust
    // hergebruikt), dus daar de open/expanded-status herstellen werkt voor
    // ELKE ververs-bron, niet alleen onze eigen add-to-cart-flow.
    var wasExpandedBeforeRefresh = false;

    $( document.body ).on( 'wc_fragments_refreshed', function () {
        if ( $( 'body' ).hasClass( BODY_CLS ) ) {
            // #mk-cart-popup is zojuist (door wie dan ook) vervangen, en de
            // verse server-HTML bevat standaard `inert` (de "dicht"-staat uit
            // templates/cart-popup.php) — zonder dit te verwijderen blijft de
            // drawer, ondanks de is-open klasse, onklikbaar en onfocusbaar.
            $( POPUP ).removeAttr( 'inert' ).addClass( OPEN_CLS ).attr( 'aria-hidden', 'false' );

            if ( wasExpandedBeforeRefresh ) {
                $( POPUP ).addClass( 'is-expanded' );
                $( '.js-mkcp-expand-toggle' ).attr( 'aria-pressed', 'true' );
                pulseTotals();
            }
        }
    } );

    function applyFragments( fragments ) {
        if ( ! fragments ) return;

        // #mk-cart-popup wordt in zijn geheel vervangen door verse server-HTML
        // (replaceWith hieronder), dus is-expanded gaat anders verloren — bv.
        // bij het toevoegen van een cross-sell product terwijl de split-
        // layout open staat, wat die net dan juist nuttig maakt. Vastleggen
        // vóór de vervanging (leeft op het element zelf, i.t.t. is-open dat
        // op <body> staat en dus wél de vervanging overleeft).
        wasExpandedBeforeRefresh = $( POPUP ).hasClass( 'is-expanded' );

        // De verse drawer-HTML komt niet onder zijn eigen '#mk-cart-popup'-
        // sleutel binnen, maar onder de neutrale '#mkcp-popup-refresh' (zie
        // de PHP-kant, mk-cart-popup.php) — WooCommerce's eigen add-to-cart.js
        // verwerkt namelijk ELKE fragment-sleutel blind (block()/fadeTo(400ms)/
        // replaceWith() voor alle keys, niet alleen zijn eigen mini-cart-
        // widgets) en botst dan met onze eigen vervanging hieronder, met als
        // gevolg dat #mk-cart-popup soms na een add-to-cart vanaf een
        // archiefpagina helemaal uit de DOM verdween. '#mkcp-popup-refresh'
        // bestaat nergens als los element, dus WooCommerce's eigen script
        // doet daar een onschuldige no-op mee — alleen wíj vervangen hiermee
        // de echte, levende drawer, precies één keer.
        var freshPopupHtml = fragments[ '#mkcp-popup-refresh' ];
        if ( freshPopupHtml ) {
            $( POPUP ).replaceWith( freshPopupHtml );
        }

        $.each( fragments, function ( selector, html ) {
            if ( selector === '#mkcp-popup-refresh' ) return;
            $( selector ).replaceWith( html );
        } );

        updateCartCountBadge();
        updatePeekTab();
        initStickyCta();
        $( document.body ).trigger( 'wc_fragments_refreshed' );

        syncBtw();
        csInitAll();
    }

    function pulseTotals() {
        var $value = $( POPUP + ' .mk-cart-popup__totals-value' );
        if ( ! $value.length ) return;
        $value.removeClass( 'is-pulsing' );
        void $value[ 0 ].offsetWidth;
        $value.addClass( 'is-pulsing' );
    }


    // ── Analytics (GA4 / GTM dataLayer) ──────────────────────────────────────

    function fireEvent( eventName, ecommerce ) {
        if ( ! analytics ) return;
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push( { ecommerce: null } ); // clear previous
        window.dataLayer.push( { event: eventName, ecommerce: ecommerce } );
        if ( debugMode ) showDebugEvent( eventName, ecommerce );
    }

    function showDebugEvent( eventName, data ) {
        var $list = $( '.js-mkcp-debug-list' );
        if ( ! $list.length ) return;

        var time    = new Date().toLocaleTimeString( 'nl-NL', { hour: '2-digit', minute: '2-digit', second: '2-digit' } );
        var color   = debugColors[ eventName ] || '#94a3b8';
        var items   = data && data.items ? data.items : [];
        var summary = items.length ? items[0].item_name + ( items.length > 1 ? ' +' + ( items.length - 1 ) : '' ) : '';

        var $event = $( '<div class="mkcp-debug-event" style="border-left-color:' + color + '">' +
            '<div class="mkcp-debug-event-head">' +
                '<span class="mkcp-debug-badge" style="background:' + color + '">' + eventName.replace(/_/g, ' ') + '</span>' +
                '<span class="mkcp-debug-summary">' + summary + '</span>' +
                '<span class="mkcp-debug-time">' + time + '</span>' +
            '</div>' +
            '<div class="mkcp-debug-body"><pre>' + JSON.stringify( data, null, 2 ) + '</pre></div>' +
            '</div>' );

        $list.prepend( $event );
        $list.find( '.mkcp-debug-event:gt(9)' ).remove();

        // tel events
        var count = $list.find( '.mkcp-debug-event' ).length;
        $( '.mkcp-debug-count' ).text( count );
    }

    function itemFromAttr( $el ) {
        var p = $el.data( 'product' );
        if ( ! p ) return null;
        return { item_id: p.id, item_name: p.name, item_sku: p.sku, price: p.price, quantity: p.qty };
    }

    function allItems() {
        var items = [];
        $( POPUP + ' .mk-cart-popup__item' ).each( function () {
            var item = itemFromAttr( $( this ) );
            if ( item ) items.push( item );
        } );
        return items;
    }


    // ── Popup AJAX helper ─────────────────────────────────────────────────────
    //
    // onSuccess(data) is called after fragments are applied.

    function cartAjax( action, data, onSuccess, onError ) {
        data.action = action;
        data.nonce  = nonce;

        $( '.mk-cart-popup__drawer' ).addClass( 'mk-loading' );

        $.ajax( {
            url:  ajaxUrl,
            type: 'POST',
            data: data,

            success: function ( res ) {
                if ( res && res.success ) {
                    applyFragments( res.data && res.data.fragments );
                    if ( typeof onSuccess === 'function' ) {
                        onSuccess( res.data );
                    }
                } else {
                    var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Er is iets misgegaan.';
                    if ( typeof onError === 'function' ) {
                        onError( msg );
                    } else {
                        showToast( msg, 'error' );
                    }
                }
            },

            error: function () {
                var msg = 'Verbindingsfout, probeer opnieuw.';
                if ( typeof onError === 'function' ) {
                    onError( msg );
                } else {
                    showToast( msg, 'error' );
                }
            },

            complete: function () {
                $( '.mk-cart-popup__drawer' ).removeClass( 'mk-loading' );
            }
        } );
    }


    // ── Intercept add-to-cart form submit (single / variable products) ────────

    var wcAjaxUrl = ( typeof wc_add_to_cart_params !== 'undefined' && wc_add_to_cart_params.wc_ajax_url )
        ? wc_add_to_cart_params.wc_ajax_url.replace( '%%endpoint%%', 'add_to_cart' )
        : '/?wc-ajax=add_to_cart';

    // Sommige thema's voegen bij een eigen swatch-UI een extra, verborgen
    // set <input required> toe naast (of i.p.v.) WooCommerce's eigen
    // variatie-veld, zonder die twee altijd in sync te houden — bv. een los
    // "required" radio-groepje voor kleurstalen die nooit :checked raakt als
    // de klant de eigenlijke (verstopte) <select> gebruikt. De browser
    // blokkeert een form.submit() dan stilzwijgend zodra checkValidity()
    // faalt: geen submit-event, geen foutmelding, geen netwerkverkeer — de
    // klant klikt en er gebeurt niets zichtbaars. Grijp daarom al bij de
    // klik op de knop in, vóórdat de browser zijn eigen (native) validatie
    // uitvoert bij de submit-poging zelf, zodat een kapotte/overbodige
    // required-markering elders in het formulier onze eigen AJAX-flow nooit
    // kan blokkeren — WooCommerce's server-side validatie is en blijft de
    // uiteindelijke bron van waarheid (zie res.error hieronder).
    $( document ).on( 'click', 'form.cart .single_add_to_cart_button', function ( e ) {
        e.preventDefault();
        submitCartForm( $( this ).closest( 'form.cart' ), $( this ) );
    } );

    // Vangnet voor submits die niet via een klik op de knop lopen (bv. Enter
    // in het aantal-veld) — loopt wél nog via de browser's eigen validatie.
    $( document ).on( 'submit', 'form.cart', function ( e ) {
        e.preventDefault();
        var $form = $( this );
        submitCartForm( $form, $form.find( '.single_add_to_cart_button' ) );
    } );

    function submitCartForm( $form, $btn ) {
        if ( $form.hasClass( 'mk-loading' ) ) return; // dubbele klik/submit binnen dezelfde ronde

        // Variabel product zonder (nog) opgeloste/actuele variation_id.
        // WooCommerce's eigen add-to-cart-variation.js hangt ZIJN click-
        // handler rechtstreeks aan dit <form> (wij zitten op document) — door
        // event-bubbling vuurt die van WooCommerce dus altijd eerder dan de
        // onze. Zodra een dropdown wijzigt, zet WooCommerce SYNCHROON de
        // class "disabled" (+ "wc-variation-selection-needed") op de knop,
        // en haalt die er pas weer af zodra de bijbehorende variatie
        // (eventueel via een eigen AJAX-rondje bij veel variatiecombinaties)
        // daadwerkelijk is teruggevonden. Vroeger checkten we hier alléén of
        // variation_id > 0 was — maar bij snel meerdere dropdowns achter
        // elkaar wijzigen kan dat veld nog een VEROUDERDE waarde bevatten
        // die niet bij de laatste keuze hoort, terwijl de knop intussen wél
        // alweer .disabled is. Die verouderde variation_id werd dan alsnog
        // meegestuurd, de server wees 'm af, en onze eigen foutafhandeling
        // deed daarna een volledige pagina-herlading (zie het "error"-blok
        // hieronder) — voor de klant leek dat willekeurig, want de dropdowns
        // stonden na de herlading gewoon weer goed. .disabled meenemen in
        // deze check dekt dit hele scenario, sync én async.
        if ( $form.hasClass( 'variations_form' ) ) {
            var $variationIdField = $form.find( 'input[name="variation_id"]' );
            var variationNotReady = $btn.hasClass( 'disabled' )
                || ( $variationIdField.length && ! ( parseInt( $variationIdField.val() || 0, 10 ) > 0 ) );
            if ( variationNotReady ) {
                // WooCommerce's eigen handler (zie hierboven) toonde bij
                // .disabled al zijn eigen window.alert() ("kies een optie" /
                // "niet beschikbaar") — dan hoeven wij niet nogmaals een
                // eigen melding te tonen, dat zou dubbelop zijn. Alleen in
                // het randgeval waarin de knop nog NIET .disabled is maar er
                // toch nog geen variation_id bekend is, heeft de klant nog
                // geen enkele melding gezien — dan tonen we die zelf.
                if ( ! $btn.hasClass( 'disabled' ) ) {
                    showToast( 'Kies eerst een optie voordat je toevoegt aan de winkelwagen.', 'error' );
                }
                return;
            }
        }

        var postData = $form.serialize();

        // Sommige thema's (zoals hier) zetten naast de knop zelf ook nog een
        // los verborgen <input name="add-to-cart" value="{parent_id}"> in het
        // formulier (voor het geval JS uitstaat) — een hidden input, dus
        // serialize() neemt 'm gewoon mee. Alleen al de AANWEZIGHEID van dit
        // veld triggert WooCommerce's KLASSIEKE, niet-AJAX formulier-handler
        // (WC_Form_Handler::add_to_cart_action(), die op wp_loaded ALTIJD
        // checkt op $_POST['add-to-cart'], ongeacht of dit een AJAX-request
        // is). Onze eigen AJAX-call gaat gelijktijdig ook via het wc-ajax=
        // add_to_cart-endpoint — zonder dit veld te strippen verwerken BEIDE
        // codepaden dezelfde toevoeging, en komt de winkelwagen op het
        // dubbele aantal uit. We sturen product_id/variation_id zelf al
        // expliciet mee, dus dit veld is voor de AJAX-call toch overbodig.
        postData = postData.replace( /(^|&)add-to-cart=[^&]*/g, '' ).replace( /^&/, '' );

        var parentProductId = parseInt(
            $btn.val()
            || $form.find( 'input[name="product_id"]' ).val()
            || $form.find( 'button[name="add-to-cart"]' ).val()
            || 0,
            10
        );
        var variationId = parseInt( $form.find( 'input[name="variation_id"]' ).val() || 0, 10 );

        // WooCommerce's wc-ajax=add_to_cart-endpoint bepaalt bij een variabel
        // product zelf het hoofdproduct + de attributen aan de hand van
        // product_id: is dat een variatie-post, dan leidt de server DAAR de
        // parent en de variation-attributen vanaf (zie WC_AJAX::add_to_cart()
        // in class-wc-ajax.php) — een los meegestuurd variation_id-veld wordt
        // voor die afleiding genegeerd. product_id moet dus de variatie-ID
        // zelf zijn, niet de hoofdproduct-ID die het formulier er standaard
        // voor gebruikt, anders denkt de server dat er geen variatie gekozen
        // is en wijst de toevoeging af (ondanks een geldige variation_id).
        var productId = variationId > 0 ? variationId : parentProductId;

        // Serialize() heeft al een product_id (hoofdproduct) uit het
        // verborgen formuliersveld meegenomen — hier ná toevoegen zorgt dat
        // onze (mogelijk overschrijvende) waarde als laatste, en dus
        // winnende, product_id wordt geparsed.
        if ( productId ) postData += '&product_id=' + productId;

        if ( variationId > 0 && postData.indexOf( 'variation_id=' ) === -1 ) {
            postData += '&variation_id=' + variationId;
        }

        var productQty  = parseInt( $form.find( '[name="quantity"]' ).val() || 1, 10 );
        var productName = $( 'h1.product_title, h1.entry-title' ).first().text().trim();

        $form.addClass( 'mk-loading' );
        $btn.prop( 'disabled', true );

        $.ajax( {
            url:  wcAjaxUrl,
            type: 'POST',
            data: postData,

            success: function ( res ) {
                $form.removeClass( 'mk-loading' );
                $btn.prop( 'disabled', false );

                if ( ! res || res.error ) {
                    window.location.href = ( res && res.product_url )
                        ? res.product_url
                        : window.location.href;
                    return;
                }

                applyFragments( res.fragments );
                openPopup( $btn[ 0 ] );
                showAddedToast( productName );

                fireEvent( 'add_to_cart', {
                    items: [ { item_id: productId, item_name: productName, quantity: productQty } ]
                } );
            },

            error: function () {
                $form.removeClass( 'mk-loading' );
                $btn.prop( 'disabled', false );
                $form[0].submit();
            }
        } );
    }


    // ── Native WC AJAX (archive / loop buttons) ───────────────────────────────

    $( document.body ).on( 'added_to_cart', function ( e, fragments, cart_hash, $button ) {
        applyFragments( fragments );
        openPopup( $button && $button.length ? $button[ 0 ] : null );

        var addedName = $button && $button.length
            ? $button.closest( '.product' ).find( '.woocommerce-loop-product__title, h2' ).first().text().trim()
            : '';
        showAddedToast( addedName );

        if ( $button && $button.length ) {
            fireEvent( 'add_to_cart', {
                items: [ {
                    item_id:   parseInt( $button.attr( 'data-product_id' ) || 0, 10 ),
                    item_name: $button.closest( '.product' ).find( '.woocommerce-loop-product__title, h2' ).first().text().trim(),
                    quantity:  1
                } ]
            } );
        }
    } );


    // ── Fallback: page reload with ?added-to-cart= ────────────────────────────

    ( function () {
        if ( window.location.search.indexOf( 'added-to-cart' ) === -1 ) return;

        openPopup();

        if ( window.history && window.history.replaceState ) {
            try {
                var params = new URLSearchParams( window.location.search );
                params.delete( 'added-to-cart' );
                var qs = params.toString();
                window.history.replaceState(
                    null, '',
                    window.location.pathname + ( qs ? '?' + qs : '' ) + window.location.hash
                );
            } catch ( ex ) {}
        }
    }() );


    // ── Trigger: cart icon links ──────────────────────────────────────────────
    //
    // Intercepts any <a> pointing to the WooCommerce cart URL so the popup
    // opens instead of navigating — works regardless of class or markup.
    // The explicit selectors (.cart_winkelmand, .mkcp-open) remain as fallback.

    var cartPath = ( params.cart_url || '' )
        .replace( /^https?:\/\/[^\/]+/, '' )  // strip origin
        .replace( /\/$/, '' );                 // strip trailing slash

    $( document ).on( 'click', 'a[href]', function ( e ) {
        if ( ! cartPath ) return;
        var href = ( $( this ).attr( 'href' ) || '' )
            .replace( /^https?:\/\/[^\/]+/, '' )
            .replace( /\/$/, '' );
        if ( href && href === cartPath ) {
            e.preventDefault();
            openPopup();
        }
    } );

    $( document ).on( 'click', '.cart_winkelmand, .mkcp-open', function ( e ) {
        e.preventDefault();
        openPopup();
    } );


    // ── Volledig-scherm review-modus ─────────────────────────────────────────
    //
    // Puur een tijdelijke weergavekeuze — geen eigen instelling, begint bij
    // elke keer dat de drawer opent weer ingeklapt (zie closePopup()).

    $( document ).on( 'click', '.js-mkcp-expand-toggle', function () {
        var $popup   = $( POPUP );
        var expanded = $popup.toggleClass( 'is-expanded' ).hasClass( 'is-expanded' );
        $( this ).attr( 'aria-pressed', expanded ? 'true' : 'false' );

        if ( expanded ) {
            // Gestaggerde infade van de 3 kolommen (transition-delay per kolom,
            // zie cart-popup.scss) — .is-entering zet de startstand (opacity 0
            // + lichte verschuiving) gelijk met .is-expanded, en wordt pas een
            // frame later verwijderd zodat de browser die startstand eerst
            // committeert voor de transition naar de eindstand start. Dubbele
            // rAF i.p.v. één, want één rAF valt soms nog in hetzelfde frame
            // als de classList-wijziging hierboven.
            $popup.addClass( 'is-entering' );
            requestAnimationFrame( function () {
                requestAnimationFrame( function () {
                    $popup.removeClass( 'is-entering' );
                } );
            } );
        }
    } );


    // ── Close triggers ────────────────────────────────────────────────────────

    $( document ).on( 'click', '.mk-cart-popup__backdrop, .mk-cart-popup__close, .js-mk-cart-close', closePopup );

    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' || e.keyCode === 27 ) {
            if ( $( POPUP ).hasClass( OPEN_CLS ) ) closePopup();
            return;
        }

        // Focus trap: houdt Tab binnen de drawer zolang die open is, zodat een
        // toetsenbordgebruiker niet "wegtabt" naar de achterliggende pagina.
        if ( e.key !== 'Tab' || ! $( POPUP ).hasClass( OPEN_CLS ) ) return;

        var $drawer    = $( POPUP ).find( '.mk-cart-popup__drawer' );
        var $focusable = $drawer.find( FOCUSABLE_SELECTOR ).filter( ':visible' );
        if ( ! $focusable.length ) return;

        var first  = $focusable[ 0 ];
        var last   = $focusable[ $focusable.length - 1 ];
        var active = document.activeElement;

        if ( e.shiftKey && ( active === first || active === $drawer[ 0 ] ) ) {
            e.preventDefault();
            last.focus();
        } else if ( ! e.shiftKey && active === last ) {
            e.preventDefault();
            first.focus();
        }
    } );


    // ── Product name click: select_item ─────────────────────────────────────

    $( document ).on( 'click', '.mk-cart-popup__item-name', function () {
        var item = itemFromAttr( $( this ).closest( '.mk-cart-popup__item' ) );
        if ( item ) fireEvent( 'select_item', { items: [ item ] } );
    } );


    // ── Quantity buttons ─────────────────────────────────────────────────────

    function qtyUpdated() {
        showToast( 'Winkelwagen bijgewerkt', 'success' );
    }

    // Debounce per item-key: snel meermaals op +/- klikken (of het aantal
    // typen) stuurt zo hooguit één AJAX-call met de uiteindelijke waarde,
    // i.p.v. bij elke klik een eigen, mogelijk overlappende request — die
    // ook nog eens in willekeurige volgorde konden terugkomen (de oudere,
    // langzamere respons kon de nieuwere overschrijven omdat applyFragments()
    // altijd onvoorwaardelijk de laatst-ONTVANGEN respons toepast). De
    // input zelf update nog steeds direct bij elke klik — alleen de
    // netwerk-call wacht op een korte stilte.
    var qtyDebounceTimers = {};
    var QTY_DEBOUNCE_MS = 400;

    function scheduleQtyUpdate( key ) {
        clearTimeout( qtyDebounceTimers[ key ] );
        qtyDebounceTimers[ key ] = setTimeout( function () {
            delete qtyDebounceTimers[ key ];
            // Vers opgezocht i.p.v. de input bij het klikmoment vastgehouden:
            // de rij kan intussen door een fragment-refresh vervangen zijn
            // (of, bij verwijderen, helemaal weg) — dit pakt altijd de
            // huidige staat, of doet simpelweg niets als de rij er niet meer is.
            var input = $( '.mk-cart-popup__qty-input[data-key="' + key + '"]' );
            if ( ! input.length ) return;
            var qty = parseInt( input.val(), 10 );
            if ( ! qty ) return;
            cartAjax( 'mkcp_update_qty', { cart_item_key: key, qty: qty }, qtyUpdated );
        }, QTY_DEBOUNCE_MS );
    }

    $( document ).on( 'click', '.mk-cart-popup__qty-btn--plus', function () {
        if ( $( this ).is( '.is-disabled, [disabled]' ) ) return;
        var key   = $( this ).data( 'key' );
        var input = $( '.mk-cart-popup__qty-input[data-key="' + key + '"]' );
        var max   = parseInt( input.attr( 'max' ), 10 ) || 0;
        var qty   = parseInt( input.val(), 10 ) + 1;
        if ( max > 0 && qty > max ) return;
        input.val( qty );
        scheduleQtyUpdate( key );
        var item = itemFromAttr( $( this ).closest( '.mk-cart-popup__item' ) );
        if ( item ) { item.quantity = 1; fireEvent( 'add_to_cart', { items: [ item ] } ); }
    } );

    $( document ).on( 'click', '.mk-cart-popup__qty-btn--min', function () {
        var key     = $( this ).data( 'key' );
        var input   = $( '.mk-cart-popup__qty-input[data-key="' + key + '"]' );
        var min     = parseInt( input.attr( 'min' ), 10 ) || 1;
        var current = parseInt( input.val(), 10 );
        if ( current <= min ) return;
        var qty = current - 1;
        input.val( qty );
        scheduleQtyUpdate( key );
        var item = itemFromAttr( $( this ).closest( '.mk-cart-popup__item' ) );
        if ( item ) { item.quantity = 1; fireEvent( 'remove_from_cart', { items: [ item ] } ); }
    } );

    $( document ).on( 'change', '.mk-cart-popup__qty-input', function () {
        var key = $( this ).data( 'key' );
        var min = parseInt( $( this ).attr( 'min' ), 10 ) || 1;
        var max = parseInt( $( this ).attr( 'max' ), 10 ) || 0;
        var qty = Math.max( min, parseInt( $( this ).val(), 10 ) || min );
        if ( max > 0 ) qty = Math.min( qty, max );
        $( this ).val( qty );
        scheduleQtyUpdate( key );
    } );


    // ── Remove item ──────────────────────────────────────────────────────────

    $( document ).on( 'click', '.mk-cart-popup__item-remove', function ( e ) {
        e.preventDefault();
        var $item    = $( this ).closest( '.mk-cart-popup__item' );
        var key      = $( this ).data( 'key' );
        var undoData = $item.data( 'undo' );
        var item     = itemFromAttr( $item );

        $item.css( 'opacity', '0.35' );

        cartAjax( 'mkcp_remove_item', { cart_item_key: key }, function () {
            if ( undoData ) showUndoToast( undoData, item && item.item_name );
        } );

        if ( item ) {
            fireEvent( 'remove_from_cart', { items: [ item ] } );
        }
    } );


    // ── Undo toast ───────────────────────────────────────────────────────────

    function showUndoToast( undoData, productName ) {
        clearTimeout( undoTimer );
        undoPending = undoData;

        var $toast = $( '.js-mkcp-undo-toast' );
        $toast.find( '.mk-cart-popup__undo-msg' ).text(
            productName ? productName + ' verwijderd.' : 'Product verwijderd.'
        );
        $toast.addClass( 'is-visible' );

        startUndoTimer( undoMs );
    }

    // Start (of hervat) de undo-termijn en laat de timerbalk synchroon
    // aflopen. Bij een hervat-aanroep (ms < undoMs) loopt de balk verder
    // vanaf z'n bevroren breedte.
    function startUndoTimer( ms ) {
        clearTimeout( undoTimer );
        undoDeadline = Date.now() + ms;
        undoTimer = setTimeout( hideUndoToast, ms );

        var bar = document.querySelector( '.js-mkcp-undo-timer' );
        if ( ! bar ) return;
        bar.style.transition = 'none';
        if ( ms >= undoMs ) bar.style.width = '100%';
        void bar.offsetWidth; // reflow: startbreedte vastleggen vóór de transition
        bar.style.transition = 'width ' + ms + 'ms linear';
        bar.style.width = '0px';
    }

    // Aanraken of hoveren van de toast pauzeert de termijn — een undo die
    // wegtikt terwijl je 'm probeert te raken is geen undo.
    function pauseUndoTimer() {
        if ( ! undoPending ) return;
        clearTimeout( undoTimer );
        undoRemaining = Math.max( 0, undoDeadline - Date.now() );
        var bar = document.querySelector( '.js-mkcp-undo-timer' );
        if ( bar ) {
            var w = window.getComputedStyle( bar ).width;
            bar.style.transition = 'none';
            bar.style.width = w; // bevriezen op de huidige breedte
        }
    }

    function resumeUndoTimer() {
        if ( ! undoPending ) return;
        startUndoTimer( Math.max( 600, undoRemaining ) ); // altijd nog even tijd om te tikken
    }

    $( document ).on( 'touchstart mouseenter', '.js-mkcp-undo-toast', pauseUndoTimer );
    $( document ).on( 'touchend touchcancel mouseleave', '.js-mkcp-undo-toast', resumeUndoTimer );

    function hideUndoToast() {
        clearTimeout( undoTimer );
        undoPending = null;
        $( '.js-mkcp-undo-toast' ).removeClass( 'is-visible' );
    }

    $( document ).on( 'click', '.js-mkcp-undo-action', function () {
        if ( ! undoPending ) return;
        var data = undoPending;
        clearTimeout( undoTimer );
        undoPending = null;

        // Directe bevestiging in de toast zelf; de fragment-refresh van het
        // terugzetten vervangt de toast-node, dus de callback zet de
        // bevestiging opnieuw op de verse node en sluit daarna.
        var confirmRestore = function () {
            var $t = $( '.js-mkcp-undo-toast' );
            $t.find( '.mk-cart-popup__undo-msg' ).text( 'Teruggezet.' );
            $t.addClass( 'is-visible' );
            var bar = document.querySelector( '.js-mkcp-undo-timer' );
            if ( bar ) { bar.style.transition = 'none'; bar.style.width = '0px'; }
            clearTimeout( undoTimer );
            undoTimer = setTimeout( hideUndoToast, 1200 );
        };
        confirmRestore();

        cartAjax( 'mkcp_re_add_item', {
            product_id:   data.product_id,
            qty:          data.qty,
            variation_id: data.variation_id || 0,
            variation:    JSON.stringify( data.variation || {} )
        }, confirmRestore );
    } );


    // ── Cross-sell: add to cart ───────────────────────────────────────────────

    var CHECK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

    $( document ).on( 'click', '.js-mkcp-crosssell-atc', function () {
        var $btn        = $( this );
        var productId   = $btn.data( 'product-id' );
        var productName = $btn.data( 'product-name' ) || '';

        if ( ! productId || $btn.prop( 'disabled' ) ) return;
        $btn.prop( 'disabled', true ).addClass( 'is-loading' );

        $.ajax( {
            url:  wcAjaxUrl,
            type: 'POST',
            data: { product_id: productId, quantity: 1 },
            success: function ( res ) {
                if ( res && res.fragments ) {
                    $btn.removeClass( 'is-loading' ).addClass( 'is-added' ).html( CHECK_SVG );
                    fireEvent( 'add_to_cart', { items: [ { item_id: productId, item_name: productName, quantity: 1 } ] } );
                    setTimeout( function () {
                        animateCrosssellRemoval( $btn.closest( '.mk-cart-popup__crosssell-item' ), function () {
                            applyFragments( res.fragments );
                            showAddedToast( productName );
                        } );
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

    // Laat het blokje in de split-layout (volle-breedte kaart) zachtjes
    // inkrimpen vóór de fragment-refresh het vervangt — in de normale, smalle
    // slider blijft dit een instant wissel zoals voorheen, dat oogt daar prima.
    function animateCrosssellRemoval( $item, done ) {
        if ( ! $item.length || ! $( POPUP ).hasClass( 'is-expanded' ) ) {
            done();
            return;
        }

        var el = $item[ 0 ];
        el.style.maxHeight = el.scrollHeight + 'px';
        void el.offsetHeight;
        $item.addClass( 'is-leaving' );

        var finished = false;
        function finish() {
            if ( finished ) return;
            finished = true;
            el.removeEventListener( 'transitionend', onEnd );
            done();
        }
        function onEnd( e ) {
            if ( e.propertyName === 'max-height' ) finish();
        }
        el.addEventListener( 'transitionend', onEnd );

        requestAnimationFrame( function () {
            el.style.maxHeight = '0px';
        } );

        setTimeout( finish, 400 ); // vangnet als transitionend niet vuurt
    }

    // ── Verzendbalk ↔ cross-sell-kolom ────────────────────────────────────────

    $( document ).on( 'click', '.js-mkcp-progress-link', function () {
        var $col = $( POPUP + ' .mk-cart-popup__col-cross' );
        if ( ! $col.length ) return;
        $col.scrollTop( 0 );
        $col.addClass( 'is-flashing' );
        setTimeout( function () { $col.removeClass( 'is-flashing' ); }, 900 );
    } );

    // ── Cross-sell slider ─────────────────────────────────────────────────────

    var CS_CARD_W = 223; // 215px card + 8px gap

    function csUpdateNav( list ) {
        var $list  = $( list );
        var $track = $list.closest( '.mk-cart-popup__crosssell-track' );
        if ( ! $track.length ) return;
        var atStart = list.scrollLeft <= 2;
        var atEnd   = list.scrollLeft + list.clientWidth >= list.scrollWidth - 2;
        $track.toggleClass( 'can-scroll-left', ! atStart );
        $track.toggleClass( 'at-end', atEnd );
        $track.find( '.mk-cart-popup__crosssell-nav--prev' ).prop( 'hidden', atStart );
        $track.find( '.mk-cart-popup__crosssell-nav--next' ).prop( 'hidden', atEnd );
    }

    function csInitAll() {
        $( '.mk-cart-popup__crosssell-list' ).each( function () { csUpdateNav( this ); } );
    }

    $( document ).on( 'scroll', '.mk-cart-popup__crosssell-list', function () {
        csUpdateNav( this );
    } );

    $( document ).on( 'click', '.js-mkcp-cs-prev', function () {
        var list = $( this ).closest( '.mk-cart-popup__crosssell-track' )
                             .find( '.mk-cart-popup__crosssell-list' )[ 0 ];
        if ( list ) list.scrollBy( { left: -CS_CARD_W, behavior: 'smooth' } );
    } );

    $( document ).on( 'click', '.js-mkcp-cs-next', function () {
        var list = $( this ).closest( '.mk-cart-popup__crosssell-track' )
                             .find( '.mk-cart-popup__crosssell-list' )[ 0 ];
        if ( list ) list.scrollBy( { left: CS_CARD_W, behavior: 'smooth' } );
    } );

    // Mouse-wheel → horizontal scroll
    $( document ).on( 'wheel', '.mk-cart-popup__crosssell-list', function ( e ) {
        var el       = this;
        var delta    = e.originalEvent.deltaY || e.originalEvent.deltaX;
        var canRight = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
        var canLeft  = el.scrollLeft > 0;
        if ( ( delta > 0 && canRight ) || ( delta < 0 && canLeft ) ) {
            e.preventDefault();
            el.scrollLeft += delta;
        }
    } );

    // Click-and-drag scroll
    var $csDrag = null, csDragX = 0, csDragScroll = 0, csDragMoved = false;

    $( document ).on( 'mousedown', '.mk-cart-popup__crosssell-list', function ( e ) {
        $csDrag      = $( this );
        csDragX      = e.pageX;
        csDragScroll = this.scrollLeft;
        csDragMoved  = false;
        $csDrag.addClass( 'is-dragging' );
    } );

    $( document ).on( 'mousemove', function ( e ) {
        if ( ! $csDrag ) return;
        var dx = e.pageX - csDragX;
        if ( Math.abs( dx ) > 3 ) csDragMoved = true;
        if ( csDragMoved ) {
            e.preventDefault();
            $csDrag[ 0 ].scrollLeft = csDragScroll - dx;
        }
    } );

    $( document ).on( 'mouseup', function () {
        if ( $csDrag ) { $csDrag.removeClass( 'is-dragging' ); $csDrag = null; }
    } );

    csInitAll();


    // ── Coupon: apply ─────────────────────────────────────────────────────────

    $( document ).on( 'click', '.js-mkcp-apply-coupon', function () {
        var $input = $( POPUP ).find( '.js-mkcp-coupon-input' );
        var code   = $.trim( $input.val() );

        if ( ! code ) {
            showToast( 'Voer een kortingscode in.', 'error' );
            return;
        }

        var $btn    = $( this ).prop( 'disabled', true );
        var $drawer = $( '.mk-cart-popup__drawer' );

        $drawer.addClass( 'mk-loading' );

        $.ajax( {
            url:  ajaxUrl,
            type: 'POST',
            data: { action: 'mkcp_apply_coupon', nonce: nonce, coupon_code: code },

            success: function ( res ) {
                if ( res && res.success ) {
                    applyFragments( res.data && res.data.fragments );
                    $( POPUP ).find( '.js-mkcp-coupon-input' ).val( '' );
                    showToast( '”' + code.toUpperCase() + '” toegepast', 'success' );
                    fireEvent( 'apply_coupon', { coupon_code: code } );
                } else {
                    var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Ongeldige kortingscode.';
                    showToast( msg, 'error' );
                }
            },

            complete: function () {
                $drawer.removeClass( 'mk-loading' );
                $btn.prop( 'disabled', false );
            }
        } );
    } );

    // Also apply when pressing Enter in the coupon input
    $( document ).on( 'keydown', '.js-mkcp-coupon-input', function ( e ) {
        if ( e.key === 'Enter' || e.keyCode === 13 ) {
            e.preventDefault();
            $( POPUP ).find( '.js-mkcp-apply-coupon' ).trigger( 'click' );
        }
    } );


    // ── Coupon: remove ────────────────────────────────────────────────────────

    $( document ).on( 'click', '.js-mkcp-remove-coupon', function () {
        var code = $( this ).data( 'code' );
        cartAjax( 'mkcp_remove_coupon', { coupon_code: code }, function () {
            showToast( '"' + String( code ).toUpperCase() + '" verwijderd', 'neutral' );
            fireEvent( 'remove_coupon', { coupon_code: code } );
        } );
    } );


    // ── Save for later ────────────────────────────────────────────────────────

    function getSaved() {
        try { return JSON.parse( localStorage.getItem( SAVED_KEY ) || '[]' ); } catch(e) { return []; }
    }

    function setSaved( items ) {
        localStorage.setItem( SAVED_KEY, JSON.stringify( items ) );
    }

    function timeAgo( ts ) {
        var diff = Math.floor( ( Date.now() - ts ) / 1000 );
        if ( diff < 120 )    return 'zojuist bewaard';
        if ( diff < 3600 )   return 'Bewaard ' + Math.floor( diff / 60 ) + ' minuten geleden';
        if ( diff < 86400 )  return 'Bewaard ' + Math.floor( diff / 3600 ) + ' uur geleden';
        var days = Math.floor( diff / 86400 );
        return 'Bewaard ' + days + ( days === 1 ? ' dag' : ' dagen' ) + ' geleden';
    }

    var HEART_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';

    // Void/replaced elementen (img, input, br, ...) kunnen geen kind-elementen
    // bevatten — een <span> die je erin append't wordt door de browser genegeerd
    // en blijft onzichtbaar. Site-eigenaren kiezen als CSS-selector vaak per
    // ongeluk het <img>-icoontje zelf i.p.v. de omliggende link; val in dat
    // geval automatisch terug op het dichtstbijzijnde bruikbare oudership.
    var MKCP_VOID_ELEMENTS = 'img, input, br, hr, area, base, col, embed, source, track, wbr';

    function mkcpAttachBadge( $target, $badge ) {
        if ( ! $target.length ) return;
        if ( $target.is( MKCP_VOID_ELEMENTS ) ) {
            $target = $target.parent();
        }
        if ( ! $target.length ) return;
        $target.css( 'position', 'relative' ).append( $badge );
    }

    // Sommige thema's renderen het cart-icoon meerdere keren tegelijk in de DOM
    // (bv. een aparte desktop- en mobile-header die beide aanwezig zijn en met
    // CSS in/uit beeld worden geschakeld). .first() zou dan altijd aan hetzelfde
    // — mogelijk onzichtbare — icoon plakken; hang de badge daarom op elk
    // matchend icoon, met een eigen gekloonde badge per element.
    //
    // :not(.mk-cart-popup__btn) alleen was niet genoeg: de fallback-selector
    // hieronder bevat [data-cart-count], en dat attribuut staat ook op #mk-
    // cart-popup zelf (de drawer-root, zie templates/cart-popup.php) — dat
    // element werd zo zelf een "match", en .css('position','relative') erop
    // overschreef de vereiste position:fixed van de drawer (cart-popup.scss),
    // waarna de popup niet meer als overlay opende. Sluit daarom expliciet de
    // eigen plugin-DOM (drawer + peek-tab) uit, niet alleen de trigger-knop.
    function mkcpAttachBadgeToAll( selector, $badgeTemplate ) {
        $( selector )
            .filter( ':not(.mk-cart-popup__btn)' )
            .not( POPUP ).not( POPUP + ' *' )
            .not( '#mkcp-peek' ).not( '#mkcp-peek *' )
            .each( function( i ) {
                mkcpAttachBadge( $( this ), i === 0 ? $badgeTemplate : $badgeTemplate.clone() );
            } );
    }

    // Aantal-in-winkelwagen badge op het eigen cart-icoon van het thema.
    // De teller komt uit het data-cart-count attribuut op #mk-cart-popup, dat
    // bij elke fragment-refresh (add/verwijder/aantal-wijziging) meekomt vanuit
    // WC()->cart->get_cart_contents_count() — zie templates/cart-popup.php.
    function updateCartCountBadge() {
        if ( ! cartCountBadgeEnabled ) return;

        var count = parseInt( $( POPUP ).attr( 'data-cart-count' ) || 0, 10 );
        $( '.mkcp-cart-count-badge' ).remove();
        if ( count < 1 ) return;

        var $badge = $( '<span class="mkcp-cart-count-badge mkcp-cart-count-badge--' + cartCountBadgePosition + '" aria-label="' + count + ' in winkelwagen">' +
            count +
            '</span>' );
        var selector = cartCountBadgeSelector
            ? cartCountBadgeSelector
            : DEFAULT_CART_ICON_SELECTOR;
        mkcpAttachBadgeToAll( selector, $badge );
    }

    // Peek-tab (mobiele app-ervaring) staat los van de fragment-template
    // (zie mk-cart-popup.php) — alleen de teller en zichtbaarheid volgen
    // hier het data-cart-count-attribuut van #mk-cart-popup, net als de
    // thema-cart-icoon-badge hierboven.
    function updatePeekTab() {
        var $peek = $( '#mkcp-peek' );
        if ( ! $peek.length ) return;
        var count = parseInt( $( POPUP ).attr( 'data-cart-count' ) || 0, 10 );
        $peek.attr( 'data-cart-count', count );
        $peek.find( '.js-mkcp-peek-count' ).text( count );
        $peek.prop( 'hidden', count < 1 );
    }

    function updateSavedBadge() {
        var count = getSaved().length;
        $( '.mkcp-cart-saved-badge' ).remove();
        if ( count < 1 ) return;
        var $badge = $( '<span class="mkcp-cart-saved-badge mkcp-cart-saved-badge--' + cartBadgePosition + '" aria-label="' + count + ' bewaard voor later">' +
            HEART_SVG + count +
            '</span>' );
        var selector = cartIconSelector
            ? cartIconSelector
            : DEFAULT_CART_ICON_SELECTOR;
        mkcpAttachBadgeToAll( selector, $badge );
    }

    var STOCK_CACHE_KEY = 'mkcp_stock_cache';
    var STOCK_CACHE_TTL = 5 * 60 * 1000; // 5 minutes

    function getStockCache() {
        try {
            var raw = JSON.parse( localStorage.getItem( STOCK_CACHE_KEY ) || 'null' );
            if ( raw && raw.ts && ( Date.now() - raw.ts ) < STOCK_CACHE_TTL ) return raw.data;
        } catch(e) {}
        return null;
    }

    function setStockCache( data ) {
        try { localStorage.setItem( STOCK_CACHE_KEY, JSON.stringify( { ts: Date.now(), data: data } ) ); } catch(e) {}
    }

    function applyStockBadges( data ) {
        $( '.js-mkcp-saved-item' ).each( function() {
            var $item = $( this );
            var pid   = parseInt( $item.data( 'product-id' ), 10 );
            if ( ! pid || ! data[ pid ] ) return;
            $item.find( '.js-mkcp-saved-stock' ).remove();
            var info = data[ pid ];
            if ( info.out_of_stock ) {
                $item.find( '.mk-cart-popup__saved-item-info' ).append(
                    '<span class="mk-cart-popup__saved-stock-badge js-mkcp-saved-stock is-out">Niet op voorraad</span>'
                );
            } else if ( info.low_stock ) {
                $item.find( '.mk-cart-popup__saved-item-info' ).append(
                    '<span class="mk-cart-popup__saved-stock-badge js-mkcp-saved-stock is-low">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                    'Nog maar ' + parseInt( info.stock_qty, 10 ) + ' op voorraad</span>'
                );
            }
        } );
    }

    function checkSavedStock() {
        var saved = getSaved();
        var ids   = [];
        $.each( saved, function( i, item ) {
            if ( item.productId && $.inArray( item.productId, ids ) === -1 ) ids.push( item.productId );
        } );
        if ( ! ids.length ) return;

        // Apply cached data immediately so the badge shows without waiting for AJAX
        var cached = getStockCache();
        if ( cached ) applyStockBadges( cached );

        // Always refresh in the background; update cache and badges on response
        $.post( ajaxUrl, { action: 'mkcp_check_stock', nonce: nonce, product_ids: ids }, function( res ) {
            if ( ! res.success ) return;
            setStockCache( res.data );
            applyStockBadges( res.data );
        } );
    }

    function renderSavedItems() {
        if ( ! saveForLater ) return;
        var $section = $( '.js-mkcp-saved-section' );
        if ( ! $section.length ) return;

        var items  = getSaved();
        var $list  = $section.find( '.js-mkcp-saved-list' );
        var $count = $section.find( '.js-mkcp-saved-count' );

        if ( ! items.length ) {
            $section.hide();
            updateSavedBadge();
            return;
        }

        $count.text( items.length );
        $list.empty();

        $.each( items, function( idx, item ) {
            var $row  = $( '<div class="mk-cart-popup__saved-item js-mkcp-saved-item"></div>' );
            $row.attr( 'data-product-id', item.productId || '' );

            // Thumbnail
            if ( item.thumb ) {
                var $img = $( '<img class="mk-cart-popup__saved-thumb" alt="" loading="lazy">' ).attr( 'src', item.thumb );
                $row.append( $img );
            } else {
                $row.append( '<div class="mk-cart-popup__saved-thumb-placeholder"></div>' );
            }

            // Info column
            var $info = $( '<div class="mk-cart-popup__saved-item-info"></div>' );
            $info.append( $( '<span class="mk-cart-popup__saved-item-name"></span>' ).text( item.name ) );
            if ( item.price ) {
                $info.append( $( '<span class="mk-cart-popup__saved-item-price"></span>' ).text( item.price ) );
            }
            if ( item.savedAt ) {
                $info.append( $( '<span class="mk-cart-popup__saved-item-time"></span>' ).text( timeAgo( item.savedAt ) ) );
            }
            $row.append( $info );

            // Actions
            var $actions = $( '<div class="mk-cart-popup__saved-item-actions"></div>' );
            var $btn = $( '<button type="button" class="mk-cart-popup__saved-restore js-mkcp-restore-saved">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.36"/></svg>' +
                '<span>Terugzetten</span>' +
                '</button>' ).data( 'idx', idx );
            var $del = $( '<button type="button" class="mk-cart-popup__saved-delete js-mkcp-delete-saved" aria-label="Verwijderen">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                '</button>' ).data( 'idx', idx );
            $actions.append( $btn ).append( $del );
            $row.append( $actions );
            $list.append( $row );
        } );

        $section.show();
        updateSavedBadge();
        if ( $( POPUP ).hasClass( OPEN_CLS ) ) {
            checkSavedStock();
        }
    }

    // Spiegelt de lokale (localStorage) "bewaar voor later"-opslag naar de
    // wishlist van ingelogde premium-klanten — puur best-effort, geen
    // wachten/blokkeren en geen foutafhandeling nodig: de localStorage-opslag
    // hierboven blijft de bron van waarheid voor de cart-popup-UI zelf, dit
    // is alleen een cross-device-back-up (zie account-tab-overleg). Werkt
    // niet voor gasten/basic-klanten — mkcp_wishlist_params bestaat dan
    // simpelweg niet (zie includes/wishlist-icon.php).
    function mirrorSavedToWishlist( productId ) {
        if ( ! productId || typeof mkcp_wishlist_params === 'undefined' ) return;
        $.post( mkcp_wishlist_params.ajax_url, {
            action:     'mkcp_account_wishlist_item_add',
            nonce:      mkcp_wishlist_params.nonce,
            product_id: productId
        } );
    }

    $( document ).on( 'click', '.js-mkcp-save-later', function() {
        if ( ! saveForLater ) return;
        var $btn     = $( this );
        var key      = $btn.data( 'key' );
        var undoData = $btn.data( 'undo' );
        var name     = $btn.data( 'name' );
        var thumb    = $btn.data( 'thumb' )      || '';
        var price    = $btn.data( 'price' )      || '';
        var pid      = parseInt( $btn.data( 'product-id' ), 10 ) || 0;

        var saved = getSaved();
        saved.push( { name: name, undo: undoData, thumb: thumb, price: price, savedAt: Date.now(), productId: pid } );
        setSaved( saved );
        mirrorSavedToWishlist( pid );

        cartAjax( 'mkcp_remove_item', { cart_item_key: key }, function() {
            renderSavedItems();
            showToast( '"' + name + '" bewaard voor later', 'neutral' );
        } );
    } );

    $( document ).on( 'click', '.js-mkcp-restore-saved', function() {
        var idx   = $( this ).data( 'idx' );
        var saved = getSaved();
        var item  = saved[ idx ];
        if ( ! item ) return;

        saved.splice( idx, 1 );
        setSaved( saved );

        var data = item.undo;
        cartAjax( 'mkcp_re_add_item', {
            product_id:   data.product_id,
            qty:          data.qty,
            variation_id: data.variation_id || 0,
            variation:    JSON.stringify( data.variation || {} )
        }, function() {
            renderSavedItems();
            showToast( '"' + item.name + '" terug in winkelwagen', 'success' );
        } );
    } );

    $( document ).on( 'click', '.js-mkcp-delete-saved', function() {
        var idx   = $( this ).data( 'idx' );
        var saved = getSaved();
        saved.splice( idx, 1 );
        setSaved( saved );
        renderSavedItems();
    } );

    // Re-render saved items whenever fragments refresh (cart updated)
    $( document ).on( 'wc_fragments_refreshed', renderSavedItems );

    // Initial render
    renderSavedItems();
    updateCartCountBadge();


    // ── Checkout button: analytics + block when disabled ──────────────────────

    $( document ).on( 'click', '.mk-cart-popup__btn--primary', function ( e ) {
        if ( $( this ).hasClass( 'is-disabled' ) ) {
            e.preventDefault();
            return;
        }

        var items = allItems();
        if ( items.length ) {
            fireEvent( 'begin_checkout', { items: items } );
        }

        if ( wcStats ) {
            $.post( ajaxUrl, { action: 'mkcp_mark_assist', nonce: nonce } );
        }
    } );


    // ── Winkelmand bewaren: URL genereren + kopiëren + mail ───────────────────

    // Toggle: share-balk in-/uitklappen
    $( document ).on( 'click', '.js-mkcp-share-toggle', function() {
        var $btn  = $( this );
        var $body = $btn.siblings( '.js-mkcp-share-body' );
        var open  = $btn.attr( 'aria-expanded' ) === 'true';
        $btn.attr( 'aria-expanded', open ? 'false' : 'true' );
        $body.slideToggle( 180 );
    } );

    var saveCacheUrl   = null;
    var saveCacheScope = null;

    function getShareScope() {
        var $active = $( '.js-mkcp-scope-pill.is-active' );
        return $active.length ? ( $active.data( 'scope' ) || 'cart' ) : 'cart';
    }

    function buildSavePayload( extra ) {
        var scope = getShareScope();
        var data  = $.extend( { scope: scope }, extra );
        if ( scope === 'saved' || scope === 'both' ) {
            var savedUndo = [];
            $.each( getSaved(), function( i, item ) {
                if ( item.undo && item.undo.product_id ) savedUndo.push( item.undo );
            } );
            data.saved_items = JSON.stringify( savedUndo );
        }
        return data;
    }

    // Scope-pill wisselen: cache ongeldig maken
    $( document ).on( 'click', '.js-mkcp-scope-pill', function() {
        $( '.js-mkcp-scope-pill' ).removeClass( 'is-active' );
        $( this ).addClass( 'is-active' );
        saveCacheUrl   = null;
        saveCacheScope = null;
        $( '.js-mkcp-url-result' ).hide();
    } );

    // Reset URL-cache als de winkelwagen verandert (fragmenten vernieuwd)
    $( document ).on( 'wc_fragments_refreshed', function() {
        saveCacheUrl   = null;
        saveCacheScope = null;
        $( '.js-mkcp-url-result' ).hide();
    } );

    $( document ).on( 'click', '.js-mkcp-gen-url', function() {
        var $btn  = $( this );
        var scope = getShareScope();

        if ( saveCacheUrl && saveCacheScope === scope ) {
            showSaveUrl( saveCacheUrl );
            return;
        }

        var origHtml = $btn.html();
        $btn.prop( 'disabled', true ).text( 'Bezig…' );

        var data = buildSavePayload( { action: 'mkcp_generate_save_url', nonce: nonce } );

        $.post( ajaxUrl, data, function( res ) {
            $btn.prop( 'disabled', false ).html( origHtml );
            if ( res && res.success && res.data.url ) {
                saveCacheUrl   = res.data.url;
                saveCacheScope = scope;
                showSaveUrl( res.data.url );
            } else {
                showToast( ( res && res.data && res.data.message ) || 'Kon geen link genereren.', 'error' );
            }
        } );
    } );

    function showSaveUrl( url ) {
        var $result = $( '.js-mkcp-url-result' );
        $result.find( '.js-mkcp-url-input' ).val( url );
        $result.show();
    }

    $( document ).on( 'click', '.js-mkcp-copy-url', function() {
        var url = $( '.js-mkcp-url-input' ).val();
        if ( ! url ) return;

        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( url ).then( function() {
                showToast( 'Link gekopieerd!', 'success' );
            } );
        } else {
            var $tmp = $( '<textarea>' ).val( url ).css( { position: 'fixed', top: '-9999px' } ).appendTo( 'body' );
            $tmp[0].select();
            try { document.execCommand( 'copy' ); showToast( 'Link gekopieerd!', 'success' ); } catch(e) {}
            $tmp.remove();
        }
    } );

    $( document ).on( 'click', '.js-mkcp-send-mail', function() {
        var $btn      = $( this );
        var $wrap     = $btn.closest( '.mk-cart-popup__share-email-wrap' );
        var $input    = $wrap.find( '.js-mkcp-mail-input' );
        var $feedback = $wrap.find( '.js-mkcp-mail-feedback' );
        var email     = $.trim( $input.val() );

        $feedback.text( '' ).removeClass( 'is-error is-success' );

        if ( ! email ) {
            $feedback.text( 'Voer een e-mailadres in.' ).addClass( 'is-error' );
            return;
        }
        if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
            $feedback.text( 'Geen geldig e-mailadres.' ).addClass( 'is-error' );
            return;
        }

        $btn.prop( 'disabled', true );

        var data = buildSavePayload( { action: 'mkcp_send_cart_email', nonce: nonce, email: email } );

        $.post( ajaxUrl, data, function( res ) {
            $btn.prop( 'disabled', false );
            if ( res && res.success ) {
                $feedback.text( res.data.message || 'Mail verzonden!' ).addClass( 'is-success' );
                $input.val( '' );
            } else {
                var msg = ( res && res.data && res.data.message ) || 'Kon mail niet verzenden.';
                $feedback.text( msg ).addClass( 'is-error' );
            }
        } );
    } );


    // ── Variatie-prijsbadges ──────────────────────────────────────────────────
    // Detecteert "(+ €1,50)" of "(- €2,00)" patronen in variatie-waarden
    // en herschrijft ze als een visuele badge. Werkt generiek voor elke winkel.

    // Matcht alleen echte prijstoeslagen: (+ €1,50) / (- $2.00) / (+ £3)
    // Vereist valutasymbool + cijfer — pakt geen vrije tekst zoals "(+ maat)".
    var SURCHARGE_RE = /(\([+\-−]\s*[€$£¥₹]\s*[\d]+[.,\d]*\))/g;

    function styleVariationPrices() {
        document.querySelectorAll( '.variation dd' ).forEach( function( dd ) {
            if ( dd.dataset.mkcpPriced ) return;
            dd.innerHTML = dd.innerHTML.replace(
                SURCHARGE_RE,
                '<span class="mkcp-var-surcharge">$1</span>'
            );
            dd.dataset.mkcpPriced = '1';
        } );
    }

    styleVariationPrices();
    $( document ).on( 'wc_fragments_refreshed', styleVariationPrices );

    } // /mkcpInit

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', mkcpInit );
    } else {
        mkcpInit();
    }

} )( jQuery );
