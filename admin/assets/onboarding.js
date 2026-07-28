/* MK Cart Popup — Onboarding-tour (Driver.js)
 *
 * Start automatisch één keer, direct na de eerste plugin-activatie (zie
 * mkcp_show_onboarding in mk-cart-popup.php / admin/onboarding.php), en is
 * daarna altijd handmatig opnieuw te starten via #mkcp-replay-tour.
 *
 * Structuur:
 *   1. Intro-stap (geen element, gecentreerde popover) — kies Snelstart of
 *      Volledige rondleiding.
 *   2. fullStepDefs — één stap per tab van de "Cart Popup"-instellingen (de
 *      "Cart Checkout"-productnav, met de aparte checkout-page-builder,
 *      valt hier bewust buiten — dat verdient een eigen tour).
 *   3. quickStepDefs — subset van fullStepDefs (licentie, aanzetten, opslaan).
 *
 * Elke "echte" stap wijst naar een element in een andere tab. Niet-actieve
 * tabs staan op display:none (zie admin/assets/settings.js, activateTab())
 * — Driver.js meet een element ZODRA het een stap highlight, dus de juiste
 * tab moet al actief zijn vóórdat die meting gebeurt. Daarom wordt de tab
 * niet in onHighlightStarted geactiveerd (te laat — Driver.js heeft het
 * (afwezige) element dan al opgezocht), maar in popover.onNextClick van de
 * VORIGE stap, vlak vóór tour.moveNext() wordt aangeroepen.
 */
