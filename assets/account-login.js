/**
 * MK Cart Popup — Account-inlogscherm: interactieve polish.
 *
 * Puur additief bovenop WooCommerce's eigen login-/registratie-/wachtwoord-
 * vergeten-formulieren — geen bestaande markup/attributen aangeraakt, alleen
 * DOM-elementen toegevoegd.
 */
( function () {
    'use strict';

    var EYE_OPEN = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0112 20c-7 0-11-8-11-8a21.6 21.6 0 015.06-6.06"/><path d="M9.9 4.24A10.94 10.94 0 0112 4c7 0 11 8 11 8a21.6 21.6 0 01-2.68 3.9"/><path d="M14.12 14.12a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    var CLOSE_X  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    var ICON_PERSON   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    var ICON_ENVELOPE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 6l-10 7L2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>';
    var ICON_CHECK    = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    // Open oogjes = niks te verbergen (leeg of wachtwoord zichtbaar), dichtgeknepen
    // streepjes = er wordt een verborgen wachtwoord getypt ("kijkt niet mee").
    var EYES_OPEN   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="7" cy="12" r="2.4" fill="currentColor"/><circle cx="17" cy="12" r="2.4" fill="currentColor"/></svg>';
    var EYES_CLOSED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4.5 12c1-1.4 2.6-2.2 2.5-2.2s1.5.8 2.5 2.2"/><path d="M14.5 12c1-1.4 2.6-2.2 2.5-2.2s1.5.8 2.5 2.2"/></svg>';

    // "Toon wachtwoord"-oogje + kijkend mascotje-icoontje links in het veld.
    function addPasswordToggle( input ) {
        var wrap = document.createElement( 'span' );
        wrap.className = 'mkcp-account-login-pwfield';
        input.parentNode.insertBefore( wrap, input );
        wrap.appendChild( input );

        var peek = document.createElement( 'span' );
        peek.className = 'mkcp-account-login-pwfield__peek';
        peek.setAttribute( 'aria-hidden', 'true' );
        wrap.insertBefore( peek, input );

        var btn = document.createElement( 'button' );
        btn.type = 'button';
        btn.className = 'mkcp-account-login-pwtoggle';
        btn.setAttribute( 'aria-label', 'Wachtwoord tonen' );
        btn.innerHTML = EYE_OPEN;
        wrap.appendChild( btn );

        var peekClosed = null;

        function updatePeek() {
            var nowClosed = input.type === 'password' && !! input.value;
            if ( nowClosed === peekClosed ) return;
            peekClosed = nowClosed;

            peek.innerHTML = peekClosed ? EYES_CLOSED : EYES_OPEN;
            peek.classList.remove( 'mkcp-account-login-pwfield__peek--pop' );
            void peek.offsetWidth; // reflow, animatie opnieuw laten starten
            peek.classList.add( 'mkcp-account-login-pwfield__peek--pop' );
        }

        input.addEventListener( 'input', updatePeek );
        updatePeek();

        btn.addEventListener( 'click', function () {
            var isShown = input.type === 'text';
            input.type = isShown ? 'password' : 'text';
            btn.innerHTML = isShown ? EYE_OPEN : EYE_OFF;
            btn.setAttribute( 'aria-label', isShown ? 'Wachtwoord tonen' : 'Wachtwoord verbergen' );
            input.focus();
            updatePeek();
        } );
    }

    // Foutmelding: sluitkruisje + schud toevoegen. WooCommerce plaatst deze notice
    // altijd als losse <ul> vóór de kaart (blijft bewust zo, zie account-login.scss).
    function enhanceNotice() {
        var notice = document.querySelector( '.woocommerce-error, .woocommerce-message, .woocommerce-info' );
        if ( ! notice ) return;

        var closeBtn = document.createElement( 'button' );
        closeBtn.type = 'button';
        closeBtn.className = 'mkcp-account-login-notice__close';
        closeBtn.setAttribute( 'aria-label', 'Melding sluiten' );
        closeBtn.innerHTML = CLOSE_X;
        closeBtn.addEventListener( 'click', function () {
            notice.style.transition = 'opacity 180ms ease';
            notice.style.opacity = '0';
            setTimeout( function () { notice.remove(); }, 180 );
        } );
        notice.appendChild( closeBtn );

        notice.setAttribute( 'tabindex', '-1' );
        notice.focus();

        // Alleen schudden bij een echte fout — bij een succes-/infomelding
        // (bv. na een wachtwoord-reset-link) voelt schudden verkeerd.
        if ( notice.classList.contains( 'woocommerce-error' ) ) {
            notice.classList.add( 'mkcp-account-login-shake' );
            notice.addEventListener( 'animationend', function handler() {
                notice.classList.remove( 'mkcp-account-login-shake' );
                notice.removeEventListener( 'animationend', handler );
            } );
        }
    }

    // ── Laadstatus op de verzendknop ─────────────────────────────────────────
    function addSubmitLoadingState() {
        document.querySelectorAll( '.mkcp-account-login-card form' ).forEach( function ( form ) {
            form.addEventListener( 'submit', function () {
                var btn = form.querySelector( 'button[type="submit"]' );
                if ( btn ) {
                    btn.classList.add( 'is-loading' );
                    btn.setAttribute( 'aria-busy', 'true' );
                }
            } );
        } );
    }

    // "Onthoud mijn gebruikersnaam" (localStorage) — los van WooCommerce's eigen
    // "Onthouden"-checkbox (die regelt de sessieduur); dit onthoudt alleen de
    // laatst ingevoerde gebruikersnaam/e-mailadres.
    var USERNAME_STORAGE_KEY = 'mkcp_account_last_username';

    function rememberUsername() {
        var field = document.querySelector( '.mkcp-account-login-card #username' );
        if ( ! field ) return;

        try {
            if ( ! field.value ) {
                var saved = window.localStorage.getItem( USERNAME_STORAGE_KEY );
                if ( saved ) field.value = saved;
            }
        } catch ( e ) { /* localStorage kan geblokkeerd zijn (privémodus) — dan gewoon overslaan */ }

        var form = field.closest( 'form' );
        if ( form ) {
            form.addEventListener( 'submit', function () {
                try {
                    if ( field.value ) {
                        window.localStorage.setItem( USERNAME_STORAGE_KEY, field.value );
                    }
                } catch ( e ) { /* zie hierboven */ }
            } );
        }
    }

    // "Welkom terug, {naam}" — leunt op dezelfde localStorage-waarde als
    // rememberUsername(). Alleen tonen zonder bestaande melding en alleen bij een
    // gebruikersnaam, geen e-mailadres (anders "Welkom terug, jan@bedrijf.nl!").
    function personalizeGreeting() {
        var greetingEl = document.querySelector( '.mkcp-account-login-greeting' );
        if ( ! greetingEl ) return;
        if ( document.querySelector( '.woocommerce-error, .woocommerce-message, .woocommerce-info' ) ) return;

        var saved = null;
        try { saved = window.localStorage.getItem( USERNAME_STORAGE_KEY ); } catch ( e ) { /* zie rememberUsername */ }
        if ( ! saved || saved.indexOf( '@' ) !== -1 ) return;

        greetingEl.textContent = 'Welkom terug, ' + saved + '!';
    }

    // Slim veld-icoon: persoon ↔ envelop zodra er een @ getypt wordt. Eigen
    // icoon-element i.p.v. de gebruikelijke background-image-aanpak (zie
    // account-login.scss) — een los element kan "poppen", background-image niet.
    function addSmartFieldIcon() {
        var field = document.querySelector( '.mkcp-account-login-card #username' );
        if ( ! field ) return;

        var wrap = document.createElement( 'span' );
        wrap.className = 'mkcp-account-login-userfield';
        field.parentNode.insertBefore( wrap, field );
        wrap.appendChild( field );

        var icon = document.createElement( 'span' );
        icon.className = 'mkcp-account-login-userfield__icon';
        icon.setAttribute( 'aria-hidden', 'true' );
        icon.innerHTML = ICON_PERSON;
        wrap.insertBefore( icon, field );

        var isEmail = false;

        function update() {
            var nowEmail = field.value.indexOf( '@' ) !== -1;
            if ( nowEmail === isEmail ) return;
            isEmail = nowEmail;

            icon.innerHTML = isEmail ? ICON_ENVELOPE : ICON_PERSON;
            // Animatie forceren opnieuw te starten (anders speelt 'm bij een
            // tweede wissel niet nogmaals af, want de klasse staat er al op).
            icon.classList.remove( 'mkcp-account-login-userfield__icon--pop' );
            void icon.offsetWidth; // reflow
            icon.classList.add( 'mkcp-account-login-userfield__icon--pop' );
        }

        field.addEventListener( 'input', update );
        update();
    }

    function fieldWrap( field ) {
        return field.closest( '.mkcp-account-login-userfield, .mkcp-account-login-checkfield' ) || field.parentNode;
    }

    // Vast persoon-icoontje (#user_login, #reg_username — geen envelop-variant
    // nodig). Zelfde overlay-element-aanpak als #username i.p.v. background-image:
    // browsers schilderen bij autofill-suggesties hun eigen achtergrond over het
    // veld, wat een background-image onzichtbaar maakt; een los element niet.
    function addStaticFieldIcon( id, iconHtml ) {
        var field = document.querySelector( '.mkcp-account-login-card #' + id );
        if ( ! field || field.closest( '.mkcp-account-login-userfield' ) ) return;

        var wrap = document.createElement( 'span' );
        wrap.className = 'mkcp-account-login-userfield';
        field.parentNode.insertBefore( wrap, field );
        wrap.appendChild( field );

        var icon = document.createElement( 'span' );
        icon.className = 'mkcp-account-login-userfield__icon';
        icon.setAttribute( 'aria-hidden', 'true' );
        icon.innerHTML = iconHtml || ICON_PERSON;
        wrap.insertBefore( icon, field );
    }

    // E-mail-typfout-detectie: fuzzy-match (Levenshtein) tegen bekende domeinen
    // i.p.v. een vaste "typfout → correct"-tabel, die alleen vooraf bedachte
    // fouten (bv. "hotmai.com") ving en varianten als "hotnmail.com" miste.
    var KNOWN_DOMAINS = [
        'gmail.com', 'hotmail.com', 'hotmail.nl', 'outlook.com', 'yahoo.com',
        'live.com', 'live.nl', 'icloud.com', 'me.com',
        'ziggo.nl', 'kpn.com', 'kpnmail.nl', 'home.nl', 'planet.nl', 'hetnet.nl',
        'telfort.nl', 'xs4all.nl', 'online.nl', 'chello.nl', 'quicknet.nl',
    ];

    function levenshtein( a, b ) {
        var m = a.length, n = b.length;
        var dp = [];
        var i, j;
        for ( i = 0; i <= m; i++ ) dp[ i ] = [ i ];
        for ( j = 0; j <= n; j++ ) dp[ 0 ][ j ] = j;
        for ( i = 1; i <= m; i++ ) {
            for ( j = 1; j <= n; j++ ) {
                dp[ i ][ j ] = a[ i - 1 ] === b[ j - 1 ]
                    ? dp[ i - 1 ][ j - 1 ]
                    : 1 + Math.min( dp[ i - 1 ][ j ], dp[ i ][ j - 1 ], dp[ i - 1 ][ j - 1 ] );
            }
        }
        return dp[ m ][ n ];
    }

    function addEmailTypoSuggestion( field ) {
        var wrap = fieldWrap( field );

        var hint = document.createElement( 'button' );
        hint.type = 'button';
        hint.className = 'mkcp-account-login-typo-hint mkcp-account-login-typo-hint--hidden';
        wrap.insertAdjacentElement( 'afterend', hint );

        function suggestion( value ) {
            var at = value.lastIndexOf( '@' );
            if ( at === -1 ) return null;
            var domain = value.slice( at + 1 ).toLowerCase();
            if ( ! domain || KNOWN_DOMAINS.indexOf( domain ) !== -1 ) return null;

            var best = null;
            var bestDist = 3; // max. 2 verschillende tekens, anders te onzeker
            for ( var i = 0; i < KNOWN_DOMAINS.length; i++ ) {
                var known = KNOWN_DOMAINS[ i ];
                if ( Math.abs( known.length - domain.length ) >= bestDist ) continue; // snelle skip
                var dist = levenshtein( domain, known );
                if ( dist > 0 && dist < bestDist ) {
                    bestDist = dist;
                    best = known;
                }
            }

            return best ? value.slice( 0, at + 1 ) + best : null;
        }

        var currentFix = null;

        field.addEventListener( 'input', function () {
            currentFix = suggestion( field.value );
            if ( currentFix ) {
                hint.textContent = 'Bedoelde je ' + currentFix + '?';
                hint.classList.remove( 'mkcp-account-login-typo-hint--hidden' );
            } else {
                hint.classList.add( 'mkcp-account-login-typo-hint--hidden' );
            }
        } );

        hint.addEventListener( 'click', function () {
            if ( currentFix ) {
                field.value = currentFix;
                field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            }
            hint.classList.add( 'mkcp-account-login-typo-hint--hidden' );
            field.focus();
        } );

        // Zonder dit bleef de typfout gewoon staan als iemand niet expliciet
        // op het hint-knopje klikte maar gewoon Enter drukte om in te loggen/
        // registreren — de suggestie is dan wel zichtbaar geweest, maar nooit
        // toegepast. Bij versturen dus stilzwijgend alsnog corrigeren, i.p.v.
        // een klik te eisen.
        var form = field.closest( 'form' );
        if ( form ) {
            form.addEventListener( 'submit', function () {
                if ( currentFix ) {
                    field.value = currentFix;
                    hint.classList.add( 'mkcp-account-login-typo-hint--hidden' );
                }
            } );
        }
    }

    // Confetti-burst als het wachtwoord "Sterk" bereikt. Puur plezier — vandaar
    // losse tijdelijke <span>-deeltjes i.p.v. canvas/library.
    function fireConfetti( originEl ) {
        if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) return;

        var colors = [ '#2e7d32', '#e29320', '#1e6fbf', '#d32f2f', '#8e44ad' ];
        var rect   = originEl.getBoundingClientRect();
        var originX = rect.left + rect.width / 2;
        var originY = rect.top + rect.height / 2;
        var count  = 16;

        for ( var i = 0; i < count; i++ ) {
            var piece = document.createElement( 'span' );
            piece.className = 'mkcp-account-login-confetti';
            piece.style.left = originX + 'px';
            piece.style.top  = originY + 'px';
            piece.style.background = colors[ i % colors.length ];

            var angle    = ( Math.PI * 2 * i ) / count + ( Math.random() * 0.4 );
            var distance = 55 + Math.random() * 45;
            piece.style.setProperty( '--mkcp-confetti-x', ( Math.cos( angle ) * distance ).toFixed( 1 ) + 'px' );
            piece.style.setProperty( '--mkcp-confetti-y', ( Math.sin( angle ) * distance - 15 ).toFixed( 1 ) + 'px' );
            piece.style.setProperty( '--mkcp-confetti-rot', ( Math.round( Math.random() * 720 - 360 ) ) + 'deg' );

            document.body.appendChild( piece );
            piece.addEventListener( 'animationend', function () { this.remove(); } );
        }
    }

    // Wachtwoordsterkte-indicator: eigen heuristiek (lengte + tekenvariatie)
    // i.p.v. WordPress' zxcvbn-meter, die een extra script vereist dat hier
    // verder nergens voor nodig is.
    function scorePasswordStrength( value ) {
        if ( ! value ) return 0;
        var score = 0;
        if ( value.length >= 8 )  score++;
        if ( value.length >= 12 ) score++;
        if ( /[a-z]/.test( value ) && /[A-Z]/.test( value ) ) score++;
        if ( /\d/.test( value ) ) score++;
        if ( /[^a-zA-Z0-9]/.test( value ) ) score++;
        return Math.min( score, 4 ); // 0–4
    }

    function addPasswordStrengthMeter( input ) {
        var wrap = document.createElement( 'div' );
        wrap.className = 'mkcp-account-login-strength';
        wrap.setAttribute( 'aria-hidden', 'true' ); // de status wordt niet cruciaal geacht om apart voor te lezen; het wachtwoordveld zelf blijft gewoon met het toetsenbord/screenreader te bedienen

        var bar = document.createElement( 'div' );
        bar.className = 'mkcp-account-login-strength__bar';
        wrap.appendChild( bar );

        var label = document.createElement( 'span' );
        label.className = 'mkcp-account-login-strength__label';
        wrap.appendChild( label );

        var field = input.closest( '.mkcp-account-login-pwfield' ) || input;
        field.insertAdjacentElement( 'afterend', wrap );

        var levels = [
            { cls: '', text: '' },
            { cls: 'mkcp-account-login-strength--weak', text: 'Zwak' },
            { cls: 'mkcp-account-login-strength--fair', text: 'Redelijk' },
            { cls: 'mkcp-account-login-strength--good', text: 'Goed' },
            { cls: 'mkcp-account-login-strength--strong', text: 'Sterk' },
        ];

        var wasStrong = false;

        input.addEventListener( 'input', function () {
            var score = scorePasswordStrength( input.value );
            wrap.className = 'mkcp-account-login-strength';
            if ( levels[ score ].cls ) wrap.classList.add( levels[ score ].cls );
            bar.style.width = input.value ? ( ( score / 4 ) * 100 ) + '%' : '0%';
            label.textContent = input.value ? levels[ score ].text : '';

            var isStrong = score === 4;
            if ( isStrong && ! wasStrong ) fireConfetti( bar );
            wasStrong = isStrong;
        } );

        return wrap;
    }

    // Wachtwoord-eisenlijst: laat per eis live zien of eraan voldaan is.
    var REQUIREMENTS = [
        { test: function ( v ) { return v.length >= 8; }, text: 'Minimaal 8 tekens' },
        { test: function ( v ) { return /[A-Z]/.test( v ); }, text: 'Een hoofdletter' },
        { test: function ( v ) { return /\d/.test( v ); }, text: 'Een cijfer' },
        { test: function ( v ) { return /[^a-zA-Z0-9]/.test( v ); }, text: 'Een symbool' },
    ];

    function addPasswordRequirements( input, afterEl ) {
        var list = document.createElement( 'ul' );
        list.className = 'mkcp-account-login-requirements';
        list.setAttribute( 'aria-live', 'polite' );

        var items = REQUIREMENTS.map( function ( req ) {
            var li = document.createElement( 'li' );
            li.className = 'mkcp-account-login-requirements__item';

            var icon = document.createElement( 'span' );
            icon.className = 'mkcp-account-login-requirements__icon';
            icon.setAttribute( 'aria-hidden', 'true' );
            icon.innerHTML = ICON_CHECK;
            li.appendChild( icon );

            var label = document.createElement( 'span' );
            label.textContent = req.text;
            li.appendChild( label );

            list.appendChild( li );
            return { li: li, req: req, met: false };
        } );

        ( afterEl || input ).insertAdjacentElement( 'afterend', list );

        input.addEventListener( 'input', function () {
            items.forEach( function ( it ) {
                var met = it.req.test( input.value );
                if ( met === it.met ) return;
                it.met = met;
                it.li.classList.toggle( 'mkcp-account-login-requirements__item--met', met );
            } );
        } );
    }

    // Wachtwoordveld vergrendeld tot het identiteitsveld bruikbaar is — puur
    // UX-sturing, geen vervanging van serverzijdige validatie.
    // requireEmail:true (#reg_email) staat alleen een geldig e-mailadres toe;
    // requireEmail:false (#username) accepteert ook een username, anders zou
    // strikte e-mailvalidatie een username-login permanent vergrendelen.
    function lockPasswordUntilIdentifierValid( identifierId, passwordId, requireEmail, hintText ) {
        var identifier = document.querySelector( '.mkcp-account-login-card #' + identifierId );
        var password   = document.querySelector( '.mkcp-account-login-card #' + passwordId );
        if ( ! identifier || ! password ) return;

        var wrap = password.closest( '.mkcp-account-login-pwfield' ) || password;

        var hint = document.createElement( 'span' );
        hint.className = 'mkcp-account-login-field-hint';
        hint.textContent = hintText;
        wrap.insertAdjacentElement( 'afterend', hint );

        function isValidEmail( value ) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
        }

        function isValid( value ) {
            value = value.trim();
            if ( ! value ) return false;
            if ( requireEmail ) return isValidEmail( value );
            return isValidEmail( value ) || value.length >= 3;
        }

        function update() {
            var valid = isValid( identifier.value );
            // Eerst leegmaken, DAN vergrendelen: een disabled veld is voor de
            // bezoeker niet meer leeg te maken, dus een ingetypt wachtwoord zou
            // anders vastzitten zodra het identiteitsveld weer ongeldig wordt.
            if ( ! valid ) password.value = '';
            password.disabled = ! valid;
            wrap.classList.toggle( 'mkcp-account-login-pwfield--locked', ! valid );
            // classList i.p.v. style.display: ruimte blijft gereserveerd, geen
            // hoogteverspringing (zie account-login.scss).
            hint.classList.toggle( 'mkcp-account-login-field-hint--hidden', valid );
        }

        identifier.addEventListener( 'input', update );
        update();
    }

    // Caps Lock-waarschuwing. getModifierState is de enige betrouwbare manier om
    // Caps Lock te detecteren (geen apart 'CapsLock'-event) — vandaar keydown/keyup.
    function addCapsLockWarning( input ) {
        var wrap = input.closest( '.mkcp-account-login-pwfield' ) || input;

        var warning = document.createElement( 'span' );
        warning.className = 'mkcp-account-login-field-hint mkcp-account-login-capslock mkcp-account-login-field-hint--hidden';
        warning.textContent = 'Caps Lock staat aan';
        wrap.insertAdjacentElement( 'afterend', warning );

        function handle( e ) {
            var on = typeof e.getModifierState === 'function' && e.getModifierState( 'CapsLock' );
            warning.classList.toggle( 'mkcp-account-login-field-hint--hidden', ! on );
        }

        input.addEventListener( 'keydown', handle );
        input.addEventListener( 'keyup', handle );
        input.addEventListener( 'blur', function () { warning.classList.add( 'mkcp-account-login-field-hint--hidden' ); } );
    }

    // Groen vinkje bij een geldig ingevuld veld — geen wachtwoordvelden (die hebben
    // al het oogje/toggle rechts, een vinkje ernaast zou botsen).
    function addValidityCheckmark( field, isValidFn ) {
        var wrap = field.closest( '.mkcp-account-login-userfield' );
        if ( ! wrap ) {
            wrap = document.createElement( 'span' );
            wrap.className = 'mkcp-account-login-checkfield';
            field.parentNode.insertBefore( wrap, field );
            wrap.appendChild( field );
        }

        var check = document.createElement( 'span' );
        check.className = 'mkcp-account-login-checkmark';
        check.setAttribute( 'aria-hidden', 'true' );
        check.innerHTML = ICON_CHECK;
        wrap.appendChild( check );

        var wasValid = null;

        function update() {
            var valid = isValidFn( field.value );
            if ( valid === wasValid ) return;
            wasValid = valid;

            check.classList.toggle( 'mkcp-account-login-checkmark--visible', valid );
            if ( valid ) {
                check.classList.remove( 'mkcp-account-login-checkmark--pop' );
                void check.offsetWidth; // reflow
                check.classList.add( 'mkcp-account-login-checkmark--pop' );
            }
        }

        field.addEventListener( 'input', update );
        update();
    }

    // Attentie-puls op "wachtwoord vergeten" na herhaalde mislukkingen. Elke
    // mislukte poging is een page-reload, dus simpele sessionStorage-teller;
    // los van de echte, beveiligingsrelevante rate-limit-teller in account-
    // frontend.php — dit is puur UX en mag "ongeveer" goed zijn.
    function pulseForgotPasswordLink() {
        var link = document.querySelector( '.mkcp-account-login-card .woocommerce-LostPassword a' );
        if ( ! link ) return;

        var key = 'mkcp_account_failed_streak';
        var hasError = !! document.querySelector( '.woocommerce-error' );
        var streak = 0;

        try {
            streak = parseInt( window.sessionStorage.getItem( key ), 10 ) || 0;
            streak = hasError ? streak + 1 : 0;
            window.sessionStorage.setItem( key, streak );
        } catch ( e ) { /* sessionStorage kan geblokkeerd zijn — dan gewoon geen puls */ }

        if ( streak >= 2 ) link.classList.add( 'mkcp-account-login-pulse' );
    }

    // Inline veld-validatie: voorkomt een page-reload voor het triviale geval
    // ("dit veld is verplicht"); vervangt niet de serverzijdige validatie.
    function addInlineValidation() {
        document.querySelectorAll( '.mkcp-account-login-card form' ).forEach( function ( form ) {
            var required = form.querySelectorAll( 'input[required]' );
            if ( ! required.length ) return;

            // Registratieformulier heeft geen novalidate; zonder dit toonde de
            // browser zijn eigen bubbel i.p.v. onze "Dit veld is verplicht"-melding.
            form.noValidate = true;

            function fieldRow( field ) {
                return field.closest( '.woocommerce-form-row, .form-row' ) || field.parentNode;
            }

            // Foutmelding-element al bij het laden aanmaken (leeg, via opacity
            // verborgen) i.p.v. pas bij een fout — anders verspringt de kaart van
            // hoogte (zie account-login.scss). Bewaard op het veld zelf.
            required.forEach( function ( field ) {
                var row = fieldRow( field );
                var msg = document.createElement( 'span' );
                msg.className = 'mkcp-account-login-field-error mkcp-account-login-field-error--hidden';
                msg.id = 'mkcp-field-error-' + ( field.id || Math.random().toString( 36 ).slice( 2 ) );
                row.appendChild( msg );
                field.mkcpErrorEl = msg;
            } );

            function clearFieldError( field ) {
                var msg = field.mkcpErrorEl;
                if ( msg ) msg.classList.add( 'mkcp-account-login-field-error--hidden' );
                field.classList.remove( 'mkcp-account-login-field-invalid' );
                field.removeAttribute( 'aria-invalid' );
                field.removeAttribute( 'aria-describedby' );
            }

            function showFieldError( field, text ) {
                var msg = field.mkcpErrorEl;
                if ( ! msg ) return;
                msg.textContent = text;
                msg.classList.remove( 'mkcp-account-login-field-error--hidden' );
                field.classList.add( 'mkcp-account-login-field-invalid' );
                field.setAttribute( 'aria-invalid', 'true' );
                field.setAttribute( 'aria-describedby', msg.id );
            }

            required.forEach( function ( field ) {
                field.addEventListener( 'input', function () { clearFieldError( field ); } );
            } );

            form.addEventListener( 'submit', function ( e ) {
                var firstInvalid = null;
                required.forEach( function ( field ) {
                    if ( field.disabled ) return; // bv. vergrendeld wachtwoordveld, zie lockPasswordUntilValidEmail
                    if ( ! field.value.trim() ) {
                        showFieldError( field, 'Dit veld is verplicht' );
                        if ( ! firstInvalid ) firstInvalid = field;
                    } else {
                        clearFieldError( field );
                    }
                } );

                if ( firstInvalid ) {
                    e.preventDefault();
                    firstInvalid.focus();
                    var btn = form.querySelector( 'button[type="submit"]' );
                    if ( btn ) btn.classList.remove( 'is-loading' );
                }
            } );
        } );
    }

    // Login ↔ Registreren wisselen i.p.v. WooCommerce's eigen naast-elkaar-
    // kolommen (#customer_login.col2-set) — die verdrukken de split-screen kaart
    // met paneel/foto. Toont hier bewust maar één kolom tegelijk met wissellink;
    // de <h2> zit al in elke kolom, dus die verandert vanzelf mee.
    function addAuthToggle() {
        var wrap = document.querySelector( '#customer_login.col2-set' );
        if ( ! wrap ) return;

        var loginCol    = wrap.querySelector( '.u-column1' );
        var registerCol = wrap.querySelector( '.u-column2' );
        if ( ! loginCol || ! registerCol ) return;

        var toRegister = document.createElement( 'p' );
        toRegister.className = 'mkcp-account-login-toggle';
        toRegister.innerHTML = 'Nog geen account? <a href="#">Maak een account aan</a>';
        loginCol.appendChild( toRegister );

        var toLogin = document.createElement( 'p' );
        toLogin.className = 'mkcp-account-login-toggle';
        toLogin.innerHTML = 'Al een account? <a href="#">Log in</a>';
        registerCol.appendChild( toLogin );

        // Hoogte vastzetten op de HOOGSTE van de twee kolommen, zodat alleen de
        // opacity-transitie zichtbaar is bij het wisselen. +4px marge tegen
        // subpixel-afronding (anders wordt het wissel-linkje afgesneden).
        // Kolommen hebben bewust geen bottom:0 (zie account-login.scss) — anders
        // plakt hun hoogte vast aan wrap en telt elke meting cumulatief mee op,
        // waardoor de kaart bij elke toetsaanslag verder zou doorgroeien.
        wrap.classList.add( 'mkcp-account-login-col2--js' );

        function remeasure() {
            wrap.style.height = ( Math.max( loginCol.offsetHeight, registerCol.offsetHeight ) + 4 ) + 'px';
        }

        remeasure();

        // Nogmaals ná volledige page-load (webfonts/iconen kunnen dan meer
        // ruimte innemen dan bij de eerste meting) en bij elke resize (gedebounced).
        window.addEventListener( 'load', remeasure );
        var resizeTimer = null;
        window.addEventListener( 'resize', function () {
            clearTimeout( resizeTimer );
            resizeTimer = setTimeout( remeasure, 150 );
        } );

        // Ook bij elke wijziging binnenin een kolom nameten: andere functies in
        // dit bestand voegen na de eerste meting nog dingen toe (foutmeldingen,
        // Caps Lock-waarschuwing) waardoor het wissel-linkje anders afgesneden
        // zou worden door overflow:hidden. Class-mutaties op de kolommen zelf
        // (m.target === loginCol/registerCol) tellen bewust NIET mee — dat is
        // precies wat show() en addSubmitLoadingState() al doen en veroorzaakte
        // anders een remeasure() middenin de crossfade-transitie. suspended
        // onderdrukt dat volledig tijdens de ~350ms van een echte wissel.
        var suspended = false;

        if ( window.MutationObserver ) {
            var mutationTimer = null;
            var observer = new MutationObserver( function ( mutations ) {
                if ( suspended ) return;
                var relevant = mutations.some( function ( m ) {
                    return m.target !== loginCol && m.target !== registerCol;
                } );
                if ( ! relevant ) return;
                clearTimeout( mutationTimer );
                mutationTimer = setTimeout( remeasure, 60 );
            } );
            observer.observe( loginCol,    { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style' ] } );
            observer.observe( registerCol, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style' ] } );
        }

        // Bij een mislukte registratiepoging houdt WooCommerce #reg_email vast
        // en herlaadt de pagina — zonder deze check verdwijnt de foutmelding
        // achter de standaard-verborgen registratiekolom.
        var regEmailField   = registerCol.querySelector( '#reg_email' );
        var startOnRegister = !! ( regEmailField && regEmailField.value );

        function show( showRegister ) {
            suspended = true;
            clearTimeout( show.timer );
            show.timer = setTimeout( function () { suspended = false; }, 400 );

            loginCol.classList.toggle( 'mkcp-account-login-col--active', ! showRegister );
            registerCol.classList.toggle( 'mkcp-account-login-col--active', showRegister );
            var target = ( showRegister ? registerCol : loginCol ).querySelector( 'input:not([type="hidden"])' );
            if ( target ) target.focus();
        }

        show( startOnRegister );

        toRegister.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
            e.preventDefault();
            show( true );
        } );
        toLogin.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
            e.preventDefault();
            show( false );
        } );
    }

    // Achtergrondfoto (position:fixed) moet exact beginnen waar het paneel begint;
    // die grens verschuift met de viewportbreedte, dus puur CSS kan het niet
    // pixel-precies — getBoundingClientRect() van het paneel is de enige
    // betrouwbare bron. Desktop: paneel rechts, `left` wordt gezet. Mobiel
    // (≤1024px): paneel onder het formulier, `top` wordt gezet i.p.v. `left`.
    function positionLoginBgPhoto() {
        var bg = document.querySelector( '.mkcp-account-login-bgphoto' );
        var panel = document.querySelector( '.mkcp-account-login-panel' );
        if ( ! bg || ! panel ) return;
        if ( panel.offsetParent === null ) return; // display:none (col2-set-uitzondering) — CSS regelt het verbergen van bg al

        var isMobile = window.matchMedia && window.matchMedia( '(max-width: 1024px)' ).matches;
        var rect = panel.getBoundingClientRect();

        if ( isMobile ) {
            bg.style.left = '0px';
            bg.style.top  = Math.round( rect.top ) + 'px';
        } else {
            bg.style.top  = '0px';
            bg.style.left = Math.round( rect.left ) + 'px';
        }
    }

    if ( document.querySelector( '.mkcp-account-login-bgphoto' ) ) {
        window.addEventListener( 'resize', positionLoginBgPhoto );
        window.addEventListener( 'load', positionLoginBgPhoto );
        positionLoginBgPhoto();
    }

    // ── Autofocus eerste veld ────────────────────────────────────────────────
    //
    // Alleen als er geen foutmelding is (die krijgt dan al focus, zie
    // enhanceNotice) en het veld nog leeg is (niet ingrijpen als de browser
    // 'm al via autofill heeft ingevuld).
    function autofocusFirstField() {
        if ( document.querySelector( '.woocommerce-error, .woocommerce-message, .woocommerce-info' ) ) return;
        var first = document.querySelector( '.mkcp-account-login-card #username, .mkcp-account-login-card #user_login' );
        if ( first && ! first.value ) first.focus();
    }

    document.querySelectorAll( '.mkcp-account-login-card input[type="password"]' ).forEach( addPasswordToggle );
    enhanceNotice();
    addSubmitLoadingState();
    addInlineValidation();
    rememberUsername();
    personalizeGreeting();
    addSmartFieldIcon();
    addStaticFieldIcon( 'user_login' );
    addStaticFieldIcon( 'reg_username' );
    addStaticFieldIcon( 'reg_email', ICON_ENVELOPE );
    autofocusFirstField();

    // Alleen op het registratieformulier (col2-set) — een sterkte-indicator
    // heeft geen zin bij het invoeren van een BESTAAND wachtwoord om in te
    // loggen, alleen bij het KIEZEN van een nieuw wachtwoord.
    var regPassword = document.querySelector( '.mkcp-account-login-card #reg_password' );
    if ( regPassword ) {
        var strengthWrap = addPasswordStrengthMeter( regPassword );
        addPasswordRequirements( regPassword, strengthWrap );
    }

    lockPasswordUntilIdentifierValid( 'reg_email', 'reg_password', true, 'Vul eerst een geldig e-mailadres in' );
    lockPasswordUntilIdentifierValid( 'username', 'password', false, 'Vul eerst je gebruikersnaam of e-mailadres in' );

    document.querySelectorAll( '.mkcp-account-login-card input[type="password"]' ).forEach( addCapsLockWarning );
    pulseForgotPasswordLink();

    function isEmailValid( v ) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v.trim() ); }
    function isIdentifierValid( v ) { v = v.trim(); return !! v && ( isEmailValid( v ) || v.length >= 3 ); }
    function isNonEmpty( v ) { return v.trim().length > 0; }

    var usernameField = document.querySelector( '.mkcp-account-login-card #username' );
    if ( usernameField ) addValidityCheckmark( usernameField, isIdentifierValid );

    var regEmailField = document.querySelector( '.mkcp-account-login-card #reg_email' );
    if ( regEmailField ) addValidityCheckmark( regEmailField, isEmailValid );

    var regUsernameField = document.querySelector( '.mkcp-account-login-card #reg_username' );
    if ( regUsernameField ) addValidityCheckmark( regUsernameField, isNonEmpty );

    if ( usernameField ) addEmailTypoSuggestion( usernameField );
    if ( regEmailField ) addEmailTypoSuggestion( regEmailField );

    addAuthToggle();
} )();
