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
