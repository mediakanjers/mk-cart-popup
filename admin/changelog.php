<?php
/**
 * MK Cart Popup — Changelog-data
 *
 * Eén centrale, gestructureerde bron voor alle changelog-entries. Wordt
 * getoond op het Updates-tabblad (admin/views/settings-page.php).
 *
 * Werkwijze: voeg bij elke inhoudelijke wijziging (fix of verbetering) een
 * nieuwe entry toe bovenaan mkcp_changelog_entries(), vóórdat je MKCP_VER
 * bumpt. Puur interne/cache-bustende versiebumps zonder gebruikersrelevante
 * wijziging hoeven geen eigen entry.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function mkcp_changelog_entries() {
    return [
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
