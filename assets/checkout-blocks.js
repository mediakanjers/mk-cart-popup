/* MK Cart Popup — Checkout content-builder veld-blokken
 *
 * De server voegt deze blokken één keer toe via de woocommerce_form_field-
 * filter (zie includes/checkout-frontend.php), als sibling ná de
 * <p class="form-row" id="{key}_field">. De kolom-uitlijning binnen de
 * velden-grid wordt in checkout.scss opgelost (het blok krijgt daar
 * dezelfde grid-column als zijn veld, zie .mkcp-zone-render--field).
 *
 * WooCommerce herbouwt de veldenmarkup soms los van een AJAX-ververing
 * (bv. de land/provincie-afhankelijke veldvolgorde-logica), waarbij ons
 * blok kan sneuvelen of op zijn oude plek achterblijft. Dit script
 * controleert daarom na elke wijziging in de velden-wrapper of het blok
 * nog exact één keer en direct ná het juiste veld staat, en zet het zo
 * nodig terug.
 */
(function ($) {
    'use strict';

    if (typeof mkcpCheckoutBlocks === 'undefined') return;
    var FIELDS = mkcpCheckoutBlocks.fields || {};
    var KEYS   = Object.keys(FIELDS);
    if (!KEYS.length) return;

    function ensureField(fieldKey) {
        var field = document.getElementById(fieldKey + '_field');
        if (!field || !field.parentNode) return;

        var existing = document.querySelectorAll('[data-mkcp-field="' + fieldKey + '"]');

        // Al precies één, direct na het veld: niets te doen (voorkomt een
        // overbodige DOM-mutatie die de MutationObserver hieronder weer
        // zou laten afgaan).
        if (existing.length === 1 && field.nextElementSibling === existing[0]) return;

        existing.forEach(function (el) { el.parentNode && el.parentNode.removeChild(el); });

        var tmp = document.createElement('div');
        tmp.innerHTML = FIELDS[fieldKey];
        var block = tmp.firstElementChild;
        if (!block) return;
        block.setAttribute('data-mkcp-field', fieldKey);
        field.parentNode.insertBefore(block, field.nextSibling);
    }

    function ensureAll() {
        KEYS.forEach(ensureField);
    }

    ensureAll();
    $(document.body).on('updated_checkout country_to_state_changed', ensureAll);

    // Vangnet voor herordeningen die geen van beide events triggeren (bv.
    // WooCommerce's eigen address-i18n veldvolgorde-logica op page load).
    var scheduled = false;
    function scheduleEnsure() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(function () {
            scheduled = false;
            ensureAll();
        });
    }

    KEYS.forEach(function (fieldKey) {
        var field = document.getElementById(fieldKey + '_field');
        var wrapper = field && field.parentNode;
        if (!wrapper || wrapper._mkcpObserved) return;
        wrapper._mkcpObserved = true;
        new MutationObserver(scheduleEnsure).observe(wrapper, { childList: true });
    });

})(jQuery);