(function () {
    'use strict';

    if (!window.driver || !window.driver.js || !window.mkcpOnboarding) return;
    var driver = window.driver.js.driver;
    var O = window.mkcpOnboarding;
    var premium = !!O.isPremium;

    var LS_PROGRESS_KEY = 'mkcp_onboarding_progress'; // { path: 'quick'|'full', index: n }

    function activateTab(selector) {
        if (!selector) return;
        var el = document.querySelector(selector);
        if (el) el.click();
    }

    function saveProgress(path, index) {
        try {
            localStorage.setItem(LS_PROGRESS_KEY, JSON.stringify({ path: path, index: index }));
        } catch (e) { /* private mode / quota — negeren, resume is een leuk-extraatje, geen kernfunctie */ }
    }

    function readProgress() {
        try {
            var raw = localStorage.getItem(LS_PROGRESS_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function clearProgress() {
        try { localStorage.removeItem(LS_PROGRESS_KEY); } catch (e) { /* zie hierboven */ }
    }

    // ── Stap-inhoud ──────────────────────────────────────────────────────────

    var verzendingText = O.hasShippingZones
        ? 'Stel hier de gratis-verzending-progressbalk in. De drempel wordt automatisch bepaald op basis van de WooCommerce-verzendzone van de bezoeker.'
        : '⚠️ Je hebt in WooCommerce nog geen verzendzone ingesteld — de gratis-verzendbalk kan daardoor nu geen drempel tonen. Stel eerst een verzendzone in (WooCommerce → Instellingen → Verzending), en kom dan hierop terug.';

    var fullStepDefs = [
        {
            activator: '.mkcp-nav-item[data-tab="dashboard"]',
            element: '[data-panel="dashboard"] .mkcp-page-header',
            title: 'Dashboard',
            description: 'Je startpunt — een overzicht van je licentiestatus, of de plugin actief is, en je belangrijkste instellingen in één oogopslag.',
            side: 'bottom',
        },
        {
            activator: '[data-goto="licentie"]',
            element: '#mkcp_license_key',
            title: 'Licentiesleutel',
            description: 'Vul hier je licentiesleutel in en klik op "Valideren". Zonder geldige, geverifieerde sleutel werkt de plugin gewoon in de gratis (basic) modus — premium-functies ontgrendel je pas na een geslaagde validatie.',
            side: 'bottom',
            validateLicense: '#mkcp_license_key',
        },
        {
            activator: '.mkcp-nav-item[data-tab="general"]',
            element: '#mkcp-enabled-toggle-wrap',
            title: 'Algemeen — plugin aanzetten',
            description: 'Zet deze schakelaar aan om de cart-popup daadwerkelijk op je website te tonen. Hier vind je ook de algemene teksten en labels van de drawer.',
            side: 'right',
        },
        {
            activator: '.mkcp-nav-item[data-tab="styling"]',
            element: '#mkcp_style_accent',
            title: 'Styling — je eigen merkkleur',
            description: premium
                ? 'Kies hier de hoofdkleur van je cart-popup — die kleur komt overal terug, van knoppen tot accenten.'
                : '🔒 Met een premium-licentie kies je hier je eigen merkkleur voor de cart-popup, in plaats van de standaardkleur.',
            side: 'right',
        },
        {
            activator: '.mkcp-nav-item[data-tab="behavior"]',
            element: '[data-panel="behavior"] .mkcp-page-header',
            title: 'Cart Gedrag',
            description: 'Hier bepaal je hoe de popup reageert op add-to-cart-acties en winkelwagen-navigatie — bijvoorbeeld of hij automatisch opent zodra een klant iets toevoegt.',
            side: 'bottom',
        },
        {
            activator: '.mkcp-nav-item[data-tab="shipping"]',
            element: '[data-panel="shipping"] .mkcp-page-header',
            title: 'Verzending',
            description: verzendingText,
            side: 'bottom',
        },
        {
            activator: '.mkcp-nav-item[data-tab="checkout"]',
            element: '[data-panel="checkout"] .mkcp-page-header',
            title: 'Checkout',
            description: 'Hier stel je het minimum bestelbedrag en de betaalmethode-iconen in die in de cart-popup getoond worden.',
            side: 'bottom',
        },
        {
            activator: '.mkcp-nav-item--builder[data-tab="builder"]',
            element: '[data-panel="builder"] .mkcp-builder-wrap',
            title: 'Content Builder',
            description: (premium
                ? 'Hier stel je de inhoud van je cart-popup samen: sleep blokken zoals een gratis-verzendbalk, cross-sell of kortingscode naar de gewenste volgorde. Rechts zie je meteen een live preview.'
                : '🔒 Met een premium-licentie stel je hier zelf de inhoud van je cart-popup samen — blokken zoals een gratis-verzendbalk, cross-sell of kortingscode, met een live preview ernaast.'
            ) + '<div class="mkcp-onboarding-builder-anim" aria-hidden="true"><span class="mkcp-onboarding-builder-anim-block"></span><span class="mkcp-onboarding-builder-anim-zone"></span></div>',
            side: 'left',
        },
        {
            activator: '.mkcp-nav-item[data-tab="crosssell"]',
            element: '[data-panel="crosssell"] .mkcp-page-header',
            title: 'Cross-selling',
            description: 'Toon relevante producten in de cart-popup zodra een klant iets aan de winkelmand toevoegt — een eenvoudige manier om de gemiddelde bestelwaarde te verhogen.',
            side: 'bottom',
        },
        {
            activator: '.mkcp-nav-item[data-tab="analytics"]',
            element: '[data-panel="analytics"] .mkcp-page-header',
            title: 'Analytics',
            description: 'Koppel GA4/Google Tag Manager via window.dataLayer, of gebruik de eigen ingebouwde WooCommerce-statistieken die GA4 niet kan zien.',
            side: 'bottom',
        },
        {
            activator: null,
            element: '.mkcp-panel.is-active .mkcp-save-bar button[type="submit"]',
            title: 'Klaar!',
            description: buildFinishDescription(),
            side: 'top',
            isFinish: true,
        },
    ];

    var quickStepDefs = [1, 2, fullStepDefs.length - 1].map(function (i) { return fullStepDefs[i]; });

    function buildFinishDescription() {
        var checklist = [];
        if (!O.enabled) checklist.push('⚠️ De plugin staat nog uit — zonder dat blijft de cart-popup onzichtbaar op je website.');
        if (!O.licenseValid) checklist.push('⚠️ Er is nog geen geldige licentie actief — premium-functies blijven zo op slot.');

        var html = 'Vergeet niet op te slaan — dat kan altijd per tab met deze knop.';
        if (checklist.length) {
            html += '<ul class="mkcp-onboarding-checklist">' + checklist.map(function (t) { return '<li>' + t + '</li>'; }).join('') + '</ul>';
        } else {
            html += ' Alles staat klaar — veel plezier met de plugin!';
        }
        return html;
    }

    // ── Voortgangs-dots + knoppen injecteren in de popover ────────────────────

    // popoverRefs is GEEN DOM-element, maar een object met losse refs die
    // Driver.js zelf al heeft opgezocht: { wrapper, title, description,
    // footer, previousButton, nextButton, closeButton, footerButtons,
    // progress }. .wrapper is de enige die zelf een <div> is (dus de enige
    // waarop .querySelector zinvol is) — footer/nextButton etc. zijn al
    // rechtstreeks de elementen zelf, geen selector nodig.
    function renderDots(popoverRefs, index, total) {
        var footer = popoverRefs.footer;
        if (!footer) return;
        var old = popoverRefs.wrapper.querySelector('.mkcp-onboarding-dots');
        if (old) old.remove();

        var wrap = document.createElement('div');
        wrap.className = 'mkcp-onboarding-dots';
        for (var i = 0; i < total; i++) {
            var dot = document.createElement('span');
            dot.className = 'mkcp-onboarding-dot' + (i <= index ? ' is-done' : '');
            wrap.appendChild(dot);
        }
        footer.parentNode.insertBefore(wrap, footer);
    }

    // Placeholder van het veld zelf (MK-XXXX-XXXX-XXXX-XXXX) is de enige
    // betrouwbare bron voor het verwachte format — geen losse aanname.
    var LICENSE_KEY_FORMAT = /^MK-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i;

    // Losse, rustige hint-tekst boven het veld zolang de invoer niet aan het
    // verwachte format voldoet — fixed-position (het veld zit in een gewone
    // instellingen-rij zonder position:relative-context), positie wordt
    // bijgewerkt bij scrollen/resizen. removeHint() ruimt zowel bij geldige
    // invoer als bij het (voortijdig) sluiten van de tour op — zie
    // newDriverInstance()'s onDestroyed hieronder als vangnet.
    function placeGateHint(field, text) {
        var hint = document.createElement('div');
        hint.className = 'mkcp-onboarding-gate-arrow';
        hint.innerHTML = '<span>' + text + '</span>';
        document.body.appendChild(hint);

        function reposition() {
            var rect = field.getBoundingClientRect();
            hint.style.left = (rect.left + rect.width / 2) + 'px';
            hint.style.top = (rect.top - 40) + 'px';
        }
        reposition();
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        return function removeHint() {
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
            if (hint.parentNode) hint.parentNode.removeChild(hint);
        };
    }

    // Hergebruikt exact dezelfde server-actie/nonce als de bestaande
    // "Verifieer nu"-knop op de licentietab (admin/assets/settings.js) —
    // maar zonder diens location.reload(), want dat zou de lopende tour
    // meteen weer afbreken. mkcpAdmin is al gelocaliseerd op het
    // 'mkcp-admin'-scripthandle, waar 'mkcp-onboarding' een dependency van
    // is (zie admin/settings.php) — dus altijd al beschikbaar tegen de tijd
    // dat dit bestand draait.
    function verifyLicenseKey(key, cb) {
        if (!window.mkcpAdmin) { cb({ ok: false, message: 'Kon de licentieserver niet bereiken.' }); return; }

        var data = new FormData();
        data.append('action', 'mkcp_verify_license');
        data.append('nonce', window.mkcpAdmin.licenseNonce);
        data.append('key', key);

        fetch(window.mkcpAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var d = res.success ? (res.data || {}) : {};
                cb({
                    ok: !!d.valid,
                    message: d.message || (d.valid ? 'Licentie geverifieerd.' : 'Deze sleutel is niet geldig.'),
                });
            })
            .catch(function () {
                cb({ ok: false, message: 'Verbinding met de licentieserver mislukt. Controleer je internetverbinding.' });
            });
    }

    function renderLicenseMessage(popoverRefs, ok, text) {
        var old = popoverRefs.wrapper.querySelector('.mkcp-onboarding-license-msg');
        if (old) old.remove();
        if (!text) return;

        var msg = document.createElement('div');
        msg.className = 'mkcp-onboarding-license-msg ' + (ok ? 'is-success' : 'is-error');
        msg.textContent = text;
        popoverRefs.description.parentNode.insertBefore(msg, popoverRefs.description.nextSibling);
    }

    // Twee lagen: eerst een format-check (client-side, direct — voorkomt
    // zinloze serveraanroepen bij overduidelijk onzin-invoer), en pas
    // daarna de ECHTE validatie tegen de licentieserver via de knop, die
    // zelf van "Valideren" naar "Volgende" verandert zodra de sleutel
    // daadwerkelijk geverifieerd is. onValidated/onInvalidated houden de
    // per-stap "mag ik door?"-vlag in buildSteps() bij.
    function setupLicenseValidation(popoverRefs, fieldSelector, onValidated, onInvalidated) {
        var nextBtn = popoverRefs.nextButton;
        var field = document.querySelector(fieldSelector);
        if (!nextBtn || !field) return;

        var removeHint = null;
        var currentHintText = null;
        var validating = false;
        var validated = false;

        function setHint(text) {
            if (text === currentHintText) return;
            if (removeHint) { removeHint(); removeHint = null; }
            if (text) removeHint = placeGateHint(field, text);
            currentHintText = text;
        }

        function setPulse(el) {
            document.querySelectorAll('.mkcp-onboarding-gate-pulse').forEach(function (n) { n.classList.remove('mkcp-onboarding-gate-pulse'); });
            if (el) el.classList.add('mkcp-onboarding-gate-pulse');
        }

        function sync() {
            if (validating) return;
            validated = false;
            onInvalidated();
            renderLicenseMessage(popoverRefs, false, '');

            var value = field.value.trim();
            var formatOk = LICENSE_KEY_FORMAT.test(value);

            nextBtn.disabled = !formatOk;
            nextBtn.classList.toggle('mkcp-onboarding-btn-disabled', !formatOk);
            nextBtn.textContent = 'Valideren';

            var ring = document.querySelector('.driver-active-element');
            if (formatOk) {
                setHint(null);
                setPulse(nextBtn);
                nextBtn.title = 'Klik om je sleutel te verifiëren';
                if (ring) ring.classList.remove('mkcp-onboarding-gate-pulse');
            } else {
                setPulse(ring);
                nextBtn.title = 'Vul eerst een geldige licentiesleutel in';
                setHint(value === '' ? 'vul hier je sleutel in' : 'dit lijkt nog geen geldige sleutel');
            }
        }

        function runValidation() {
            validating = true;
            nextBtn.disabled = true;
            setPulse(null);
            var origText = nextBtn.textContent;
            nextBtn.textContent = 'Valideren…';
            renderLicenseMessage(popoverRefs, false, '');

            verifyLicenseKey(field.value.trim(), function (result) {
                validating = false;
                nextBtn.disabled = false;

                if (result.ok) {
                    validated = true;
                    onValidated();
                    nextBtn.textContent = 'Volgende →';
                    setHint(null);
                    renderLicenseMessage(popoverRefs, true, '✓ ' + result.message);
                } else {
                    validated = false;
                    onInvalidated();
                    nextBtn.textContent = origText;
                    setPulse(nextBtn);
                    renderLicenseMessage(popoverRefs, false, '✕ ' + result.message);
                }
            });
        }

        field.addEventListener('input', sync);
        sync();

        // Geeft buildSteps() een manier om te weten of een klik op de knop
        // moet valideren of gewoon mag doorgaan naar de volgende stap.
        return function handleNextClick() {
            if (validated) return true; // mag door
            if (!nextBtn.disabled && !validating) runValidation();
            return false; // nog niet door
        };
    }

    function renderFinishCta(popoverRefs) {
        var footer = popoverRefs.footer;
        if (!footer || popoverRefs.wrapper.querySelector('.mkcp-onboarding-cta')) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mkcp-onboarding-cta';
        btn.textContent = 'Bekijk je cart-popup live ↗';
        btn.addEventListener('click', function () {
            window.open(O.siteUrl, '_blank', 'noopener');
        });
        footer.parentNode.insertBefore(btn, footer);
    }

    // ── Stappen opbouwen ─────────────────────────────────────────────────────

    function buildSteps(tour, defs, path) {
        return defs.map(function (def, i) {
            var isLast = i === defs.length - 1;
            var licenseValidated = false;
            var licenseNextHandler = null;

            function goToNext() {
                activateTab(defs[i + 1].activator);
                tour.moveNext();
            }

            return {
                element: def.element,
                popover: {
                    title: def.title,
                    description: def.description,
                    side: def.side,
                    onPopoverRender: function (popoverRefs) {
                        renderDots(popoverRefs, i, defs.length);
                        saveProgress(path, i);
                        if (def.validateLicense) {
                            licenseNextHandler = setupLicenseValidation(
                                popoverRefs,
                                def.validateLicense,
                                function () { licenseValidated = true; },
                                function () { licenseValidated = false; }
                            );
                        }
                        if (def.isFinish) renderFinishCta(popoverRefs);
                    },
                    onNextClick: isLast ? function () {
                        clearProgress();
                        tour.destroy();
                    } : function () {
                        if (def.validateLicense) {
                            // Eerste klik(ken): valideert (async) en blijft
                            // op deze stap staan. Pas ná een geslaagde
                            // validatie doet dezelfde knop (dan al omgezet
                            // naar "Volgende →") de echte stap-overgang.
                            if (!licenseValidated) { if (licenseNextHandler) licenseNextHandler(); return; }
                        }
                        goToNext();
                    },
                    onPrevClick: i === 0 ? undefined : function () {
                        activateTab(defs[i - 1].activator);
                        tour.movePrevious();
                    },
                },
            };
        });
    }

    // ── Intro / pad-keuze ────────────────────────────────────────────────────
    //
    // Belangrijk: een LOPENDE Driver.js-instance hergebruiken door er
    // gewoon setSteps()+drive() opnieuw op aan te roepen ruimt de oude
    // popover-DOM niet altijd netjes op (leidde tot twee popovers tegelijk
    // op het scherm — de oude intro-popover én de nieuwe eerste stap).
    // Daarom hier altijd expliciet eerst de vorige instance destroy()'en en
    // dan een compleet verse instance starten, i.p.v. dezelfde te hergebruiken.
    function beginPath(path, startIndex, previousTour) {
        if (previousTour) previousTour.destroy();

        var tour = newDriverInstance();
        var defs = path === 'quick' ? quickStepDefs : fullStepDefs;
        activateTab(defs[0].activator);
        tour.setSteps(buildSteps(tour, defs, path));
        tour.drive(startIndex || 0);
    }

    function buildIntroStep(introTour) {
        return {
            popover: {
                title: 'Welkom bij MK Cart Popup 👋',
                description: 'Loop even met ons mee, of ga meteen zelf aan de slag.',
                showButtons: [],
                onPopoverRender: function (popoverRefs) {
                    var footer = popoverRefs.footer;
                    if (!footer) return;

                    var wrap = document.createElement('div');
                    wrap.className = 'mkcp-onboarding-path-choice';

                    var quickBtn = document.createElement('button');
                    quickBtn.type = 'button';
                    quickBtn.className = 'mkcp-onboarding-path-btn';
                    quickBtn.innerHTML = '<strong>Snelstart</strong><span>3 stappen — licentie, aanzetten, opslaan</span>';
                    quickBtn.addEventListener('click', function () { beginPath('quick', 0, introTour); });

                    var fullBtn = document.createElement('button');
                    fullBtn.type = 'button';
                    fullBtn.className = 'mkcp-onboarding-path-btn mkcp-onboarding-path-btn--primary';
                    fullBtn.innerHTML = '<strong>Volledige rondleiding</strong><span>11 stappen — elk onderdeel van de plugin</span>';
                    fullBtn.addEventListener('click', function () { beginPath('full', 0, introTour); });

                    wrap.appendChild(quickBtn);
                    wrap.appendChild(fullBtn);
                    footer.parentNode.insertBefore(wrap, footer);
                },
            },
        };
    }

    // ── Starten ──────────────────────────────────────────────────────────────

    function newDriverInstance() {
        return driver({
            showProgress: false,
            allowClose: true,
            overlayOpacity: 0.65,
            stagePadding: 8,
            stageRadius: 10,
            animate: true,
            popoverClass: 'mkcp-onboarding-popover',
            nextBtnText: 'Volgende',
            prevBtnText: 'Vorige',
            doneBtnText: 'Klaar',
            // Vangnet: als de tour halverwege de licentiestap wordt gesloten
            // (X-knop/overlay-klik/Escape) terwijl het veld nog leeg/ongeldig
            // is, blijft de losse, aan document.body gehangen hint-tekst
            // anders achter in de DOM — setupLicenseValidation()'s eigen
            // opruimlogica loopt dan niet meer (die hangt aan input-events
            // op een veld dat inmiddels niet meer relevant is).
            onDestroyed: function () {
                document.querySelectorAll('.mkcp-onboarding-gate-arrow').forEach(function (el) { el.remove(); });
                // Bewust GEEN resize/'wp-window-resized'-dispatch meer hier —
                // dat was een eerdere, destijds onvoldoende gebleken poging om
                // het "menu schuift achter de admin-bar"-probleem op te lossen
                // door WP's eigen admin-menu-herberekening te triggeren. De
                // echte fix zit in admin/assets/settings.css (#adminmenuwrap
                // krijgt daar zelf position:fixed). Die dispatch hier actief
                // laten staan is niet alleen overbodig maar schadelijk: het
                // roept WP's eigen menu-JS alsnog aan, die dan een inline
                // top-waarde op #adminmenuwrap kan zetten die de vaste
                // positionering uit settings.css weer overschrijft.
            },
        });
    }

    function startTour(resumeFromAuto) {
        var progress = resumeFromAuto ? readProgress() : null;

        if (progress && progress.path && progress.index > 0) {
            beginPath(progress.path, progress.index);
        } else {
            var introTour = newDriverInstance();
            introTour.setSteps([buildIntroStep(introTour)]);
            introTour.drive();
        }
    }

    window.mkcpStartOnboardingTour = function () { startTour(false); };

    // ── D2: losse "wat is er nieuw"-highlights, onafhankelijk van de tour ────
    // Toont — als er onbekeken highlights zijn — er telkens één als los
    // popover-momentje (geen volledige tour), markeert 'm daarna als gezien.
    // Met een lege featureHighlights-lijst (huidige staat) gebeurt hier niets.
    function showFeatureHighlights() {
        var queue = (O.featureHighlights || []).slice();
        if (!queue.length) return;

        function markSeen(id) {
            if (!window.jQuery) return;
            window.jQuery.post(O.ajaxUrl, { action: 'mkcp_mark_feature_seen', nonce: O.nonce, id: id });
        }

        function showNext() {
            var item = queue.shift();
            if (!item) return;
            activateTab(item.tabActivator);
            var mini = driver({
                popoverClass: 'mkcp-onboarding-popover mkcp-onboarding-feature-highlight',
                overlayOpacity: 0.5,
                nextBtnText: 'Oké, snap het',
                showButtons: ['next'],
            });
            mini.setSteps([{
                element: item.selector,
                popover: {
                    title: item.title,
                    description: item.description,
                    onNextClick: function () {
                        markSeen(item.id);
                        mini.destroy();
                        showNext();
                    },
                },
            }]);
            mini.drive();
        }

        showNext();
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (O.autoStart) {
            // Even wachten tot settings.js zijn eigen tab-listeners heeft
            // gezet en de skeleton-shimmer-animatie van de eerste load
            // voorbij is, anders oogt de eerste highlight rommelig.
            setTimeout(function () { startTour(true); }, 500);
        } else {
            setTimeout(showFeatureHighlights, 800);
        }
        var replayBtn = document.getElementById('mkcp-replay-tour');
        if (replayBtn) {
            replayBtn.addEventListener('click', function (e) {
                e.preventDefault();
                clearProgress();
                startTour(false);
            });
        }
    });
})();
