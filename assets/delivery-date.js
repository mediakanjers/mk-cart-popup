/* MK Cart Popup — Bezorgdatum / afhaal-tijdvak kiezer
 *
 * Fase 2: er kunnen nu 0, 1 of 2 instanties tegelijk op de checkout staan —
 * één voor het pakket dat momenteel "Laten bezorgen" gekozen heeft, één voor
 * het pakket dat momenteel "Zelf afhalen" gekozen heeft (nooit twee van
 * dezelfde rol, zie includes/shipping-choice.php voor de rol-gebaseerde
 * scope-beslissing). Elke instantie is voor zijn hele levensduur aan precies
 * één rol gebonden (eigen dom-id-prefix, eigen hidden-inputnamen) en leest
 * zijn volledige config rechtstreeks uit zijn eigen data-eilandje
 * (#mkcp-dd-data resp. #mkcp-pu-data, zie mkcp_dd_data_div_html() in
 * includes/delivery-date.php) — geen apart wp_localize_script-object meer
 * nodig. Bestaat dat data-eilandje niet, dan is die rol nu simpelweg niet
 * relevant voor de winkelwagen en bestaat de instantie niet.
 *
 * De widget-HTML wordt bij elke AJAX-refresh (adres/methode-wijziging) al
 * volledig vers meegerenderd via de bestaande fragment-mechanismen van
 * shipping-choice.php (dezelfde cyclus die ook de verzendkeuze-kaarten
 * ververst) — deze JS hoeft dus niet zelf een los "haal verse data op"-
 * mechanisme te onderhouden; refreshAll() leest gewoon opnieuw uit de
 * (inmiddels verse) data-eilandjes na elk 'updated_checkout'-event.
 */
