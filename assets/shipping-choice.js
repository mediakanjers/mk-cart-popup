/* MK Cart Popup — Ophalen/Bezorgen keuzekaarten
 *
 * De kaarten zelf worden nu volledig server-side gerenderd (zie
 * templates/cart-shipping-choice.php) — deze JS doet alleen nog het
 * uit-/inklappen van een kaart met meerdere opties (bv. 2 afhaallocaties).
 * Een kaart met precies 1 optie heeft de radio al in de <label> zelf zitten,
 * dus die werkt zonder JS. State (welke kaart actief is) komt telkens vers
 * van de server mee via $chosen_method — hier hoeft niets ververst te
 * worden na een AJAX-refresh.
 */
(function ($) {
    'use strict';

    $(document).on('click', '.mkcp-sc-card-wrap > .mkcp-sc-card', function (e) {
        if ($(e.target).is('input')) return; // single-optie kaart: natief radiogedrag

        var $options = $(this).siblings('.mkcp-sc-card-options');
        if (!$options.length) return;

        var $radios = $options.find('input.shipping_method');
        if ($radios.filter(':checked').length) {
            $options.toggleClass('is-open');
            return;
        }

        $radios.first().prop('checked', true).trigger('change');
    });

    /* Laadfeedback tijdens het wisselen van verzendmethode (bv. "Laten
     * bezorgen" ↔ "Zelf afhalen"): WooCommerce's eigen blockUI-overlay dekt
     * alleen #order_review/#payment af, niet de keuzekaarten zelf — de klant
     * ziet dus niets gebeuren terwijl de update_checkout-AJAX-call loopt.
     *
     * De kaarten (.woocommerce-shipping-totals) worden bij elke refresh zelf
     * wholesale vervangen, dus de klasse komt op form.checkout te staan (die
     * blijft altijd bestaan) — de CSS dimt/overlayt via een descendant-
     * selector, ongeacht welk element er op dat moment precies in de DOM zit.
     */
    var scLoadingTimer = null;

    $(document).on('change', 'input.shipping_method', function () {
        $('form.checkout').addClass('mkcp-sc-is-loading');

        // Vangnet: als 'updated_checkout' onverhoopt niet vuurt (bv. een
        // mislukte AJAX-call), blijft de overlay anders voorgoed staan en
        // blokkeert hij (via pointer-events:none) de kaarten.
        clearTimeout(scLoadingTimer);
        scLoadingTimer = setTimeout(function () {
            $('form.checkout').removeClass('mkcp-sc-is-loading');
        }, 8000);
    });

    function mkcp_update_active_card_state() {
        var $checkedRadio = $('input.shipping_method:checked');
        $('.mkcp-sc-card').removeClass('is-active');
        $('.mkcp-sc-card-options').removeClass('is-open');

        if (!$checkedRadio.length) return;

        // Zoek de bovenliggende kaart. Dit kan de directe parent zijn,
        // of de sibling als de radio in een uitklapmenu zit.
        var $card = $checkedRadio.closest('.mkcp-sc-card');
        if ($card.length) {
            $card.addClass('is-active');
        } else {
            var $options = $checkedRadio.closest('.mkcp-sc-card-options');
            if ($options.length) {
                $options.siblings('.mkcp-sc-card').addClass('is-active');
                $options.addClass('is-open');
            }
        }
    }

    $(document).on('updated_checkout', function () {
        clearTimeout(scLoadingTimer);
        $('form.checkout').removeClass('mkcp-sc-is-loading');
        mkcp_update_active_card_state();
    });

    // Voer ook uit bij het laden van de pagina
    mkcp_update_active_card_state();

    /* "en N meer" bij de artikelenlijst boven de kaarten (zie
     * templates/cart-shipping-choice.php) — klap de volledige, ongekorte
     * lijst in/uit binnen dezelfde .mkcp-sc-package-name-regel. Document-
     * gedelegeerd omdat deze regel bij elke AJAX-refresh opnieuw gerenderd
     * wordt (dezelfde reden als de andere handlers hierboven). */
    $(document).on('click', '.js-mkcp-sc-package-more', function () {
        var $wrap = $(this).closest('.mkcp-sc-package-name');
        $wrap.find('.mkcp-sc-package-details-short').prop('hidden', true);
        $wrap.find('.mkcp-sc-package-details-full').prop('hidden', false);
    });

    $(document).on('click', '.js-mkcp-sc-package-less', function () {
        var $wrap = $(this).closest('.mkcp-sc-package-name');
        $wrap.find('.mkcp-sc-package-details-full').prop('hidden', true);
        $wrap.find('.mkcp-sc-package-details-short').prop('hidden', false);
    });

})(jQuery);
