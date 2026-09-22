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
            'version' => '1.14.31-beta.74',
            'date'    => '2026-09-22',
            'items'   => [
                // ── Account ──
                'Nieuw: volledig eigen gestileerd inlogscherm voor Account — eigen logo, achtergrondfoto en welkomsttekst/voordelen, met een begroeting die met het dagdeel meebeweegt en bescherming tegen te veel mislukte inlogpogingen.',
                'Nieuw: een heel "Retourneren"-tabblad in Account met een overzicht van al je retouraanvragen en een visuele voortgangsbalk per aanvraag (aangevraagd → goedgekeurd → terugbetaald/afgehandeld).',
                'Nieuw: meerdere producten uit één bestelling in één keer als retour aanmelden (bulk-retour), in plaats van elk product apart.',
                'Nieuw: een terugbetaling via WooCommerce\'s eigen "Terugbetalen"-knop zet de bijbehorende retouraanvraag automatisch op "Terugbetaald", met automatisch bericht aan de klant.',
                'Nieuw (admin): retouraanvragen groeperen per bestelling, in bulk goedkeuren/afwijzen met een gezamenlijke notitie, en direct afhandelen vanaf de bestelpagina zelf; een nieuwe "Retour?"-kolom in de bestellingenlijst laat in één oogopslag zien welke bestellingen een retouraanvraag hebben.',
                'Nieuw: los instelbare meldingen (e-mail en/of dashboard) bij een prijsdaling of als een wishlist-product weer op voorraad is, met "Alles selecteren" op de wishlist voor bulk-acties.',
                'Nieuw: klanten krijgen nu ook een melding bij elke bestelstatus-wijziging (in verwerking, afgerond, geannuleerd, terugbetaald, betaling mislukt).',
                'Nieuw: bij een accountverwijdering worden voortaan ook productreviews geanonimiseerd (naam/e-mail weg, tekst blijft staan), en is Account volledig gekoppeld aan WordPress\' eigen privacytools (gegevens exporteren/wissen) inclusief wishlist, adresboek, meldingen en retouren.',
                'Nieuw: het Dashboard toont nu een winkelwagen-teaser en snelkoppelingen (wishlist, laatste/actieve bestelling), plus een winkelwagen-icoon met live-bijgewerkt aantal in de topbar.',
                'Verbetering: verzendkosten staan nu als eigen regel bij een bestelling i.p.v. onzichtbaar in het totaalbedrag te verdwijnen, en producten in een bestelling zijn nu aanklikbaar naar de productpagina.',
                'Verbetering: acties in Account (retour aanvragen, review plaatsen, meldingsvoorkeuren) tonen nu een duidelijke bevestiging of foutmelding via een korte melding in beeld, i.p.v. soms stilzwijgend niets te doen.',
                'Verbetering: gelezen meldingen worden na verloop van tijd automatisch opgeruimd (ongelezen blijven altijd bewaard), en het meldingencentrum heeft nu ook eigen iconen/filters voor wishlist- en review-meldingen.',
                'Fix: de factuurdownload-knop gebruikt nu de juiste toegangscheck van WooCommerce PDF Invoices & Packing Slips (geen onterechte "onvoldoende rechten"-melding meer).',
                'Fix: bezorg-/afhaaldata worden gevalideerd zodat een lege/foutieve datum niet meer als "1 januari 1970" verschijnt, en afhaalbestellingen die klaarstaan tonen nu de juiste laatste stap in de besteltracker.',
                'Fix: diverse mobiele lay-outproblemen in Account verholpen (een "dode zone" tussen omslagpunten, ontbrekende ruimte boven de iPhone-thuisbalk, een pagina die zijwaarts kon scrollen).',
                'Fix: de vorige/volgende-pijltjes van de productcarrousel op het Dashboard konden na het laden van "Onlangs bekeken" onterecht uitgeschakeld blijven.',
                // ── Checkout ──
                'Nieuw: cross-sell-productsuggesties onder het orderoverzicht op checkout, met eigen aan/uit-instelling en een klikbare "toevoegen"-knop.',
                'Nieuw: bij een gemengd winkelmandje (verzenden + afhalen) groepeert het orderoverzicht op checkout nu met kopjes + filterknoppen (Alles/Verzenden/Afhalen).',
                'Nieuw: bij een lange productlijst op checkout blijft het subtotaal/totaal altijd zichtbaar onderaan, terwijl alleen de productlijst zelf scrolt — met een pijltje dat toont of er nog meer te zien is en omdraait zodra je onderaan bent.',
                'Nieuw: instelbare, wisselende teksten voor het "Bestelling verwerken…"-laadscherm op checkout.',
                'Nieuw: bij een mislukte validatie op checkout scrollt en focust de pagina automatisch naar het eerste foute veld, met een korte trilling ter attentie.',
                'Nieuw: na het invullen van postcode + huisnummer springt de focus automatisch door naar het telefoonnummerveld.',
                'Nieuw: telefoonnummer wordt tijdens het typen live opgemaakt volgens de officiële netnummer-indeling van Nederland, België of Duitsland, met een klikbaar vlagicoontje waarmee je het telefoonnummer-land los van het factuur-/afleveradres kan instellen (handig bij bv. een Belgisch mobiel nummer op een Nederlands adres), plus een niet-blokkerende waarschuwing bij een onvolledig aantal cijfers.',
                'Nieuw: e-mailadres op checkout krijgt dezelfde typfout-suggestie ("Bedoelde je gmail.com?") als het inlogscherm.',
                'Verbetering: BTW-weergave en "verzenden naar een ander adres" zijn nu schuifknoppen i.p.v. losse knoppen/een vierkantje, met een rustiger rechterpaneel en compactere, consistentere betaalmethode-lijst.',
                'Verbetering: merkbare snelheidswinst op checkout — een overbodige, premature verzendkosten-berekening bij elk "totalen bijwerken"-verzoek is verwijderd, samen met andere achterliggende performance-fixes.',
                'Fix: sectie-icoontjes (verzenden/afhalen) op checkout werden niet getoond door een verkeerde manier van SVG-opmaak injecteren in JavaScript.',
                'Fix: de Alles/Verzenden/Afhalen-filterknoppen bij een gemengd winkelmandje communiceerden hun actieve status niet naar schermlezers (ontbrekende aria-pressed).',
                'Fix: betaal-icoontjes onder het orderoverzicht konden na een adreswijziging blijvend verdwijnen totdat je hard herlaadde; een lang productkenmerk kon op mobiel de kolom breder dan het scherm maken.',
                // ── Bezorgdatum / Afhalen / Verzendkeuze ──
                'Nieuw: een overzicht van je ingevulde bezorgadres direct boven de datumkeuze op checkout, zodat je het kunt controleren vóór je een datum kiest.',
                'Nieuw: eigen namen instelbaar voor verzendmethodes op de verzendkeuze-kaarten, zonder dat dit de echte WooCommerce-naam op facturen/e-mails verandert.',
                'Verbetering: de datumkeuze toont nu meteen 6 kaarten i.p.v. 4 (plus een kalender-kaart voor een andere datum), en de gekozen datum/tijdvak blijft altijd zichtbaar i.p.v. automatisch in te klappen.',
                'Verbetering: bezorgdatum-beschikbaarheid laadt merkbaar sneller (in één keer voor de hele periode i.p.v. per dag apart).',
                'Fix: een bezorgdatum kon in zeldzame gevallen lijken gekozen terwijl checkout toch een "verplicht veld"-melding gaf; "Zelf afhalen" kon per ongeluk als standaardkeuze aangevinkt staan i.p.v. "Laten bezorgen".',
                'Fix: telefoonnummer is nu altijd verplicht op checkout, ongeacht bezorgen/afhalen, zodat de foutmelding nooit meer tegenspreekt wat er op het scherm staat.',
                // ── Winkelwagen-pop-up ──
                'Nieuw: de winkelwagen-pop-up is nu handmatig breder/smaller te slepen (met een magnetische drempel naar volledig scherm), volledig met het toetsenbord te bedienen.',
                'Nieuw: accountlink met persoonlijke begroeting ("Hoi [voornaam]") bovenaan de winkelwagen-pop-up voor ingelogde klanten, en een instelbare vertrouwensbadge (sterrenscore + reviewaantal, evt. gekoppeld aan Trusted Shops/WebwinkelKeur/Kiyoh).',
                'Nieuw: directe link op de bedankt-pagina om de zojuist geplaatste bestelling in je account te bekijken (alleen zichtbaar bij een ingelogde klant met actieve accountomgeving).',
                'Nieuw: instelbare afkoelperiode voor verlaten-winkelwagen-herinneringsmails.',
                'Verbetering: de BTW incl./excl.-keuze in de pop-up is nu een echte, over de volle breedte klikbare schuifknop i.p.v. twee kleine tekstknopjes, en reageert direct op een klik.',
                'Verbetering: de winkelwagen-pop-up opent/sluit merkbaar sneller (animatieduur van 460ms naar 300ms).',
                'Fix: de sleepgreep werd eerder afgesneden door de afgeronde hoeken van de pop-up; een handmatig ingestelde breedte ging verloren bij een automatische ververting; de pop-up klapte soms abrupt dicht i.p.v. netjes uit te schuiven bij snel sluiten na het aanpassen van de breedte.',
                'Fix: de "volledig scherm"-knop werkte niet meer consistent voor schermlezer-gebruikers, en toetsenbordgebruikers konden per ongeluk uit de pop-up "wegtaben" via de sleepgreep.',
                // ── Admin/instellingen ──
                'Nieuw: bulkacties in het retourenbeheer (meerdere aanvragen tegelijk goedkeuren/afwijzen/voltooien met notitie), en een eigen uitlegtekst instelbaar boven het Retourneren-tabblad in Account.',
                'Nieuw: de breedte-sleepgreep en het account-icoontje van de winkelwagen-pop-up zijn los aan/uit te zetten, net als productreviews en de nieuwsbrief-checkbox binnen Account.',
                'Fix: een negatief bedrag bij "Gratis verzending vanaf" of de minimale bestelwaarde kon per ongeluk worden opgeslagen; wordt nu altijd op minimaal 0 gehouden.',
                // ── Beveiliging & techniek ──
                'Verbetering: de plugin-updatecontrole accepteert alleen nog updatepakketten van vertrouwde, beveiligde (https) bronnen, en een onbekende/foutieve licentiecontrole weigert voortaan toegang tot premium-functies i.p.v. daar stilzwijgend op terug te vallen.',
                'Verbetering: de updatemelding in het WordPress-dashboard toont nu een eigen plugin-icoon en banner i.p.v. een lege placeholder.',
                'Verbetering: de "WP-Cron uitgeschakeld"-melding voor verlaten-winkelwagen-herinneringen is verplaatst van een losse admin-melding naar een permanent statuskaartje in de installatiecheck, dat ook toont of de laatste herinneringsmail daadwerkelijk is verstuurd.',
            ],
        ],
        [
            'version' => '1.14.31-beta.28',
            'date'    => '2026-09-03',
            'items'   => [
                'Nieuw: Installatie-check op elk hoofdonderdeel (Winkelwagen, Checkout, Account) — waarschuwt direct als een WordPress/WooCommerce-instelling nog moet gebeuren om een functie écht te laten werken (bv. een ontbrekende verzendzone, de "Mijn account"-pagina, of een niet-gekoppelde postcode-/BTW-plugin), met een 1-klik-oplossing waar mogelijk.',
                'Nieuw: WooCommerce\'s eigen account-instellingen (account aanmaken bij checkout/op de accountpagina, wachtwoord/gebruikersnaam automatisch genereren) zijn nu rechtstreeks vanuit de plugin te bewerken, zonder naar WooCommerce → Instellingen te hoeven.',
                'Nieuw: de onboarding-rondleiding neemt nu ook het hele Account-onderdeel mee.',
                'Verbetering: de sleepgreep van de winkelwagen-drawer is visueel vernieuwd (subtieler in rust, duidelijker bij slepen) en volledig met het toetsenbord te bedienen.',
                'Verbetering: het inloggen/"account aanmaken" op checkout gebeurt nu via twee duidelijke tabs i.p.v. losse meldingen, en blijft na het aanvinken zichtbaar bevestigd ook als je de pop-up weer sluit.',
                'Verbetering: de productlijst bij verzendkeuze toont nu altijd alles in één keer, in plaats van af te kappen met een "en X meer"-knop.',
                'Fix: de rechterkolom op checkout (bestelling/BTW) kon na het inloggen ver naar beneden schuiven met een groot leeg vlak erboven.',
                'Fix: reviews van een verwijderde klant blijven nu staan (met geanonimiseerde naam/gegevens) in plaats van een kapotte/verweesde review achter te laten; volledige GDPR-export en -verwijdering uitgebreid naar wishlist, adresboek en meldingen.',
                'Fix: de "Elke"-attributen-fix voor variabele producten (zie 1.14.31-beta.27) werkt nu ook bij "opnieuw bestellen" en bij het overzetten van een wishlist-item naar de winkelwagen.',
                'Verbetering: bezorgdatum-beschikbaarheid en het Account-dashboard laden nu merkbaar sneller door minder databasebelasting.',
                'Verbetering: strengere beveiliging van het update-systeem (alleen vertrouwde downloadbronnen) en de licentiecontrole.',
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
                'Fix: de totalen-tabel op checkout toonde bij een gemengd winkelwagentje (bezorgen + afhalen tegelijk) maar één verzendkosten-rij, met het totaalbedrag van beide pakketten samen achter het verkeerde label. Toont nu per rol een eigen rij ("Afhalen: Gratis" + "Verzendkosten: €4,95" naast elkaar).',
                'Verbetering: de "X van Y pakketten compleet"-voortgangsmelding bij gemengde verzendpakketten is verwijderd.',
                'Verbetering (admin, Styling-tab): laadindicator tijdens het scannen naar kleuren van je website, een kopieerknop per gedetecteerde kleur, en een knop om automatisch een kant-en-klare stijl te genereren op basis van die kleuren.',
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
        [
            'version' => '1.2.1',
            'date'    => '',
            'items'   => [
                'Admin UI verbeteringen.',
                'Scroll-indicator in zijbalk navigatie.',
            ],
        ],
        [
            'version' => '1.0.0',
            'date'    => '',
            'items'   => [
                'Initial release.',
            ],
        ],
    ];
}
