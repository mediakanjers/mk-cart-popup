/**
 * MK Cart Popup — mobiele app-ervaring (premium, Instellingen → Styling).
 *
 * Alleen geladen als de toggle 'mobile_app_experience' aanstaat (zie
 * mk-cart-popup.php). Doet drie dingen, uitsluitend op schermen < 720px
 * wanneer de drawer de klasse .mk-cart-popup--app draagt:
 *
 *  1. Bottom-sheet-drag: de drawer (dan een sheet, zie cart-popup.scss)
 *     volgt de vinger bij omlaag slepen op de grabber/header — of op de
 *     body als die bovenaan gescrold staat — en sluit voorbij een drempel
 *     of bij een snelle flick; anders veert hij terug.
 *  2. Swipe-to-remove mét bevestig-stap: een rij naar links vegen laat 'm
 *     open staan tegen een rode "Verwijder"-knop; pas een tik op die knop
 *     triggert de bestaande remove-knop van die rij (AJAX + undo-toast
 *     draaien ongewijzigd). Elke andere aanraking sluit de rij weer.
 *  3. Haptische feedback via navigator.vibrate() (Android; iOS negeert dit).
 *  4. Peek-tab: een losse, altijd-aanwezige knop (buiten #mk-cart-popup,
 *     zie mk-cart-popup.php) die zichtbaar is zolang de drawer dicht staat.
 *     Een tik opent 'm via de bestaande .mkcp-open-click-handler; omhoog
 *     vegen doet hetzelfde na het passeren van een drempel of bij een
 *     snelle flick (zone 'peek' in de state machine hieronder).
 *
 * Belangrijk: applyFragments() in cart-popup.js vervangt bij elke
 * cart-mutatie de complete #mk-cart-popup-node. Daarom staan alle listeners
 * op document (die overleven dat) en reset wc_fragments_refreshed een
 * eventueel lopend gebaar (abortGesture).
 */