(function ($) {
    'use strict';

    var MONTHS_NL = ['januari', 'februari', 'maart', 'april', 'mei', 'juni',
        'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    var DAYS_FULL = ['Zondag', 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag'];
    var DAYS_SHORT = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];

    var CHECK_SVG = '<span class="mkcp-dd-card-check" aria-hidden="true">' +
        '<svg viewBox="0 0 16 16" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
        '<polyline points="2.5 8.5 6 12 13.5 4.5"/></svg></span>';

    /* Zelfde iconen als de "Laten bezorgen"/"Zelf afhalen"-kaarten
       (templates/cart-shipping-choice.php, $icons) — hergebruikt in de
       samenvattingsregel zodat in één oogopslag duidelijk is welke regel bij
       welke rol hoort, zonder de tekst te hoeven lezen. */
    var DELIVERY_ICON_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<rect x="1" y="6" width="14" height="11"/><path d="M15 9h4l3 3v5h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17.5" cy="19" r="2"/></svg>';
    var PICKUP_ICON_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M3 9l1-5h16l1 5"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M9 21v-8h6v8"/></svg>';

    /* Tijdens de checkout-AJAX-refresh bestaat er kort een venster waarin
       zowel een oude (nog niet opgeruimde) als een verse kopie van de
       verzendkeuze-kaarten — en dus ook van de data-eilandjes/containers
       daarbinnen — naast elkaar in de DOM staan (zie mkco_reorganize() in
       includes/checkout-frontend.php, die de oude pas ~80-120ms later
       opruimt). document.getElementById() kan in dat venster de verkeerde
       (oude, zo weggegooide) kopie teruggeven. querySelectorAll + de
       LAATSTE match pakt betrouwbaar de vers-gerenderde kopie — zelfde
       aanpak als el() binnen createInstance() hieronder, hier los
       beschikbaar voor de top-level lookups (progress/eindsamenvatting). */
    function idLast(id) {
        var matches = document.querySelectorAll('#' + id);
        return matches.length ? matches[matches.length - 1] : null;
    }

    function padTwo(n) { return n < 10 ? '0' + n : String(n); }
    function toYMD(y, m, d) { return y + '-' + padTwo(m + 1) + '-' + padTwo(d); }
    function parseYMD(ymd) { var p = ymd.split('-'); return { y: +p[0], m: +p[1] - 1, d: +p[2] }; }
    function sprintf_hhmm(totalMin) {
        return padTwo(Math.floor(totalMin / 60)) + ':' + padTwo(totalMin % 60);
    }
    function formatFull(ymd) {
        var p = parseYMD(ymd);
        var js = new Date(p.y, p.m, p.d);
        return DAYS_FULL[js.getDay()] + ' ' + p.d + ' ' + MONTHS_NL[p.m] + ' ' + p.y;
    }

    /**
     * Bouwt één instantie (bezorgen ÓF afhalen). Alle interactieve state
     * (geselecteerde datum/slot, kalenderstatus, cutoff-teller) leeft in de
     * closure van deze functie — twee instanties delen dus niets met elkaar,
     * ook niet als ze gelijktijdig op de pagina staan.
     *
     * @param {string}  prefix      'mkcp-dd' (bezorgen) of 'mkcp-pu' (afhalen).
     * @param {string}  dataId      id van het data-eilandje voor deze rol.
     * @param {string}  dateFieldId id/name van het hidden datum-veld (vast per rol).
     * @param {string}  slotFieldId id/name van het hidden tijdslot-veld (vast per rol).
     * @param {boolean} isPickup    vast voor de hele levensduur van de instantie.
     */
    function createInstance(prefix, dataId, dateFieldId, slotFieldId, isPickup) {
        var DATES = [];
        var REQUIRED = false;
        var LABEL = isPickup ? 'Afhaaldatum' : 'Gewenste bezorgdatum';
        var CUTOFF = '12:00';
        var CUTOFF_TS = 0;
        var SHIPPING_DAYS = [];
        var BLACKOUT_SET = {};
        var SLOTS_ENABLED = false;
        var SLOT_MINUTES = 60;
        var SLOTS_BY_DOW = {};
        var PREP_MINUTES = 0;
        var ADDRESS = '';
        var METHOD_LABEL = '';

        var selectedDate = '';
        var selectedSlot = '';
        var collapsed = false;
        var calOpen = false;
        var calYear = 0;
        var calMonth = 0;
        var cutoffJustPassed = null;
        var availableSet = {};

        function id(suffix) { return prefix + '-' + suffix; }

        /* Zoekt op id binnen deze instantie se eigen, prefix-gebonden
           namespace. querySelectorAll + laatste match (i.p.v. gewoon
           getElementById): overleeft het korte venster waarin de "Cart
           Checkout"-sectie-indeling (checkout-frontend.php, mkco_reorganize())
           een vorige kopie nog aan het opruimen is. */
        function el(suffix) {
            var matches = document.querySelectorAll('#' + id(suffix));
            return matches.length ? matches[matches.length - 1] : null;
        }

        function dateInputEl() { return document.getElementById(dateFieldId); }
        function slotInputEl() { return document.getElementById(slotFieldId); }

        function unavailableReason(ymd, todayYMD) {
            if (ymd < todayYMD) return 'Deze datum is al voorbij';
            var p = parseYMD(ymd);
            var js = new Date(p.y, p.m, p.d);
            if (SHIPPING_DAYS.length && SHIPPING_DAYS.indexOf(js.getDay()) === -1) return 'Geen verzenddag';
            if (BLACKOUT_SET[ymd]) return 'Uitzondering (bv. feestdag)';
            return 'Niet beschikbaar (bv. te dichtbij of vol)';
        }

        /* "start - eind" i.p.v. alleen de starttijd — anders lijkt een venster
           dat bv. tot 17:00 loopt (laatste slot "16:00", 60 min lang) net of
           het niet tot 17:00 doorloopt. */
        function formatSlotRange(hhmm) {
            var parts = hhmm.split(':');
            var startMin = (+parts[0]) * 60 + (+parts[1]);
            var endMin = (startMin + SLOT_MINUTES) % 1440;
            return hhmm + ' - ' + sprintf_hhmm(endMin);
        }

        function applyCardWidths() {
            var vp = el('cards-viewport');
            if (!vp) return;
            var gap = 8;
            var w = Math.floor((vp.clientWidth - gap * 1.5) / 2.5);
            vp.querySelectorAll('.mkcp-dd-card').forEach(function (c) {
                c.style.width = w + 'px';
                c.style.minWidth = w + 'px';
            });
        }

        function buildCardEl(ymd, selected) {
            var p = parseYMD(ymd);
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
            var container = el('cards');
            if (!container) return;
            container.classList.remove('is-loading');
            container.innerHTML = '';

            /* Standaard de eerste 4 datums als kaart; valt de gekozen datum
               (bv. via de kalender) verderop, dan schuift het kaarten-venster
               door tót en met die datum. */
            var selIdx = selectedDate ? DATES.indexOf(selectedDate) : -1;
            var upto = (selIdx > 3) ? selIdx + 1 : 4;

            DATES.slice(0, upto).forEach(function (ymd) {
                container.appendChild(buildCardEl(ymd, ymd === selectedDate));
            });

            applyCardWidths();
            updateNavState();

            var vp = el('cards-viewport');
            if (vp) vp.addEventListener('scroll', updateNavState, { passive: true });
        }

        function calBtnHtml() {
            return '<button type="button" class="mkcp-dd-chip mkcp-dd-chip--cal" id="' + id('cal-btn') + '" ' +
                'aria-label="Kalender openen" aria-haspopup="dialog" aria-expanded="false">' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
                'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>' +
                '<line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></button>';
        }

        function renderChips() {
            var container = el('chips');
            if (!container) return;
            container.innerHTML = '';

            DATES.slice(0, 6).forEach(function (ymd) {
                var p = parseYMD(ymd);
                var js = new Date(p.y, p.m, p.d);
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

            container.insertAdjacentHTML('beforeend', calBtnHtml());
            updateCalBtnState();

            updateChipsOverflow();
            container.addEventListener('scroll', updateChipsOverflow, { passive: true });
        }

        function updateChipsOverflow() {
            var row = el('chips');
            if (!row) return;

            var maxScroll = row.scrollWidth - row.clientWidth;
            var overflows = maxScroll > 2;
            var atStart = row.scrollLeft <= 2;
            var atEnd = row.scrollLeft >= maxScroll - 2;

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
            row.style.maskImage = mask;
            row.style.webkitMaskImage = mask;
        }

        function updateNavState() {
            var vp = el('cards-viewport');
            var prev = el('nav-prev');
            var next = el('nav-next');
            if (!vp) return;
            if (prev) prev.disabled = vp.scrollLeft <= 4;
            if (next) next.disabled = vp.scrollLeft >= (vp.scrollWidth - vp.clientWidth - 4);
        }

        function scrollToCard(ymd) {
            var vp = el('cards-viewport');
            if (!vp) return;
            var card = vp.querySelector('.mkcp-dd-card[data-date="' + ymd + '"]');
            if (!card) return;
            card.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
        }

        function selectDate(ymd) {
            if (!availableSet[ymd]) return;
            selectedDate = ymd;

            var errBox = el('error');
            if (errBox) { errBox.hidden = true; errBox.textContent = ''; }

            var input = dateInputEl();
            if (input) input.value = ymd;

            var chipsRow = el('chips');
            if (chipsRow) {
                chipsRow.querySelectorAll('.mkcp-dd-chip[data-date]').forEach(function (chip) {
                    chip.classList.toggle('is-selected', chip.getAttribute('data-date') === ymd);
                });
            }

            var matchedCard = false;
            var cardsContainer = el('cards');
            if (cardsContainer) {
                cardsContainer.querySelectorAll('.mkcp-dd-card').forEach(function (card) {
                    var sel = card.getAttribute('data-date') === ymd;
                    card.classList.toggle('is-selected', sel);
                    card.setAttribute('aria-pressed', sel ? 'true' : 'false');
                    if (sel) matchedCard = true;
                });
            }
            if (!matchedCard) renderCards();

            setCalendarOpen(false);
            renderCalDays();

            updateCalBtnState();
            renderSlots();
            updateConfirm();
            updateSummary();
        }

        /* Bouwt het tijdslot-blokje + hidden input als het nog niet bestaat —
           nodig zodra de klant pas via de kaarten van bezorgen náár afhalen
           wisselt binnen dezelfde instantie-levensduur (zeldzaam sinds Fase 2
           elke rol een eigen, blijvend gerenderde widget geeft, maar defensief
           gehouden voor het geval slots_enabled zelf per refresh verandert). */
        function ensureSlotsBox() {
            var wrap = el('wrap');
            if (!wrap) return { box: null, row: null };

            var box = document.createElement('div');
            box.className = 'mkcp-pu-slots';
            box.id = id('slots');
            box.hidden = true;

            var label = document.createElement('span');
            label.className = 'mkcp-pu-slots-label';
            label.textContent = 'Kies een tijdstip';

            var row = document.createElement('div');
            row.className = 'mkcp-pu-slots-row';
            row.id = id('slots-row');

            box.appendChild(label);
            box.appendChild(row);

            var confirm = el('confirm');
            if (confirm) confirm.insertAdjacentElement('afterend', box);
            else wrap.appendChild(box);

            var slotInput = document.createElement('input');
            slotInput.type = 'hidden';
            slotInput.name = slotFieldId;
            slotInput.id = slotFieldId;
            slotInput.value = '';
            box.insertAdjacentElement('afterend', slotInput);

            return { box: box, row: row };
        }

        function renderSlots() {
            var box = el('slots');
            var row = el('slots-row');

            if (!SLOTS_ENABLED) {
                if (box) {
                    box.hidden = true;
                    if (row) row.innerHTML = '';
                }
                var staleInput = slotInputEl();
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
            var slotInput = slotInputEl();
            if (slotInput) slotInput.value = '';

            if (!selectedDate) { box.hidden = true; return; }

            var p = parseYMD(selectedDate);
            var js = new Date(p.y, p.m, p.d);
            var dow = js.getDay();
            var list = SLOTS_BY_DOW[dow] || SLOTS_BY_DOW[String(dow)] || [];

            var now = new Date();
            var todayYMD = now.getFullYear() + '-' + padTwo(now.getMonth() + 1) + '-' + padTwo(now.getDate());
            var isToday = selectedDate === todayYMD;
            var nowMin = now.getHours() * 60 + now.getMinutes() + PREP_MINUTES;

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
            select.id = id('slot-select');
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
            var input = slotInputEl();
            if (input) input.value = hhmm;

            var select = el('slot-select');
            if (select) select.value = hhmm;

            updateConfirm();
            updateSummary();
        }

        function updateCalBtnState() {
            var calBtn = el('cal-btn');
            if (!calBtn) return;
            var pickedViaCalendar = !!selectedDate && DATES.slice(0, 6).indexOf(selectedDate) === -1;
            calBtn.classList.toggle('is-selected', pickedViaCalendar);
        }

        function updateConfirm() {
            var box = el('confirm');
            if (!box) return;
            box.hidden = true;
            box.innerHTML = '';
        }

        function updateHeaderLabel() {
            var wrap = el('wrap');
            if (!wrap) return;
            var span = wrap.querySelector('.mkcp-dd-label');
            if (!span) return;

            var abbr = span.querySelector('abbr');
            span.textContent = LABEL + ' ';
            if (abbr) span.appendChild(abbr);
        }

        function updateLocationBox() {
            var track = el('track');
            if (!track) return;

            var box = document.getElementById(prefix + '-location');

            if (!isPickup) {
                if (box) box.remove();
                return;
            }

            if (!box) {
                box = document.createElement('div');
                box.className = 'mkcp-pu-location';
                box.id = prefix + '-location';
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

        function updateMicrocopy() {
            var box = el('microcopy');
            if (!box || !DATES.length) return;

            var now = Date.now();
            var cut = CUTOFF_TS;

            var nowDate = new Date();
            var todayYMD = toYMD(nowDate.getFullYear(), nowDate.getMonth(), nowDate.getDate());
            var tomorrowDate = new Date(nowDate);
            tomorrowDate.setDate(tomorrowDate.getDate() + 1);
            var tomorrowYMD = toYMD(tomorrowDate.getFullYear(), tomorrowDate.getMonth(), tomorrowDate.getDate());

            var firstLabel;
            if (DATES[0] === todayYMD) {
                firstLabel = 'vandaag';
            } else if (DATES[0] === tomorrowYMD) {
                firstLabel = 'morgen';
            } else {
                var firstP = parseYMD(DATES[0]);
                var firstJs = new Date(firstP.y, firstP.m, firstP.d);
                firstLabel = DAYS_FULL[firstJs.getDay()].toLowerCase();
            }

            if (now < cut) {
                var msLeft = cut - now;
                var hLeft = Math.floor(msLeft / 3600000);
                var mLeft = Math.floor((msLeft % 3600000) / 60000);
                var sLeft = Math.floor((msLeft % 60000) / 1000);
                var left = (hLeft > 0 ? hLeft + 'u ' : '') + mLeft + 'm ' + padTwo(sLeft) + 's';
                box.innerHTML = 'Besteld binnen <strong class="mkcp-dd-microcopy-time">' + left +
                    '</strong> (vóór ' + CUTOFF + ') → ' + firstLabel + (isPickup ? ' afhalen' : ' in huis');
            } else {
                box.textContent = 'Eerstvolgende optie: ' + formatFull(DATES[0]);
            }

            /* Cutoff zojuist verstreken terwijl de pagina open stond: forceer
               één keer een WooCommerce checkout-refresh, die via de bestaande
               fragment-cyclus de server opnieuw laat rekenen met de actuele
               tijd. */
            var afterCutoff = now >= cut;
            if (afterCutoff && cutoffJustPassed === false && window.jQuery) {
                $(document.body).trigger('update_checkout');
            }
            cutoffJustPassed = afterCutoff;
        }

        function updateSummary() {
            var box = el('summary');
            if (!box) return;

            if (!selectedDate) {
                box.hidden = true;
                box.innerHTML = '';
                return;
            }

            box.hidden = false;
            box.innerHTML =
                '<span class="mkcp-dd-summary-icon" aria-hidden="true">' + (isPickup ? PICKUP_ICON_SVG : DELIVERY_ICON_SVG) + '</span>' +
                '<span>' + LABEL + ': <strong>' + formatFull(selectedDate) + (selectedSlot ? ', ' + formatSlotRange(selectedSlot) : '') + '</strong></span>' +
                '<button type="button" class="mkcp-dd-summary-edit" id="' + id('summary-edit') + '">wijzigen</button>';
        }

        /* Klapt .mkcp-dd-body dicht/open — alleen de header + .mkcp-dd-summary
           blijven dan zichtbaar. */
        function applyCollapsedState() {
            // Geen [hidden] meer op .mkcp-dd-body zelf — dat blokkeert de
            // grid-rows-transitie in delivery-date.scss volledig (een
            // element met display:none kan niet animeren). De is-collapsed
            // klasse op de wrap stuurt de CSS-transitie aan; aria-hidden
            // houdt de ingeklapte inhoud wel buiten de accessibility-tree.
            var body = el('body');
            var wrap = el('wrap');
            if (body) body.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
            if (wrap) wrap.classList.toggle('is-collapsed', collapsed);
        }

        /* Synchroniseert de in-/uitklapstaat met de huidige volledigheid —
           in beide richtingen: klapt in zodra alles is ingevuld (ook als dat
           kwam van een automatische default-selectie, bv. bezorgdatum zonder
           tijdvak-vereiste is na de eerste render al "compleet"), en klapt
           weer open als een eerder complete keuze door een refresh ongeldig
           is geworden (bv. een adreswijziging haalt de gekozen datum weg). */
        function collapseIfComplete() {
            collapsed = !!selectedDate && (!SLOTS_ENABLED || !!selectedSlot);
            applyCollapsedState();
        }

        function expand() {
            collapsed = false;
            applyCollapsedState();
        }

        function setCalendarOpen(open) {
            calOpen = open;
            var cal = el('calendar');
            var calBtn = el('cal-btn');
            if (!cal) return;
            if (open) {
                cal.classList.add('is-open');
                cal.setAttribute('aria-hidden', 'false');
                if (calBtn) { calBtn.setAttribute('aria-expanded', 'true'); calBtn.classList.add('is-open'); }
            } else {
                cal.classList.remove('is-open');
                cal.setAttribute('aria-hidden', 'true');
                if (calBtn) { calBtn.setAttribute('aria-expanded', 'false'); calBtn.classList.remove('is-open'); }
            }
        }

        function openCalendar() {
            var ref = selectedDate || DATES[0];
            if (ref) { var p = parseYMD(ref); calYear = p.y; calMonth = p.m; }
            else { var now = new Date(); calYear = now.getFullYear(); calMonth = now.getMonth(); }
            renderCalendar();
            positionCalendar();
            setCalendarOpen(true);
        }

        function positionCalendar() {
            var cal = el('calendar');
            var track = el('track');
            var wrap = el('wrap');
            if (!cal || !track || !wrap) return;

            cal.classList.remove('mkcp-dd-calendar--flip-up');
            cal.style.top = track.offsetTop + 'px';
            cal.style.bottom = '';

            var calRect = cal.getBoundingClientRect();
            var wrapRect = wrap.getBoundingClientRect();
            var margin = 12;

            var overflowsBelow = calRect.bottom > (window.innerHeight - margin);
            var spaceAbove = wrapRect.top;

            if (overflowsBelow && spaceAbove > calRect.height + margin) {
                cal.style.top = '';
                cal.style.bottom = (wrap.offsetHeight - track.offsetTop) + 'px';
                cal.classList.add('mkcp-dd-calendar--flip-up');
            }
        }

        function renderCalendar() {
            var title = el('cal-month-title');
            if (title) title.textContent = MONTHS_NL[calMonth] + ' ' + calYear;
            renderCalDays();
            updateCalNavButtons();
        }

        function updateCalNavButtons() {
            var first = DATES.length ? parseYMD(DATES[0]) : null;
            var last = DATES.length ? parseYMD(DATES[DATES.length - 1]) : null;
            var prev = el('cal-prev');
            var next = el('cal-next');
            if (prev && first) prev.disabled = !(calYear > first.y || (calYear === first.y && calMonth > first.m));
            if (next && last) next.disabled = !(calYear < last.y || (calYear === last.y && calMonth < last.m));
        }

        function renderCalDays() {
            var daysEl = el('cal-days');
            if (!daysEl) return;
            daysEl.innerHTML = '';

            daysEl.classList.add('is-animating');
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { daysEl.classList.remove('is-animating'); });
            });

            var now = new Date();
            var todayYMD = now.getFullYear() + '-' + padTwo(now.getMonth() + 1) + '-' + padTwo(now.getDate());
            var firstJs = new Date(calYear, calMonth, 1);
            var offset = (firstJs.getDay() + 6) % 7;
            var days = new Date(calYear, calMonth + 1, 0).getDate();

            for (var o = 0; o < offset; o++) {
                var empty = document.createElement('div');
                empty.className = 'mkcp-dd-cal-day mkcp-dd-cal-day--empty';
                daysEl.appendChild(empty);
            }

            for (var d = 1; d <= days; d++) {
                var ymd = toYMD(calYear, calMonth, d);
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

        /* Leest de volledige config rechtstreeks uit het data-eilandje —
           zowel bij de eerste render als na elke AJAX-refresh (dezelfde bron,
           zie de docblock bovenaan dit bestand). Retourneert false als het
           eilandje (nog) niet bestaat: dan is deze rol niet relevant. */
        function readFromIsland() {
            var box = idLast(dataId);
            if (!box || !box.dataset.dates) return false;

            if (box.dataset.cutoffTs) CUTOFF_TS = Number(box.dataset.cutoffTs) || CUTOFF_TS;
            if (box.dataset.cutoffTime) CUTOFF = box.dataset.cutoffTime;
            if (box.dataset.slotsByDow) {
                try { SLOTS_BY_DOW = JSON.parse(box.dataset.slotsByDow) || SLOTS_BY_DOW; } catch (e) { /* houd vorige waarde aan */ }
            }
            if (typeof box.dataset.prepMinutes !== 'undefined') PREP_MINUTES = Number(box.dataset.prepMinutes) || 0;
            if (typeof box.dataset.slotMinutes !== 'undefined') SLOT_MINUTES = Number(box.dataset.slotMinutes) || SLOT_MINUTES;
            if (typeof box.dataset.slotsEnabled !== 'undefined') SLOTS_ENABLED = box.dataset.slotsEnabled === '1';
            if (typeof box.dataset.required !== 'undefined') REQUIRED = box.dataset.required === '1';
            if (typeof box.dataset.label !== 'undefined' && box.dataset.label !== '') LABEL = box.dataset.label;
            if (typeof box.dataset.address !== 'undefined') ADDRESS = box.dataset.address;
            if (typeof box.dataset.methodLabel !== 'undefined') METHOD_LABEL = box.dataset.methodLabel;

            try {
                if (box.dataset.shippingDays) SHIPPING_DAYS = JSON.parse(box.dataset.shippingDays).map(Number);
                if (box.dataset.blackoutDates) {
                    BLACKOUT_SET = {};
                    JSON.parse(box.dataset.blackoutDates).forEach(function (d) { BLACKOUT_SET[d] = true; });
                }
            } catch (e) { /* houd de vorige waarden aan bij onverwachte data */ }

            var fresh;
            try { fresh = JSON.parse(box.dataset.dates); } catch (e) { return true; }
            if (!Array.isArray(fresh)) return true;

            var changed = JSON.stringify(fresh) !== JSON.stringify(DATES);
            DATES = fresh;
            availableSet = {};
            DATES.forEach(function (d) { availableSet[d] = true; });

            if (changed && selectedDate && !availableSet[selectedDate]) {
                selectedDate = '';
                var input = dateInputEl();
                if (input) input.value = '';
            }

            return true;
        }

        /* Herbouwt de widget na de eerste render of een AJAX-refresh. Bewaart
           een eerder gekozen tijdslot (priorSlot) zodat die, als 'ie nog geldig
           is voor de (mogelijk ververste) datum, opnieuw geselecteerd wordt —
           i.p.v. de klant zijn keuze bij elke refresh kwijt te laten raken. */
        function refresh() {
            if (!readFromIsland()) return false;

            updateHeaderLabel();
            updateLocationBox();
            renderChips();
            renderCards();
            updateMicrocopy();

            var priorSlot = selectedSlot;

            if (!selectedDate && DATES.length) {
                selectDate(DATES[0]);
            } else {
                renderSlots();
                updateConfirm();
                updateSummary();
            }

            var input = dateInputEl();
            if (input) input.value = selectedDate;

            if (SLOTS_ENABLED && priorSlot) {
                var p = selectedDate ? parseYMD(selectedDate) : null;
                var dow = p ? new Date(p.y, p.m, p.d).getDay() : -1;
                var stillOk = p && (SLOTS_BY_DOW[dow] || SLOTS_BY_DOW[String(dow)] || []).indexOf(priorSlot) !== -1;
                if (stillOk) selectSlot(priorSlot);
            }

            // .mkcp-dd-body is bij een AJAX-refresh volledig vers vanaf de
            // server meegekomen (dus altijd zichtbaar) — de in-/uitklapstaat
            // hier opnieuw laten kloppen met de huidige volledigheid (ook na
            // de automatische default-selectie hierboven, niet alleen na een
            // expliciete klik van de klant).
            collapseIfComplete();

            return true;
        }

        function validate() {
            var missingDate = REQUIRED && !selectedDate;
            var missingSlot = SLOTS_ENABLED && selectedDate && !selectedSlot;
            if (!missingDate && !missingSlot) return true;

            // Ongeldig ondanks een eerder ingeklapte staat (bv. capaciteit die
            // net op dit moment vol raakte) — weer uitklappen zodat de klant
            // de fout ook daadwerkelijk kan zien en oplossen.
            expand();

            var errBox = el('error');
            if (errBox) {
                errBox.textContent = missingSlot
                    ? (isPickup ? 'Kies een tijdstip om af te halen.' : 'Kies een tijdstip om te laten bezorgen.')
                    : ('"' + LABEL + '" is een verplicht veld.');
                errBox.hidden = false;
            }

            var wrap = el('wrap');
            if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });

            return false;
        }

        /* ── Kalender-dagnavigatie met pijltjestoetsen (WCAG-datepicker-patroon,
           vereenvoudigd): zoekt de eerstvolgende beschikbare dag in de gekozen
           richting binnen dezelfde maand. Home/End springen naar de eerste/
           laatste beschikbare dag binnen de huidige dagen-grid. ─────────────── */
        function handleCalDayKey(e, dayEl) {
            var STEP = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };

            if (e.key === 'Home' || e.key === 'End') {
                e.preventDefault();
                var scope = dayEl.closest('.mkcp-dd-cal-days') || document;
                var all = scope.querySelectorAll('.mkcp-dd-cal-day--available');
                var target = e.key === 'Home' ? all[0] : all[all.length - 1];
                if (target) target.focus();
                return;
            }

            if (!(e.key in STEP)) return;
            e.preventDefault();

            var p = parseYMD(dayEl.getAttribute('data-date'));
            var cursor = new Date(p.y, p.m, p.d);
            var step = STEP[e.key];
            var daysGrid = dayEl.closest('.mkcp-dd-cal-days');

            for (var i = 0; i < 31; i++) {
                cursor.setDate(cursor.getDate() + step);
                if (cursor.getMonth() !== calMonth || cursor.getFullYear() !== calYear) break;

                var ymd = toYMD(cursor.getFullYear(), cursor.getMonth(), cursor.getDate());
                var btn = (daysGrid || document).querySelector('.mkcp-dd-cal-day[data-date="' + ymd + '"]');
                if (btn) { btn.focus(); break; }
            }
        }

        return {
            prefix: prefix,
            wrapId: id('wrap'),
            refresh: refresh,
            validate: validate,
            selectDate: selectDate,
            scrollToCard: scrollToCard,
            selectSlot: selectSlot,
            collapseIfComplete: collapseIfComplete,
            expand: expand,
            openCalendar: openCalendar,
            toggleCalendar: function () { calOpen ? setCalendarOpen(false) : openCalendar(); },
            closeCalendarIfOpen: function () { if (calOpen) setCalendarOpen(false); },
            isCalendarOpen: function () { return calOpen; },
            prevMonth: function () {
                calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; }
                renderCalendar();
                positionCalendar();
            },
            nextMonth: function () {
                calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; }
                renderCalendar();
                positionCalendar();
            },
            navScroll: function (delta) {
                var vp = el('cards-viewport');
                if (vp) vp.scrollBy({ left: delta, behavior: 'smooth' });
            },
            scrollToWrap: function () {
                var wrap = el('wrap');
                if (wrap) wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            },
            applyCardWidths: applyCardWidths,
            repositionCalendarIfOpen: function () { if (calOpen) positionCalendar(); },
            handleCalDayKey: handleCalDayKey,
            setCardsLoading: function (loading) {
                var c = el('cards');
                if (c) c.classList.toggle('is-loading', loading);
            },
            tickMicrocopy: updateMicrocopy,
            isPickup: isPickup,
            /* Voor het overkoepelende eindsamenvattingsblok
               (#mkcp-dd-final-summary) — null zolang er nog geen (geldige)
               datum gekozen is. */
            getSummary: function () {
                if (!selectedDate) return null;
                return {
                    isPickup: isPickup,
                    label: LABEL,
                    text: formatFull(selectedDate) + (selectedSlot ? ', ' + formatSlotRange(selectedSlot) : '')
                };
            }
        };
    }

    var ROLE_DEFS = [
        { prefix: 'mkcp-dd', dataId: 'mkcp-dd-data', dateFieldId: 'mkcp_delivery_date', slotFieldId: 'mkcp_time_slot', isPickup: false },
        { prefix: 'mkcp-pu', dataId: 'mkcp-pu-data', dateFieldId: 'mkcp_pickup_date', slotFieldId: 'mkcp_pickup_time_slot', isPickup: true }
    ];

    var instances = {};

    function refreshAll() {
        ROLE_DEFS.forEach(function (def) {
            if (!idLast(def.dataId)) {
                delete instances[def.prefix];
                return;
            }
            if (!instances[def.prefix]) {
                instances[def.prefix] = createInstance(def.prefix, def.dataId, def.dateFieldId, def.slotFieldId, def.isPickup);
            }
            instances[def.prefix].refresh();
        });
        updateFinalSummary();
    }

    /* Eindsamenvattingsblok vlak boven de bestelknop (#mkcp-dd-final-summary,
       zie includes/delivery-date.php) — alleen gevuld/zichtbaar zodra er 2
       widgets tegelijk zijn; bij 1 widget staat de eigen samenvattingsregel
       er al vlak boven, dan zou dit blok dubbelop zijn. */
    function updateFinalSummary() {
        var box = idLast('mkcp-dd-final-summary');
        if (!box) return;

        var keys = Object.keys(instances);
        if (keys.length < 2) { box.hidden = true; box.innerHTML = ''; return; }

        var rows = keys.map(function (k) {
            var s = instances[k].getSummary();
            if (!s) return null;
            var roleClass = s.isPickup ? ' mkcp-dd-final-row--pickup' : '';
            var icon = s.isPickup ? PICKUP_ICON_SVG : DELIVERY_ICON_SVG;
            return '<div class="mkcp-dd-final-row' + roleClass + '">' +
                '<span class="mkcp-dd-final-icon" aria-hidden="true">' + icon + '</span>' +
                '<span>' + s.label + ': <strong>' + s.text + '</strong></span></div>';
        }).filter(function (row) { return row !== null; });

        if (!rows.length) { box.hidden = true; box.innerHTML = ''; return; }
        box.hidden = false;
        box.innerHTML = rows.join('');
    }

    /* Vindt de instantie waar een gegeven DOM-element bij hoort, via de
       dichtstbijzijnde .mkcp-dd-wrap se eigen (rol-specifieke) id. */
    function instanceFor(elOrEvent) {
        var target = elOrEvent instanceof Event ? elOrEvent.target : elOrEvent;
        var wrap = target.closest ? target.closest('.mkcp-dd-wrap') : null;
        if (!wrap) return null;
        var prefix = wrap.id.replace(/-wrap$/, '');
        return instances[prefix] || null;
    }

    /* ── Event delegation (overleeft AJAX-refresh; werkt voor beide instanties
       via de gedeelde classes — welke instantie het aangaat wordt per event
       bepaald via instanceFor()). ──────────────────────────────────────────── */

    $(document)
        .on('click', '.mkcp-dd-chip[data-date]', function () {
            var inst = instanceFor(this);
            if (!inst) return;
            var ymd = this.getAttribute('data-date');
            inst.selectDate(ymd);
            inst.scrollToCard(ymd);
            inst.collapseIfComplete();
            updateProgress();
            updateFinalSummary();
        })
        .on('click', '.mkcp-dd-card', function () {
            var inst = instanceFor(this);
            if (!inst) return;
            var ymd = this.getAttribute('data-date');
            inst.selectDate(ymd);
            inst.scrollToCard(ymd);
            inst.collapseIfComplete();
            updateProgress();
            updateFinalSummary();
        })
        .on('change', '.mkcp-pu-slot-select', function () {
            var inst = instanceFor(this);
            if (!inst) return;
            inst.selectSlot(this.value);
            inst.collapseIfComplete();
            updateProgress();
            updateFinalSummary();
        })
        .on('click', '.mkcp-dd-chip--cal', function (e) {
            e.stopPropagation();
            var inst = instanceFor(this);
            if (inst) inst.toggleCalendar();
        })
        .on('click', '.mkcp-dd-cal-day--available', function () {
            var inst = instanceFor(this);
            if (!inst) return;
            var ymd = this.getAttribute('data-date');
            inst.selectDate(ymd);
            inst.scrollToCard(ymd);
            inst.collapseIfComplete();
            updateProgress();
            updateFinalSummary();
        })
        .on('click', '.mkcp-dd-cal-prev', function () {
            if (this.disabled) return;
            var inst = instanceFor(this);
            if (inst) inst.prevMonth();
        })
        .on('click', '.mkcp-dd-cal-next', function () {
            if (this.disabled) return;
            var inst = instanceFor(this);
            if (inst) inst.nextMonth();
        })
        .on('click', '.mkcp-dd-nav--prev', function () {
            var inst = instanceFor(this);
            if (inst) inst.navScroll(-240);
        })
        .on('click', '.mkcp-dd-nav--next', function () {
            var inst = instanceFor(this);
            if (inst) inst.navScroll(240);
        })
        .on('click', '.mkcp-dd-summary-edit', function () {
            var inst = instanceFor(this);
            if (!inst) return;
            inst.expand();
            inst.scrollToWrap();
        })
        /* Klik buiten een wrap sluit ELKE open kalender (er is er hoogstens
           één tegelijk open, maar de klik kan buiten beide wraps vallen). */
        .on('click', function (e) {
            Object.keys(instances).forEach(function (prefix) {
                var inst = instances[prefix];
                if (!inst.isCalendarOpen()) return;
                if (!$(e.target).closest('#' + inst.wrapId).length) inst.closeCalendarIfOpen();
            });
        })
        .on('keydown', function (e) {
            if (e.key !== 'Escape') return;
            Object.keys(instances).forEach(function (prefix) {
                instances[prefix].closeCalendarIfOpen();
            });
        })
        .on('keydown', '.mkcp-dd-cal-day--available', function (e) {
            var inst = instanceFor(this);
            if (inst) inst.handleCalDayKey(e, this);
        });

    /* ── Laadstatus tijdens checkout-AJAX (bv. wisselen verzendmethode) ─────── */

    $(document.body).on('update_checkout', function () {
        Object.keys(instances).forEach(function (prefix) {
            instances[prefix].setCardsLoading(true);
        });
    });

    $(document).on('updated_checkout', function () {
        refreshAll();
        // Vangnet: mkco_reorganize() (checkout-frontend.php) ruimt de oude
        // kopie van de verzendkeuze-kaarten (en dus ook onze data-eilandjes
        // daarbinnen) pas ~80ms na dit event op — ván vóór die opruiming kan
        // idLast() nog de net-verwijderde oude rol zien staan, waardoor de
        // sectie-brede voortgang/eindsamenvatting een rol te veel/te weinig
        // toont totdat er weer iets geklikt wordt. Nog een keer verversen ná
        // die opruiming garandeert dat ze altijd op de definitieve DOM
        // gebaseerd zijn, ook zonder verdere klant-interactie.
        setTimeout(refreshAll, 150);
    });

    $(document).ready(function () {
        refreshAll();
        /* Live cutoff-countdown: elke seconde verversen, voor elke instantie
           die op dat moment bestaat. */
        setInterval(function () {
            Object.keys(instances).forEach(function (prefix) {
                instances[prefix].tickMicrocopy();
            });
        }, 1000);
    });

    $(window).on('resize', function () {
        Object.keys(instances).forEach(function (prefix) {
            instances[prefix].applyCardWidths();
            instances[prefix].repositionCalendarIfOpen();
        });
    });

    /* ── Client-side formuliervalidatie ─────────────────────────────────────
     * WooCommerce's eigen checkout.js bindt zijn submit-handler RECHTSTREEKS
     * op form.checkout (niet gedelegeerd), en 'checkout_place_order' wordt
     * via $form.triggerHandler(...) op datzelfde element afgevuurd — een
     * op document/document.body gedelegeerde listener bereikt dat event niet
     * betrouwbaar (reentrancy-verschil in hoe jQuery dat verwerkt bij een
     * al-lopende submit-afhandeling). Daarom rechtstreeks op form.checkout,
     * één enkele binding die over ALLE op dat moment bestaande instanties
     * loopt — blokkeert de submit zodra er ook maar één ongeldig is. */
    $('form.checkout').on('checkout_place_order', function () {
        var ok = true;
        Object.keys(instances).forEach(function (prefix) {
            if (!instances[prefix].validate()) ok = false;
        });
        return ok;
    });

}(jQuery));
