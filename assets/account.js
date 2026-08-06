/**
 * MK Cart Popup — Account router + formulieren
 *
 * Minimale hash-based router: leest #/{route}(/{id}) uit de URL, haalt het
 * bijbehorende fragment op via AJAX (mkcp_account_get_fragment) en zet het
 * in #mkcp-account-content. Geen page-reload bij het wisselen van tab.
 *
 * Bewust vanilla JS (fetch/addEventListener), geen jQuery-afhankelijkheid —
 * voorkomt de klasse bugs die eerder in deze codebase ontstond door jQuery-
 * en addEventListener-gebaseerde handlers door elkaar te gebruiken op
 * dezelfde pagina (zie checkout-debug-lessen).
 */
(function () {
    'use strict';

    if (typeof mkcp_account_params === 'undefined') return;

    var content = document.getElementById('mkcp-account-content');
    var nav = document.getElementById('mkcp-account-nav');
    var bottomNav = document.querySelector('.mkcp-account-bottomnav');
    if (!content || !nav) return;

    // Zolang er een fragment aan het laden is, kan er geen tweede navigatie
    // gestart worden — zonder deze guard vuurt elke klik zijn eigen fetch,
    // en "wint" niet per se de fetch van de laatst-geklikte tab (fetches
    // ronden niet gegarandeerd af in de volgorde waarin ze gestart zijn).
    // Bij meerdere snelle kliks zag je daardoor na het laden alsnog een
    // paar keer achter elkaar van tab wisselen, ongeacht welke tab je als
    // laatste had aangeklikt.
    var isLoading = false;

    // ── Gestylede bevestigingsdialoog (i.p.v. window.confirm()) ──────────────
    //
    // Belooft-gebaseerd i.p.v. synchroon zoals window.confirm() dat was —
    // elke aanroeper wacht nu op mkcpConfirm(...).then(ok => ...) i.p.v. een
    // regel verderop meteen te lezen. Focus gaat terug naar de knop die de
    // dialoog opende (lastTrigger), Escape/backdrop-klik = annuleren, Tab
    // blijft binnen de twee knoppen (er zijn er maar twee, dus een volledige
    // FOCUSABLE_SELECTOR-achtige lijst zoals bij de checkout-login-modal is
    // hier overkill).
    var confirmModal = document.getElementById('mkcp-account-confirm');
    var confirmMessage = document.getElementById('mkcp-account-confirm-message');
    var confirmOkBtn = document.getElementById('mkcp-account-confirm-ok');
    var confirmCancelBtn = document.getElementById('mkcp-account-confirm-cancel');
    var confirmResolve = null;
    var confirmLastTrigger = null;

    function closeConfirm(result) {
        if (!confirmModal) return;
        confirmModal.classList.remove('is-open');
        confirmModal.setAttribute('inert', '');
        if (confirmLastTrigger && confirmLastTrigger.focus) confirmLastTrigger.focus();
        if (confirmResolve) { confirmResolve(result); confirmResolve = null; }
    }

    function mkcpConfirm(message, confirmLabel) {
        if (!confirmModal || !confirmMessage || !confirmOkBtn) {
            // Vangnet als de modal-markup onverhoopt ontbreekt — dan liever
            // terugvallen op het oude gedrag dan de actie stilzwijgend nooit
            // te laten bevestigen.
            return Promise.resolve(window.confirm(message));
        }
        confirmLastTrigger = document.activeElement;
        confirmMessage.textContent = message;
        confirmOkBtn.textContent = confirmLabel || confirmOkBtn.textContent;
        confirmModal.removeAttribute('inert');
        confirmModal.classList.add('is-open');
        confirmOkBtn.focus();
        return new Promise(function (resolve) { confirmResolve = resolve; });
    }

    if (confirmOkBtn) confirmOkBtn.addEventListener('click', function () { closeConfirm(true); });
    if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', function () { closeConfirm(false); });
    if (confirmModal) {
        confirmModal.querySelector('.mkcp-account-confirm__backdrop').addEventListener('click', function () { closeConfirm(false); });
        confirmModal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeConfirm(false); return; }
            if (e.key !== 'Tab') return;
            // Twee knoppen, dus simpelweg tussen die twee laten cyclen.
            var focusables = [confirmCancelBtn, confirmOkBtn];
            var idx = focusables.indexOf(document.activeElement);
            e.preventDefault();
            var next = e.shiftKey ? idx - 1 : idx + 1;
            if (next < 0) next = focusables.length - 1;
            if (next >= focusables.length) next = 0;
            focusables[next].focus();
        });
    }

    // ── "Retour aanvragen"/"Schrijf een review" als volwaardige popup ────────
    //
    // Deze formulieren staan al (verborgen) server-side klaar per item, zie
    // account-returns.php/account-reviews.php — i.p.v. ze inline te tonen
    // onder de knop (het vorige gedrag) worden ze nu als gecentreerde modal
    // getoond. Bewust GEEN DOM-verplaatsing naar een losse modal-container
    // buiten #mkcp-account-content: de submit-/klik-afhandeling hieronder is
    // gedelegeerd op die content-container, en een form die daarbuiten komt
    // te hangen zou die listeners missen. position:fixed werkt prima op een
    // element dat gewoon binnen #mkcp-account-content blijft staan — er zit
    // geen transform/filter op een tussenliggende ouder die dat zou breken.
    var formModalBackdrop = null;

    function getFormModalBackdrop() {
        if (formModalBackdrop) return formModalBackdrop;
        formModalBackdrop = document.createElement('div');
        formModalBackdrop.className = 'mkcp-form-modal-backdrop';
        return formModalBackdrop;
    }

    function formModalFocusables(form) {
        return Array.prototype.slice.call(
            form.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])')
        ).filter(function (el) { return el.offsetParent !== null; });
    }

    function openFormModal(form, triggerBtn) {
        var backdrop = getFormModalBackdrop();
        form.parentNode.insertBefore(backdrop, form);
        form.hidden = false;
        form.classList.add('is-modal-open');
        triggerBtn.hidden = true;
        form._mkcpTrigger = triggerBtn;

        backdrop.addEventListener('click', function onBackdropClick() {
            closeFormModal(form);
        }, { once: true });

        function onKeydown(e) {
            if (e.key === 'Escape') { closeFormModal(form); return; }
            if (e.key !== 'Tab') return;
            var focusables = formModalFocusables(form);
            if (!focusables.length) return;
            var idx = focusables.indexOf(document.activeElement);
            e.preventDefault();
            var next = e.shiftKey ? idx - 1 : idx + 1;
            if (next < 0) next = focusables.length - 1;
            if (next >= focusables.length) next = 0;
            focusables[next].focus();
        }
        form.addEventListener('keydown', onKeydown);
        form._mkcpKeydownHandler = onKeydown;

        var firstField = formModalFocusables(form)[0];
        if (firstField) firstField.focus();
    }

    function closeFormModal(form) {
        form.classList.remove('is-modal-open');
        form.hidden = true;
        if (formModalBackdrop && formModalBackdrop.parentNode) formModalBackdrop.parentNode.removeChild(formModalBackdrop);
        if (form._mkcpKeydownHandler) form.removeEventListener('keydown', form._mkcpKeydownHandler);
        if (form._mkcpTrigger) {
            form._mkcpTrigger.hidden = false;
            form._mkcpTrigger.focus();
        }
    }

    // Route-vorm: #/{fragment} of #/{fragment}/{id} (bv. #/orders/123 voor
    // een bestelling-detail, Account-plan sectie 12). Alleen dat ene extra
    // segment wordt ondersteund — geen volwaardige sub-routing nodig zolang
    // er maar één view is (Bestellingen) die een detail-ID gebruikt.
    function currentRoute() {
        var hash = window.location.hash.replace(/^#\/?/, '');
        var parts = hash ? hash.split('/') : [];
        return {
            fragment: parts[0] || mkcp_account_params.default_route || 'dashboard',
            id: parts[1] || null
        };
    }

    // Server rendert het badge-aantal alleen bij de eerste paginalading —
    // hierna houdt de client 'm bij op elk moment dat een melding (of alle
    // meldingen) als gelezen gemarkeerd wordt, zonder de hele pagina te
    // herladen.
    function updateNavBadge(count) {
        // Zowel de sidebar- als de mobiele bottom-nav-badge delen deze class
        // (twee aparte DOM-plekken voor dezelfde teller, zie account-page.php).
        document.querySelectorAll('.js-mkcp-account-nav-badge').forEach(function (badge) {
            badge.textContent = String(count);
            badge.hidden = count <= 0;
        });
    }

    function setActiveNavLink(fragment) {
        // Sidebar én mobiele bottom-nav zijn twee losse <nav>-elementen (zie
        // account-page.php) die dezelfde [data-route]-links delen — allebei
        // bijwerken, niet alleen de sidebar.
        var links = document.querySelectorAll('#mkcp-account-nav [data-route], .mkcp-account-bottomnav [data-route]');
        var activeLabel = null;
        for (var i = 0; i < links.length; i++) {
            var isActive = links[i].getAttribute('data-route') === fragment;
            links[i].classList.toggle('is-active', isActive);
            if (isActive) {
                links[i].setAttribute('aria-current', 'page');
                if (!activeLabel) activeLabel = links[i].getAttribute('data-route-label');
            } else {
                links[i].removeAttribute('aria-current');
            }
        }
        // Kruimelpad in de topbar (account-page.php) — alleen de sidebar-
        // links hebben data-route-label (de bottom-nav-links niet), vandaar
        // de "eerste gevonden label wint"-guard hierboven.
        var crumbCurrent = document.querySelector('.mkcp-account-topbar__crumb-current');
        if (crumbCurrent && activeLabel) crumbCurrent.textContent = activeLabel;
    }

    function showSessionExpired() {
        content.innerHTML =
            '<div class="mkcp-account-notice mkcp-account-notice--error" role="alert">' +
            '<p>Je sessie is verlopen. Log opnieuw in om verder te gaan.</p>' +
            '</div>';
    }

    function showError() {
        content.innerHTML =
            '<div class="mkcp-account-notice mkcp-account-notice--error" role="alert">' +
            '<p>Er ging iets mis bij het laden. Probeer het opnieuw.</p>' +
            '</div>';
    }

    // Skeleton-placeholder per fragment — de vorm volgt globaal de kaarten/
    // rijen die daadwerkelijk op die pagina staan (bento-rij op het
    // Dashboard, rijen bij Bestellingen, een kaartengrid bij Wishlist/
    // Adressen, ...) i.p.v. overal dezelfde drie kale kaarten te tonen.
    // Bewust een nieuw patroon t.o.v. de bestaande mk-loading-dim-class: die
    // blijft voor kleine, sub-seconde formulier-acties (opslaan/toevoegen),
    // een skeleton is voor het laden van een hele view (Account-plan, sectie 4).
    function skeletonRepeat(n, className) {
        var html = '';
        for (var i = 0; i < n; i++) html += '<div class="' + className + '"></div>';
        return html;
    }

    function skeletonMarkup(fragment) {
        var title = '<div class="mkcp-skeleton__bar mkcp-skeleton__bar--title"></div>';
        var statsRow = '<div class="mkcp-skeleton__stats">' + skeletonRepeat(4, 'mkcp-skeleton__stat') + '</div>';

        var body;
        if (fragment === 'dashboard') {
            body = statsRow +
                '<div class="mkcp-skeleton__card mkcp-skeleton__card--lg"></div>' +
                '<div class="mkcp-skeleton__stats mkcp-skeleton__stats--quickactions">' + skeletonRepeat(4, 'mkcp-skeleton__stat') + '</div>' +
                '<div class="mkcp-skeleton__card"></div>' +
                '<div class="mkcp-skeleton__card"></div>';
        } else if (fragment === 'orders') {
            body = '<div class="mkcp-skeleton__chips">' + skeletonRepeat(4, 'mkcp-skeleton__chip') + '</div>' +
                skeletonRepeat(6, 'mkcp-skeleton__row');
        } else if (fragment === 'wishlist') {
            body = '<div class="mkcp-skeleton__card mkcp-skeleton__card--lg">' +
                '<div class="mkcp-skeleton__grid">' + skeletonRepeat(4, 'mkcp-skeleton__tile') + '</div>' +
                '</div>';
        } else if (fragment === 'addresses') {
            body = '<div class="mkcp-skeleton__grid mkcp-skeleton__grid--addr">' + skeletonRepeat(2, 'mkcp-skeleton__card') + '</div>';
        } else if (fragment === 'notifications') {
            body = '<div class="mkcp-skeleton__chips">' + skeletonRepeat(2, 'mkcp-skeleton__chip') + '</div>' +
                skeletonRepeat(5, 'mkcp-skeleton__row');
        } else {
            // Accountgegevens, bestelling-detail, en elke toekomstige route
            // zonder eigen vorm hierboven — drie kale kaarten is nog steeds
            // beter dan niets.
            body = skeletonRepeat(3, 'mkcp-skeleton__card');
        }

        return '<div class="mkcp-skeleton" aria-hidden="true">' + title + body + '</div>';
    }

    function loadFragment(fragment, id) {
        isLoading = true;
        nav.classList.add('is-loading');
        if (bottomNav) bottomNav.classList.add('is-loading');
        content.setAttribute('aria-busy', 'true');
        content.innerHTML = skeletonMarkup(fragment);

        var body = new URLSearchParams();
        body.set('action', 'mkcp_account_get_fragment');
        body.set('nonce', mkcp_account_params.nonce);
        body.set('fragment', fragment);
        if (fragment === 'orders' && id) {
            body.set('order_id', id);
        }

        fetch(mkcp_account_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                    // #/addresses/new (Dashboard-quickaction "Adres toevoegen")
                    // opent het toevoeg-formulier meteen, i.p.v. dat de klant
                    // eerst zelf nog op "+ Adres toevoegen" moet klikken.
                    if (fragment === 'addresses' && id === 'new') {
                        var addToggle = document.getElementById('mkcp-address-add-toggle');
                        if (addToggle) addToggle.click();
                    }
                    if (fragment === 'dashboard') {
                        loadRecentlyViewed();
                        initDashScrollers();
                    }
                    return;
                }
                var code = json && json.data && json.data.code;
                if (code === 'session_expired') {
                    showSessionExpired();
                } else {
                    showError();
                }
            })
            .catch(showError)
            .finally(function () {
                isLoading = false;
                nav.classList.remove('is-loading');
                if (bottomNav) bottomNav.classList.remove('is-loading');
                content.removeAttribute('aria-busy');
                content.classList.remove('is-loading');
            });
    }

    function navigate() {
        var route = currentRoute();
        setActiveNavLink(route.fragment);
        // Vers binnenkomen op de bestellingenlijst (via de nav, niet via onze
        // eigen filter/paginering-knoppen) reset het filter/zoek-geheugen —
        // anders lijkt de lijst "kapot" (leeg/gefilterd) als je terugkomt
        // vanaf een andere tab met een oud filter nog actief.
        if (route.fragment === 'orders' && !route.id && typeof ordersState !== 'undefined') {
            ordersState.status = '';
            ordersState.search = '';
            ordersState.page = 1;
        }
        loadFragment(route.fragment, route.id);
    }

    function guardNavClick(e) {
        var link = e.target.closest('[data-route]');
        if (!link) return;
        // Tijdens het laden van een fragment de klik gewoon negeren — de
        // browser mag de hash dan niet eens zetten, anders zou de
        // hashchange-listener hieronder alsnog een nieuwe fetch starten.
        if (isLoading) {
            e.preventDefault();
            return;
        }
        // Laat de browser de hash gewoon zetten (href="#/...") — de
        // hashchange-listener hieronder handelt de rest af, geen
        // preventDefault() nodig.
    }
    nav.addEventListener('click', guardNavClick);
    if (bottomNav) bottomNav.addEventListener('click', guardNavClick);

    window.addEventListener('hashchange', function () {
        // Vangnet voor navigatie buiten een klik om (terug/vooruit-knop,
        // handmatig de URL aanpassen) — zelfde reden als hierboven: geen
        // tweede fetch starten zolang de vorige nog bezig is.
        if (isLoading) return;
        navigate();
    });


    // ── Formulieren: Accountgegevens / Wachtwoord / Adressen (Fase 1, stap 3) ──
    //
    // Eén generieke POST-helper + inline statusfeedback per formulier, i.p.v.
    // hetzelfde bouwsteentje losjes te dupliceren per formulier (zelfde les
    // als de eerder-geconsolideerde mkcpFieldStatus-duplicatie in checkout).

    function postAction(action, formEl) {
        var body = new URLSearchParams(new FormData(formEl));
        body.set('action', action);
        body.set('nonce', mkcp_account_params.nonce);
        return fetch(mkcp_account_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (res) { return res.json(); });
    }

    function setFormStatus(formEl, kind, message) {
        var statusEl = formEl.querySelector('[data-form-status]');
        if (!statusEl) return;
        statusEl.textContent = message || '';
        statusEl.classList.remove('is-success', 'is-error');
        if (kind) statusEl.classList.add('is-' + kind);
    }

    function errorMessage(json, fallback) {
        if (json && json.data) {
            if (json.data.code === 'session_expired') return 'Je sessie is verlopen. Log opnieuw in.';
            if (json.data.message) return json.data.message;
        }
        return fallback;
    }

    // Accountgegevens: toon het "huidig wachtwoord"-veld alleen als het
    // e-mailadres daadwerkelijk wijzigt (Account-plan, sectie 8).
    content.addEventListener('input', function (e) {
        if (e.target.id === 'mkcp-profile-email') {
            var form = e.target.closest('form');
            var row = form && form.querySelector('#mkcp-profile-current-password-row');
            if (row) row.hidden = e.target.value === e.target.defaultValue;
            return;
        }

        // Zoeken binnen Bestellingen — gedebouncet (300ms) zodat niet elke
        // toetsaanslag meteen een eigen AJAX-rondje start.
        if (e.target.id === 'mkcp-orders-search') {
            clearTimeout(ordersSearchTimer);
            var value = e.target.value;
            ordersSearchTimer = setTimeout(function () {
                ordersState.search = value;
                ordersState.page = 1;
                loadOrdersList();
            }, 300);
        }
    });

    content.addEventListener('submit', function (e) {
        var form = e.target;

        if (form.id === 'mkcp-profile-form') {
            e.preventDefault();
            setFormStatus(form, null, '');
            postAction('mkcp_account_save_profile', form).then(function (json) {
                if (json && json.success) {
                    setFormStatus(form, 'success', 'Opgeslagen.');
                    form.querySelector('#mkcp-profile-email').defaultValue = form.querySelector('#mkcp-profile-email').value;
                    var row = form.querySelector('#mkcp-profile-current-password-row');
                    if (row) row.hidden = true;
                } else {
                    setFormStatus(form, 'error', errorMessage(json, 'Opslaan mislukt.'));
                }
            }).catch(function () { setFormStatus(form, 'error', 'Opslaan mislukt.'); });
            return;
        }

        if (form.id === 'mkcp-password-form') {
            e.preventDefault();
            setFormStatus(form, null, '');
            var newPw = form.querySelector('#mkcp-pw-new').value;
            var confirmPw = form.querySelector('#mkcp-pw-confirm').value;
            if (newPw !== confirmPw) {
                setFormStatus(form, 'error', 'De wachtwoorden komen niet overeen.');
                return;
            }
            postAction('mkcp_account_change_password', form).then(function (json) {
                if (json && json.success) {
                    setFormStatus(form, 'success', 'Wachtwoord gewijzigd.');
                    form.reset();
                } else {
                    setFormStatus(form, 'error', errorMessage(json, 'Wijzigen mislukt.'));
                }
            }).catch(function () { setFormStatus(form, 'error', 'Wijzigen mislukt.'); });
            return;
        }

        if (form.id === 'mkcp-address-form') {
            e.preventDefault();
            setFormStatus(form, null, '');
            postAction('mkcp_account_address_save', form).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                } else {
                    setFormStatus(form, 'error', errorMessage(json, 'Opslaan mislukt.'));
                }
            }).catch(function () { setFormStatus(form, 'error', 'Opslaan mislukt.'); });
            return;
        }

        if (form.classList.contains('js-mkcp-return-form')) {
            e.preventDefault();
            setFormStatus(form, null, '');
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            postAction('mkcp_account_return_request', form).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    // Volledige order-detail opnieuw renderen — de zojuist
                    // ingediende aanvraag toont zich dan meteen als status-
                    // badge i.p.v. het formulier, geen aparte DOM-patch nodig.
                    content.innerHTML = json.data.html;
                } else {
                    setFormStatus(form, 'error', errorMessage(json, 'Versturen mislukt.'));
                    if (submitBtn) submitBtn.disabled = false;
                }
            }).catch(function () {
                setFormStatus(form, 'error', 'Versturen mislukt.');
                if (submitBtn) submitBtn.disabled = false;
            });
            return;
        }

        if (form.classList.contains('js-mkcp-review-form')) {
            e.preventDefault();
            setFormStatus(form, null, '');
            var reviewSubmitBtn = form.querySelector('button[type="submit"]');
            if (reviewSubmitBtn) reviewSubmitBtn.disabled = true;
            postAction('mkcp_account_review_submit', form).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                } else if (json && json.success) {
                    setFormStatus(form, 'success', (json.data && json.data.message) || 'Bedankt!');
                    form.hidden = true;
                } else {
                    setFormStatus(form, 'error', errorMessage(json, 'Versturen mislukt.'));
                    if (reviewSubmitBtn) reviewSubmitBtn.disabled = false;
                }
            }).catch(function () {
                setFormStatus(form, 'error', 'Versturen mislukt.');
                if (reviewSubmitBtn) reviewSubmitBtn.disabled = false;
            });
            return;
        }

        if (form.id === 'mkcp-wishlist-new-form') {
            e.preventDefault();
            setFormStatus(form, null, '');
            postAction('mkcp_account_wishlist_create', form).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                } else {
                    setFormStatus(form, 'error', errorMessage(json, 'Aanmaken mislukt.'));
                }
            }).catch(function () { setFormStatus(form, 'error', 'Aanmaken mislukt.'); });
        }
    });

    // Kleine POST-helper voor de simpele "actie -> herlaad hele fragment"-
    // knoppen hieronder (verwijderen/delen/naar-cart) — geen los formulier
    // nodig, gewoon een paar velden.
    function postSimple(action, fields) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', mkcp_account_params.nonce);
        Object.keys(fields).forEach(function (key) { body.set(key, fields[key]); });
        return fetch(mkcp_account_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (res) { return res.json(); });
    }

    // ── Bestellingen: statusfilter + zoeken ──────────────────────────────────
    //
    // Client-side bijgehouden state (i.p.v. in de hash) — zelfde reden als de
    // bestaande paginering: geen bookmarkbare filter-URL's nodig voor dit
    // MVP, gewoon het fragment opnieuw ophalen met andere parameters.
    var ordersState = { status: '', search: '', page: 1 };
    var ordersSearchTimer = null;

    function loadOrdersList() {
        var body = new URLSearchParams();
        body.set('action', 'mkcp_account_get_fragment');
        body.set('nonce', mkcp_account_params.nonce);
        body.set('fragment', 'orders');
        body.set('page', ordersState.page);
        body.set('status', ordersState.status);
        body.set('search', ordersState.search);

        // Elke respons vervangt het hele fragment (dus ook het zoekveld zelf)
        // — zonder dit verloor het veld na elke toetsaanslag de focus/cursor,
        // wat aanvoelde alsof zoeken niet werkte (je kon niet doortypen
        // zonder telkens opnieuw te klikken).
        var searchWasFocused = document.activeElement && document.activeElement.id === 'mkcp-orders-search';
        var searchCursorPos = searchWasFocused ? document.activeElement.selectionStart : null;
        var searchWrap = document.querySelector('.mkcp-order-search');
        if (searchWasFocused && searchWrap) searchWrap.classList.add('is-searching');

        content.setAttribute('aria-busy', 'true');
        content.classList.add('is-loading');
        fetch(mkcp_account_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                    if (searchWasFocused) {
                        var newSearchInput = document.getElementById('mkcp-orders-search');
                        if (newSearchInput) {
                            newSearchInput.focus();
                            if (searchCursorPos !== null) newSearchInput.setSelectionRange(searchCursorPos, searchCursorPos);
                        }
                    }
                    return;
                }
                // Zonder deze tak bleef een mislukte/geweigerde aanvraag (bv.
                // sessie verlopen) onzichtbaar — de knop leek dan simpelweg
                // niets te doen, terwijl er in werkelijkheid wél iets
                // misging, alleen nooit getoond werd.
                var code = json && json.data && json.data.code;
                if (code === 'session_expired') {
                    showSessionExpired();
                } else {
                    showError();
                }
            })
            .catch(showError)
            .finally(function () {
                content.removeAttribute('aria-busy');
                content.classList.remove('is-loading');
                var currentSearchWrap = document.querySelector('.mkcp-order-search');
                if (currentSearchWrap) currentSearchWrap.classList.remove('is-searching');
            });
    }

    function triggerCartOpen(fragments, cartHash) {
        if (window.jQuery) {
            window.jQuery(document.body).trigger('added_to_cart', [fragments, cartHash, null]);
        }
    }

    // ── Wishlist bulk-acties ──────────────────────────────────────────────────
    //
    // Elke wishlist (er kan meer dan één zijn) heeft zijn eigen bulkbar en
    // houdt zijn eigen selectie bij — een vinkje in lijst A telt niet mee
    // voor lijst B's balk. .mkcp-wishlist-list is de gedeelde ouder van zowel
    // de checkboxes als de bijbehorende bulkbar (zie account-wishlist.php).

    function wishlistSelectedIds(scopeEl) {
        if (!scopeEl) return [];
        var listEl = scopeEl.closest ? (scopeEl.classList.contains('mkcp-wishlist-list') ? scopeEl : scopeEl.closest('.mkcp-wishlist-list')) : null;
        if (!listEl) return [];
        return Array.prototype.map.call(
            listEl.querySelectorAll('.js-mkcp-wishlist-select:checked'),
            function (cb) { return cb.value; }
        );
    }

    function updateWishlistBulkbar(listEl) {
        if (!listEl) return;
        var ids = wishlistSelectedIds(listEl);
        var bar = listEl.querySelector('.mkcp-wishlist-bulkbar');
        if (!bar) return;
        bar.hidden = ids.length === 0;
        var countEl = bar.querySelector('.js-mkcp-wishlist-bulk-count');
        if (countEl) countEl.textContent = String(ids.length);
    }

    function resetAddressForm(addressForm) {
        addressForm.reset();
        addressForm.querySelector('[name="address_id"]').value = '';
    }

    function setAddressFormTitle(addressForm, text) {
        var titleEl = addressForm.querySelector('.js-mkcp-address-form-title');
        if (titleEl) titleEl.textContent = text;
    }

    function fillAddressForm(addressForm, data) {
        Object.keys(data).forEach(function (key) {
            // data.id komt van het adres-record (kolom "id"), maar het
            // verborgen veld in het formulier heet "address_id" — zonder
            // deze mapping bleef dat veld bij het bewerken altijd leeg, en
            // werd elke "opslaan" dus als NIEUW adres verwerkt (insert
            // i.p.v. update) — vandaar de dubbele adressen na bewerken.
            var fieldName = key === 'id' ? 'address_id' : key;
            var field = addressForm.querySelector('[name="' + fieldName + '"]');
            if (!field) return;
            if (field.type === 'checkbox') {
                field.checked = !!Number(data[key]);
            } else {
                field.value = data[key];
            }
        });
    }

    content.addEventListener('click', function (e) {
        // .closest() i.p.v. een directe id-check op e.target — de tegel
        // heeft geneste <span>-iconen/labels die het grootste deel van het
        // klikoppervlak innemen, dus een klik daarop miste voorheen dit
        // element compleet (e.target.id was dan leeg) en deed zichtbaar
        // niets. Zelfde laadspinner-conventie als elders (icoon tijdelijk
        // vervangen door een draaiend rondje) omdat scrollIntoView + de
        // fade-in van het formulier merkbaar tijd kosten.
        var addToggleBtn = e.target.closest('#mkcp-address-add-toggle');
        if (addToggleBtn) {
            var form = document.getElementById('mkcp-address-form');
            addToggleBtn.classList.add('is-loading');
            addToggleBtn.disabled = true;
            resetAddressForm(form);
            setAddressFormTitle(form, 'Nieuw adres');
            form.hidden = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            form.querySelector('input[name="first_name"]').focus();
            setTimeout(function () {
                addToggleBtn.classList.remove('is-loading');
                addToggleBtn.disabled = false;
            }, 500);
            return;
        }

        if (e.target.id === 'mkcp-address-cancel') {
            document.getElementById('mkcp-address-form').hidden = true;
            return;
        }

        var editBtn = e.target.closest('.js-mkcp-address-edit');
        if (editBtn) {
            var card = editBtn.closest('.mkcp-address-card');
            var form2 = document.getElementById('mkcp-address-form');
            var data = JSON.parse(card.getAttribute('data-address'));
            fillAddressForm(form2, data);
            setAddressFormTitle(form2, 'Adres bewerken');
            form2.hidden = false;
            form2.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        var deleteBtn = e.target.closest('.js-mkcp-address-delete');
        if (deleteBtn) {
            var card2 = deleteBtn.closest('.mkcp-address-card');
            mkcpConfirm('Dit adres verwijderen?', 'Verwijderen').then(function (ok) {
                if (!ok) return;
                var body = new URLSearchParams();
                body.set('action', 'mkcp_account_address_delete');
                body.set('nonce', mkcp_account_params.nonce);
                body.set('address_id', card2.getAttribute('data-address-id'));
                fetch(mkcp_account_params.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        if (json && json.success && json.data && typeof json.data.html === 'string') {
                            content.innerHTML = json.data.html;
                        }
                    });
            });
            return;
        }

        // Paginering binnen de bestellingenlijst: bewust géén hash-wijziging
        // (geen bookmarkbare paginanummers nodig voor dit MVP) — gewoon het
        // fragment opnieuw ophalen met een page-parameter.
        var pageBtn = e.target.closest('.js-mkcp-orders-page');
        if (pageBtn) {
            ordersState.page = parseInt(pageBtn.getAttribute('data-page'), 10) || 1;
            loadOrdersList();
            return;
        }

        var filterChip = e.target.closest('.js-mkcp-orders-filter');
        if (filterChip) {
            // Meteen visueel reageren (i.p.v. te wachten op de server-render
            // die pas na de AJAX-respons de "echte" is-active-class zet) —
            // zonder dit gaf een klik geen enkele directe terugkoppeling
            // zolang de aanvraag nog liep.
            var filterWrap = filterChip.closest('.mkcp-order-filters');
            if (filterWrap) {
                filterWrap.querySelectorAll('.js-mkcp-orders-filter').forEach(function (c) { c.classList.remove('is-active'); });
            }
            filterChip.classList.add('is-active');

            ordersState.status = filterChip.getAttribute('data-status') || '';
            ordersState.page = 1;
            loadOrdersList();
            return;
        }

        // Opnieuw bestellen: voegt de nog-bestelbare producten toe aan de
        // winkelwagen en hergebruikt daarna de bestaande 'added_to_cart'-
        // event-flow van cart-popup.js om de drawer te openen — geen nieuw
        // open-mechanisme, geen dubbele fragment-render-logica.
        var reorderBtn = e.target.closest('.js-mkcp-reorder');
        if (reorderBtn) {
            var statusEl = reorderBtn.parentElement.querySelector('[data-form-status="reorder"]');
            reorderBtn.disabled = true;
            var body3 = new URLSearchParams();
            body3.set('action', 'mkcp_account_reorder');
            body3.set('nonce', mkcp_account_params.nonce);
            body3.set('order_id', reorderBtn.getAttribute('data-order-id'));
            fetch(mkcp_account_params.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body3.toString()
            })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json && json.success) {
                        if (statusEl) {
                            statusEl.textContent = json.data.message || '';
                            statusEl.classList.add('is-success');
                        }
                        if (window.jQuery) {
                            window.jQuery(document.body).trigger('added_to_cart', [json.data.fragments, json.data.cart_hash, null]);
                        }
                    } else if (statusEl) {
                        statusEl.textContent = errorMessage(json, 'Opnieuw bestellen mislukt.');
                        statusEl.classList.add('is-error');
                    }
                })
                .catch(function () {
                    if (statusEl) { statusEl.textContent = 'Opnieuw bestellen mislukt.'; statusEl.classList.add('is-error'); }
                })
                .finally(function () { reorderBtn.disabled = false; });
            return;
        }

        var wlDeleteBtn = e.target.closest('.js-mkcp-wishlist-delete');
        if (wlDeleteBtn) {
            mkcpConfirm('Deze lijst verwijderen? Alle bewaarde items gaan mee verloren.', 'Verwijderen').then(function (ok) {
                if (!ok) return;
                var wlId = wlDeleteBtn.closest('.mkcp-wishlist-list').getAttribute('data-wishlist-id');
                postSimple('mkcp_account_wishlist_delete', { wishlist_id: wlId }).then(function (json) {
                    if (json && json.success && json.data && typeof json.data.html === 'string') {
                        content.innerHTML = json.data.html;
                    }
                });
            });
            return;
        }

        var wlCopyBtn = e.target.closest('.js-mkcp-wishlist-share-copy');
        if (wlCopyBtn) {
            var input = wlCopyBtn.parentElement.querySelector('.js-mkcp-wishlist-share-input');
            if (input && navigator.clipboard) {
                navigator.clipboard.writeText(input.value);
                wlCopyBtn.textContent = 'Gekopieerd!';
                setTimeout(function () { wlCopyBtn.textContent = 'Kopiëren'; }, 2000);
            }
            return;
        }

        var wlToCartBtn = e.target.closest('.js-mkcp-wishlist-to-cart');
        if (wlToCartBtn) {
            var itemId1 = wlToCartBtn.closest('.mkcp-wishlist-item').getAttribute('data-item-id');
            wlToCartBtn.disabled = true;
            wlToCartBtn.classList.add('is-loading');
            postSimple('mkcp_account_wishlist_item_to_cart', { item_id: itemId1 }).then(function (json) {
                if (json && json.success) {
                    triggerCartOpen(json.data.fragments, json.data.cart_hash);
                }
            }).catch(function () {}).finally(function () {
                wlToCartBtn.disabled = false;
                wlToCartBtn.classList.remove('is-loading');
            });
            return;
        }

        var wlRemoveBtn = e.target.closest('.js-mkcp-wishlist-remove');
        if (wlRemoveBtn) {
            var itemId2 = wlRemoveBtn.closest('.mkcp-wishlist-item').getAttribute('data-item-id');
            postSimple('mkcp_account_wishlist_item_remove', { item_id: itemId2 }).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                }
            });
            return;
        }

        // Bulk: geselecteerde items verwijderen — zelfde bevestigingsdialoog
        // als een los adres/losse wishlist-lijst verwijderen.
        var bulkDeleteBtn = e.target.closest('.js-mkcp-wishlist-bulk-delete');
        if (bulkDeleteBtn) {
            var bulkDeleteIds = wishlistSelectedIds(bulkDeleteBtn);
            if (!bulkDeleteIds.length) return;
            mkcpConfirm(bulkDeleteIds.length === 1 ? 'Dit item verwijderen?' : bulkDeleteIds.length + ' items verwijderen?', 'Verwijderen').then(function (ok) {
                if (!ok) return;
                bulkDeleteBtn.disabled = true;
                var body = new URLSearchParams();
                body.set('action', 'mkcp_account_wishlist_bulk_delete');
                body.set('nonce', mkcp_account_params.nonce);
                bulkDeleteIds.forEach(function (id) { body.append('item_ids[]', id); });
                fetch(mkcp_account_params.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        if (json && json.success && json.data && typeof json.data.html === 'string') {
                            content.innerHTML = json.data.html;
                        } else {
                            bulkDeleteBtn.disabled = false;
                        }
                    })
                    .catch(function () { bulkDeleteBtn.disabled = false; });
            });
            return;
        }

        // Bulk: geselecteerde items naar de winkelwagen — zelfde "open de
        // drawer via het bestaande added_to_cart-event"-truc als de losse
        // to-cart-knop per item.
        var bulkCartBtn = e.target.closest('.js-mkcp-wishlist-bulk-cart');
        if (bulkCartBtn) {
            var bulkCartIds = wishlistSelectedIds(bulkCartBtn);
            if (!bulkCartIds.length) return;
            bulkCartBtn.disabled = true;
            bulkCartBtn.classList.add('is-loading');
            var cartBody = new URLSearchParams();
            cartBody.set('action', 'mkcp_account_wishlist_bulk_to_cart');
            cartBody.set('nonce', mkcp_account_params.nonce);
            bulkCartIds.forEach(function (id) { cartBody.append('item_ids[]', id); });
            fetch(mkcp_account_params.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: cartBody.toString()
            })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json && json.success) {
                        triggerCartOpen(json.data.fragments, json.data.cart_hash);
                    }
                })
                .catch(function () {})
                .finally(function () {
                    bulkCartBtn.disabled = false;
                    bulkCartBtn.classList.remove('is-loading');
                });
            return;
        }

        var wlNotifyBtn = e.target.closest('.js-mkcp-wishlist-notify');
        if (wlNotifyBtn) {
            var itemId3 = wlNotifyBtn.closest('.mkcp-wishlist-item').getAttribute('data-item-id');
            wlNotifyBtn.disabled = true;
            wlNotifyBtn.classList.add('is-loading');
            postSimple('mkcp_account_wishlist_item_notify_toggle', { item_id: itemId3 }).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                }
            }).finally(function () {
                wlNotifyBtn.disabled = false;
                wlNotifyBtn.classList.remove('is-loading');
            });
            return;
        }

        // Retour aanvragen: opent het bijbehorende formulier (dezelfde
        // .js-mkcp-return-form die direct ná de knop staat, zie
        // account-returns.php) als volwaardige popup.
        var returnOpenBtn = e.target.closest('.js-mkcp-return-open');
        if (returnOpenBtn) {
            var returnForm = returnOpenBtn.nextElementSibling;
            if (returnForm && returnForm.classList.contains('js-mkcp-return-form')) {
                openFormModal(returnForm, returnOpenBtn);
            }
            return;
        }

        var returnCancelBtn = e.target.closest('.js-mkcp-return-cancel');
        if (returnCancelBtn) {
            var cancelForm = returnCancelBtn.closest('.js-mkcp-return-form');
            if (cancelForm) closeFormModal(cancelForm);
            return;
        }

        // Review schrijven: zelfde open/annuleren-patroon als retour aanvragen.
        var reviewOpenBtn = e.target.closest('.js-mkcp-review-open');
        if (reviewOpenBtn) {
            var reviewForm = reviewOpenBtn.nextElementSibling;
            if (reviewForm && reviewForm.classList.contains('js-mkcp-review-form')) {
                openFormModal(reviewForm, reviewOpenBtn);
            }
            return;
        }

        var reviewCancelBtn = e.target.closest('.js-mkcp-review-cancel');
        if (reviewCancelBtn) {
            var reviewCancelForm = reviewCancelBtn.closest('.js-mkcp-review-form');
            if (reviewCancelForm) closeFormModal(reviewCancelForm);
            return;
        }

        // Eén melding als gelezen markeren — direct in de DOM bijgewerkt
        // (geen hele fragment-herlaad nodig voor zo'n kleine wijziging),
        // alleen het nav-badge-aantal komt uit de server-respons.
        var notifReadBtn = e.target.closest('.js-mkcp-notif-read');
        if (notifReadBtn) {
            var notifRow = notifReadBtn.closest('.mkcp-notif');
            var notifId = notifRow && notifRow.getAttribute('data-notification-id');
            if (!notifId) return;
            notifReadBtn.disabled = true;
            postSimple('mkcp_account_notif_read', { notification_id: notifId }).then(function (json) {
                if (json && json.success) {
                    notifRow.classList.remove('is-unread');
                    notifReadBtn.remove();
                    updateNavBadge(json.data.unread_count);
                }
            }).catch(function () { notifReadBtn.disabled = false; });
            return;
        }

        if (e.target.id === 'mkcp-account-delete-request') {
            var deleteBtn2 = e.target;
            var deleteStatus = deleteBtn2.parentElement.querySelector('[data-form-status="delete-account"]');
            mkcpConfirm(
                'Weet je zeker dat je je account definitief wilt verwijderen? Je ontvangt een bevestigingsmail — pas na het klikken op de link daarin wordt je account echt verwijderd.',
                'Ja, verwijder mijn account'
            ).then(function (ok) {
                if (!ok) return;
                deleteBtn2.disabled = true;
                postSimple('mkcp_account_delete_request', {}).then(function (json) {
                    if (deleteStatus) {
                        if (json && json.success) {
                            deleteStatus.textContent = (json.data && json.data.message) || 'Bevestigingsmail verstuurd.';
                            deleteStatus.classList.add('is-success');
                        } else {
                            deleteStatus.textContent = errorMessage(json, 'Aanvragen mislukt.');
                            deleteStatus.classList.add('is-error');
                        }
                    }
                }).catch(function () {
                    if (deleteStatus) { deleteStatus.textContent = 'Aanvragen mislukt.'; deleteStatus.classList.add('is-error'); }
                }).finally(function () { deleteBtn2.disabled = false; });
            });
            return;
        }

        if (e.target.id === 'mkcp-notif-mark-all') {
            e.target.disabled = true;
            postSimple('mkcp_account_notif_read_all', {}).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                    updateNavBadge(json.data.unread_count);
                }
            }).catch(function () { e.target.disabled = false; });
            return;
        }

        // Meldingen-filterchips (Alles/Ongelezen/per type) — puur client-side
        // (de hele lijst is al geladen), geen nieuw AJAX-rondje nodig. "all"
        // en "unread" blijven een speciaal geval (leesstatus i.p.v. type);
        // elke andere data-filter-waarde is een letterlijke notification.type
        // (zie account-notifications.php).
        var notifTab = e.target.closest('.js-mkcp-notif-tab');
        if (notifTab) {
            var tabsWrap = notifTab.closest('.mkcp-notif-tabs');
            if (tabsWrap) {
                tabsWrap.querySelectorAll('.js-mkcp-notif-tab').forEach(function (t) { t.classList.remove('is-active'); });
            }
            notifTab.classList.add('is-active');
            var filter = notifTab.getAttribute('data-filter');
            var list = document.getElementById('mkcp-notif-list');
            if (list) {
                list.querySelectorAll('.mkcp-notif').forEach(function (row) {
                    var show = filter === 'all'
                        || (filter === 'unread' && row.classList.contains('is-unread'))
                        || (filter !== 'unread' && row.getAttribute('data-type') === filter);
                    row.hidden = !show;
                });
                // Datum-groeplabel ("Vandaag"/"Deze week"/"Eerder") verbergen
                // zodra na filteren geen enkele melding in die groep meer
                // zichtbaar is — anders blijft er een kaal, betekenisloos
                // kopje boven een lege ruimte staan.
                list.querySelectorAll('.mkcp-notif-group-label').forEach(function (label) {
                    var sibling = label.nextElementSibling;
                    var hasVisible = false;
                    while (sibling && !sibling.classList.contains('mkcp-notif-group-label')) {
                        if (!sibling.hidden) { hasVisible = true; break; }
                        sibling = sibling.nextElementSibling;
                    }
                    label.hidden = !hasVisible;
                });
            }
            return;
        }

        // Klikken op een ongelezen melding zelf (niet op de "markeer als
        // gelezen"-knop, die is hierboven al afgehandeld en heeft de klik
        // dan al met een 'return' gestopt) markeert 'm ook als gelezen, en
        // navigeert daarna naar de bijbehorende route als die er is.
        var notifRow2 = e.target.closest('.mkcp-notif.is-unread');
        if (notifRow2) {
            var notifId2 = notifRow2.getAttribute('data-notification-id');
            var url = notifRow2.getAttribute('data-url');
            postSimple('mkcp_account_notif_read', { notification_id: notifId2 }).then(function (json) {
                if (json && json.success) {
                    notifRow2.classList.remove('is-unread');
                    var btn = notifRow2.querySelector('.js-mkcp-notif-read');
                    if (btn) btn.remove();
                    updateNavBadge(json.data.unread_count);
                }
            });
            if (url) window.location.hash = url.replace(/^#\/?/, '#/');
            return;
        }
    });

    content.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-mkcp-wishlist-share-toggle')) {
            var wlId2 = e.target.closest('.mkcp-wishlist-list').getAttribute('data-wishlist-id');
            var checkbox = e.target;
            checkbox.disabled = true;
            postSimple('mkcp_account_wishlist_share_toggle', { wishlist_id: wlId2, enabled: checkbox.checked ? '1' : '' }).then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string') {
                    content.innerHTML = json.data.html;
                } else {
                    checkbox.disabled = false;
                }
            });
            return;
        }

        // Gewenste-prijsgrens per wishlist-item — bewust geen fragment-
        // herrender als response (zou de focus uit het veld halen), alleen
        // een korte visuele "opgeslagen"-flits op het veld zelf.
        if (e.target.classList.contains('js-mkcp-wishlist-target-price')) {
            var priceInput = e.target;
            var itemId4 = priceInput.closest('.mkcp-wishlist-item').getAttribute('data-item-id');
            priceInput.classList.remove('is-saved');
            postSimple('mkcp_account_wishlist_item_target_price', { item_id: itemId4, target_price: priceInput.value }).then(function (json) {
                if (json && json.success) {
                    // target_price_display komt al met komma opgemaakt van de
                    // server — niet target_price zelf gebruiken, dat is een
                    // rauw getal en zou met een punt terugkomen (JS'
                    // Number-naar-string), inconsistent met wat de klant
                    // typte en met de rest van de site (NL-notatie).
                    var display = json.data && json.data.target_price_display;
                    priceInput.value = (json.data && json.data.target_price === null) ? '' : ( display || '' );
                    priceInput.classList.add('is-saved');
                    setTimeout(function () { priceInput.classList.remove('is-saved'); }, 1500);
                }
            });
            return;
        }

        // Item aan-/uitvinken voor bulk-acties — de balk zelf toont/verbergt
        // zich puur op basis van "staat er ergens ≥1 vinkje aan", geen aparte
        // "bulk-modus"-schakelaar nodig.
        if (e.target.classList.contains('js-mkcp-wishlist-select')) {
            var listEl = e.target.closest('.mkcp-wishlist-list');
            updateWishlistBulkbar(listEl);
            return;
        }

        // Verplaatsen-dropdown in de bulk-balk: direct uitvoeren zodra er een
        // lijst gekozen is, geen aparte "verplaats"-knop nodig naast de select.
        if (e.target.classList.contains('js-mkcp-wishlist-bulk-move-target')) {
            var moveSelect = e.target;
            var targetId = moveSelect.value;
            if (!targetId) return;
            var moveBar = moveSelect.closest('.mkcp-wishlist-bulkbar');
            var moveIds = wishlistSelectedIds(moveBar);
            if (!moveIds.length) return;
            moveSelect.disabled = true;
            var moveBody = new URLSearchParams();
            moveBody.set('action', 'mkcp_account_wishlist_bulk_move');
            moveBody.set('nonce', mkcp_account_params.nonce);
            moveBody.set('target_wishlist_id', targetId);
            moveIds.forEach(function (id) { moveBody.append('item_ids[]', id); });
            fetch(mkcp_account_params.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: moveBody.toString()
            })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json && json.success && json.data && typeof json.data.html === 'string') {
                        content.innerHTML = json.data.html;
                    } else {
                        moveSelect.disabled = false;
                    }
                })
                .catch(function () { moveSelect.disabled = false; });
            return;
        }
    });

    // ── "Recent bekeken producten" (Dashboard-widget) ────────────────────────
    //
    // De ID's staan al klaar in localStorage (assets/wishlist-icon.js volgt
    // die bij op elke productpagina) — hier alleen uitlezen en de kaart
    // vullen via een klein AJAX-rondje. Faalt dit (leeg/geen localStorage/
    // AJAX-fout) dan blijft de kaart gewoon verborgen, geen foutmelding
    // nodig voor zo'n secundaire widget.
    function loadRecentlyViewed() {
        var card = document.getElementById('mkcp-recently-viewed-card');
        var list = document.getElementById('mkcp-recently-viewed-list');
        if (!card || !list) return;

        var ids = [];
        try {
            var stored = JSON.parse(localStorage.getItem('mkcp_recently_viewed') || '[]');
            if (Array.isArray(stored)) ids = stored;
        } catch (e) {}
        if (!ids.length) return;

        var body = new URLSearchParams();
        body.set('action', 'mkcp_account_recently_viewed');
        body.set('nonce', mkcp_account_params.nonce);
        ids.forEach(function (id) { body.append('ids[]', id); });

        fetch(mkcp_account_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json && json.success && json.data && typeof json.data.html === 'string' && json.data.html) {
                    list.innerHTML = json.data.html;
                    card.hidden = false;
                    initDashScrollers();
                }
            })
            .catch(function () {});
    }

    // ── Productsliders (Dashboard-widgets + de volledige Wishlist-lijst) ─────
    //
    // Zelfde pijltjesnavigatie-patroon als de checkout-adreskiezer/bezorg-
    // datum-kaarten: scrollt een vaste afstand, knoppen worden uitgeschakeld
    // aan het begin/eind — met weinig items (alles past al) blijven ze dus
    // gewoon (onzichtbaar) uitgeschakeld, wat vanzelf "alleen een slider bij
    // meer dan een paar items" oplevert zonder aparte drempel-logica.
    // STEP wordt per track berekend (kaartbreedte verschilt: 140px voor de
    // compacte Dashboard-kaarten, 208px voor de volledige Wishlist-kaarten)
    // i.p.v. een vaste waarde die alleen voor één van de twee klopte.
    function initDashScrollers() {
        document.querySelectorAll('.mkcp-dash-scroller').forEach(function (wrap) {
            if (wrap.dataset.mkcpNavBound) return;
            var track = wrap.querySelector('.mkcp-dash-product-scroller, .mkcp-wishlist-items');
            var prevBtn = wrap.querySelector('.mkcp-dash-scroller__nav--prev');
            var nextBtn = wrap.querySelector('.mkcp-dash-scroller__nav--next');
            if (!track || !(prevBtn || nextBtn)) return;
            wrap.dataset.mkcpNavBound = '1';

            function stepSize() {
                var firstCard = track.firstElementChild;
                if (!firstCard) return 200;
                var gap = parseFloat(getComputedStyle(track).columnGap) || 0;
                return Math.ceil(firstCard.getBoundingClientRect().width + gap);
            }

            function updateNavState() {
                if (prevBtn) prevBtn.disabled = track.scrollLeft <= 4;
                if (nextBtn) nextBtn.disabled = track.scrollLeft >= (track.scrollWidth - track.clientWidth - 4);
            }

            if (prevBtn) prevBtn.addEventListener('click', function () {
                track.scrollBy({ left: -stepSize() * 2, behavior: 'smooth' });
            });
            if (nextBtn) nextBtn.addEventListener('click', function () {
                track.scrollBy({ left: stepSize() * 2, behavior: 'smooth' });
            });
            track.addEventListener('scroll', updateNavState);
            updateNavState();
        });
    }

    // Fragment-innerHTML-vervangingen gebeuren op meerdere plekken (wishlist
    // aanmaken/verwijderen, item toevoegen/verwijderen/melding-toggle,
    // lijst delen) — i.p.v. initDashScrollers() bij elk van die plekken los
    // aan te roepen, één generieke observer die 'm na elke DOM-wijziging in
    // #mkcp-account-content opnieuw aanroept (goedkoop en idempotent dankzij
    // de mkcpNavBound-guard hierboven, die alleen echt nieuwe wrappers bindt).
    if (window.MutationObserver) {
        var mkcpScrollerObserver = new MutationObserver(function () { initDashScrollers(); });
        mkcpScrollerObserver.observe(content, { childList: true, subtree: true });
    }

    // ── Thema-toggle (licht/donker) ──────────────────────────────────────────
    //
    // account-page.php zet het opgeslagen/voorkeurs-thema al vóór de eerste
    // render via een inline <script> in de <head> (voorkomt een flits van
    // het verkeerde thema) — hier alleen de klik zelf + het bijwerken van
    // localStorage, zodat het bij de volgende paginalading (of routewissel,
    // want dit knop-element staat BUITEN #mkcp-account-content) onthouden blijft.
    var themeToggle = document.getElementById('mkcp-account-theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-mkcp-theme') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-mkcp-theme', next);
            try { localStorage.setItem('mkcp_account_theme', next); } catch (e) {}
        });
    }

    // Script staat in de footer (na de DOM), dus 'DOMContentLoaded' is op dit
    // punt al gevuurd en zou nooit meer afgaan — meteen zelf aanroepen i.p.v.
    // op dat event te wachten.
    navigate();
})();
