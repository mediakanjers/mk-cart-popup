<?php
/**
 * MK Cart Popup — Changelog-data, getoond op het Updates-tabblad
 * (admin/views/settings-page.php).
 *
 * Voeg bij elke inhoudelijke wijziging een entry toe bovenaan
 * mkcp_changelog_entries(), vóór het bumpen van MKCP_VER. Puur interne
 * versiebumps zonder gebruikersrelevante wijziging hoeven geen entry.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Entries hierboven blijven bewust in ISO-formaat (Y-m-d) — sorteert vanzelf
// correct en is ondubbelzinnig om in te vullen. Voor de weergave (Updates-tab)
// naar het Nederlandse dag-maand-jaar-formaat, i.p.v. het ISO-jaar-eerst-
// formaat dat verwarrend oogt naast een Nederlandstalige interface.
function mkcp_changelog_format_date( string $date ): string {
    $ts = strtotime( $date );
    return $ts ? date_i18n( 'd-m-Y', $ts ) : $date;
}

function mkcp_changelog_entries() {
    return [
        [
            'version' => '1.16.0-beta.6',
            'date'    => '2026-10-16',
            'items'   => [
                'Verbetering: winkelier-ingestelde tekst (titel, knoplabels, kop-/hulpteksten in de winkelwagen-pop-up, checkout en bedankt-pagina) is nu bestand tegen hostingomgevingen met moeilijk te doorgronden of verkeerd ingestelde caching (pagina-cache, CDN/edge-cache, reverse proxy). Eerdere pogingen om dit specifiek voor WooCommerce\'s eigen winkelwagen-cache op te lossen bleken ontoereikend zodra een andere cachelaag meespeelt — de plugin haalt deze tekst nu bij elke paginalaad altijd rechtstreeks, met een harde cache-buster, op en overschrijft de pagina daarmee, ongeacht welke cachelaag(en) actief zijn.',
                'Fix: een product dat werd "bewaard voor later" terwijl prijzen zichtbaar waren, kon zijn prijs tonen in de bewaarlijst nadat prijzen verborgen waren gezet — ook bij een al eerder bewaard item (opgeslagen in de browser). Die prijs wordt nu ook bij weergave zelf verborgen, niet alleen bij het bewaren.',
                'Fix: de "Prijzen verbergen"-instelling voor de bedankt-pagina kon in een specifiek scenario (een betaalmethode die de betaling pas bij terugkomst op de bedankt-pagina zelf afrondt) ook de bestelbevestigingsmail van prijzen ontdoen, terwijl bedragen in e-mails altijd zichtbaar horen te blijven. De verbergregels staan nu alleen nog aan tijdens het daadwerkelijk tonen van de pagina zelf, niet meer voor de rest van dat verzoek.',
            ],
        ],
        [
            'version' => '1.16.0-beta.5',
            'date'    => '2026-10-16',
            'items'   => [
                'Fix: eigen tekst in de winkelwagen-pop-up (titel, "Verder winkelen"-knoptekst, lege-winkelwagen-tekst e.d.) kon na het opslaan tijdelijk weer de oude tekst tonen bij het laden van een pagina. Oorzaak: WooCommerce\'s eigen winkelwagen-cache in de browser (sessionStorage) wordt alleen ververst als de winkelwagen zelf verandert, niet als een instelling wijzigt — waardoor een verouderde snapshot de net bijgewerkte tekst soms weer kon overschrijven. De plugin haalt nu altijd nog een keer de actuele instellingen op zodra dit gebeurt.',
                'Verbetering: enkele kerntekstvelden van de winkelwagen-pop-up (titel, knoplabels, lege-winkelwagen-tekst) vallen voortaan terug op de standaardtekst zodra ze per ongeluk leeg worden opgeslagen, net als al gold voor een paar andere tekstvelden.',
                'Fix: een klant kreeg op de bedankt-pagina "Je hebt onvoldoende rechten voor deze pagina" bij het openen van de factuur- of pakbon-downloadknop, ook voor zijn eigen bestelling. Oorzaak: de PDF Invoices & Packing Slips-plugin liet (bij de standaard "alleen ingelogd"-toegangsinstelling) een klant zonder orderbeheerrechten alleen door als het verzoek expliciet van Mijn Account of een shortcode kwam — niet vanaf de bedankt-pagina. Gebruikt nu dezelfde aanpak als de downloadknoppen in Account → Bestellingen, die dit al correct deden.',
            ],
        ],
        [
            'version' => '1.16.0-beta.4',
            'date'    => '2026-10-14',
            'items'   => [
                'Verbetering: de changelog op dit tabblad toont voortaan maximaal 10 versies tegelijk, met Vorige/Volgende-paginering eronder zodra er meer zijn — voorheen stond de hele geschiedenis in één lange lijst.',
                'Fix: het aantal in het +/− aantalveld van een winkelwagenregel kon volledig onzichtbaar zijn (wél correct opgeslagen, alleen niet te zien) wanneer het thema een eigen, generieke stijlregel voor alle number-invoervelden had (bv. ruime links/rechts-padding). Het aantalveld van de plugin negeert zulke thema-brede regels nu altijd.',
                'Fix: de variatiegegevens (bv. "Type verpakking: Doosje") onder een winkelwagenregel konden los van elkaar op een eigen regel komen te staan, in zowel de pop-up als het besteloverzicht op de checkout. Oorzaak: sommige (child-)thema\'s hebben nog een verouderde eigen kopie van WooCommerce\'s cart-item-data-sjabloon. De plugin gebruikt voor dit onderdeel nu altijd WooCommerce\'s eigen, actuele standaardsjabloon.',
                'Nieuw (premium): prijzen verbergen, voor webshops zonder (openbare) prijzen — drie volledig losse instellingengroepen: de winkelwagen-pop-up, de checkout, én (nieuw) de bedankt-pagina. Elke groep heeft een eigen hoofdschakelaar, losse onderdelen (productprijzen, subtotaal, verzendkosten, BTW-bedrag — bij checkout en bedankt-pagina ook een apart totaalbedrag) en een eigen zichtbaarheidskeuze (iedereen, alleen niet-ingelogde bezoekers, of specifieke gebruikersrollen). Geldt uitsluitend voor de front-end weergave — in wp-admin en in e-mails blijven bedragen altijd gewoon zichtbaar. Bij een verborgen onderdeel verdwijnt ook het bijbehorende rijtje/kolomkopje zelf (bv. "Subtotaal"), niet alleen het bedrag — dit geldt ook voor het totaalbedrag in de bovenste "Bestelnummer/Datum/E-mail/Totaal/Betaalmethode"-samenvatting op de bedankt-pagina, die een eigen WooCommerce-filter gebruikt los van de besteldetails-tabel eronder. De winkelwagenregel krijgt bij verborgen productprijs meer ruimte voor de productnaam, en de live voorbeeldweergave in de instellingenpagina (Content Builder) houdt hier nu ook rekening mee.',
                'Fix: op de checkout bleven prijzen zichtbaar ondanks een actieve "Helemaal geen prijzen tonen"-instelling. Drie oorzaken tegelijk: (1) de BTW-schakelaar bouwt op dezelfde WooCommerce-filters zijn eigen (incl./excl.-BTW) prijsweergave op en overschreef daarbij het verbergen — verbergen wint nu altijd; (2) WooCommerce ververst het besteloverzicht automatisch via een eigen achtergrondverzoek (bv. na een adreswissel) dat buiten de normale paginalaad om liep en het verbergen daardoor meteen weer ongedaan maakte — werkt nu ook bij die verversing; (3) een verzendmethode met de prijs in de eigen titel (bv. "Verzending - €15,-") liet dat bedrag nog gewoon staan — wordt er nu ook uit gehaald.',
                'Fix: de groene "Opgeslagen"-balk boven in de instellingenpagina liep bij het opslaan opeens over de volle breedte i.p.v. een smalle, gecentreerde balk te zijn. Oorzaak: een eerdere CSS-fix voor een ander scherm-verspringprobleem (contain:layout paint) maakte de omliggende wrapper ongewild het referentiekader voor alle zwevende elementen, waardoor deze balk zijn breedte niet langer automatisch op de inhoud afstemde.',
                'Fix: bij verborgen prijzen op de checkout bleef de "Verzendkosten"-rij (incl. bedrag) nog gewoon staan wanneer de premium Ophalen/Bezorgen-keuzekaarten actief zijn — die rij gebruikt een eigen klasse i.p.v. WooCommerce\'s standaardklasse, waar de verbergregel nog niet op was afgestemd.',
                'Fix: de "Subtotaal"/"Verzendkosten"/"Totaal"-rijen bleven bij verborgen prijzen alsnog zichtbaar bij een actieve "Cart Checkout"-vormgeving. Oorzaak: een bestaande, specifiekere opmaakregel voor die vormgeving (die elke rij in de voettabel een vaste weergave meegeeft) won altijd van de verbergregel, ongeacht de volgorde in het stijlbestand.',
                'Nieuw (premium): pakbon-downloadknop op de bedankt-pagina, naast de bestaande factuur-downloadknop (Checkout → Bedankt-pagina → Documenten) — eigen, losse aan/uit-schakelaar. Werkt net als de factuurknop via de WooCommerce PDF Invoices & Packing Slips-plugin; de inhoud/opmaak van de pakbon zelf regelt die plugin.',
                'Fix: het "Bezorglocatie:"-kaartje op de checkout (bij de bezorgdatum-kiezer) werd niet altijd bijgewerkt na een adreswijziging — zowel bij het rechtstreeks wijzigen van een veld als bij het wisselen tussen opgeslagen adressen in de adreskiezer (de kaartjes bij "Factuurgegevens"/"Verzendgegevens"). Het kaartje leest nu rechtstreeks de actuele veldwaarden, onafhankelijk van de kaart eromheen, en is bovendien publiek oproepbaar (window.mkcpRefreshDeliveryLocationBox) zodat ook externe code die zelf adresvelden vult — zoals een thema-scaffold — het direct kan verversen, zonder te hoeven wachten op WooCommerce\'s eigen update_checkout-AJAX-ronde.',
                'Verbetering: de "Prijzen"-instellingen (winkelwagen-pop-up, checkout én bedankt-pagina) zijn overzichtelijker opgemaakt — "Losse onderdelen" toont nu dezelfde schakelaar-stijl als de hoofdschakelaar erboven i.p.v. kale checkboxes, de rollenkeuze bij "Wie ziet prijzen" is een rij klikbare pil-knoppen i.p.v. een onduidelijke rij losse vinkjes, het label staat boven i.p.v. naast de dropdown, en alle dropdowns in de instellingenpagina (incl. "Adresvelden bij afhalen" en het icoonkeuzelijstje per USP) hebben nu dezelfde gestylede look. Bij de winkelwagen-pop-up is de losse "Totaalbedrag"-schakelaar weggehaald: de pop-up heeft geen apart totaalregeltje los van het ene "Subtotaal"-regeltje, dat al door een andere schakelaar wordt bepaald.',
            ],
        ],
        [
            'version' => '1.15.2',
            'date'    => '2026-09-30',
            'items'   => [
                'Nieuw: rate limiting tegen misbruik door bots/scripts — instelbaar onder Cart Gedrag → Beveiliging, standaard aan. Remt te veel add-to-cart-pogingen, kortingscode-pogingen en e-mailverzoeken achter elkaar af; shopmanagers en admins worden hier nooit door geblokkeerd.',
                'Verbetering: de winkelwagen-pop-up en de teller-badge halen bij elke paginalaad direct de actuele inhoud op, onafhankelijk van cache-instellingen bij de hosting (WP Rocket, CDN, reverse proxy).',
                'Verbetering: werkt nu ook correct na terugnavigeren met de browserknop.',
                'Fix: een trage achtergrond-ververing kon in zeldzame gevallen een net doorgevoerde wijziging weer overschrijven.',
                'Verbetering: geen cache meer op eigen AJAX-verzoeken van de plugin.',
                'Verbetering: de teller-badge verschijnt nu met een rustige puls-animatie zodra het juiste aantal bekend is.',
            ],
        ],
        [
            'version' => '1.15.1',
            'date'    => '2026-09-29',
            'items'   => [
                'Fix: een verwijderd product uit de winkelwagen-pop-up kon na het navigeren naar een andere pagina (met name bij paginacaching) weer "terug" lijken. WooCommerce\'s eigen automatische ververs-mechanisme haalde de actuele winkelwagen al wel op, maar paste die niet toe op de pop-up zelf — de pop-up wordt nu ook bij die automatische ronde correct bijgewerkt.',
            ],
        ],
        [
            'version' => '1.15.0',
            'date'    => '2026-09-29',
            'items'   => [
                // ── Account (premium) ──
                'Nieuw (premium): volledige "Account"-omgeving als vervanging van WooCommerce\'s "Mijn Account" — dashboard, bestellingenoverzicht met opnieuw-bestellen, wishlist, adresboek, retouraanvragen met voortgangsbalk, productreviews en een meldingencentrum, inclusief volledige GDPR-export/verwijdering.',
                'Nieuw: eigen gestileerd inlogscherm, met bescherming tegen te veel mislukte inlogpogingen.',
                'Nieuw (admin): retouraanvragen groeperen per bestelling, in bulk afhandelen vanaf de bestelpagina.',

                // ── Checkout ──
                'Nieuw: eigen inlog-/"account aanmaken"-pop-up met adreskiezer uit je adresboek.',
                'Nieuw: telefoonnummer wordt live opgemaakt (NL/BE/DE), met een land-kiezer los van het adres.',
                'Nieuw: cross-sell-suggesties onder het besteloverzicht, met laad- en succesanimatie.',
                'Nieuw: meerdere verzendpakketten — eigen bezorgdatum/afhaal-tijdvak per rol bij een gemengd winkelmandje.',
                'Nieuw: instelling "Adresvelden bij afhalen" (verplicht/niet verplicht/weg).',
                'Nieuw: vertrouwensbadge — handmatig, live Google Reviews, of Trusted Shops/WebwinkelKeur/Kiyoh.',
                'Nieuw: instelbaar PDF-documenten-blok (bezorg-/afhaalkop, BTW-verlegging, telefoon/e-mail op pakbon).',
                'Nieuw: "Overzicht van je bestelling" op mobiel als losse pop-up i.p.v. inline.',
                'Fix: interne WooCommerce-verzendzone-melding kon zichtbaar blijven voor klanten.',
                'Fix: checkout kon op mobiel onbedoeld zijwaarts scrollen.',

                // ── Bedankt-pagina (premium) ──
                'Nieuw (premium): volledig herzien — persoonlijke heading, bezorg-/afhaalbanner, factuur-downloadknop, stappenstrip, cross-sell en vertrouwenselementen.',

                // ── Winkelwagen-popup ──
                'Nieuw: sleepbare breedte met magnetische volledig-scherm-drempel; de brede lay-out werkt nu ook als alleen de sleepgreep aanstaat.',
                'Nieuw: instelbare badge-kleur, losse aan/uit voor betaalicoontjes en USP-truststrip.',
                'Nieuw: eigen geanimeerd plugin-icoon.',
                'Fix: herhaald tikken op mobiel kon per ongeluk als dubbeltik-zoom worden opgevat.',

                // ── Overig ──
                'Verbetering: merkbaar snellere checkout en Account-dashboard.',
                'Verbetering: compactere mobiele opmaak op checkout en in Account.',
                'Fix: vertrouwensbadge-"laatst bijgewerkt"-stempel ververste bij élke instellingen-opslag i.p.v. alleen bij een echte wijziging.',
                'Fix: bezorgadres-voorbeeld op checkout kon straat/huisnummer en postcode/plaats uit verschillende adresgroepen combineren.',
                'Fix: zwart gekozen tekstkleur in een tekstblok werd altijd genegeerd.',
                'Verbetering: plugin-updates alleen nog van vertrouwde, beveiligde bronnen; installatie-check per hoofdonderdeel.',
            ],
        ],
        [
            'version' => '1.14.31-beta.72',
            'date'    => '2026-09-28',
            'items'   => [
                // ── Winkelwagen-popup ──
                'Fix: snel herhaald tikken op mobiel (bv. op de aantal-knoppen) kon per ongeluk als dubbeltik-zoom-gebaar worden opgevat. Scrollen en pinch-zoom blijven gewoon werken.',
                'Fix: de brede 2-koloms-lay-out (productkaarten, cross-sell-kolom, USP\'s) verscheen alleen als de "volledig scherm"-knop aanstond. Staat alleen de breedte-sleepgreep aan, dan reageert de lay-out nu ook gewoon op de daadwerkelijke breedte.',
                'Fix: de sleepgreep zonder "volledig scherm"-knop zag er nog verouderd uit t.o.v. de combi-variant — beide zien er nu hetzelfde uit.',
                'Fix: tekst in waarschuwingsblokken (Content Builder) was onnodig groot.',

                // ── Checkout ──
                'Fix: de checkout-pagina kon op mobiel soms onbedoeld zijwaarts bewegen.',
                'Fix: een interne WooCommerce-verzendzone-melding, nooit voor klanten bedoeld, kon blijven terugkomen na een adres-/verzendwijziging.',
                'Verbetering: bij het toevoegen van een cross-sell-suggestie zie je nu duidelijk een laad-animatie en een bevestiging (vinkje) zodra het gelukt is.',
                'Nieuw: scheidingslijntje tussen de betaalicoontjes en de vertrouwensbadge, alleen zichtbaar als beide ook echt naast elkaar staan.',
                'Verbetering: compactere ruimte rond de BTW-schakelaar.',
            ],
        ],
        [
            'version' => '1.14.31-beta.71',
            'date'    => '2026-09-28',
            'items'   => [
                // ── Checkout ──
                'Fix: de scheidingslijn onder een product in het besteloverzicht liep bij een variabel product (met keuze-opties eronder) niet door tot de prijskolom. Loopt nu over de volle breedte.',
                'Fix: na het wisselen tussen "Laten bezorgen" en "Zelf afhalen" kon de samenvatting boven de bestelknop een verouderde rol blijven tonen. Ververst nu op het juiste moment.',
                'Fix: "Nu verversen" bij de Google-vertrouwensbadge testte de laatst opgeslagen API-sleutel/Place ID i.p.v. de live ingevulde waarden.',
                'Fix: padding van de betaalmethode-iconen ontbrak op smallere schermen, en kon op sommige thema\'s wegvallen bij de betaalmethodes-lijst zelf.',
                'Verbetering: compactere mobiele opmaak voor de stappen-indicator, het header-logo en de "Ik ben nieuw"/"Ik ben al klant"-tabs.',
                'Verbetering: het inlogformulier (gebruikersnaam/wachtwoord) staat op mobiel nu onder elkaar i.p.v. te krap naast elkaar.',

                // ── Winkelwagen-popup ──
                'Fix: de titel "Misschien ook interessant?" boven de cross-sell-kaartjes stond niet gelijk uitgelijnd met de kaartjes eronder.',
                'Fix: de zijmarge van de vertrouwensbadge in de winkelwagen-popup was verdwenen.',
                'Nieuw: kleur van de aantal-in-winkelwagen-badge is nu instelbaar, met snelkeuze uit de kleuren van je thema.',
                'Nieuw: losse aan/uit voor de betaalicoontjes en de USP truststrip, ook rechtstreeks vanuit de Content Builder.',

                // ── Overig ──
                'Verbetering: de in-plugin documentatie is bijgewerkt naar de huidige stand van de plugin.',
            ],
        ],
        [
            'version' => '1.14.31-beta.41',
            'date'    => '2026-09-23',
            'items'   => [
                'Nieuw: "Overzicht van je bestelling" heeft op mobiel een compleet nieuwe plek gekregen — niet meer inline tussen de ingevulde velden, maar een los knopje naast "Bestellen" in de vaste onderbalk dat een pop-up opent met het volledige besteloverzicht, met een vinger omlaag weg te vegen om te sluiten.',
                'Verbetering: het knopje dat de bestel-overzicht-popup opent toont nu ook de tekst "Bekijk bestelling" naast het icoon.',
                'Verbetering: overbodige kleurtint achter de productlijst in die popup verwijderd op mobiel.',
                'Verbetering: hover-achtergrond van de pijltjes-navigatie in de adreskiezer op checkout is nu wit.',
                'Verbetering: header (logo + stappen) heeft op mobiel nu ademruimte t.o.v. de eerste sectie.',
                'Verbetering: afgeronde hoeken van de secties (adres, levering, betaling) verwijderd op mobiel.',
                'Verbetering: de datumkiezer toont op mobiel minder datumkaarten naast elkaar, zodat elke datum breder en makkelijker te raken is.',
                'Verbetering: het huisnummer-toevoeging-veld (bij een gekoppelde postcode-checker) krijgt nu expliciet geen telefoon-autocomplete-suggestie meer.',
                'Fix: het laad-icoontje bij "Kleuren van je website zoeken…" (Instellingen → Styling) draaide niet rond.',
                'Fix: een BTW-verleggingsvergrendeling (na een geldig EU-btw-nummer op checkout) kon de winkelwagen-pop-up voorgoed op "excl. BTW" laten hangen op andere pagina\'s.',
                'Fix: het totaalbedrag in de mobiele onderbalk toonde soms permanent "excl. BTW" terwijl het getoonde bedrag inclusief was, en wisselde niet mee met de incl./excl.-schakelaar.',
                'Fix: de bestel-overzicht-popup kon op de verkeerde plek openen (los van je scrollpositie).',
                'Fix: de sluitknop (kruisje) van de popup was ovaal i.p.v. rond.',
                'Fix: de hele mobiele onderbalk (incl. het totaalbedrag) opende per ongeluk ook de "Bekijk bestelling"-popup — nu alleen dat knopje zelf.',
                'Verbetering: bèta-updates komen voortaan uit een eigen "pre-release"-kanaal i.p.v. de hoofd-branch, zodat een bèta nooit meer per ongeluk vermengd raakt met een stabiele release.',
            ],
        ],
        [
            'version' => '1.14.31-beta.28',
            'date'    => '2026-09-22',
            'items'   => [
                // ── Account ──
                'Nieuw: installatie-check op elk hoofdonderdeel (Winkelwagen, Checkout, Account) — waarschuwt direct als een WordPress/WooCommerce-instelling nog moet gebeuren, met 1-klik-oplossing waar mogelijk.',
                'Nieuw: volledig eigen gestileerd inlogscherm — eigen logo, achtergrondfoto en welkomsttekst/voordelen, met een begroeting die met het dagdeel meebeweegt en bescherming tegen te veel mislukte inlogpogingen.',
                'Nieuw: WooCommerce\'s eigen account-instellingen (account aanmaken bij checkout/op de accountpagina, wachtwoord/gebruikersnaam automatisch genereren) zijn nu rechtstreeks vanuit de plugin te bewerken.',
                'Nieuw: de onboarding-rondleiding neemt nu ook het hele Account-onderdeel mee.',
                'Nieuw: een heel "Retourneren"-tabblad met een overzicht van al je retouraanvragen en een visuele voortgangsbalk per aanvraag (aangevraagd → goedgekeurd → terugbetaald/afgehandeld).',
                'Nieuw: meerdere producten uit één bestelling in één keer als retour aanmelden (bulk-retour).',
                'Nieuw: een terugbetaling via WooCommerce\'s eigen "Terugbetalen"-knop zet de bijbehorende retouraanvraag automatisch op "Terugbetaald", met automatisch bericht aan de klant.',
                'Nieuw (admin): retouraanvragen groeperen per bestelling, in bulk goedkeuren/afwijzen met een gezamenlijke notitie, en direct afhandelen vanaf de bestelpagina zelf; een nieuwe "Retour?"-kolom in de bestellingenlijst.',
                'Nieuw: los instelbare meldingen (e-mail en/of dashboard) bij een prijsdaling of als een wishlist-product weer op voorraad is, met "Alles selecteren" op de wishlist.',
                'Nieuw: klanten krijgen nu ook een melding bij elke bestelstatus-wijziging.',
                'Nieuw: bij een accountverwijdering worden productreviews voortaan geanonimiseerd (i.p.v. verwijderd), en Account is volledig gekoppeld aan WordPress\' eigen privacytools.',
                'Nieuw: het Dashboard toont nu een winkelwagen-teaser, snelkoppelingen en een winkelwagen-icoon met live-bijgewerkt aantal in de topbar.',
                'Nieuw: bulkacties in het retourenbeheer, en een eigen uitlegtekst instelbaar boven het Retourneren-tabblad.',
                'Verbetering: verzendkosten als eigen regel bij een bestelling, aanklikbare producten naar de productpagina.',
                'Verbetering: duidelijke bevestigings-/foutmeldingen bij acties i.p.v. soms stilzwijgend niets te doen.',
                'Verbetering: gelezen meldingen worden automatisch opgeruimd; eigen iconen/filters voor wishlist- en review-meldingen.',
                'Verbetering: het Account-dashboard laadt merkbaar sneller.',
                'Fix: de factuurdownload-knop gaf geen onterechte "onvoldoende rechten"-melding meer.',
                'Fix: bezorg-/afhaaldata worden gevalideerd (geen "1 januari 1970" meer); afhaalbestellingen tonen de juiste laatste stap in de besteltracker.',
                'Fix: diverse mobiele lay-outproblemen verholpen.',
                'Fix: carrousel-pijltjes op het Dashboard konden onterecht uitgeschakeld blijven.',
                'Fix: de "Elke"-attributen-fix voor variabele producten werkt nu ook bij "opnieuw bestellen" en vanuit de wishlist.',
                'Fix: de rechterkolom op checkout kon na het inloggen ver naar beneden schuiven met een groot leeg vlak erboven.',

                // ── Checkout ──
                'Nieuw: cross-sell-productsuggesties onder het orderoverzicht, met eigen aan/uit-instelling.',
                'Nieuw: bij een gemengd winkelmandje (verzenden + afhalen) groepeert het orderoverzicht nu met kopjes + filterknoppen (Alles/Verzenden/Afhalen).',
                'Nieuw: bij een lange productlijst blijft het subtotaal/totaal altijd zichtbaar, met een scroll-pijltje dat aangeeft of er nog meer te zien is.',
                'Nieuw: instelbare, wisselende teksten voor het "Bestelling verwerken…"-laadscherm.',
                'Nieuw: bij een mislukte validatie scrollt en focust de pagina automatisch naar het eerste foute veld, met een korte trilling ter attentie.',
                'Nieuw: na het invullen van postcode + huisnummer springt de focus automatisch door naar het telefoonnummerveld.',
                'Nieuw: telefoonnummer wordt live opgemaakt volgens de officiële netnummer-indeling van Nederland, België of Duitsland, met een klikbaar vlagicoontje om het telefoonnummer-land los van het adres in te stellen.',
                'Nieuw: e-mailadres krijgt dezelfde typfout-suggestie ("Bedoelde je gmail.com?") als het inlogscherm.',
                'Nieuw: het inloggen/"account aanmaken" gebeurt via twee duidelijke tabs i.p.v. losse meldingen.',
                'Verbetering: BTW-weergave en "verzenden naar een ander adres" zijn nu schuifknoppen, met een rustiger rechterpaneel en compactere betaalmethode-lijst.',
                'Verbetering: merkbare snelheidswinst — een overbodige, premature verzendkosten-berekening bij elk "totalen bijwerken"-verzoek is weggehaald, samen met andere achterliggende performance-fixes.',
                'Fix: sectie-icoontjes (verzenden/afhalen) werden niet getoond.',
                'Fix: de Alles/Verzenden/Afhalen-filterknoppen communiceerden hun status niet naar schermlezers.',
                'Fix: betaal-icoontjes konden na een adreswijziging blijvend verdwijnen; een lang productkenmerk kon op mobiel de kolom te breed maken.',

                // ── Bezorgdatum / Afhalen / Verzendkeuze ──
                'Nieuw: een overzicht van je ingevulde bezorgadres direct boven de datumkeuze.',
                'Nieuw: eigen namen instelbaar voor verzendmethodes op de verzendkeuze-kaarten.',
                'Verbetering: de datumkeuze toont meteen 6 kaarten i.p.v. 4, en blijft altijd zichtbaar i.p.v. automatisch in te klappen.',
                'Verbetering: de productlijst bij verzendkeuze toont alles in één keer i.p.v. "en X meer".',
                'Verbetering: bezorgdatum-beschikbaarheid laadt merkbaar sneller.',
                'Fix: een bezorgdatum kon lijken gekozen terwijl checkout toch een "verplicht veld"-melding gaf.',
                'Fix: "Zelf afhalen" kon per ongeluk als standaardkeuze aangevinkt staan i.p.v. "Laten bezorgen".',
                'Fix: telefoonnummer is nu altijd verplicht, ongeacht bezorgen/afhalen.',

                // ── Winkelwagen-pop-up ──
                'Nieuw: handmatig breder/smaller te slepen (met een magnetische drempel naar volledig scherm), volledig met het toetsenbord te bedienen.',
                'Nieuw: accountlink met persoonlijke begroeting, en een instelbare vertrouwensbadge (sterrenscore, evt. gekoppeld aan Trusted Shops/WebwinkelKeur/Kiyoh).',
                'Nieuw: directe link op de bedankt-pagina naar de zojuist geplaatste bestelling in je account.',
                'Nieuw: instelbare afkoelperiode voor verlaten-winkelwagen-herinneringsmails.',
                'Verbetering: de BTW incl./excl.-keuze is nu een echte schuifknop i.p.v. twee kleine tekstknopjes.',
                'Verbetering: opent/sluit merkbaar sneller (animatieduur van 460ms naar 300ms).',
                'Fix: sleepgreep-positie, verloren handmatige breedte bij een ververting, abrupt dichtklappen bij snel sluiten.',
                'Fix: screenreader-status van de "volledig scherm"-knop, en een focus-val-lek via de sleepgreep.',

                // ── Admin/instellingen & beveiliging ──
                'Nieuw: de breedte-sleepgreep en het account-icoontje van de winkelwagen-pop-up zijn los aan/uit te zetten, net als productreviews en de nieuwsbrief-checkbox binnen Account.',
                'Verbetering: de plugin-updatecontrole accepteert alleen nog updatepakketten van vertrouwde, beveiligde (https) bronnen; een onbekende/foutieve licentiecontrole weigert voortaan toegang tot premium-functies i.p.v. daarop terug te vallen.',
                'Verbetering: de updatemelding in het WordPress-dashboard toont nu een eigen plugin-icoon en banner.',
                'Verbetering: de "WP-Cron uitgeschakeld"-melding voor verlaten-winkelwagen-herinneringen is verplaatst naar een permanent statuskaartje in de installatiecheck, dat ook toont of de laatste herinneringsmail is verstuurd.',
                'Fix: een negatief bedrag bij "Gratis verzending vanaf" of de minimale bestelwaarde kon per ongeluk worden opgeslagen.',
            ],
        ],
        [
            'version' => '1.14.31-beta.27',
            'date'    => '2026-08-06',
            'items'   => [
                'Nieuw (premium): Account — een volledige vervanging van WooCommerce\'s "Mijn Account" met dashboard, bestellingenoverzicht + detail met opnieuw-bestellen, wishlist (meerdere lijsten, delen, prijs-/voorraadmeldingen), adresboek, retour-aanvragen en productreviews als losse pop-ups vanaf de bestelling, een meldingencentrum en volledige GDPR-export/verwijdering.',
                'Nieuw: checkout krijgt een eigen inlog-pop-up en een adreskiezer die het adresboek uit Account hergebruikt zodra een klant is ingelogd; de "account aanmaken"-checkbox en het inlogformulier voor terugkerende klanten zijn nu los aan/uit te zetten, met eigen toelichtingstekst.',
                'Nieuw: wishlist-hart-icoon op product- en overzichtspagina\'s om producten direct aan de wishlist toe te voegen, zonder naar de productpagina te hoeven.',
                'Fix: variabele producten waarbij één of meer opties voor alle varianten hetzelfde zijn ("Elke", bv. een boeket met vaste prijsklassen maar vrij te kiezen gelegenheid/kleur/stijl) konden niet worden toegevoegd aan de winkelwagen — gaf altijd de melding dat verplichte velden ontbraken, ook als alles zichtbaar was ingevuld.',
                'Fix: snel na elkaar wisselen van variatie-opties kon een verouderde keuze alsnog laten doorglippen naar de winkelwagen, met een verwarrende paginaherlading tot gevolg.',
                'Fix: bij een verse bezoeker zonder eerder ingevuld adres kon de verzendkeuze-sectie op checkout helemaal leeg blijven in plaats van een "vul eerst je postcode in"-melding te tonen.',
            ],
        ],
        [
            'version' => '1.14.31-beta.1',
            'date'    => '2026-08-03',
            'items'   => [
                'Fix: de verzendkosten-rij op checkout toont nu per rol (bezorgen/afhalen) een eigen bedrag i.p.v. één totaalrij met het verkeerde label bij gemengde winkelwagens.',
                'Verbetering: de "Kleuren van je website"-kaart (Styling-tab) heeft nu een laadindicator tijdens het scannen, een kopieerknop per kleur, en een knop om automatisch een kant-en-klare stijl te genereren.',
            ],
        ],
        [
            'version' => '1.14.30',
            'date'    => '2026-07-28',
            'items'   => [
                'Fix: bestellingen met "Afhalen" konden onterecht geblokkeerd worden door een verouderde thema-validatie die niet meer werd opgeruimd tijdens het daadwerkelijke afrekenen (alleen bij een normale paginalaad, niet bij het afronden van de bestelling zelf).',
                'Fix: de winkelwagen-drawer kon volledig verdwijnen (met een onscrollbare pagina tot gevolg) na het toevoegen van een product via een "Toevoegen aan winkelwagen"-knop op een overzichtspagina — botste met WooCommerce\'s eigen ververs-mechanisme.',
                'Verbetering: de akkoord-checkbox bij de algemene voorwaarden op checkout is nu netjes opgemaakt (was eerder onopgemaakt en kon de "Plaats bestelling"-knop overlappen).',
                'Verbetering: ingesloten tekst van de voorwaarden-/privacybeleidpagina op checkout blijft nu altijd leesbaar, ook als die pagina met een eigen page-builder is opgebouwd.',
            ],
        ],
        [
            'version' => '1.14.29',
            'date'    => '2026-07-28',
            'items'   => [
                'Fix: "Plugin inschakelen" was geen echte hoofdschakelaar — de /cart-omleiding en verschillende premium checkout-features (bezorgdatum, afhalen, verzendkeuze, BTW-switch e.d.) bleven actief als de plugin was uitgeschakeld.',
                'Fix: enkele content-builder-zones op de checkout controleerden de licentie niet, waardoor ze mogelijk ook op basic-licenties zichtbaar konden zijn.',
                'Nieuw: pre-release (bèta) kanaal — licenties kunnen nu individueel toegang krijgen tot bèta-versies via het licentiedashboard.',
            ],
        ],
        [
            'version' => '1.14.28',
            'date'    => '2026-07-27',
            'items'   => [
                'Fix: add-to-cart bij variabele producten kon soms de dubbele hoeveelheid toevoegen (een verborgen thema-veld triggerde tegelijk WooCommerce\'s klassieke én AJAX-afhandeling).',
                'Fix: add-to-cart-knop reageerde soms helemaal niet (thema\'s met een eigen, verborgen "required"-veld naast de variatie-select blokkeerden de submit stilzwijgend).',
                'Fix: product_id/variation_id-afhandeling aangepast voor recentere WooCommerce-versies (10.x) bij variabele producten.',
                'Fix: winkelwagen-drawer bleef soms dicht na een add-to-cart als WooCommerce zelf ook nog een fragment-ververs deed.',
                'Fix: achtergrondpagina bleef scrollbaar terwijl de winkelwagen-drawer open stond (bij thema\'s die overflow op <html> i.p.v. <body> zetten).',
                'Fix: bezorgdatum gekozen via de kalender (buiten de eerste 4 kaarten) liet een gat vallen i.p.v. de tussenliggende datums te tonen.',
                'Fix: mobiele scroll naar de laatst gekozen datumkaart eindigde soms net verkeerd uitgelijnd (nu via scrollIntoView i.p.v. handmatige scrollberekening).',
                'Fix: tijdvak-dropdown (afhalen/bezorgen) deed niets bij een keuze — ontbrekende change-listener na eerdere omzetting van knoppen naar dropdown.',
                'Fix: tab/product-combinatie in de instellingenpagina kon elkaar tegenspreken (bv. na opslaan vanuit Cart Checkout), waardoor de verkeerde navigatie bij het verkeerde paneel getoond werd.',
                'Verbetering: bezorgdatum-samenvatting op checkout blijft nu ook staan na een externe (WooCommerce-eigen) paginaverversing.',
                'Verbetering: bezorgdatum/afhaalinfo op de PDF-factuur en pakbon staat nu direct onder "Betaalmethode" i.p.v. onderaan, en het bezorgdatum-label is vetgedrukt.',
                'Verbetering: sectietitels in de 3-blokken checkout-lay-out vallen nu binnen de kaartrand.',
                'Verbetering: mobiele bestelbalk verschijnt niet meer op de bedankt-pagina.',
            ],
        ],
    ];
}
