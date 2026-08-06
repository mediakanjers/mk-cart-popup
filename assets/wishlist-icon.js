/**
 * MK Cart Popup — Wishlist hart-icoon (sitebreed)
 *
 * Eén gedelegeerde click-listener voor alle .js-mkcp-wishlist-heart-knoppen,
 * ongeacht waar WooCommerce ze rendert (archief, single product, gerelateerde
 * producten, cross-sell-widget die later via AJAX bijgeladen wordt). Vanilla
 * JS, geen jQuery-afhankelijkheid nodig voor deze ene interactie.
 */
(function () {
    'use strict';

    if (typeof mkcp_wishlist_params === 'undefined') return;

    // Kort de "net gelukt"-pop-animatie tonen en daarna weer opruimen — zonder
    // dit zou de animatie bij de volgende de/activatie niet opnieuw afspelen
    // (een class die al staat, triggert geen CSS-animatie opnieuw).
    function flashSuccess(btn) {
        btn.classList.remove('is-success');
        // Forceer reflow zodat het opnieuw toevoegen van de class ook echt
        // een nieuwe animatie start (anders ziet de browser 'm als ongewijzigd).
        void btn.offsetWidth;
        btn.classList.add('is-success');
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-mkcp-wishlist-heart');
        if (!btn) return;

        // Het hartje staat vaak genest in de product-link (WooCommerce's
        // loop-template wrapt afbeelding+titel in één <a>) — zonder dit stopt
        // de klik niet en navigeert de browser gewoon door naar het product.
        e.preventDefault();
        e.stopPropagation();

        if (btn.classList.contains('is-loading')) return;
        btn.classList.remove('is-error', 'is-success');
        btn.classList.add('is-loading');

        var body = new URLSearchParams();
        body.set('action', 'mkcp_account_wishlist_toggle');
        body.set('nonce', mkcp_wishlist_params.nonce);
        body.set('product_id', btn.getAttribute('data-product-id'));

        fetch(mkcp_wishlist_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    btn.classList.add('is-error');
                    return;
                }
                var active = !!json.data.in_wishlist;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                flashSuccess(btn);
            })
            .catch(function () {
                btn.classList.add('is-error');
            })
            .finally(function () {
                btn.classList.remove('is-loading');
            });
    });

    // Klikken buiten de knop of nogmaals klikken herstart de poging toch al
    // (bovenstaande listener), maar de foutstatus moet ook zonder nieuwe
    // klik weer normaal ogen zodra de gebruiker doorscrolt/wegkijkt en later
    // terugkomt — na de hover-uitleg in de CSS (kruisje -> hartje bij hover)
    // is een expliciete "reset op mouseleave" niet nodig: de is-error-class
    // blijft bewust staan tot een nieuwe klik, dat IS de retry-indicator.
    document.addEventListener('animationend', function (e) {
        if (e.animationName === 'mkcp-heart-pop' && e.target.classList.contains('mkcp-wishlist-heart__icon--heart')) {
            var btn = e.target.closest('.js-mkcp-wishlist-heart');
            if (btn) btn.classList.remove('is-success');
        }
    });

    // ── "Recent bekeken producten" (Dashboard-widget, account-orders.php) ────
    //
    // Bewust client-side (localStorage) i.p.v. een server-side tracking-
    // tabel — geen database-schrijfactie bij elke productweergave nodig
    // (Account-plan, sectie 13: "geen nieuwe server-side tracking-tabel
    // hiervoor nodig"). mkcp_wishlist_params.current_product_id is alleen
    // > 0 op een single product-pagina (zie wishlist-icon.php).
    var RECENT_KEY = 'mkcp_recently_viewed';
    var RECENT_MAX = 12;

    if (mkcp_wishlist_params.current_product_id) {
        try {
            var stored = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
            if (!Array.isArray(stored)) stored = [];
            var id = mkcp_wishlist_params.current_product_id;
            // Al aanwezig? Dan naar voren halen i.p.v. dupliceren.
            stored = stored.filter(function (existingId) { return existingId !== id; });
            stored.unshift(id);
            stored = stored.slice(0, RECENT_MAX);
            localStorage.setItem(RECENT_KEY, JSON.stringify(stored));
        } catch (e) {}
    }
})();
