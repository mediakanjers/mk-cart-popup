(function ($) {
    'use strict';

    $(function () {
        var $btn    = $('#mkcp-pu-ready-btn');
        var $status = $('#mkcp-pu-ready-status');

        if (!$btn.length || typeof mkcpPuReady === 'undefined') return;

        $btn.on('click', function () {
            if (!window.confirm(mkcpPuReady.confirmText)) return;

            var origText = $btn.text();
            $btn.prop('disabled', true).text(mkcpPuReady.sendingText);

            $.post(mkcpPuReady.ajaxUrl, {
                action:   'mkcp_pu_mark_ready',
                order_id: mkcpPuReady.orderId,
                nonce:    mkcpPuReady.nonce
            }).done(function (res) {
                if (res && res.success) {
                    var parts = [];
                    if (res.data.emailSent !== null) parts.push('e-mail: ' + (res.data.emailSent ? '✓' : '✗'));
                    if (res.data.smsSent   !== null) parts.push('sms: '    + (res.data.smsSent   ? '✓' : '✗'));

                    $status.text('Verstuurd op ' + res.data.sentAtHuman + ' door ' + res.data.sentBy + (parts.length ? ' — ' + parts.join(' · ') : ''));
                    $btn.text(mkcpPuReady.resendText);
                } else {
                    $status.text((res && res.data && res.data.message) || mkcpPuReady.errorText);
                    $btn.text(origText);
                }
            }).fail(function () {
                $status.text(mkcpPuReady.errorText);
                $btn.text(origText);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });

})(jQuery);