jQuery( function ( $ ) {
    'use strict';

    var POPUP     = '#mk-cart-popup';
    var APP_CLS   = 'mk-cart-popup--app';
    var SHEET_MQ  = window.matchMedia( '(max-width: 719px)' );
    var REDUCE_MQ = window.matchMedia( '(prefers-reduced-motion: reduce)' );

    // Bewegingstaal (zelfde curves als de tokens in cart-popup.scss):
    // spring = drempel & snap-open (lichte overshoot), exit = verwijderen
    // (versnelt het beeld uit), collapse = de lijst die rustig dichtklapt.
    var EASE_SPRING   = 'cubic-bezier(0.34, 1.56, 0.64, 1)';
    var EASE_EXIT     = 'cubic-bezier(0.4, 0, 1, 1)';
    var EASE_COLLAPSE = 'cubic-bezier(0.4, 0, 0.2, 1)';

    var TRASH_SVG =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
        '<polyline points="3 6 5 6 21 6"/>' +
        '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>' +
        '<path d="M10 11v6M14 11v6"/>' +
        '<path d="M9 6V4h6v2"/>' +
        '</svg>';

    function popupEl() {
        return document.getElementById( 'mk-cart-popup' );
    }

    // Alleen actief als sheet-modus daadwerkelijk rendert: app-klasse
    // aanwezig, drawer open, én telefoon-breakpoint. Per touchstart
    // geëvalueerd, dus rotatie/resize heeft geen aparte listener nodig.
    function appActive() {
        var p = popupEl();
        return !! p && p.classList.contains( APP_CLS ) && p.classList.contains( 'is-open' ) && SHEET_MQ.matches;
    }

    // Peek-tab: het spiegelbeeld van appActive() — relevant is juist wanneer
    // de drawer DICHT staat (de knop is dan zichtbaar, zie cart-popup.scss).
    function peekActive() {
        var p = popupEl();
        return !! p && p.classList.contains( APP_CLS ) && ! p.classList.contains( 'is-open' ) && SHEET_MQ.matches;
    }


    // ── Haptische feedback ───────────────────────────────────────────────────

    function mkcpHaptic( pattern ) {
        var p = popupEl();
        if ( ! p || ! p.classList.contains( APP_CLS ) ) return;
        if ( REDUCE_MQ.matches ) return;
        if ( ! ( 'vibrate' in navigator ) ) return;
        try { navigator.vibrate( pattern ); } catch ( e ) { /* stil negeren */ }
    }

    $( document ).on( 'click', '.mk-cart-popup__item-remove', function () { mkcpHaptic( 20 ); } );
    $( document ).on( 'click', '.js-mkcp-undo-action',        function () { mkcpHaptic( 15 ); } );
    $( document.body ).on( 'mkcp_added_toast',                function () { mkcpHaptic( 15 ); } );
    // Dekt zowel een gewone tik als het omhoog-vegen (dat ook .trigger('click')
    // op #mkcp-peek doet, zie onTouchEnd) — één plek voor de open-bevestiging.
    $( document ).on( 'click', '#mkcp-peek',                   function () { mkcpHaptic( 10 ); } );


    // ── Gesture state machine ────────────────────────────────────────────────
    //
    // Modes: idle → pending → (sheet-drag | row-drag | scroll) → idle.
    // 'scroll' = het gebaar is aan native scrollen afgestaan tot touchend.

    var S = {
        mode      : 'idle',
        zone      : '',      // 'handle' | 'body' | 'peek'
        startX    : 0,
        startY    : 0,
        lastPos   : 0,       // laatste x (row) of y (sheet/peek) voor velocity
        lastT     : 0,
        vel       : 0,       // gladgestreken px/ms (positief = omlaag/rechts)
        armed     : false,   // voorbij de drempel — loslaten voert de actie uit
        drawer    : null,
        backdrop  : null,
        bodyEl    : null,
        itemEl    : null,
        overlayEl : null,
        peekEl    : null,
        sheetH    : 0,
        rafId     : 0,       // geplande frame-write tijdens row-drag
        pendingTx : 0,       // laatst berekende translateX (rAF schrijft 'm)
        lastArmHaptic : 0    // throttle: max 1 drempel-puls per 300ms
    };

    function resetState() {
        if ( S.rafId ) { cancelAnimationFrame( S.rafId ); S.rafId = 0; }
        S.mode = 'idle';
        S.zone = '';
        S.armed = false;
        S.vel = 0;
        S.pendingTx = 0;
        S.drawer = S.backdrop = S.bodyEl = S.itemEl = S.overlayEl = S.peekEl = null;
    }

    // Ruimt de progressie-variabele (+ will-change) op die tijdens een
    // row-drag op de lijst/rij stonden.
    function clearRowProgress( itemEl ) {
        if ( ! itemEl ) return;
        itemEl.style.willChange = '';
        if ( itemEl.parentElement ) {
            itemEl.parentElement.style.removeProperty( '--mkcp-swipe-p' );
        }
    }

    function removeOverlay() {
        if ( S.overlayEl && S.overlayEl.parentNode ) {
            S.overlayEl.parentNode.removeChild( S.overlayEl );
        }
        S.overlayEl = null;
    }

    // Lopend gebaar afbreken zonder actie — o.a. bij fragment-vervanging
    // mid-gesture (de node waar we aan sleepten bestaat dan niet meer).
    function abortGesture() {
        if ( S.mode === 'idle' ) return;
        if ( S.drawer && document.body.contains( S.drawer ) ) {
            S.drawer.style.transition = '';
            S.drawer.style.transform  = '';
        }
        if ( S.backdrop && document.body.contains( S.backdrop ) ) {
            S.backdrop.style.opacity = '';
        }
        if ( S.itemEl && document.body.contains( S.itemEl ) ) {
            S.itemEl.style.transition = '';
            S.itemEl.style.transform  = '';
            S.itemEl.classList.remove( 'is-swiping' );
            clearRowProgress( S.itemEl );
        }
        if ( S.peekEl && document.body.contains( S.peekEl ) ) {
            S.peekEl.style.transition = '';
            S.peekEl.style.transform  = '';
        }
        removeOverlay();
        resetState();
    }

    $( document ).on( 'wc_fragments_refreshed', function () {
        abortGesture();
        // Een openstaande bevestig-knop hoorde bij de zojuist vervangen
        // node — alleen de referenties wissen, de DOM is al opgeruimd.
        revealed.itemEl = revealed.overlayEl = null;
    } );

    function trackVelocity( pos, now ) {
        var dt = Math.max( 1, now - S.lastT );
        var v  = ( pos - S.lastPos ) / dt;
        S.vel  = 0.6 * v + 0.4 * S.vel;
        S.lastPos = pos;
        S.lastT   = now;
    }


    // ── Bevestig-stap voor verwijderen ───────────────────────────────────────
    //
    // Een swipe verwijdert niet direct: de rij blijft open staan tegen een
    // rode "Verwijder"-knop, en pas een tik op die knop verwijdert echt
    // (de "weet je het zeker"-stap). Elke andere aanraking sluit de rij.

    var REVEAL_W = 112;              // px die de rij open blijft staan (drag-afstand tot de knop)
    var ARM_PX   = REVEAL_W * 0.5;   // drempel: hier slaat de intentie om (progressie = 1)
    var BTN_GAP  = 8;                // lucht tussen de knop en de container-rand + de content ernaast
    var BTN_W    = REVEAL_W - BTN_GAP; // eigen breedte van de rode knop (smaller dan de drag-afstand)

    var revealed = { itemEl: null, overlayEl: null };

    function openReveal( itemEl, overlayEl ) {
        closeReveal(); // hooguit één rij tegelijk open
        // Landen met een lichte spring-overshoot — het enige overshoot-moment
        // in de hele interactie (massa suggereren waar betekenis zit).
        itemEl.style.transition = REDUCE_MQ.matches ? '' : 'transform 260ms ' + EASE_SPRING;
        itemEl.style.transform  = 'translateX(-' + REVEAL_W + 'px)';
        // Kaart-dim opheffen: in de bevestig-staat moet het product juist
        // goed leesbaar zijn (verifieer wat je gaat verwijderen).
        clearRowProgress( itemEl );
        // Exacte breedte forceren (i.p.v. wat de drag toevallig bereikte,
        // vaak nog niet de volle breedte bij loslaten net over de drempel)
        // — met een eigen, korte transition zodat de knop vloeiend "inhaalt"
        // in plaats van te springen. Buiten de drag-loop is dit veilig
        // (rowFrame() schrijft alleen tijdens mode === 'row-drag').
        overlayEl.style.transition = REDUCE_MQ.matches ? '' : 'width 260ms ' + EASE_SPRING + ', opacity 220ms ease';
        overlayEl.style.width = BTN_W + 'px';
        overlayEl.classList.add( 'is-open' );
        overlayEl.classList.remove( 'is-armed' );
        overlayEl.removeAttribute( 'aria-hidden' );
        revealed.itemEl    = itemEl;
        revealed.overlayEl = overlayEl;
        mkcpHaptic( 10 );
    }

    // De DOM-node van een gesloten knop blijft nog even (240ms) staan zodat
    // de snap-back-animatie kan afspelen — maar klasse/klikbaarheid worden
    // HIER AL synchroon opgeruimd. Zonder dat kon een tweede swipe binnen
    // die 240ms een nieuwe knop naast de nog-niet-verwijderde oude
    // (nog steeds .is-open, dus nog klikbaar) plaatsen, die dan de tik op
    // "Verwijder" wegving — de gemelde "tweede swipe doet niks"-bug.
    function snapBackRow( itemEl, overlayEl ) {
        itemEl.style.transition = '';
        itemEl.style.transform  = '';
        clearRowProgress( itemEl ); // dims faden terug via hun eigen transitions

        if ( overlayEl ) {
            overlayEl.classList.remove( 'is-open', 'is-armed' );
            overlayEl.style.pointerEvents = 'none';
            overlayEl.setAttribute( 'aria-hidden', 'true' );
        }

        setTimeout( function () {
            itemEl.classList.remove( 'is-swiping' );
            if ( overlayEl && overlayEl.parentNode ) overlayEl.parentNode.removeChild( overlayEl );
        }, 240 );
    }

    function closeReveal() {
        if ( ! revealed.itemEl ) return;
        if ( document.body.contains( revealed.itemEl ) ) {
            snapBackRow( revealed.itemEl, revealed.overlayEl );
        } else if ( revealed.overlayEl && revealed.overlayEl.parentNode ) {
            revealed.overlayEl.parentNode.removeChild( revealed.overlayEl );
        }
        revealed.itemEl = revealed.overlayEl = null;
    }

    // Tik op de rode knop = bevestigen: de kaart glijdt uit beeld (exit),
    // direct gevolgd door het dichtklappen van de vrijgekomen hoogte — de
    // lijst heelt vóór de server antwoordt. De bestaande remove-flow
    // (AJAX + undo-toast + analytics) draait ondertussen ongewijzigd.
    $( document ).on( 'click', '.mk-cart-popup__swipe-delete.is-open', function () {
        var overlay = this;
        var itemEl  = revealed.itemEl;
        revealed.itemEl = revealed.overlayEl = null;
        if ( ! itemEl || ! document.body.contains( itemEl ) ) return;

        overlay.disabled = true; // dubbele tik onmogelijk
        var rowH   = itemEl.offsetHeight; // gemeten vóór de exit (enige read)
        var reduce = REDUCE_MQ.matches;

        itemEl.classList.add( 'is-removing' );
        itemEl.style.transition = reduce ? 'none'
            : 'transform 200ms ' + EASE_EXIT + ', opacity 200ms ' + EASE_EXIT;
        itemEl.style.transform  = 'translateX(-110%)';
        itemEl.style.opacity    = '0';
        $( itemEl ).find( '.mk-cart-popup__item-remove' ).trigger( 'click' );

        // Collapse: 40ms overlap met de exit zodat het als één gebaar leest.
        setTimeout( function () {
            if ( ! document.body.contains( itemEl ) ) return; // fragments waren sneller
            var clsp = reduce ? 'none'
                : 'transform 200ms ' + EASE_EXIT + ', opacity 200ms ' + EASE_EXIT
                + ', height 200ms ' + EASE_COLLAPSE + ', padding 200ms ' + EASE_COLLAPSE
                + ', margin 200ms ' + EASE_COLLAPSE;
            itemEl.style.height   = rowH + 'px';
            itemEl.style.overflow = 'hidden';
            void itemEl.offsetHeight; // reflow: starthoogte vastleggen
            itemEl.style.transition     = clsp;
            itemEl.style.height         = '0px';
            itemEl.style.paddingTop     = '0px';
            itemEl.style.paddingBottom  = '0px';
            itemEl.style.marginTop      = '0px';
            itemEl.style.marginBottom   = '0px';
            if ( overlay.parentNode ) {
                overlay.style.transition = reduce ? 'none' : 'height 200ms ' + EASE_COLLAPSE + ', opacity 200ms ease';
                overlay.style.height  = '0px';
                overlay.style.opacity = '0';
            }
        }, reduce ? 0 : 160 );

        // Vangnet voor als de AJAX faalt en er dus geen fragment-refresh komt.
        setTimeout( function () {
            if ( overlay.parentNode ) overlay.parentNode.removeChild( overlay );
        }, 1200 );
    } );


    // ── touchstart ───────────────────────────────────────────────────────────

    function onTouchStart( e ) {
        if ( e.touches.length !== 1 ) {
            // Tweede vinger erbij mid-gesture: netjes afbreken.
            if ( S.mode !== 'idle' ) abortGesture();
            return;
        }

        // Elke aanraking die níet op de open bevestig-knop landt, sluit 'm
        // weer — de tik op de knop zelf laat de click-handler z'n werk doen.
        if ( revealed.itemEl && ! ( e.target instanceof Element && e.target.closest( '.mk-cart-popup__swipe-delete.is-open' ) ) ) {
            closeReveal();
        }

        // Peek-tab: apart pad met eigen precondities (relevant is juist
        // wanneer de drawer DICHT staat, dus buiten de appActive()-guard
        // hieronder om). Een gewone tik loopt hier niet doorheen — die
        // wordt afgehandeld door de bestaande .mkcp-open-click-handler in
        // cart-popup.js (er wordt pas preventDefault() aangeroepen zodra
        // dit gebaar echt in een omhoog-veeg verandert, zie onTouchMove).
        var target = e.target;
        if ( target instanceof Element && target.closest( '#mkcp-peek' ) && peekActive() ) {
            S.zone   = 'peek';
            S.peekEl = document.getElementById( 'mkcp-peek' );
            S.startX = e.touches[0].clientX;
            S.startY = e.touches[0].clientY;
            S.lastT  = e.timeStamp;
            S.vel    = 0;
            S.armed  = false;
            S.mode   = 'pending';
            return;
        }

        if ( ! appActive() ) return;

        var t = e.target;
        if ( ! ( t instanceof Element ) || ! t.closest( POPUP ) ) return;
        // Toasts hebben eigen interactie (undo-knop) — nooit hijacken.
        if ( t.closest( '.mk-cart-popup__undo-toast, .mk-cart-popup__added-toast' ) ) return;

        if ( t.closest( '.mk-cart-popup__grabber, .mk-cart-popup__header' ) ) {
            S.zone   = 'handle';
            S.itemEl = null;
        } else if ( t.closest( '.mk-cart-popup__body' ) ) {
            S.zone   = 'body';
            // Alleen echte cart-rijen (directe kinderen van __items) — niet
            // saved-for-later-rijen of cross-sell-kaarten.
            var item = t.closest( '.mk-cart-popup__item' );
            S.itemEl = ( item && item.parentElement && item.parentElement.classList.contains( 'mk-cart-popup__items' ) ) ? item : null;
        } else {
            return; // footer/ctas: nooit een sleepzone
        }

        var p = popupEl();
        S.drawer   = p.querySelector( '.mk-cart-popup__drawer' );
        S.backdrop = p.querySelector( '.mk-cart-popup__backdrop' );
        S.bodyEl   = p.querySelector( '.mk-cart-popup__body' );
        if ( ! S.drawer ) { resetState(); return; }

        S.sheetH  = S.drawer.getBoundingClientRect().height || 1;
        S.startX  = e.touches[0].clientX;
        S.startY  = e.touches[0].clientY;
        S.lastT   = e.timeStamp;
        S.vel     = 0;
        S.armed   = false;
        S.mode    = 'pending';
    }


    // ── touchmove ────────────────────────────────────────────────────────────

    function onTouchMove( e ) {
        if ( S.mode === 'idle' || S.mode === 'scroll' ) return;
        if ( S.zone !== 'peek' && ( ! S.drawer || ! document.body.contains( S.drawer ) ) ) { abortGesture(); return; }
        if ( S.zone === 'peek' && S.peekEl && ! document.body.contains( S.peekEl ) ) { abortGesture(); return; }

        var x  = e.touches[0].clientX;
        var y  = e.touches[0].clientY;
        var dx = x - S.startX;
        var dy = y - S.startY;

        // Intent-lock: pas na 10px beweging beslissen wat dit gebaar is.
        if ( S.mode === 'pending' ) {
            if ( Math.abs( dx ) < 10 && Math.abs( dy ) < 10 ) return;

            if ( S.zone === 'peek' ) {
                if ( dy < 0 ) {
                    // Omhoog: dit wordt een echte sleepbeweging — vanaf hier
                    // mag de pagina niet meer meescrollen.
                    S.mode = 'peek-drag';
                    S.peekEl.style.transition = 'none';
                    S.lastPos = y;
                } else {
                    S.mode = 'scroll'; // omlaag op de tab: geen actie, negeren
                    return;
                }
            } else if ( Math.abs( dx ) > Math.abs( dy ) * 1.2 ) {
                // Horizontaal dominant.
                if ( dx < 0 && S.itemEl ) {
                    S.mode = 'row-drag';
                    S.itemEl.classList.add( 'is-swiping' );
                    S.itemEl.style.transition = 'none';
                    S.itemEl.style.willChange = 'transform'; // alleen tijdens het gebaar
                    insertOverlay();
                    S.lastPos = x;
                } else {
                    S.mode = 'scroll'; // naar rechts / cross-sell-pan: native laten
                    return;
                }
            } else {
                // Verticaal dominant.
                var sheetIntent = S.zone === 'handle' ||
                    ( S.zone === 'body' && dy > 0 && S.bodyEl && S.bodyEl.scrollTop <= 0 );
                if ( sheetIntent ) {
                    S.mode = 'sheet-drag';
                    S.drawer.style.transition = 'none';
                    S.lastPos = y;
                } else {
                    S.mode = 'scroll';
                    return;
                }
            }
        }

        if ( S.mode === 'sheet-drag' ) {
            e.preventDefault(); // blokkeert pagina-scroll + pull-to-refresh
            // 1:1 omlaag; omhoog alleen een klein beetje meegeven (rubber band).
            var ty = dy >= 0 ? dy : dy * 0.15;
            S.drawer.style.transform = 'translateY(' + ty + 'px)';
            if ( S.backdrop ) {
                S.backdrop.style.opacity = String( Math.max( 0, 1 - Math.max( 0, ty ) / S.sheetH ) );
            }
            trackVelocity( y, e.timeStamp );

            var overSheet = ty > S.sheetH * 0.35;
            if ( overSheet !== S.armed ) {
                if ( overSheet ) mkcpHaptic( 10 );
                S.armed = overSheet;
            }
        } else if ( S.mode === 'row-drag' ) {
            e.preventDefault();
            var tx = Math.min( 0, dx ); // alleen naar links
            if ( tx < -REVEAL_W ) {
                // Weerstand voorbij de knopbreedte — verder trekken kan,
                // maar voelt zwaar (en verwijdert nog steeds niets).
                tx = -REVEAL_W + ( tx + REVEAL_W ) * 0.3;
            }
            // rAF-batching: touch-events vuren sneller dan de framerate;
            // hier alleen de doelwaarde bewaren, rowFrame() schrijft 1×/frame.
            S.pendingTx = tx;
            if ( ! S.rafId ) S.rafId = requestAnimationFrame( rowFrame );
            trackVelocity( x, e.timeStamp );

            // Voorbij de drempel (helft knopbreedte): loslaten laat de rij
            // open staan tegen de bevestig-knop (nog géén verwijdering).
            var overRow = -tx > ARM_PX;
            if ( overRow !== S.armed ) {
                if ( overRow && e.timeStamp - S.lastArmHaptic > 300 ) {
                    mkcpHaptic( 10 );
                    S.lastArmHaptic = e.timeStamp;
                }
                S.armed = overRow;
                if ( S.overlayEl ) S.overlayEl.classList.toggle( 'is-armed', overRow );
            }
        } else if ( S.mode === 'peek-drag' ) {
            e.preventDefault(); // blokkeert dat de pagina zelf meescrollt
            var maxLift = 56;
            var ty2 = Math.min( 0, dy ); // alleen omhoog
            if ( ty2 < -maxLift ) {
                // Weerstand voorbij het lift-maximum — voelt zwaar zodra de
                // knop z'n "openings-hoogte" al bereikt heeft.
                ty2 = -maxLift + ( ty2 + maxLift ) * 0.3;
            }
            S.peekEl.style.transform = 'translateY(' + ty2 + 'px)';
            trackVelocity( y, e.timeStamp );

            var overPeek = -ty2 > maxLift * 0.55;
            if ( overPeek !== S.armed ) {
                if ( overPeek ) mkcpHaptic( 10 );
                S.armed = overPeek;
            }
        }
    }

    // Eén schrijfmoment per frame: transform op de kaart + de progressie
    // (0→1 tot de drempel) als CSS-variabele op de lijst — de stylesheet
    // vertaalt die naar overlay-fade, icoon-groei en kaart-dim (GPU-werk).
    function rowFrame() {
        S.rafId = 0;
        if ( S.mode !== 'row-drag' || ! S.itemEl ) return;
        S.itemEl.style.transform = 'translateX(' + S.pendingTx + 'px)';
        if ( S.itemEl.parentElement ) {
            S.itemEl.parentElement.style.setProperty(
                '--mkcp-swipe-p',
                String( Math.min( 1, -S.pendingTx / ARM_PX ) )
            );
        }
        if ( S.overlayEl ) {
            // Knop groeit mee met de sleepafstand, gekapt op z'n eigen
            // breedte — blijft altijd smaller dan de drag-afstand (BTN_GAP),
            // dus nooit rood tot tegen de prijs/container-rand.
            S.overlayEl.style.width = Math.max( 0, Math.min( BTN_W, -S.pendingTx - BTN_GAP ) ) + 'px';
        }
    }


    // ── touchend ─────────────────────────────────────────────────────────────

    function onTouchEnd() {
        if ( S.mode === 'sheet-drag' ) {
            var m  = /translateY\(([-\d.]+)px\)/.exec( S.drawer.style.transform );
            var ty = m ? parseFloat( m[1] ) : 0;
            var flick = S.vel > 0.5 && ty > 30;

            // Inline styles wissen: de stylesheet-transition (--mkcp-anim)
            // neemt het over en animeert vanaf de laatst getekende positie.
            S.drawer.style.transition = '';
            S.drawer.style.transform  = '';
            if ( S.backdrop ) S.backdrop.style.opacity = '';

            if ( S.armed || flick ) {
                // Volledige closePopup() (focus, inert, is-expanded) via de
                // bestaande gedelegeerde close-handler in cart-popup.js.
                $( POPUP ).find( '.mk-cart-popup__close' ).trigger( 'click' );
            }
            resetState();
        } else if ( S.mode === 'row-drag' ) {
            var tx = S.pendingTx || 0;
            var rowFlick = S.vel < -0.3 && -tx > 24;

            if ( S.armed || rowFlick ) {
                // Nog niet verwijderen: rij open laten staan tegen de rode
                // bevestig-knop ("weet je het zeker"-stap).
                openReveal( S.itemEl, S.overlayEl );
            } else {
                snapBackRow( S.itemEl, S.overlayEl );
            }
            S.overlayEl = null; // eigendom is overgedragen (reveal of cleanup-timeout)
            resetState();
        } else if ( S.mode === 'peek-drag' ) {
            var peekFlick = S.vel < -0.5; // snelle veeg omhoog, ook als de drempel nog niet gehaald is

            S.peekEl.style.transition = '';
            S.peekEl.style.transform  = '';

            if ( S.armed || peekFlick ) {
                // Opent via de bestaande .mkcp-open-click-handler in
                // cart-popup.js — geen aparte openPopup()-aanroep nodig.
                $( S.peekEl ).trigger( 'click' );
            }
            resetState();
        } else {
            resetState();
        }
    }

    function insertOverlay() {
        var items = S.itemEl.parentElement; // .mk-cart-popup__items (position:relative in de app-CSS)

        // Defensieve opruiming: mocht er door een eerdere race toch nog een
        // niet-actieve knop van deze rij rondslingeren, ruim 'm meteen op —
        // voorkomt dat twee knoppen elkaar overlappen en tikken wegvangen.
        items.querySelectorAll( '.mk-cart-popup__swipe-delete' ).forEach( function ( stale ) {
            if ( stale !== revealed.overlayEl ) stale.remove();
        } );

        var el = document.createElement( 'button' );
        el.type = 'button';
        el.className = 'mk-cart-popup__swipe-delete';
        // Tijdens de swipe puur decoratief en onklikbaar; openReveal() maakt
        // er de echte bevestig-knop van. tabindex -1 houdt 'm buiten de
        // focus-trap — toetsenbordgebruikers hebben de gewone X-knop.
        el.setAttribute( 'aria-hidden', 'true' );
        el.setAttribute( 'tabindex', '-1' );
        el.innerHTML = TRASH_SVG + '<span>Verwijder</span>';
        el.style.top    = S.itemEl.offsetTop + 'px';
        el.style.height = S.itemEl.offsetHeight + 'px';
        // Rechts-verankerde knop met eigen (groeiende) breedte — geen volle
        // "bleed"-laag meer over de hele rij, dus geen rood meer tegen de
        // titel of links van de rij, en vanzelf lucht t.o.v. de prijs.
        el.style.right      = BTN_GAP + 'px';
        el.style.width      = '0px';
        el.style.transition = 'none'; // 1:1 met de vinger; rowFrame() schrijft elk frame
        items.insertBefore( el, S.itemEl );
        S.overlayEl = el;
    }


    // ── Wiring — native listeners op document (overleven fragment-replace) ──
    // touchmove expliciet non-passive: de drag-vs-scroll-beslissing valt
    // mídden in het gebaar en vereist daar preventDefault().

    document.addEventListener( 'touchstart',  onTouchStart,  { passive: true  } );
    document.addEventListener( 'touchmove',   onTouchMove,   { passive: false } );
    document.addEventListener( 'touchend',    onTouchEnd,    { passive: true  } );
    document.addEventListener( 'touchcancel', abortGesture,  { passive: true  } );
} );
