/* MK Cart Popup — Bezorgdatum kiezer
 *
 * jQuery event delegation zodat alles blijft werken na WC AJAX-refresh.
 * Kaarten worden door JS gegenereerd vanuit mkcpDD.dates.
 */
(function ($) {
    'use strict';

    if (typeof mkcpDD === 'undefined') return;

    var DATES         = mkcpDD.dates         || [];
    var REQUIRED      = mkcpDD.required      === '1';
    var LABEL         = mkcpDD.label         || 'Gewenste bezorgdatum';
    /* var (niet const): kan per verzendmethode een eigen cutoff-regel hebben,
       ververst dus mee in refreshDatesFromFragment() net als CUTOFF_TS. */
    var CUTOFF        = mkcpDD.cutoffTime    || '12:00';
    /* Epoch-ms van vandaags cutoff-moment, door PHP berekend in de
       sitetijdzone (wp_timezone_string()). Gebruik dit i.p.v. zelf een
       Date met setHours() te bouwen: de browser-tijdzone van de bezoeker
       kan afwijken van de sitetijdzone, waardoor "verstreken" op de pagina
       een ander moment zou zijn dan de server hanteert — dan denkt de JS
       dat de cutoff voorbij is terwijl de server nog dezelfde (dus
       ongewijzigde) datumlijst teruggeeft, en lijkt de refresh niets te doen. */
    var CUTOFF_TS     = Number(mkcpDD.cutoffTs) || 0;
    var SHIPPING_DAYS = (mkcpDD.shippingDays || []).map(Number);
    var BLACKOUT_SET  = {};
    (mkcpDD.blackoutDates || []).forEach(function (d) { BLACKOUT_SET[d] = true; });

    /* ── Afhalen: zelfde script/markup als bezorgdatum (mutueel exclusief —
       er wordt per pageload/AJAX-refresh maar één van de twee gerenderd),
       met een extra tijdslot-stap als de locatie dat aanbiedt. ──────────── */
    var PICKUP        = !!mkcpDD.pickup;
    var SLOTS_ENABLED = !!mkcpDD.slotsEnabled;
    var SLOT_MINUTES  = Number(mkcpDD.slotMinutes) || 60;
    var SLOTS_BY_DOW  = mkcpDD.slotsByDow || {};
    /* Minimale tijd (minuten) tussen bestelmoment en het vroegste tijdslot dat
       vandaag nog gekozen mag worden — geeft de winkel tijd om de bestelling
       klaar te leggen. Alleen relevant voor sloten vandáág; latere dagen zijn
       hier per definitie los van. Ververst mee per AJAX-refresh, want kan per
       afhaallocatie verschillen. */
    var PREP_MINUTES  = Number(mkcpDD.prepMinutes) || 0;
    var selectedSlot  = '';
    /* Locatie-info (adres/methode-naam) van de actieve afhaallocatie — leeg bij
       bezorgen. Net als PICKUP e.a. hierboven: ververst mee per AJAX-refresh,
       want kan wisselen tussen twee afhaallocaties of bij wisselen naar bezorgen. */
    var ADDRESS       = mkcpDD.address     || '';
    var METHOD_LABEL  = mkcpDD.methodLabel || '';

    var MONTHS_NL = ['januari','februari','maart','april','mei','juni',
                     'juli','augustus','september','oktober','november','december'];
    var DAYS_FULL = ['Zondag','Maandag','Dinsdag','Woensdag','Donderdag','Vrijdag','Zaterdag'];

    var availableSet = {};
    DATES.forEach(function (d) { availableSet[d] = true; });

    /* ── Reden waarom een datum niet beschikbaar is (voor tooltip/aria-label) ──── */

    function unavailableReason(ymd, todayYMD) {
        if (ymd < todayYMD) return 'Deze datum is al voorbij';
        var p  = parseYMD(ymd);
        var js = new Date(p.y, p.m, p.d);
        if (SHIPPING_DAYS.length && SHIPPING_DAYS.indexOf(js.getDay()) === -1) return 'Geen verzenddag';
        if (BLACKOUT_SET[ymd]) return 'Uitzondering (bv. feestdag)';
        return 'Niet beschikbaar (bv. te dichtbij of vol)';
    }

    /* State — overleeft AJAX-refresh */
    var selectedDate = '';
    var calOpen      = false;
    var calYear      = 0;
    var calMonth     = 0;
    /* null = nog niet geëvalueerd (voorkomt een overbodige refresh vlak na
       page-load als de cutoff toevallig al verstreken was) */
    var cutoffJustPassed = null;

    /* ── Hulpfuncties ──────────────────────────────────────────────────────────── */

    function padTwo(n) { return n < 10 ? '0' + n : String(n); }
    function toYMD(y, m, d) { return y + '-' + padTwo(m + 1) + '-' + padTwo(d); }
    function parseYMD(ymd) { var p = ymd.split('-'); return { y: +p[0], m: +p[1] - 1, d: +p[2] }; }

    /* Label voor een tijdslot-knop als "start - eind" i.p.v. alleen de
       starttijd — anders lijkt een venster dat bv. tot 17:00 loopt (laatste
       slot "16:00", 60 min lang) net of de bezorging niet tot 17:00 gaat: de
       knop toonde voorheen alleen "16:00", niet dat 'ie tot 17:00 doorloopt. */
    function formatSlotRange(hhmm) {
        var parts    = hhmm.split(':');
        var startMin = (+parts[0]) * 60 + (+parts[1]);
        var endMin   = (startMin + SLOT_MINUTES) % 1440;
        return hhmm + ' - ' + sprintf_hhmm(endMin);
    }
    function sprintf_hhmm(totalMin) {
        return padTwo(Math.floor(totalMin / 60)) + ':' + padTwo(totalMin % 60);
    }

    function formatFull(ymd) {
        var p  = parseYMD(ymd);
        var js = new Date(p.y, p.m, p.d);
        return DAYS_FULL[js.getDay()] + ' ' + p.d + ' ' + MONTHS_NL[p.m] + ' ' + p.y;
    }

    /* Normaal identiek aan getElementById(), maar als de "Cart Checkout"-
       sectie-indeling (checkout-frontend.php, mkco_reorganize()) een vorige
       kopie van dit blok naar een andere plek in de pagina heeft verplaatst,
       bestaan er heel eventjes TWEE elementen met dezelfde id: de oude
       (al verplaatst) en de verse (net door WooCommerce's AJAX-fragment
       opnieuw gerenderd, nog op zijn oorspronkelijke plek binnen #payment).
       getElementById() geeft dan de EERSTE in document-volgorde terug — en
       dat is altijd de oude, verplaatste kopie (de sectie-indeling zet 'm
       vóór #payment's normale positie), terwijl juist de láátste kopie de
       verse, bijgewerkte is. Bij een duplicaat dus altijd de laatste pakken;
       zonder duplicaat (verreweg het normale geval) is dit identiek aan
       getElementById(). */
    function el(id) {
        var matches = document.querySelectorAll('#' + id);
        return matches.length ? matches[matches.length - 1] : null;
    }

    var CHECK_SVG = '<span class="mkcp-dd-card-check" aria-hidden="true">' +
        '<svg viewBox="0 0 16 16" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
        '<polyline points="2.5 8.5 6 12 13.5 4.5"/></svg></span>';

    /* ── Kaartbreedte dynamisch instellen (altijd 2.5 zichtbaar) ───────────────── */

    function applyCardWidths() {
        var vp = el('mkcp-dd-cards-viewport');
        if (!vp) return;
        var gap = 8;
        var w   = Math.floor((vp.clientWidth - gap * 1.5) / 2.5);
        document.querySelectorAll('.mkcp-dd-card').forEach(function (c) {
            c.style.width    = w + 'px';
            c.style.minWidth = w + 'px';
        });
    }

    /* ── Kaarten renderen (JS vult de lege container van PHP) ──────────────────── */

    /* Bouwt één kaart-element voor renderCards(). */
    function buildCardEl(ymd, selected) {
        var p  = parseYMD(ymd);
        var js = new Date(p.y, p.m, p.d);

        var card = document.createElement('button');
        card.type = 'button';
        card.className = 'mkcp-dd-card' + (selected ? ' is-selected' : '');
        card.setAttribute('data-date', ymd);
        card.setAttribute('aria-label', formatFull(ymd));
        card.setAttribute('aria-pressed', selected ? 'true' : 'false');
        card.innerHTML =
            CHECK_SVG +
            '<span class="mkcp-dd-card-dow">' + DAYS_FULL[js.getDay()] + '</span>' +
            '<span class="mkcp-dd-card-num">' + p.d + '</span>' +
            '<span class="mkcp-dd-card-mon">' + MONTHS_NL[p.m] + '</span>';
        return card;
    }

    function renderCards() {
        var container = el('mkcp-dd-cards');
        if (!container) return;
        container.classList.remove('is-loading');
        container.innerHTML = '';

        /* Standaard de eerste 4 datums als kaart. Valt de gekozen datum
           (bv. via de kalender) verderop in de lijst, dan schuiven we het
           kaarten-venster door tót en met die datum — niet zomaar een losse
           kaart achteraan plakken met een gat ertussen, want de tussenliggende
           datums (wél al als chip zichtbaar) horen dan ook als kaart te
           bestaan; anders lijkt het of ze wegvallen/vervangen worden. */
        var selIdx = selectedDate ? DATES.indexOf(selectedDate) : -1;
        var upto   = (selIdx > 3) ? selIdx + 1 : 4;

        DATES.slice(0, upto).forEach(function (ymd) {
            container.appendChild(buildCardEl(ymd, ymd === selectedDate));
        });

        applyCardWidths();
        updateNavState();

        /* Scroll-listener opnieuw binden (element is nieuw na AJAX-refresh) */
        var vp = el('mkcp-dd-cards-viewport');
        if (vp) vp.addEventListener('scroll', updateNavState, { passive: true });
    }

    /* ── Chips-rij herbouwen (nodig zodra DATES wijzigt, bv. na wisselen
       verzendmethode — voorheen bleef deze rij statisch na de eerste render) ── */

    var CAL_BTN_HTML =
        '<button type="button" class="mkcp-dd-chip mkcp-dd-chip--cal" id="mkcp-dd-cal-btn" ' +
        'aria-label="Kalender openen" aria-haspopup="dialog" aria-expanded="false">' +
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>' +
        '<line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></button>';

    var DAYS_SHORT = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];

    function renderChips() {
        var container = el('mkcp-dd-chips');
        if (!container) return;
        container.innerHTML = '';

        DATES.slice(0, 6).forEach(function (ymd) {
            var p   = parseYMD(ymd);
            var js  = new Date(p.y, p.m, p.d);
            var sel = ymd === selectedDate;

            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'mkcp-dd-chip' + (sel ? ' is-selected' : '');
            chip.setAttribute('data-date', ymd);
            chip.innerHTML =
                '<span class="mkcp-dd-chip-day">' + DAYS_SHORT[js.getDay()] + '</span>' +
                '<span class="mkcp-dd-chip-num">' + p.d + '</span>';
            container.appendChild(chip);
        });

        container.insertAdjacentHTML('beforeend', CAL_BTN_HTML);
        updateCalBtnState();

        updateChipsOverflow();
        container.addEventListener('scroll', updateChipsOverflow, { passive: true });
    }

    /* ── Scroll-hint (fade) op de chips-rij ─────────────────────────────────────── */
    /* Alleen relevant zodra de rij daadwerkelijk breder is dan zichtbaar — op
       een brede desktop passen alle chips + kalender-knop meestal gewoon, dan
       blijft de fade dus vanzelf uit. Puur CSS-gebaseerd via mask-image, dus
       werkt ongeacht de achtergrondkleur van het thema. */

    function updateChipsOverflow() {
        var row = el('mkcp-dd-chips');
        if (!row) return;

        var maxScroll = row.scrollWidth - row.clientWidth;
        var overflows = maxScroll > 2;
        var atStart   = row.scrollLeft <= 2;
        var atEnd     = row.scrollLeft >= maxScroll - 2;

        var mask = 'none';
        if (overflows) {
            if (atStart) {
                mask = 'linear-gradient(to right, #000 calc(100% - 24px), transparent 100%)';
            } else if (atEnd) {
                mask = 'linear-gradient(to right, transparent 0, #000 24px, #000 100%)';
            } else {
                mask = 'linear-gradient(to right, transparent 0, #000 24px, #000 calc(100% - 24px), transparent 100%)';
            }
        }
        row.style.maskImage       = mask;
        row.style.webkitMaskImage = mask;
    }

    /* ── Nav-knopstatus bijwerken ──────────────────────────────────────────────── */

    function updateNavState() {
        var vp   = el('mkcp-dd-cards-viewport');
        var prev = el('mkcp-dd-nav-prev');
        var next = el('mkcp-dd-nav-next');
        if (!vp) return;
        prev.disabled = vp.scrollLeft <= 4;
        next.disabled = vp.scrollLeft >= (vp.scrollWidth - vp.clientWidth - 4);
    }

    /* ── Scroll kaarten naar geselecteerde datum ───────────────────────────────── */

    function scrollToCard(ymd) {
        var vp = el('mkcp-dd-cards-viewport');
        if (!vp) return;
        /* Zoeken binnen vp (niet document-breed): tijdens het korte venster
           waarin de "Cart Checkout"-sectie-indeling een vorige kopie van dit
           blok nog aan het opruimen is, kan er even een stale kaart met
           hetzelfde data-date elders in de pagina staan — scoping voorkomt
           dat we per ongeluk die kaart pakken i.p.v. de zichtbare. */
        var card = vp.querySelector('.mkcp-dd-card[data-date="' + ymd + '"]');
        if (!card) return;
        // scrollIntoView() i.p.v. zelf een pixel-offset berekenen + scrollBy():
        // de viewport heeft scroll-snap-type:x mandatory, en een handmatig
        // berekende offset botst daar soms mee — vooral bij de láátste kaart
        // (net toegevoegd na een kalenderkeuze), waar de snap de rij dan naar
        // een net-niet-goede positie trekt (kaart wel links in beeld, maar
        // met lege ruimte erna, alsof er niets meer volgt). scrollIntoView()
        // gebruikt de browser's eigen snap-resolutie en blijft ook correct
        // als de rij net van breedte is veranderd.
        card.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
    }

    /* ── Datum selecteren ──────────────────────────────────────────────────────── */

    function selectDate(ymd) {
        if (!availableSet[ymd]) return;
        selectedDate = ymd;

        $('#mkcp-dd-error').prop('hidden', true).text('');

        /* Hidden input */
        var input = el('mkcp_delivery_date');
        if (input) input.value = ymd;

        /* Chips highlight */
        $('#mkcp-dd-chips .mkcp-dd-chip[data-date]').each(function () {
            $(this).toggleClass('is-selected', $(this).data('date') === ymd);
        });

        /* Kaarten highlight — valt de datum buiten wat er nu al aan kaarten
           staat (bv. via de kalender een datum verderop gekozen), dan bouwt
           renderCards() de rij opnieuw op tót en met die datum, zodat de
           tussenliggende datums (al wel als chip zichtbaar) ook als kaart
           verschijnen i.p.v. dat het lijkt of ze wegvallen. */
        var matchedCard = false;
        $('.mkcp-dd-card').each(function () {
            var sel = $(this).attr('data-date') === ymd;
            $(this).toggleClass('is-selected', sel);
            this.setAttribute('aria-pressed', sel ? 'true' : 'false');
            if (sel) matchedCard = true;
        });
        if (!matchedCard) renderCards();

        /* Kalender sluiten + dag-grid bijwerken als open */
        setCalendarOpen(false);
        renderCalDays();

        updateCalBtnState();
        renderSlots();
        updateConfirm();
        updateSummary();
    }

    /* ── Tijdsloten (alleen bij afhalen met sloten aan) ────────────────────────── */
    /* Sloten hangen alleen af van de weekdag (vaste openingstijden per dag) —
       geen aparte serverroundtrip nodig. Sloten die al voorbij zijn (vandaag)
       worden client-side weggefilterd; capaciteit per slot wordt bij het
       versturen server-side gevalideerd (zie includes/pickup.php), niet hier,
       om een AJAX-call per datumkeuze te vermijden. */

    /* Bouwt het tijdslot-blokje + het bijbehorende hidden input-veld op,
       identiek aan de markup die includes/pickup.php (mkcp_pickup_render_field)
       server-side rendert. Nodig omdat dat PHP-blokje alleen wordt meegerenderd
       als de pagina AL in afhaalmodus laadt — wisselt de klant pas via de
       Ophalen/Bezorgen-kaarten van bezorgen náár afhalen, dan bestaat dit
       element nog helemaal niet in de DOM (AJAX ververst alleen #mkcp-dd-data,
       niet de rest van #mkcp-dd-wrap). Zonder deze lazy-aanmaak blijft de
       tijdslot-keuze dan onzichtbaar totdat de klant de pagina herlaadt. */
    function ensureSlotsBox() {
        var wrap = el('mkcp-dd-wrap');
        if (!wrap) return { box: null, row: null };

        var box = document.createElement('div');
        box.className = 'mkcp-pu-slots';
        box.id = 'mkcp-dd-slots';
        box.hidden = true;

        var label = document.createElement('span');
        label.className = 'mkcp-pu-slots-label';
        label.textContent = 'Kies een tijdstip';

        var row = document.createElement('div');
        row.className = 'mkcp-pu-slots-row';
        row.id = 'mkcp-dd-slots-row';

        box.appendChild(label);
        box.appendChild(row);

        var confirm = el('mkcp-dd-confirm');
        if (confirm) confirm.insertAdjacentElement('afterend', box);
        else wrap.appendChild(box);

        var slotInput = document.createElement('input');
        slotInput.type = 'hidden';
        slotInput.name = 'mkcp_time_slot';
        slotInput.id = 'mkcp_time_slot';
        slotInput.value = '';
        box.insertAdjacentElement('afterend', slotInput);

        return { box: box, row: row };
    }

    function renderSlots() {
        var box = el('mkcp-dd-slots');
        var row = el('mkcp-dd-slots-row');

        if (!SLOTS_ENABLED) {
            if (box) {
                box.hidden = true;
                if (row) row.innerHTML = '';
            }
            var staleInput = el('mkcp_time_slot');
            if (staleInput) staleInput.value = '';
            selectedSlot = '';
            return;
        }

        if (!box || !row) {
            var created = ensureSlotsBox();
            box = created.box;
            row = created.row;
            if (!box || !row) return;
        }

        row.innerHTML = '';
        selectedSlot = '';
        var slotInput = el('mkcp_time_slot');
        if (slotInput) slotInput.value = '';

        if (!selectedDate) { box.hidden = true; return; }

        var p    = parseYMD(selectedDate);
        var js   = new Date(p.y, p.m, p.d);
        var dow  = js.getDay();
        var list = SLOTS_BY_DOW[dow] || SLOTS_BY_DOW[String(dow)] || [];

        var now      = new Date();
        var todayYMD = now.getFullYear() + '-' + padTwo(now.getMonth() + 1) + '-' + padTwo(now.getDate());
        var isToday  = selectedDate === todayYMD;
        var nowMin   = now.getHours() * 60 + now.getMinutes() + PREP_MINUTES;

        var available = list.filter(function (hhmm) {
            if (!isToday) return true;
            var parts = hhmm.split(':');
            return (+parts[0] * 60 + +parts[1]) >= nowMin;
        });

        box.hidden = false;

        if (!available.length) {
            row.innerHTML = '<p class="mkcp-pu-slots-empty">Geen tijdsloten meer beschikbaar op deze datum — kies een andere datum.</p>';
            return;
        }

        var select = document.createElement('select');
        select.className = 'mkcp-pu-slot-select';
        select.id = 'mkcp-dd-slot-select';
        select.setAttribute('aria-label', 'Kies een tijdstip');

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Kies een tijdstip…';
        placeholder.disabled = true;
        placeholder.selected = true;
        select.appendChild(placeholder);

        available.forEach(function (hhmm) {
            var opt = document.createElement('option');
            opt.value = hhmm;
            opt.textContent = formatSlotRange(hhmm);
            select.appendChild(opt);
        });

        row.appendChild(select);
    }

    function selectSlot(hhmm) {
        selectedSlot = hhmm;
        var input = el('mkcp_time_slot');
        if (input) input.value = hhmm;

        var select = el('mkcp-dd-slot-select');
        if (select) select.value = hhmm;

        updateConfirm();
        updateSummary();
    }

    /* ── Kalender-knop "actief" tonen als de selectie alleen via de kalender
       bereikbaar is (dus niet ook als chip zichtbaar is) ───────────────────────── */

    function updateCalBtnState() {
        var calBtn = el('mkcp-dd-cal-btn');
        if (!calBtn) return;
        var pickedViaCalendar = !!selectedDate && DATES.slice(0, 6).indexOf(selectedDate) === -1;
        calBtn.classList.toggle('is-selected', pickedViaCalendar);
    }

    /* ── Bevestiging voor datums buiten de 4 grote kaarten ──────────────────────── */
    /* Chip 5/6 en kalender-datums die niet bij de zichtbare kaarten horen, hadden
       geen duidelijke bevestiging van de keuze — dit vult dat gat direct onder
       de kaartenrij, zonder de kaart-bevestiging te dupliceren wanneer die er
       al wél is. */

    function updateConfirm() {
        // Uitgeschakeld: dit blok dupliceerde de bevestiging die
        // updateSummary() al toont (bv. in de mobiele besteldbalk) —
        // "Maandag 13 juli 2026, 16:00 - 16:30 geselecteerd" stond zo
        // twee keer op het scherm.
        var box = el('mkcp-dd-confirm');
        if (!box) return;
        box.hidden = true;
        box.innerHTML = '';
    }

    /* ── Titel boven de picker: "Bezorgdatum" ↔ "Afhaaldatum" ───────────────────── */
    /* Statisch server-gerenderd bij de eerste paginalaad; moet zelf mee-
       wisselen zodra de klant via de Ophalen/Bezorgen-keuzekaarten van modus
       verandert zonder dat de pagina herlaadt (AJAX-refresh ververst alleen
       de data-attributen, niet deze HTML-tekst). */
    function updateHeaderLabel() {
        var label = el('mkcp-dd-wrap');
        if (!label) return;
        var span = label.querySelector('.mkcp-dd-label');
        if (!span) return;

        var abbr = span.querySelector('abbr');
        span.textContent = LABEL + ' ';
        if (abbr) span.appendChild(abbr);
    }

    /* ── Afhaallocatie-infobox (adres) — alleen bij afhalen, onder de datumkaarten ── */
    /* Zelfde reden als updateHeaderLabel(): dit blok bestaat alleen in de HTML
       als de pagina initieel in afhaalmodus is gerenderd, dus bij het
       wisselen (in beide richtingen) moet JS 'm zelf aanmaken/verwijderen. */
    function updateLocationBox() {
        var track = el('mkcp-dd-track');
        if (!track) return;

        var box = el('mkcp-pu-location');

        /* Ook zonder adres (leeg gelaten in de admin) tonen we de locatienaam —
           anders ziet de klant helemaal geen aanduiding van de gekozen
           afhaallocatie. Alleen bij bezorgen (niet PICKUP) verdwijnt dit blok. */
        if (!PICKUP) {
            if (box) box.remove();
            return;
        }

        if (!box) {
            box = document.createElement('div');
            box.className = 'mkcp-pu-location';
            box.id = 'mkcp-pu-location';
            track.insertAdjacentElement('afterend', box);
        }

        var strong = document.createElement('strong');
        strong.textContent = METHOD_LABEL || 'Afhaallocatie';

        box.innerHTML = '';
        box.appendChild(strong);

        if (ADDRESS) {
            var p = document.createElement('p');
            String(ADDRESS).split(/\r\n|\r|\n/).forEach(function (line, i) {
                if (i > 0) p.appendChild(document.createElement('br'));
                p.appendChild(document.createTextNode(line));
            });
            box.appendChild(p);
        }
    }

    /* ── Microcopy onder de picker: cutoff-tijd vertaald naar mensentaal ───────── */

    function updateMicrocopy() {
        var box = el('mkcp-dd-microcopy');
        if (!box || !DATES.length) return;

        var now = Date.now();
        var cut = CUTOFF_TS;

        /* "Vandaag"/"morgen" leest prettiger dan de weekdag zelf (en klinkt
           een stuk directer als aansporing dan bv. "woensdag"). Alleen als de
           eerste beschikbare datum niet vandaag/morgen is, valt dit terug op
           de weekdag-naam — die dan ook los van een zinsbegin verschijnt,
           dus met kleine letter (DAYS_FULL is verder overal met hoofdletter,
           bv. in formatFull()). */
        var nowDate     = new Date();
        var todayYMD    = toYMD(nowDate.getFullYear(), nowDate.getMonth(), nowDate.getDate());
        var tomorrowDate = new Date(nowDate);
        tomorrowDate.setDate(tomorrowDate.getDate() + 1);
        var tomorrowYMD = toYMD(tomorrowDate.getFullYear(), tomorrowDate.getMonth(), tomorrowDate.getDate());

        var firstLabel;
        if (DATES[0] === todayYMD) {
            firstLabel = 'vandaag';
        } else if (DATES[0] === tomorrowYMD) {
            firstLabel = 'morgen';
        } else {
            var firstP  = parseYMD(DATES[0]);
            var firstJs = new Date(firstP.y, firstP.m, firstP.d);
            firstLabel = DAYS_FULL[firstJs.getDay()].toLowerCase();
        }

        if (now < cut) {
            var msLeft = cut - now;
            var hLeft  = Math.floor(msLeft / 3600000);
            var mLeft  = Math.floor((msLeft % 3600000) / 60000);
            var sLeft  = Math.floor((msLeft % 60000) / 1000);
            var left   = (hLeft > 0 ? hLeft + 'u ' : '') + mLeft + 'm ' + padTwo(sLeft) + 's';
            box.innerHTML = 'Besteld binnen <strong class="mkcp-dd-microcopy-time">' + left +
                '</strong> (vóór ' + CUTOFF + ') → ' + firstLabel + (PICKUP ? ' afhalen' : ' in huis');
        } else {
            box.textContent = 'Eerstvolgende optie: ' + formatFull(DATES[0]);
        }

        /* Cutoff zojuist verstreken terwijl de pagina open stond: DATES bevat
           nu een verouderde eerste datum ("morgen" die eigenlijk niet meer
           mag). Forceer één keer een WooCommerce checkout-refresh, die via
           de bestaande update_order_review-fragments-hook de server opnieuw
           laat rekenen met de actuele tijd — dezelfde route als bij het
           wisselen van verzendmethode. Zo blijft alle datum-logica op één
           plek (PHP) i.p.v. verdubbeld in JS. */
        var afterCutoff = now >= cut;
        if (afterCutoff && cutoffJustPassed === false && window.jQuery) {
            $(document.body).trigger('update_checkout');
        }
        cutoffJustPassed = afterCutoff;
    }

    /* ── Mini-samenvatting bij de betaalmethodes ────────────────────────────────── */

    function updateSummary() {
        var box = el('mkcp-dd-summary');
        if (!box) return;

        if (!selectedDate) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }

        box.hidden = false;
        box.innerHTML =
            '<span class="mkcp-dd-summary-icon" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<polyline points="2.5 8.5 6 12 13.5 4.5"/></svg></span>' +
            '<span>' + LABEL + ': <strong>' + formatFull(selectedDate) + (selectedSlot ? ', ' + formatSlotRange(selectedSlot) : '') + '</strong></span>' +
            '<button type="button" class="mkcp-dd-summary-edit" id="mkcp-dd-summary-edit">wijzigen</button>';
    }

    /* ── Kalender open/dicht ───────────────────────────────────────────────────── */

    function setCalendarOpen(open) {
        calOpen = open;
        var cal    = el('mkcp-dd-calendar');
        var calBtn = el('mkcp-dd-cal-btn');
        if (!cal) return;
        if (open) {
            cal.classList.add('is-open');
            cal.setAttribute('aria-hidden', 'false');
            if (calBtn) { calBtn.setAttribute('aria-expanded', 'true');  calBtn.classList.add('is-open'); }
        } else {
            cal.classList.remove('is-open');
            cal.setAttribute('aria-hidden', 'true');
            if (calBtn) { calBtn.setAttribute('aria-expanded', 'false'); calBtn.classList.remove('is-open'); }
        }
    }

    function openCalendar() {
        var ref = selectedDate || DATES[0];
        if (ref) { var p = parseYMD(ref); calYear = p.y; calMonth = p.m; }
        else     { var now = new Date(); calYear = now.getFullYear(); calMonth = now.getMonth(); }
        renderCalendar();
        positionCalendar();
        setCalendarOpen(true);
    }

    /* ── Kalender positioneren: even breed als de kaartenrij, start op dezelfde
       hoogte als mkcp-dd-cards-viewport, en klapt automatisch naar boven open
       als er onderin het scherm niet genoeg ruimte meer is (bv. checkout
       verder naar beneden gescrold) — zo blijft de kalender altijd volledig
       in beeld i.p.v. een vaste positie die soms half onder de vouw valt. ── */

    function positionCalendar() {
        var cal   = el('mkcp-dd-calendar');
        var track = el('mkcp-dd-track');
        var wrap  = el('mkcp-dd-wrap');
        if (!cal || !track || !wrap) return;

        cal.classList.remove('mkcp-dd-calendar--flip-up');
        cal.style.top    = track.offsetTop + 'px';
        cal.style.bottom = '';

        var calRect  = cal.getBoundingClientRect();
        var wrapRect = wrap.getBoundingClientRect();
        var margin   = 12;

        var overflowsBelow = calRect.bottom > (window.innerHeight - margin);
        var spaceAbove     = wrapRect.top;

        if (overflowsBelow && spaceAbove > calRect.height + margin) {
            cal.style.top    = '';
            cal.style.bottom = (wrap.offsetHeight - track.offsetTop) + 'px';
            cal.classList.add('mkcp-dd-calendar--flip-up');
        }
    }

    /* ── Kalender renderen ─────────────────────────────────────────────────────── */

    function renderCalendar() {
        var title = el('mkcp-dd-cal-month-title');
        if (title) title.textContent = MONTHS_NL[calMonth] + ' ' + calYear;
        renderCalDays();
        updateCalNavButtons();
    }

    function updateCalNavButtons() {
        var first = DATES.length ? parseYMD(DATES[0]) : null;
        var last  = DATES.length ? parseYMD(DATES[DATES.length - 1]) : null;
        var prev  = el('mkcp-dd-cal-prev');
        var next  = el('mkcp-dd-cal-next');
        if (prev && first) prev.disabled = !(calYear > first.y || (calYear === first.y && calMonth > first.m));
        if (next && last)  next.disabled = !(calYear < last.y  || (calYear === last.y  && calMonth < last.m));
    }

    function renderCalDays() {
        var daysEl = el('mkcp-dd-cal-days');
        if (!daysEl) return;
        daysEl.innerHTML = '';

        /* Kleine fade/slide-animatie bij elke (maand)wissel */
        daysEl.classList.add('is-animating');
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { daysEl.classList.remove('is-animating'); });
        });

        var now      = new Date();
        var todayYMD = now.getFullYear() + '-' + padTwo(now.getMonth() + 1) + '-' + padTwo(now.getDate());
        var firstJs  = new Date(calYear, calMonth, 1);
        var offset   = (firstJs.getDay() + 6) % 7;
        var days     = new Date(calYear, calMonth + 1, 0).getDate();

        for (var o = 0; o < offset; o++) {
            var empty = document.createElement('div');
            empty.className = 'mkcp-dd-cal-day mkcp-dd-cal-day--empty';
            daysEl.appendChild(empty);
        }

        for (var d = 1; d <= days; d++) {
            var ymd   = toYMD(calYear, calMonth, d);
            var avail = !!availableSet[ymd];
            var isSel = ymd === selectedDate;
            var isTod = ymd === todayYMD;

            var cell = document.createElement(avail ? 'button' : 'div');
            cell.className = 'mkcp-dd-cal-day';
            if (avail) cell.classList.add('mkcp-dd-cal-day--available');
            if (isSel) cell.classList.add('mkcp-dd-cal-day--selected');
            if (isTod) cell.classList.add('mkcp-dd-cal-day--today');
            cell.textContent = d;

            if (avail) {
                cell.type = 'button';
                cell.setAttribute('data-date', ymd);
                cell.setAttribute('aria-label', formatFull(ymd));
                if (isSel) cell.setAttribute('aria-pressed', 'true');
            } else {
                var reason = unavailableReason(ymd, todayYMD);
                cell.title = reason;
                cell.setAttribute('aria-label', formatFull(ymd) + ' — ' + reason);
            }

            daysEl.appendChild(cell);
        }
    }

    /* ── Event delegation (overleeft AJAX-refresh) ─────────────────────────────── */

    $(document)
        /* Chip → selecteer + scroll kaart in beeld */
        .on('click', '.mkcp-dd-chip[data-date]', function () {
            var ymd = $(this).data('date');
            selectDate(ymd);
            scrollToCard(ymd);
        })
        /* Kaart → selecteer + volledig in beeld schuiven (kan een gedeeltelijk
           zichtbare kaart zijn, bv. de "0.5"-kaart aan de rand) */
        .on('click', '.mkcp-dd-card', function () {
            var ymd = $(this).attr('data-date');
            selectDate(ymd);
            scrollToCard(ymd);
        })
        
        /* Tijdslot-dropdown */
        .on('change', '#mkcp-dd-slot-select', function () {
            selectSlot($(this).val());
        })

        /* Kalender-icoon */
        .on('click', '#mkcp-dd-cal-btn', function (e) {
            e.stopPropagation();
            calOpen ? setCalendarOpen(false) : openCalendar();
        })
        /* Dag in kalender → selecteer + scroll kaart */
        .on('click', '#mkcp-dd-cal-days .mkcp-dd-cal-day--available', function () {
            var ymd = $(this).attr('data-date');
            selectDate(ymd);
            scrollToCard(ymd);
        })
        /* Kalender: vorige maand (aantal weekrijen kan wisselen → herpositioneren) */
        .on('click', '#mkcp-dd-cal-prev', function () {
            if (!this.disabled) {
                calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; }
                renderCalendar();
                positionCalendar();
            }
        })
        /* Kalender: volgende maand */
        .on('click', '#mkcp-dd-cal-next', function () {
            if (!this.disabled) {
                calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; }
                renderCalendar();
                positionCalendar();
            }
        })
        /* Nav prev: scroll kaarten links */
        .on('click', '#mkcp-dd-nav-prev', function () {
            var vp = el('mkcp-dd-cards-viewport');
            if (vp) vp.scrollBy({ left: -240, behavior: 'smooth' });
        })
        /* Nav next: scroll kaarten rechts */
        .on('click', '#mkcp-dd-nav-next', function () {
            var vp = el('mkcp-dd-cards-viewport');
            if (vp) vp.scrollBy({ left: 240, behavior: 'smooth' });
        })
        /* "Wijzigen"-link in de mini-samenvatting bij de betaalmethodes → terug naar de picker */
        .on('click', '#mkcp-dd-summary-edit', function () {
            var wrap = el('mkcp-dd-wrap');
            if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
        })
        /* Klik buiten kalender sluit hem */
        .on('click', function (e) {
            if (calOpen && !$(e.target).closest('#mkcp-dd-wrap').length) {
                setCalendarOpen(false);
            }
        })
        /* Escape sluit kalender */
        .on('keydown', function (e) {
            if (calOpen && e.key === 'Escape') setCalendarOpen(false);
        })
        /* Pijltjesnavigatie tussen dagen in de kalender (WCAG-datepicker-patroon,
           vereenvoudigd): zoekt in de gekozen richting de eerstvolgende
           beschikbare dag binnen dezelfde maand. Home/End springen naar de
           eerste/laatste beschikbare dag. */
        .on('keydown', '.mkcp-dd-cal-day--available', function (e) {
            var STEP = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };

            if (e.key === 'Home' || e.key === 'End') {
                e.preventDefault();
                var scope = this.closest('.mkcp-dd-cal-days') || document;
                var all = scope.querySelectorAll('.mkcp-dd-cal-day--available');
                var target = e.key === 'Home' ? all[0] : all[all.length - 1];
                if (target) target.focus();
                return;
            }

            if (!(e.key in STEP)) return;
            e.preventDefault();

            var p         = parseYMD(this.getAttribute('data-date'));
            var cursor    = new Date(p.y, p.m, p.d);
            var step      = STEP[e.key];
            var daysGrid  = this.closest('.mkcp-dd-cal-days');

            for (var i = 0; i < 31; i++) {
                cursor.setDate(cursor.getDate() + step);
                if (cursor.getMonth() !== calMonth || cursor.getFullYear() !== calYear) break;

                var ymd = toYMD(cursor.getFullYear(), cursor.getMonth(), cursor.getDate());
                /* Zoeken binnen dezelfde daysGrid als het huidige focus-element
                   (niet document-breed) — zelfde reden als scrollToCard(). */
                var btn = (daysGrid || document).querySelector('.mkcp-dd-cal-day[data-date="' + ymd + '"]');
                if (btn) { btn.focus(); break; }
            }
        });

    /* ── Bijgewerkte datumlijst overnemen na wisselen verzendmethode ───────────── */
    /* WooCommerce's update_order_review AJAX-call (getriggerd door de klant die
       een andere verzendmethode kiest) levert via de fragments-filter een
       vernieuwd #mkcp-dd-data element aan (zie includes/delivery-date.php).
       Als de datumlijst is gewijzigd: state + geselecteerde datum bijwerken. */
    function refreshDatesFromFragment() {
        var box = el('mkcp-dd-data');
        if (!box || !box.dataset.dates) return;

        if (box.dataset.cutoffTs) CUTOFF_TS = Number(box.dataset.cutoffTs) || CUTOFF_TS;
        if (box.dataset.cutoffTime) CUTOFF = box.dataset.cutoffTime;
        if (box.dataset.slotsByDow) {
            try { SLOTS_BY_DOW = JSON.parse(box.dataset.slotsByDow) || SLOTS_BY_DOW; } catch (e) { /* houd vorige waarde aan */ }
        }
        if (typeof box.dataset.prepMinutes !== 'undefined') PREP_MINUTES = Number(box.dataset.prepMinutes) || 0;
        if (typeof box.dataset.slotMinutes !== 'undefined') SLOT_MINUTES = Number(box.dataset.slotMinutes) || SLOT_MINUTES;
        /* Modus kan per AJAX-refresh wisselen (klant kiest een andere
           verzendmethode: bezorgen ↔ afhalen, of tussen twee afhaallocaties)
           — zonder dit blijven PICKUP/SLOTS_ENABLED/REQUIRED/LABEL op de
           vorige keuze hangen, ook al rendert de HTML al wel de juiste modus. */
        if (typeof box.dataset.pickup       !== 'undefined') PICKUP        = box.dataset.pickup === '1';
        if (typeof box.dataset.slotsEnabled !== 'undefined') SLOTS_ENABLED = box.dataset.slotsEnabled === '1';
        if (typeof box.dataset.required     !== 'undefined') REQUIRED      = box.dataset.required === '1';
        if (typeof box.dataset.label        !== 'undefined' && box.dataset.label !== '') LABEL = box.dataset.label;
        if (typeof box.dataset.address      !== 'undefined') ADDRESS      = box.dataset.address;
        if (typeof box.dataset.methodLabel  !== 'undefined') METHOD_LABEL = box.dataset.methodLabel;

        /* Reden-data (verzenddagen/geblokkeerde datums) volgt de eigen regels
           van de nieuw gekozen verzendmethode/locatie — dit moet, net als de
           velden hierboven, ALTIJD meeversen (ook als de datumlijst zelf
           toevallig identiek is aan de vorige, bv. twee afhaallocaties met
           dezelfde openingsdagen). Vóór de "dates ongewijzigd"-early-return
           hieronder, anders blijft de kalender-tooltip (unavailableReason())
           in dat geval de verzenddag-/blackout-regels van de vórige methode
           tonen terwijl al het andere al wel is bijgewerkt. */
        try {
            if (box.dataset.shippingDays) SHIPPING_DAYS = JSON.parse(box.dataset.shippingDays).map(Number);
            if (box.dataset.blackoutDates) {
                BLACKOUT_SET = {};
                JSON.parse(box.dataset.blackoutDates).forEach(function (d) { BLACKOUT_SET[d] = true; });
            }
        } catch (e) { /* houd de vorige waarden aan bij onverwachte data */ }

        var fresh;
        try { fresh = JSON.parse(box.dataset.dates); } catch (e) { return; }
        if (!Array.isArray(fresh)) return;
        if (JSON.stringify(fresh) === JSON.stringify(DATES)) return;

        DATES = fresh;
        availableSet = {};
        DATES.forEach(function (d) { availableSet[d] = true; });

        if (selectedDate && !availableSet[selectedDate]) {
            selectedDate = '';
            var input = el('mkcp_delivery_date');
            if (input) input.value = '';
        }
    }

    /* ── Laadstatus tijdens checkout-AJAX (bv. wisselen verzendmethode) ─────────── */
    /* 'update_checkout' vuurt WooCommerce zodra een AJAX-refresh begint (vóór
       het request), 'updated_checkout' zodra 'ie klaar is — dat gebruiken we
       al hieronder om de kaarten te herbouwen (wat 'is-loading' ook opheft). */

    $(document.body).on('update_checkout', function () {
        var cards = el('mkcp-dd-cards');
        if (cards) cards.classList.add('is-loading');
    });

    /* ── Na WooCommerce AJAX-refresh: kaarten herbouwen + state herstellen ─────── */

    $(document).on('updated_checkout', function () {
        /* renderSlots() wist selectedSlot altijd zelf (nieuwe lijst, nieuwe
           knoppen) — bewaar de vorige keuze dus vooraf om 'm hierna, als
           'ie nog geldig is voor de (mogelijk ververste) datum, opnieuw te
           kunnen selecteren. Het hele blok wordt immers bij elke AJAX-
           refresh server-side opnieuw leeg gerenderd, net als de rest. */
        var priorSlot = selectedSlot;

        refreshDatesFromFragment();
        updateHeaderLabel();
        updateLocationBox();
        renderChips();
        renderCards();
        updateMicrocopy();

        /* refreshDatesFromFragment() kan selectedDate net hebben leeggemaakt
           (gekozen datum zit niet meer in de verse lijst) — zelfde vangnet
           als bij de eerste paginalaad ($(document).ready() hieronder):
           val terug op de eerstvolgende optie i.p.v. het veld stilzwijgend
           leeg te laten staan. selectDate() doet zelf al renderSlots()/
           updateConfirm()/updateSummary(), dus alleen bij een blijvend lege
           selectie (geen datums beschikbaar) die drie los aanroepen. */
        if (!selectedDate && DATES.length) {
            selectDate(DATES[0]);
        } else {
            renderSlots();
            updateConfirm();
            updateSummary();
        }

        var input = el('mkcp_delivery_date');
        if (input) input.value = selectedDate;

        if (SLOTS_ENABLED && priorSlot) {
            var p       = selectedDate ? parseYMD(selectedDate) : null;
            var dow     = p ? new Date(p.y, p.m, p.d).getDay() : -1;
            var stillOk = p && (SLOTS_BY_DOW[dow] || SLOTS_BY_DOW[String(dow)] || []).indexOf(priorSlot) !== -1;
            if (stillOk) selectSlot(priorSlot);
        }
    });

    /* ── Initieel: chips/kaarten opbouwen + microcopy starten ──────────────────── */

    $(document).ready(function () {
        renderChips();
        renderCards();
        updateMicrocopy();

        /* Snelste optie (eerste datum) alvast voorselecteren, zodat de klant
        niet zelf nog hoeft te klikken als de standaardkeuze prima is. */
        if (!selectedDate && DATES.length) {
            selectDate(DATES[0]);
        } else {
            renderSlots();
            updateConfirm();
            updateSummary();
        }

        /* Live cutoff-countdown: elke seconde verversen */
        setInterval(updateMicrocopy, 1000);
    });

    $(window).on('resize', function () {
        if (el('mkcp-dd-cards')) applyCardWidths();
        if (calOpen) positionCalendar();
    });

    /* ── Client-side formuliervalidatie ────────────────────────────────────────── */

    // WooCommerce's eigen checkout.js bindt zijn 'submit'-handler RECHTSTREEKS
    // op form.checkout (niet gedelegeerd) en die handler 'return'et altijd
    // false — dat stopt de submit-event vóórdat die ooit bij een op
    // document/document.body gedelegeerde 'submit'-listener aankomt. Een
    // eerdere versie van deze validatie hing op $(document).on('submit', ...)
    // en werd daardoor bij een echte klik op "Bestelling plaatsen" nooit
    // uitgevoerd — de fout kwam dan alleen nog server-side terug als generieke
    // melding bovenaan de pagina, zonder feedback bij het veld zelf.
    // 'checkout_place_order' is WooCommerce's eigen, officiële hook voor
    // precies dit doel (hetzelfde patroon dat betaalproviders gebruiken om een
    // submit te blokkeren): geeft de handler 'false' terug, dan annuleert
    // checkout.js de AJAX-submit zelf, vóór er ook maar een request verstuurd is.
    // Rechtstreeks op form.checkout gebonden, NIET gedelegeerd via document/
    // document.body: WooCommerce's eigen submit-handler roept
    // $form.triggerHandler('checkout_place_order', ...) aan op datzelfde
    // form-element. Een live test wees uit dat dat 'checkout_place_order'-
    // triggerHandler-event, wanneer 'ie van bínnenuit WC's eigen, al lopende
    // submit-afhandeling wordt afgevuurd, een op document.body gedelegeerde
    // listener niet betrouwbaar bereikt (wél bij een geïsoleerde, handmatige
    // aanroep — een nesting/reentrancy-verschil in hoe jQuery dat verwerkt).
    // Rechtstreeks aan hetzelfde form-element hangen sluit die ambiguïteit uit.
    $('form.checkout').on('checkout_place_order', function () {
        var missingDate = REQUIRED && !selectedDate;
        var missingSlot = SLOTS_ENABLED && selectedDate && !selectedSlot;
        if (!missingDate && !missingSlot) return true;

        // Statisch neergezet in de PHP-template (delivery-date.php/pickup.php),
        // #mkcp-dd-chips en #mkcp-dd-slots verwijzen er al naar via
        // aria-describedby — hier alleen tekst zetten en zichtbaar maken.
        $('#mkcp-dd-error')
            .text(missingSlot ? (PICKUP ? 'Kies een tijdstip om af te halen.' : 'Kies een tijdstip om te laten bezorgen.') : '"' + LABEL + '" is een verplicht veld.')
            .prop('hidden', false);

        var wrap = el('mkcp-dd-wrap');
        if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });

        return false;
    });

}(jQuery));
