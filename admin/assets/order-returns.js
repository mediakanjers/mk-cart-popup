(function ($) {
    'use strict';

    $(function () {
        var $box = $('#mkcp-order-returns-box');
        if (!$box.length || typeof mkcpOrderReturns === 'undefined') return;

        $box.on('click', '.js-mkcp-order-return-action', function () {
            var $btn = $(this);
            var $row = $btn.closest('.mkcp-order-return-row');
            // H2: deze knoppen zijn kale WP-.button-elementen zonder het svg-
            // spinner-iconensysteem van de rest van de plugin — een tekstwissel
            // is hier het duidelijkste laadsignaal, i.p.v. alleen [disabled]
            // (dat op deze knoppen nauwelijks zichtbaar is).
            var origText = $btn.text();
            $btn.prop('disabled', true).text('Bezig…');

            $.post(mkcpOrderReturns.ajaxUrl, {
                action: 'mkcp_account_admin_return_update',
                nonce:  mkcpOrderReturns.nonce,
                id:     $row.data('return-id'),
                status: $btn.data('status'),
                note:   ''
            }).done(function (res) {
                if (res && res.success) {
                    // Simpelste betrouwbare manier om deze rij (en de "Retour?"-
                    // kolom in de orderlijst bij terugkeer) de nieuwe status te
                    // laten tonen — dezelfde AJAX-actie geeft normaal het hele
                    // plugin-eigen retourenscherm terug, niet bruikbaar hier.
                    window.location.reload();
                } else {
                    $btn.prop('disabled', false).text(origText);
                    window.alert(mkcpOrderReturns.errorText);
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(origText);
                window.alert(mkcpOrderReturns.errorText);
            });
        });
    });

})(jQuery);
