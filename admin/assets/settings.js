(function() {

    // ── Tab navigation ───────────────────────────────────────────────────────

    var navItems  = document.querySelectorAll('.mkcp-nav-item[data-tab]');
    var panels    = document.querySelectorAll('.mkcp-panel[data-panel]');
    var tabInput  = document.getElementById('mkcp-active-tab');
    var adminWrap = document.getElementById('mkcp-admin-wrap');

    function activateTab(tab) {
        navItems.forEach(function(n) { n.classList.toggle('is-active', n.dataset.tab === tab); });
        // Sync footer links (Theme Overrides / Updates / Licentie)
        document.querySelectorAll('.mkcp-docs-link[data-goto]').forEach(function(el) {
            el.classList.toggle('is-active', el.dataset.goto === tab);
        });
        panels.forEach(function(p) {
            var active = p.dataset.panel === tab;
            p.classList.toggle('is-active', active);
            if (active) p.style.animation = 'none', p.offsetHeight, p.style.animation = '';
        });
        if (tabInput) tabInput.value = tab;
        if (adminWrap) adminWrap.setAttribute('data-active-panel', tab);
        var prod = (document.getElementById('mkcp-active-product') || {value:'popup'}).value || 'popup';
        history.replaceState(null, '', '?page=mkcp-settings&tab=' + tab + '&product=' + prod);

        // Skeleton shimmer on newly activated cards
        var activePanel = document.querySelector('.mkcp-panel.is-active');
        if (activePanel) {
            var cards = activePanel.querySelectorAll('.mkcp-dash-card, .mkcp-glass');
            cards.forEach(function(c) { c.classList.add('is-skeleton'); });
            setTimeout(function() { cards.forEach(function(c) { c.classList.remove('is-skeleton'); }); }, 550);
        }
    }

    navItems.forEach(function(n) {
        n.addEventListener('click', function() { activateTab(this.dataset.tab); });
    });

    // Quick-action "goto" buttons on dashboard
    document.querySelectorAll('[data-goto]').forEach(function(btn) {
        btn.addEventListener('click', function(e) { e.preventDefault(); activateTab(this.dataset.goto); });
    });

    // ── Kleurmodus admin-UI (auto/licht/donker) ─────────────────────────────
    //
    // Klasse direct togglen voor instant effect (geen reload nodig — enkel
    // een set CSS-variabelen wisselt); de AJAX-call erna slaat de keuze op
    // in user meta zodat 'ie ook bij de volgende paginalading (server-side
    // via de admin_body_class-filter, zie admin/settings.php) meteen goed
    // staat — dus zonder flits van het verkeerde thema.
    document.querySelectorAll('.mkcp-theme-btn[data-theme]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var theme = this.dataset.theme;
            document.body.classList.remove('mkcp-theme-dark', 'mkcp-theme-light');
            if (theme !== 'auto') document.body.classList.add('mkcp-theme-' + theme);

            document.querySelectorAll('.mkcp-theme-btn[data-theme]').forEach(function(b) {
                b.classList.toggle('is-active', b.dataset.theme === theme);
            });

            if (typeof mkcpAdmin === 'undefined') return;
            var data = new FormData();
            data.append('action', 'mkcp_set_admin_theme');
            data.append('nonce',  mkcpAdmin.themeNonce);
            data.append('theme',  theme);
            fetch(mkcpAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
        });
    });

    // Set initial ambient panel on page load
    (function() {
        var activeNav = document.querySelector('.mkcp-nav-item.is-active');
        if (activeNav && adminWrap) adminWrap.setAttribute('data-active-panel', activeNav.dataset.tab || 'dashboard');
    }());

    // ── Retour-aanvragen (Account → Retouren) ───────────────────────────────
    //
    // Statusfilter herlaadt de pagina met een aangepaste query-string (simpel
    // en robuust — de tabel staat toch al server-side gerenderd, geen reden
    // om dat ene filter apart via AJAX te doen). Goedkeuren/afwijzen/
    // voltooien gaat wél via AJAX: vervangt alleen de tabelkaart, niet de
    // hele pagina, en blijft in hetzelfde filter staan (server geeft het
    // filter terug mee in de herrenderde HTML).
    (function() {
        // Event delegation i.p.v. een losse element-referentie op te slaan —
        // het filterveld wordt na elke Goedkeuren/Afwijzen/Voltooien-actie
        // vervangen (de hele kaart krijgt een nieuwe outerHTML), waardoor een
        // vastgehouden referentie stale zou worden en de listener na de
        // eerste actie niet meer zou vuren.
        document.addEventListener('change', function(e) {
            if (e.target.id !== 'mkcp-return-status-filter') return;
            var url = new URL(window.location.href);
            if (e.target.value) {
                url.searchParams.set('mkcp_return_status', e.target.value);
            } else {
                url.searchParams.delete('mkcp_return_status');
            }
            window.location.href = url.toString();
        });

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-mkcp-return-action');
            if (!btn || typeof mkcpAdmin === 'undefined') return;

            var row = btn.closest('tr[data-return-id]');
            if (!row) return;
            var noteField = row.querySelector('.js-mkcp-return-note');
            var currentFilterEl = document.getElementById('mkcp-return-status-filter');
            var currentFilter = currentFilterEl ? currentFilterEl.value : '';

            btn.disabled = true;
            var data = new FormData();
            data.append('action', 'mkcp_account_admin_return_update');
            data.append('nonce', mkcpAdmin.returnsNonce);
            data.append('id', row.dataset.returnId);
            data.append('status', btn.dataset.status);
            data.append('note', noteField ? noteField.value : '');
            data.append('status_filter', currentFilter);

            fetch(mkcpAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(res) { return res.json(); })
                .then(function(json) {
                    if (json && json.success && json.data && typeof json.data.html === 'string') {
                        var panel = document.querySelector('.mkcp-panel--account-returns .mkcp-glass');
                        if (panel) panel.outerHTML = json.data.html;
                    }
                })
                .catch(function() { btn.disabled = false; });
        });
    }());


    // ── Sub-tabs binnen een panel (bv. Bezorgen / Afhalen) ───────────────────
    // Puur clientside tonen/verbergen — alle velden van beide sub-tabs blijven
    // altijd in de DOM en worden dus samen met de rest van het formulier
    // opgeslagen, ongeacht welke sub-tab actief is bij het klikken op Opslaan.

    document.querySelectorAll('.mkcp-subtabs').forEach(function(group) {
        var buttons = group.querySelectorAll('.mkcp-subtab[data-subtab]');
        var panelsWrap = group.parentElement;
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = this.dataset.subtab;
                buttons.forEach(function(b) { b.classList.toggle('is-active', b === btn); });
                panelsWrap.querySelectorAll(':scope > .mkcp-subpanel[data-subpanel]').forEach(function(p) {
                    p.classList.toggle('is-active', p.dataset.subpanel === target);
                });
            });
        });
    });


    // ── Eigen regels per verzendmethode — rij open/dicht op basis van toggle ──

    document.querySelectorAll('.js-mkcp-rule-row').forEach(function(row) {
        var toggle = row.querySelector('.js-mkcp-rule-toggle');
        if (!toggle) return;
        var sync = function() { row.classList.toggle('is-open', toggle.checked); };
        sync();
        toggle.addEventListener('change', sync);
    });

    // ── Bezorg-tijdsloten per verzendmethode — velden alleen tonen als aan ────

    document.querySelectorAll('.js-mkcp-slot-toggle-wrap').forEach(function(wrap) {
        var toggle = wrap.querySelector('.js-mkcp-slot-toggle');
        var fields = wrap.querySelector('.js-mkcp-slot-fields');
        if (!toggle || !fields) return;
        var sync = function() { fields.classList.toggle('is-visible', toggle.checked); };
        sync();
        toggle.addEventListener('change', sync);
    });

    // ── "Account aanmaken?"-checkbox — toelichtingstekst live aan/uit ────────

    (function() {
        var toggle = document.getElementById('mkcp-createaccount-enabled');
        var row    = document.getElementById('mkcp-createaccount-info-row');
        if (!toggle || !row) return;
        var fields = row.querySelectorAll('input, textarea');
        var sync = function() {
            row.style.opacity = toggle.checked ? '' : '.4';
            row.style.pointerEvents = toggle.checked ? '' : 'none';
            fields.forEach(function(f) { f.disabled = !toggle.checked; });
        };
        toggle.addEventListener('change', sync);
    }());

    // ── "Terugkerende klant?"-inlogformulier — toelichtingstekst live aan/uit ─

    (function() {
        var toggle = document.getElementById('mkcp-login-reminder-enabled');
        var row    = document.getElementById('mkcp-login-reminder-info-row');
        if (!toggle || !row) return;
        var fields = row.querySelectorAll('input, textarea');
        var sync = function() {
            row.style.opacity = toggle.checked ? '' : '.4';
            row.style.pointerEvents = toggle.checked ? '' : 'none';
            fields.forEach(function(f) { f.disabled = !toggle.checked; });
        };
        toggle.addEventListener('change', sync);
    }());


    // ── Product switcher (Cart Popup ↔ Cart Checkout) ────────────────────────

    var productBtns       = document.querySelectorAll('.mkcp-product-btn[data-product]');
    var productInput      = document.getElementById('mkcp-active-product');
    var navWraps = {
        popup:    document.getElementById('mkcp-nav-wrap-popup'),
        checkout: document.getElementById('mkcp-nav-wrap-checkout'),
        account:  document.getElementById('mkcp-nav-wrap-account')
    };
    // Elk product krijgt hier zijn eigen tab-prefix + terugvaltab, zodat een
    // toekomstig vierde product hier maar op één plek toegevoegd hoeft te
    // worden i.p.v. op meerdere losse if/else-takken (zoals dit vóór de
    // Account-tab nog was, met een expliciete "isCheckoutPanel"-check).
    var PRODUCT_DEFAULTS = {
        popup:    { prefix: null,         defaultTab: 'dashboard' },
        checkout: { prefix: 'checkout-',  defaultTab: 'checkout-dashboard' },
        account:  { prefix: 'account-',   defaultTab: 'account-general' }
    };
    var popupSidebar      = document.getElementById('mkcp-popup-sidebar');
    var PRODUCT_KEY       = 'mkcp_active_product';

    function activateProduct(product) {
        if (!PRODUCT_DEFAULTS[product]) product = 'popup';

        productBtns.forEach(function(b) { b.classList.toggle('is-active', b.dataset.product === product); });
        Object.keys(navWraps).forEach(function(key) {
            if (navWraps[key]) navWraps[key].style.display = key === product ? '' : 'none';
        });
        if (popupSidebar) popupSidebar.style.display = product === 'popup' ? '' : 'none';
        if (productInput) productInput.value = product;

        // Switch to correct panel for the newly visible nav — alleen als de
        // huidige panel niet al bij dit product hoort (prefix-check, anders
        // belandt elke nieuwe "checkout-*"/"account-*"-tab hier niet in de
        // lijst en springt een reload na opslaan altijd terug naar het
        // eerste tabblad van dat product).
        var currentPanel = (document.querySelector('.mkcp-panel.is-active') || {}).dataset && document.querySelector('.mkcp-panel.is-active').dataset.panel;
        var belongsToProduct = currentPanel && Object.keys(PRODUCT_DEFAULTS).some(function(key) {
            var prefix = PRODUCT_DEFAULTS[key].prefix;
            var matches = prefix ? currentPanel.indexOf(prefix) === 0 : currentPanel.indexOf('-') === -1;
            return key === product && matches;
        });

        if (!belongsToProduct) {
            activateTab(PRODUCT_DEFAULTS[product].defaultTab);
        }

        try { localStorage.setItem(PRODUCT_KEY, product); } catch(e) {}
    }

    productBtns.forEach(function(b) {
        b.addEventListener('click', function() { activateProduct(this.dataset.product); });
    });

    // Restore product from URL param or localStorage on page load
    (function() {
        var urlProduct = new URLSearchParams(window.location.search).get('product');
        var stored;
        try { stored = localStorage.getItem(PRODUCT_KEY); } catch(e) {}
        var initial = urlProduct || stored || 'popup';
        if (initial !== 'popup') activateProduct(initial);
    }());


    // ── Toggle pulse ─────────────────────────────────────────────────────────

    document.querySelectorAll('.mkcp-toggle input').forEach(function(inp) {
        inp.addEventListener('change', function() {
            var track = this.nextElementSibling;
            if (!track) return;
            track.classList.remove('is-pulsing');
            track.offsetHeight; // reflow to restart animation
            track.classList.add('is-pulsing');
            setTimeout(function() { track.classList.remove('is-pulsing'); }, 600);
        });
    });


    // ── Payment icon toggles ─────────────────────────────────────────────────

    document.querySelectorAll('.mkcp-pay-toggle input').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.closest('.mkcp-pay-toggle').classList.toggle('is-active', this.checked);
        });
    });


    // ── Badge-positie kiezer ─────────────────────────────────────────────────

    document.querySelectorAll('.mkcp-badge-pos-option input[type="radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            // Scoped op de eigen .mkcp-badge-position-grid — er staan meerdere
            // van deze positie-kiezers los naast elkaar op de pagina (hartje-
            // badge, aantal-badge, ...), een ongescoped document-brede query
            // wiste de highlight van de andere kiezer(s) mee.
            var grid = this.closest('.mkcp-badge-position-grid');
            grid.querySelectorAll('.mkcp-badge-pos-option').forEach(function(opt) {
                opt.classList.remove('is-selected');
            });
            this.closest('.mkcp-badge-pos-option').classList.add('is-selected');
        });
    });


    // ── USP rows ─────────────────────────────────────────────────────────────

    var uspIcons = ['shield', 'truck', 'phone', 'star', 'check'];

    function makeUspRow(icon, text) {
        var row = document.createElement('div');
        row.className = 'mkcp-usp-row';

        var sel = document.createElement('select');
        sel.name = 'mkcp_usp_icon[]';
        uspIcons.forEach(function(ic) {
            var opt = document.createElement('option');
            opt.value = ic;
            opt.textContent = ic.charAt(0).toUpperCase() + ic.slice(1);
            if (ic === icon) opt.selected = true;
            sel.appendChild(opt);
        });

        var inp = document.createElement('input');
        inp.type = 'text';
        inp.name = 'mkcp_usp_text[]';
        inp.value = text || '';
        inp.placeholder = 'Voordeel omschrijving…';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'mkcp-usp-remove';
        btn.title = 'Verwijderen';
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        btn.addEventListener('click', function() {
            row.style.opacity = '0';
            row.style.transform = 'translateX(-8px)';
            row.style.transition = 'opacity 200ms, transform 200ms';
            setTimeout(function() { row.remove(); }, 200);
        });

        row.appendChild(sel);
        row.appendChild(inp);
        row.appendChild(btn);
        return row;
    }

    var uspRows = document.getElementById('mkcp-usp-rows');
    if (uspRows) {
        uspRows.addEventListener('click', function(e) {
            var rmBtn = e.target.closest('.mkcp-usp-remove');
            if (rmBtn) {
                var row = rmBtn.closest('.mkcp-usp-row');
                row.style.opacity = '0';
                row.style.transform = 'translateX(-8px)';
                row.style.transition = 'opacity 200ms, transform 200ms';
                setTimeout(function() { row.remove(); }, 200);
            }
        });
    }

    var addUspBtn = document.getElementById('mkcp-add-usp');
    if (addUspBtn) {
        addUspBtn.addEventListener('click', function() {
            var row = makeUspRow('check', '');
            row.style.opacity = '0';
            row.style.transform = 'translateY(6px)';
            uspRows.appendChild(row);
            requestAnimationFrame(function() {
                row.style.transition = 'opacity 250ms, transform 250ms cubic-bezier(0.22,1,0.36,1)';
                row.style.opacity = '1';
                row.style.transform = 'translateY(0)';
            });
            row.querySelector('input').focus();
        });
    }


    // ── Save banner auto-hide ─────────────────────────────────────────────────

    var banner = document.getElementById('mkcp-save-banner');
    if (banner) {
        setTimeout(function() {
            banner.style.transition = 'opacity .5s, transform .5s';
            banner.style.opacity = '0';
            banner.style.transform = 'translateX(-50%) translateY(-8px)';
            setTimeout(function() { banner.remove(); }, 500);
        }, 5000);

        var bannerClose = banner.querySelector('.mkcp-save-banner-close');
        if (bannerClose) {
            bannerClose.addEventListener('click', function() {
                banner.style.transition = 'opacity .25s, transform .25s';
                banner.style.opacity = '0';
                banner.style.transform = 'translateX(-50%) translateY(-6px)';
                setTimeout(function() { banner.remove(); }, 250);
            });
        }
    }


    // ── Form submit: tab sync + micro-feedback (spinner → checkmark → submit) ──

    var form = document.getElementById('mkcp-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            var submitter = e.submitter;
            if (!submitter || !submitter.classList.contains('mkcp-btn--primary')) return;
            if (submitter.dataset.submitting) return; // guard against double-fire

            e.preventDefault();

            // Sync active tab + product to hidden inputs
            if (tabInput) {
                var activeNav = document.querySelector('.mkcp-nav-item.is-active');
                if (activeNav && activeNav.dataset.tab) tabInput.value = activeNav.dataset.tab;
            }
            var productInput = document.getElementById('mkcp-active-product');
            if (productInput) {
                var activeProd = document.querySelector('.mkcp-product-btn.is-active');
                if (activeProd && activeProd.dataset.product) productInput.value = activeProd.dataset.product;
            }

            // Phase 1: spinner
            submitter.dataset.submitting = '1';
            submitter.disabled = true;
            submitter.classList.add('is-loading');
            submitter.innerHTML = '<svg class="mkcp-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15" style="display:inline-block"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg> Opslaan…';

            // Phase 2: checkmark → form submits
            setTimeout(function() {
                submitter.classList.remove('is-loading');
                submitter.classList.add('is-saved');
                submitter.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg> Opgeslagen!';
                setTimeout(function() { form.submit(); }, 550);
            }, 500);
        });
    }


    // ── Stand-alone action buttons (avoids nested <form> inside mkcp-form) ─────

    document.querySelectorAll('[data-post-action]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var confirmMsg = btn.dataset.confirm;
            if (confirmMsg && !window.confirm(confirmMsg)) return;

            var postUrl = (typeof mkcpAdmin !== 'undefined' && mkcpAdmin.adminPostUrl)
                ? mkcpAdmin.adminPostUrl : '/wp-admin/admin-post.php';

            var f = document.createElement('form');
            f.method = 'post';
            f.action = postUrl;
            f.style.display = 'none';

            function hi(name, val) {
                var i = document.createElement('input');
                i.type = 'hidden'; i.name = name; i.value = val;
                f.appendChild(i);
            }

            hi('action',   btn.dataset.postAction);
            hi('_wpnonce', btn.dataset.postNonce);
            hi('_wp_http_referer', window.location.pathname + window.location.search);

            if (btn.dataset.postFields) {
                try {
                    var fields = JSON.parse(btn.dataset.postFields);
                    Object.keys(fields).forEach(function(k) { hi(k, fields[k]); });
                } catch(e) {}
            }

            if (btn.dataset.postWithCheckbox) {
                var cb = document.getElementById(btn.dataset.postWithCheckbox);
                if (cb && cb.checked) { hi(cb.name, cb.value); }
            }

            document.body.appendChild(f);
            f.submit();
        });
    });


    // ── Betaalmethode iconen uploaden (WP Media Library) ─────────────────────

    var payList   = document.getElementById('mkcp-pay-icons-list');
    var addPayBtn = document.getElementById('mkcp-add-pay-icon');

    function makePayRow(url, label) {
        var row = document.createElement('div');
        row.className = 'mkcp-pay-upload-row';

        var handle = document.createElement('span');
        handle.className = 'mkcp-pay-upload-handle';
        handle.title = 'Sleep om te herordenen';
        handle.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>';
        row.appendChild(handle);

        var preview = document.createElement('div');
        preview.className = 'mkcp-pay-upload-preview';
        var img = document.createElement('img');
        img.src = url;
        img.alt = '';
        preview.appendChild(img);

        var urlInput = document.createElement('input');
        urlInput.type = 'hidden';
        urlInput.name = 'mkcp_pay_icon_url[]';
        urlInput.value = url;

        var labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.className = 'mkcp-input';
        labelInput.name = 'mkcp_pay_icon_label[]';
        labelInput.value = label || '';
        labelInput.placeholder = 'Label (bijv. iDEAL)';

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'mkcp-pay-upload-remove';
        removeBtn.title = 'Verwijderen';
        removeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        removeBtn.addEventListener('click', function() {
            row.style.opacity = '0';
            row.style.transform = 'translateX(-8px)';
            row.style.transition = 'opacity 200ms, transform 200ms';
            setTimeout(function() { row.remove(); }, 200);
        });

        row.appendChild(preview);
        row.appendChild(urlInput);
        row.appendChild(labelInput);
        row.appendChild(removeBtn);
        return row;
    }

    if (payList) {
        payList.addEventListener('click', function(e) {
            var rmBtn = e.target.closest('.mkcp-pay-upload-remove');
            if (rmBtn) {
                var row = rmBtn.closest('.mkcp-pay-upload-row');
                row.style.opacity = '0';
                row.style.transform = 'translateX(-8px)';
                row.style.transition = 'opacity 200ms, transform 200ms';
                setTimeout(function() { row.remove(); }, 200);
            }
        });

        // ── Sleep-en-neerzetten om de volgorde van de betaalicoontjes aan te passen ──
        // Rijen zijn alleen "draggable" zolang je het handvat ingedrukt houdt, zodat
        // tekst selecteren/bewerken in het labelveld gewoon blijft werken.
        var dragRow = null;

        payList.addEventListener('mousedown', function(e) {
            var handle = e.target.closest('.mkcp-pay-upload-handle');
            if (!handle) return;
            var row = handle.closest('.mkcp-pay-upload-row');
            if (row) row.draggable = true;
        });

        payList.addEventListener('mouseup', function() {
            payList.querySelectorAll('.mkcp-pay-upload-row[draggable="true"]').forEach(function(r) {
                r.draggable = false;
            });
        });

        payList.addEventListener('dragstart', function(e) {
            var row = e.target.closest('.mkcp-pay-upload-row');
            if (!row) return;
            dragRow = row;
            row.classList.add('is-dragging');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
            }
        });

        payList.addEventListener('dragend', function() {
            if (dragRow) {
                dragRow.classList.remove('is-dragging');
                dragRow.draggable = false;
            }
            dragRow = null;
        });

        payList.addEventListener('dragover', function(e) {
            if (!dragRow) return;
            e.preventDefault();
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';

            var overRow = e.target.closest('.mkcp-pay-upload-row');
            if (!overRow || overRow === dragRow) return;

            var rect   = overRow.getBoundingClientRect();
            var before = ( e.clientY - rect.top ) < ( rect.height / 2 );
            payList.insertBefore(dragRow, before ? overRow : overRow.nextSibling);
        });

        payList.addEventListener('drop', function(e) {
            e.preventDefault();
        });
    }

    if (addPayBtn) {
        var mediaFrame = null;

        addPayBtn.addEventListener('click', function() {
            if (typeof wp === 'undefined' || !wp.media) {
                console.error('MKCP: wp.media niet beschikbaar');
                return;
            }

            if (!mediaFrame) {
                mediaFrame = wp.media({
                    title   : 'Betaalicoon selecteren',
                    button  : { text: 'Selecteren' },
                    multiple: true
                });

                mediaFrame.on('select', function() {
                    mediaFrame.state().get('selection').each(function(attachment) {
                        var attrs = attachment.toJSON();
                        var url   = attrs.url || '';
                        var label = attrs.title || '';
                        if (!url) return;

                        var row = makePayRow(url, label);
                        row.style.opacity = '0';
                        row.style.transform = 'translateY(6px)';
                        if (payList) {
                            payList.appendChild(row);
                            requestAnimationFrame(function() {
                                row.style.transition = 'opacity 250ms, transform 250ms cubic-bezier(0.22,1,0.36,1)';
                                row.style.opacity = '1';
                                row.style.transform = 'translateY(0)';
                            });
                        }
                    });
                });
            }

            mediaFrame.open();
        });
    }


    // ── Licentie: toon/verberg sleutel ───────────────────────────────────────

    var toggleKeyBtn = document.getElementById('mkcp-toggle-key');
    var keyInput     = document.getElementById('mkcp_license_key');
    if (toggleKeyBtn && keyInput) {
        toggleKeyBtn.addEventListener('click', function() {
            keyInput.type = keyInput.type === 'password' ? 'text' : 'password';
        });
    }


    // ── Licentie: verifieer via AJAX ─────────────────────────────────────────

    var verifyBtn     = document.getElementById('mkcp-verify-license');
    var licenseResult = document.getElementById('mkcp-license-result');

    if (verifyBtn && typeof mkcpAdmin !== 'undefined') {
        verifyBtn.addEventListener('click', function() {
            var key = keyInput ? keyInput.value.trim() : '';
            verifyBtn.disabled = true;
            verifyBtn.classList.add('is-loading');
            var origHtml = verifyBtn.innerHTML;
            verifyBtn.innerHTML = '<svg class="mkcp-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg> Verifiëren…';

            var data = new FormData();
            data.append('action', 'mkcp_verify_license');
            data.append('nonce',  mkcpAdmin.licenseNonce);
            data.append('key',    key);

            fetch(mkcpAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    verifyBtn.disabled = false;
                    verifyBtn.classList.remove('is-loading');
                    verifyBtn.innerHTML = origHtml;

                    if (!licenseResult) return;
                    licenseResult.style.display = 'block';

                    var d = res.success ? res.data : {};
                    var valid = d.valid || false;
                    var tier  = d.tier  || 'none';
                    var msg   = d.message || (res.success ? 'Verificatie geslaagd.' : 'Verificatie mislukt.');

                    var tierColors = { none: '#e74c3c', basic: '#27ae60', premium: '#5d6bf8' };
                    var tierLabels = { none: 'Niet actief', basic: 'Basic', premium: 'Premium' };
                    var color = tierColors[tier] || '#888';
                    var label = tierLabels[tier] || tier;

                    var icon = valid
                        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>'
                        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

                    licenseResult.innerHTML =
                        '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:6px;background:' +
                        (valid ? '#f0fdf4' : '#fff5f5') + ';border:1px solid ' + (valid ? '#bbf7d0' : '#fca5a5') + ';color:' + color + '">' +
                        icon + '<span>' + msg + '</span>' +
                        (valid ? '<span style="margin-left:auto;font-size:11px;font-weight:700;background:' + color + ';color:#fff;padding:1px 8px;border-radius:20px">' + label + '</span>' : '') +
                        '</div>';

                    if (valid) setTimeout(function() { location.reload(); }, 1500);
                })
                .catch(function() {
                    verifyBtn.disabled = false;
                    verifyBtn.classList.remove('is-loading');
                    verifyBtn.innerHTML = origHtml;
                    if (licenseResult) {
                        licenseResult.style.display = 'block';
                        licenseResult.innerHTML = '<div style="padding:10px 14px;border-radius:6px;background:#fff5f5;border:1px solid #fca5a5;color:#e74c3c">Verbinding mislukt. Controleer je internetverbinding.</div>';
                    }
                });
        });
    }


    // ── Testmails (verlaten winkelwagen / winkelmand delen) ─────────────────────

    var mkcpTestEmailIconOk =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    var mkcpTestEmailIconErr =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

    document.querySelectorAll('.js-mkcp-send-test-email').forEach(function(btn) {
        var row      = btn.closest('.mkcp-setting-control');
        var input    = row ? row.querySelector('.js-mkcp-test-email-input') : null;
        var result   = row ? row.querySelector('.js-mkcp-test-email-result') : null;
        var action   = btn.dataset.testEmailAction;
        var valueKey = btn.dataset.testValueKey || 'email';

        if (!input || !result || !action || typeof mkcpAdmin === 'undefined') return;

        function showResult(ok, message) {
            result.className = 'js-mkcp-test-email-result mkcp-test-email-result is-visible ' + (ok ? 'is-success' : 'is-error');
            result.innerHTML = (ok ? mkcpTestEmailIconOk : mkcpTestEmailIconErr) + '<span>' + message + '</span>';
        }

        btn.addEventListener('click', function() {
            var value = input.value.trim();
            if (!value) {
                showResult(false, valueKey === 'phone' ? 'Vul eerst een telefoonnummer in.' : 'Vul eerst een e-mailadres in.');
                return;
            }

            btn.disabled = true;
            btn.classList.add('is-loading');
            var origHtml = btn.innerHTML;
            btn.innerHTML = '<svg class="mkcp-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg> Versturen…';

            var data = new FormData();
            data.append('action', action);
            data.append('nonce',  mkcpAdmin.testEmailNonce);
            data.append(valueKey, value);

            fetch(mkcpAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.innerHTML = origHtml;
                    showResult(!!res.success, (res.data && res.data.message) || (res.success ? 'Testmail verzonden.' : 'Versturen mislukt.'));
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.innerHTML = origHtml;
                    showResult(false, 'Verbinding mislukt. Controleer je internetverbinding.');
                });
        });
    });


    // ── License tier enforcement ──────────────────────────────────────────────

    (function() {
        var tier = (typeof mkcpAdmin !== 'undefined' && mkcpAdmin.licenseTier) ? mkcpAdmin.licenseTier : 'none';
        if (tier === 'premium') return;

        var lockSvg =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
            'stroke-linecap="round" stroke-linejoin="round" width="22" height="22">' +
            '<rect x="3" y="11" width="18" height="11" rx="2"/>' +
            '<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

        function lockElement(el) {
            el.classList.add('mkcp-tier-locked');
            el.querySelectorAll('input, select, textarea, button').forEach(function(ctrl) {
                ctrl.disabled = true;
            });
            var overlay = document.createElement('div');
            overlay.className = 'mkcp-tier-lock-overlay';
            overlay.innerHTML =
                '<div class="mkcp-tier-lock-content">' +
                lockSvg +
                '<span>Premium feature</span>' +
                '<button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="licentie" ' +
                'style="font-size:11px;padding:4px 12px;pointer-events:all">Upgraden naar Premium →</button>' +
                '</div>';
            el.appendChild(overlay);
        }

        if (tier === 'none') {
            // No license — show banner and disable all form controls (settings page only)
            var mainArea = document.getElementById('mkcp-form') ? document.querySelector('.mkcp-main') : null;
            if (mainArea) {
                var noLicBanner = document.createElement('div');
                noLicBanner.className = 'mkcp-no-license-banner';
                noLicBanner.innerHTML =
                    lockSvg +
                    '<span><strong>Geen geldige licentie</strong> — Voer een licentiesleutel in om instellingen op te slaan. ' +
                    '<button type="button" data-goto="licentie" style="background:none;border:none;cursor:pointer;color:var(--mkcp-ui-accent);text-decoration:underline;padding:0;font:inherit">Sleutel invoeren →</button></span>';
                mainArea.insertBefore(noLicBanner, mainArea.firstChild);
            }
            var theForm = document.getElementById('mkcp-form');
            if (theForm) {
                // Exclude the license key field, its helper buttons, and checkout controls.
                // Checkout settings are always saveable regardless of license tier (PHP enforces this).
                var licenseExcludes = ['mkcp_license_key', 'mkcp-toggle-key', 'mkcp-verify-license'];
                var checkoutNames   = ['mkcp_checkout_enabled', 'mkcp_checkout_header_enabled', 'mkcp_checkout_header_bg',
                                       'mkcp_checkout_header_logo_id', 'mkcp_checkout_footer_enabled'];
                var checkoutIds     = ['mkcp-checkout-logo-upload', 'mkcp-checkout-logo-remove',
                                       'mkcp-checkout-logo-id', 'mkcp-checkout-logo-preview',
                                       'mkcp-footer-block-list', 'mkcp-footer-blocks-json'];
                theForm.querySelectorAll('input:not([name="mkcp_active_tab"]):not([name="mkcp_save_nonce"]):not([type="hidden"]), select, textarea').forEach(function(ctrl) {
                    if (licenseExcludes.indexOf(ctrl.name) !== -1 || licenseExcludes.indexOf(ctrl.id) !== -1) return;
                    if (checkoutNames.indexOf(ctrl.name) !== -1 || checkoutIds.indexOf(ctrl.id) !== -1) return;
                    ctrl.disabled = true;
                });
                // Do NOT disable submit buttons — checkout settings must always be saveable.
                // PHP correctly gates popup settings behind the license check.
            }
        } else if (tier === 'basic') {
            // Basic license — lock only premium-marked elements
            document.querySelectorAll('[data-mkcp-tier="premium"]').forEach(lockElement);
        }
    }());


    // ── Nav scroll-fade indicator ────────────────────────────────────────────
    (function() {
        document.querySelectorAll('.mkcp-nav').forEach(function(nav) {
            var wrap = nav.closest('.mkcp-nav-wrap');
            if (!wrap) return;

            var arrow = null;

            function update() {
                var needsScroll = nav.scrollHeight > nav.clientHeight + 4;
                var atBottom    = !needsScroll || nav.scrollTop + nav.clientHeight >= nav.scrollHeight - 4;

                nav.classList.toggle('is-at-bottom', atBottom);

                if (needsScroll && !arrow) {
                    arrow = document.createElement('div');
                    arrow.className = 'mkcp-nav-scroll-arrow';
                    arrow.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
                    wrap.appendChild(arrow);
                }

                if (arrow) arrow.classList.toggle('is-hidden', atBottom);
            }

            nav.addEventListener('scroll', update, { passive: true });
            if (window.ResizeObserver) {
                new ResizeObserver(update).observe(nav);
            } else {
                update();
            }
        });
    }());


    // ── Styling: kleuren + breedte → live preview, contrastcheck, reset ────────

    (function() {
        var previewFrame  = document.getElementById('mkcp-preview-frame');
        var colorInputs   = document.querySelectorAll('.js-mkcp-style-color');
        var widthInput    = document.getElementById('mkcp_style_width');
        var positionInput = document.getElementById('mkcp_style_position');
        var btnStyleInput = document.getElementById('mkcp_style_btn_style');
        var resetBtn      = document.getElementById('mkcp-style-reset');
        var contrastBox   = document.getElementById('mkcp-style-contrast-warning');

        if (!colorInputs.length) return;

        // Eén kleurveld stuurt soms meerdere CSS-variabelen tegelijk aan.
        // Twee redenen:
        // 1) losstaande defaults die toevallig hetzelfde zijn (Hoofdkleur →
        //    zowel --mkcp-accent als --mkcp-primary, geen var()-alias van
        //    elkaar in de SCSS);
        // 2) var()-kettingen zoals --mkcp-btn-p-bg: var(--mkcp-accent). Zo'n
        //    kketen wordt in de browser maar ÉÉN keer opgelost, namelijk op
        //    :root zelf (waar 'ie gedefinieerd staat) — een override die we
        //    via JS op een kind-element (#mkcp-preview-frame) zetten, komt
        //    dus nooit door in --mkcp-btn-p-bg zolang die zelf niet ook
        //    expliciet hier meegegeven wordt. Vandaar dat elke variabele die
        //    ELDERS in de SCSS via var() naar een van onze 6 velden verwijst,
        //    ook los in onderstaande lijst staat.
        // style_accent en style_btn_text sturen de knop niet rechtstreeks aan —
        // die gaan via applyButtonStyle() hieronder, want de uitkomst hangt ook
        // af van de outline/gevuld-keuze (zie mkcp_style_inline_css() in
        // config.php voor dezelfde logica server-side).
        var VAR_MAP = {
            style_accent  : [ '--mkcp-accent', '--mkcp-primary' ],
            style_bg      : [ '--mkcp-bg' ],
            style_text    : [ '--mkcp-text', '--mkcp-btn-s-text' ],
            style_border  : [ '--mkcp-light1', '--mkcp-light2', '--mkcp-btn-s-border' ],
            style_danger  : [ '--mkcp-danger' ]
        };

        function applyToPreview(cssVar, value) {
            if (previewFrame) previewFrame.style.setProperty(cssVar, value);
        }

        // cart-popup.scss kent naast --mkcp-text nog --mkcp-dark (iets
        // nadrukkelijkere tekst), --mkcp-text-light (gedempte tekst) en
        // --mkcp-light (hover-achtergrond) — geen losse velden, maar afgeleid
        // via dezelfde color-mix()-uitdrukking als mkcp_style_inline_css() in
        // config.php, zodat de preview ook op donkere presets leesbaar blijft
        // i.p.v. op de light-mode-defaults (#333/#888/#f5f5f5) te blijven hangen.
        function applyDerivedColors() {
            var textInput   = document.getElementById('mkcp_style_text');
            var bgInput     = document.getElementById('mkcp_style_bg');
            var accentInput = document.getElementById('mkcp_style_accent');
            var text   = textInput   ? textInput.value   : '#1a1a1a';
            var bg     = bgInput     ? bgInput.value     : '#ffffff';
            var accent = accentInput ? accentInput.value : '#2e7d32';

            applyToPreview('--mkcp-dark', text);
            applyToPreview('--mkcp-text-light', 'color-mix(in srgb, ' + text + ' 55%, ' + bg + ' 45%)');
            applyToPreview('--mkcp-light', 'color-mix(in srgb, ' + text + ' 8%, ' + bg + ' 92%)');
            applyToPreview('--mkcp-progress-bg', 'color-mix(in srgb, ' + accent + ' 15%, ' + bg + ' 85%)');
        }

        // Laat de Styling-kaarten zelf ook zacht meekleuren met de gekozen
        // Hoofdkleur (een dun gekleurd randje + gloed), zodat het tabblad
        // reageert op je keuzes i.p.v. alleen de preview-sidebar.
        var glowCards = document.querySelectorAll('.mkcp-style-glow-card');
        function applyPanelGlow() {
            var accentInput = document.getElementById('mkcp_style_accent');
            var accent = accentInput ? accentInput.value : '#2e7d32';
            var glow = 'color-mix(in srgb, ' + accent + ' 22%, transparent)';
            glowCards.forEach(function(card) {
                card.style.setProperty('--mkcp-style-glow', glow);
            });
        }

        function applyButtonStyle() {
            var accentInput  = document.getElementById('mkcp_style_accent');
            var btnTextInput = document.getElementById('mkcp_style_btn_text');
            var accent  = accentInput  ? accentInput.value  : '#2e7d32';
            var btnText = btnTextInput ? btnTextInput.value : '#ffffff';
            var outline = btnStyleInput && btnStyleInput.value === 'outline';

            applyToPreview('--mkcp-btn-p-bg', outline ? 'transparent' : accent);
            applyToPreview('--mkcp-btn-p-text', outline ? accent : btnText);
            applyToPreview('--mkcp-btn-p-border', outline ? '2px solid ' + accent : 'none');
        }

        // WCAG 2.x contrastberekening (relative luminance) — zelfde formule
        // als de bekende contrastcheckers, geen externe library nodig.
        function hexToRgb(hex) {
            hex = (hex || '').replace('#', '');
            if (hex.length === 3) hex = hex.split('').map(function(c) { return c + c; }).join('');
            var num = parseInt(hex, 16) || 0;
            return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 };
        }

        function relativeLuminance(rgb) {
            var chan = [rgb.r, rgb.g, rgb.b].map(function(c) {
                c = c / 255;
                return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * chan[0] + 0.7152 * chan[1] + 0.0722 * chan[2];
        }

        function contrastRatio(hexA, hexB) {
            var lumA    = relativeLuminance(hexToRgb(hexA));
            var lumB    = relativeLuminance(hexToRgb(hexB));
            var lighter = Math.max(lumA, lumB);
            var darker  = Math.min(lumA, lumB);
            return (lighter + 0.05) / (darker + 0.05);
        }

        function checkContrast() {
            var accentInput  = document.getElementById('mkcp_style_accent');
            var btnTextInput = document.getElementById('mkcp_style_btn_text');
            var bgInput      = document.getElementById('mkcp_style_bg');
            if (!contrastBox || !accentInput || !btnTextInput || !bgInput) return;

            // In outline-modus staat de knoptekst in de hoofdkleur óp de
            // drawer-achtergrond (niet op een gevulde knop) — dan is
            // hoofdkleur-t.o.v.-achtergrond de relevante combinatie, niet
            // hoofdkleur-t.o.v.-knoptekstkleur. Zelfde onderscheid als
            // mkcp_style_inline_css() in config.php.
            var outline = btnStyleInput && btnStyleInput.value === 'outline';
            var fg = outline ? accentInput.value : btnTextInput.value;
            var bg = outline ? bgInput.value : accentInput.value;

            var ratio   = contrastRatio( fg, bg );
            var passAA  = ratio >= 4.5;
            var passAAA = ratio >= 7;

            contrastBox.innerHTML =
                '<span class="mkcp-contrast-ratio">' + ratio.toFixed(1) + ':1</span>' +
                '<span class="mkcp-contrast-badge ' + (passAA  ? 'is-pass' : 'is-fail') + '">AA</span>' +
                '<span class="mkcp-contrast-badge ' + (passAAA ? 'is-pass' : 'is-fail') + '">AAA</span>' +
                '<span class="mkcp-contrast-hint">' + (passAA
                    ? 'Knoptekst is goed leesbaar op de knop.'
                    : 'Laag contrast — knoptekst kan moeilijk leesbaar worden op de knop.') + '</span>';
        }

        colorInputs.forEach(function(input) {
            input.addEventListener('input', function() {
                (VAR_MAP[input.dataset.styleVar] || []).forEach(function(cssVar) {
                    applyToPreview(cssVar, input.value);
                });
                applyDerivedColors();
                applyButtonStyle();
                applyPanelGlow();
                checkContrast();
                markActivePreset();
            });
        });

        // ── Hex-tekstveld naast elke swatch ──────────────────────────────────
        //
        // Schrijft terug naar het echte <input type="color"> en speelt daar een
        // 'input'-event op af — hergebruikt zo alles hierboven (live preview,
        // contrastcheck, actieve-preset-highlight) i.p.v. het te dupliceren.
        function normalizeHex(raw) {
            var v = (raw || '').trim();
            if (v.charAt(0) !== '#') v = '#' + v;
            if (/^#[0-9a-fA-F]{6}$/.test(v)) return v.toLowerCase();
            if (/^#[0-9a-fA-F]{3}$/.test(v)) {
                return '#' + v.slice(1).split('').map(function(c) { return c + c; }).join('').toLowerCase();
            }
            return null;
        }

        colorInputs.forEach(function(input) {
            var hexField = document.querySelector('.js-mkcp-style-hex[data-for="' + input.id + '"]');
            if (!hexField) return;

            input.addEventListener('input', function() {
                hexField.value = input.value;
            });

            hexField.addEventListener('input', function() {
                var normalized = normalizeHex(hexField.value);
                if (!normalized) return;
                input.value = normalized;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });

            hexField.addEventListener('blur', function() {
                hexField.value = input.value;
            });
        });

        // ── Kopieer-knop ──────────────────────────────────────────────────────
        document.querySelectorAll('.js-mkcp-style-copy').forEach(function(btn) {
            var input = document.getElementById(btn.dataset.for);
            if (!input) return;
            btn.addEventListener('click', function() {
                if (!navigator.clipboard) return;
                navigator.clipboard.writeText(input.value).then(function() {
                    var original = btn.innerHTML;
                    btn.classList.add('is-copied');
                    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                    setTimeout(function() {
                        btn.classList.remove('is-copied');
                        btn.innerHTML = original;
                    }, 1200);
                });
            });
        });

        // ── Eyedropper ────────────────────────────────────────────────────────
        //
        // window.EyeDropper is een native browser-API (Chrome/Edge) waarmee je
        // een kleur van ÉÉN willekeurige pixel op je hele scherm kan overnemen —
        // niet alleen uit een kleurenwiel. Knop wordt verborgen op browsers
        // zonder ondersteuning (bv. Firefox/Safari) i.p.v. een kapotte knop te
        // tonen.
        if (typeof window.EyeDropper === 'function') {
            document.querySelectorAll('.js-mkcp-style-eyedrop').forEach(function(btn) {
                var input = document.getElementById(btn.dataset.for);
                if (!input) return;
                btn.addEventListener('click', function() {
                    new window.EyeDropper().open().then(function(result) {
                        input.value = result.sRGBHex;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }).catch(function() { /* gebruiker annuleerde — niets doen */ });
                });
            });
        } else {
            document.querySelectorAll('.js-mkcp-style-eyedrop').forEach(function(btn) {
                btn.hidden = true;
            });
        }

        if (widthInput) {
            widthInput.addEventListener('input', function() {
                var px = parseInt(widthInput.value, 10) || 500;
                applyToPreview('--mkcp-width', px + 'px');
            });
        }

        if (btnStyleInput) {
            btnStyleInput.addEventListener('change', function() {
                applyButtonStyle();
                checkContrast();
                markActivePreset();
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                colorInputs.forEach(function(input) {
                    input.value = input.dataset.default;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
                if (widthInput) {
                    widthInput.value = widthInput.dataset.default;
                    widthInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (btnStyleInput) {
                    btnStyleInput.value = btnStyleInput.dataset.default;
                    btnStyleInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (positionInput) {
                    positionInput.value = positionInput.dataset.default;
                }
            });
        }

        // ── Kant-en-klare presets ────────────────────────────────────────────
        //
        // Vult gewoon de bestaande velden en speelt hun 'input'/'change'-events
        // af — hergebruikt zo alle logica hierboven (live preview, contrastcheck)
        // zonder die te dupliceren. Na toepassen is een preset niet meer dan een
        // startpunt: de normale style_*-velden, verder los aan te passen.
        var PRESET_FIELD_MAP = {
            accent : 'mkcp_style_accent',
            bg     : 'mkcp_style_bg',
            text   : 'mkcp_style_text',
            btnText: 'mkcp_style_btn_text',
            border : 'mkcp_style_border',
            danger : 'mkcp_style_danger'
        };

        function setFieldValue(id, value) {
            var el = document.getElementById(id);
            if (!el || !value) return;
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Fris opgevraagd i.p.v. een eenmalige snapshot bij het laden van de
        // pagina — "Genereer stijl uit deze kleuren" hieronder voegt later
        // dynamisch een extra preset-kaart toe, die dan ook meegenomen moet
        // worden bij het markeren van de actieve preset.
        function markActivePreset() {
            document.querySelectorAll('.js-mkcp-style-preset').forEach(function(btn) {
                var matches = Object.keys(PRESET_FIELD_MAP).every(function(key) {
                    var el = document.getElementById(PRESET_FIELD_MAP[key]);
                    return el && el.value.toLowerCase() === (btn.dataset[key] || '').toLowerCase();
                });
                if (matches && btnStyleInput) matches = btnStyleInput.value === btn.dataset.btnStyle;
                btn.classList.toggle('is-active', matches);
            });
        }

        function applyPresetButton(btn) {
            Object.keys(PRESET_FIELD_MAP).forEach(function(key) {
                setFieldValue(PRESET_FIELD_MAP[key], btn.dataset[key]);
            });
            if (btnStyleInput && btn.dataset.btnStyle) {
                btnStyleInput.value = btn.dataset.btnStyle;
                btnStyleInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            markActivePreset();

            // Korte bevestigingspuls zodat een klik voelt alsof er iets
            // gebeurt, i.p.v. een instant, onopgemerkte kleursprong.
            btn.classList.remove('is-applying');
            void btn.offsetWidth; // forceer reflow zodat de animatie opnieuw start bij snel herhaald klikken
            btn.classList.add('is-applying');
        }

        // Gedelegeerd (i.p.v. per-knop listeners) zodat een later dynamisch
        // toegevoegde preset-kaart (zie "Genereer stijl uit deze kleuren"
        // hieronder) zonder aparte wiring gewoon klikbaar is.
        var presetList = document.querySelector('.mkcp-style-preset-list');
        if (presetList) {
            presetList.addEventListener('click', function(e) {
                var btn = e.target.closest('.js-mkcp-style-preset');
                if (btn) applyPresetButton(btn);
            });
            presetList.addEventListener('animationend', function(e) {
                if (e.target.classList && e.target.classList.contains('js-mkcp-style-preset')) {
                    e.target.classList.remove('is-applying');
                }
            });
        }

        // ── Gedetecteerde kleuren van de website ────────────────────────────
        //
        // Tier 1 (server-side, mkcp_detect_theme_colors() in config.php): bij
        // moderne blok-thema's met theme.json al gecategoriseerd aangeleverd
        // als PHP-knoppen hieronder — "Toepassen" vult direct het bijbehorende
        // veld, zelfde patroon als de presets hierboven.
        document.querySelectorAll('.js-mkcp-detected-apply').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setFieldValue(btn.dataset.field, btn.dataset.color);
                btn.classList.remove('is-applying');
                void btn.offsetWidth;
                btn.classList.add('is-applying');
            });
            btn.addEventListener('animationend', function() { btn.classList.remove('is-applying'); });
        });

        // Losse kopieer-knop bovenop elke tier-1-swatch — naast "Toepassen"
        // (klik op de swatch zelf) kan de hexcode ook los gekopieerd worden,
        // bv. om 'm ergens anders te plakken zonder 'm meteen als stijlveld
        // toe te passen. stopPropagation: zit ALS kind in de "Toepassen"-knop
        // (geen geneste <button>'s toegestaan, vandaar een span met role="button"),
        // dus zonder stopPropagation zou een klik hier ook de apply-click van
        // de omvattende knop triggeren.
        document.querySelectorAll('.js-mkcp-detected-copy-inline').forEach(function(el) {
            function doCopy(e) {
                e.stopPropagation();
                if (!navigator.clipboard) return;
                var hex = el.dataset.color;
                navigator.clipboard.writeText(hex).then(function() {
                    el.classList.add('is-copied');
                    setTimeout(function() { el.classList.remove('is-copied'); }, 1200);
                });
            }
            el.addEventListener('click', doCopy);
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doCopy(e); }
            });
        });

        // Tier 2 (client-side fallback voor klassieke thema's zónder
        // theme.json): een verborgen iframe van de eigen site laden en de
        // computed styles van knoppen/links/header aftasten. Kan niet
        // categoriseren (geen bron zegt welke kleur "de hoofdkleur" is) — dus
        // alleen kopieerbaar, geen "Toepassen". Vindt de live-scan niks
        // bruikbaars, dan blijft de kaart gewoon verborgen (tier 3: niks tonen).
        //
        // Dit laadt de VOLLEDIGE homepage (afbeeldingen, trackers, alles) —
        // te zwaar om bij elk paginabezoek te doen. Daarom: (1) alleen
        // starten als de Styling-tab ook echt actief wordt, niet bij elk
        // tabblad, en (2) resultaat 30 dagen cachen in localStorage zodat
        // herhaalde bezoeken aan de tab niet telkens opnieuw scannen.
        var CACHE_KEY = 'mkcp_detected_colors_v1';
        var CACHE_MAX_AGE = 30 * 24 * 60 * 60 * 1000;

        function readCache() {
            try {
                var raw = window.localStorage.getItem(CACHE_KEY);
                if (!raw) return null;
                var data = JSON.parse(raw);
                if (!data || typeof data.ts !== 'number' || !Array.isArray(data.colors)) return null;
                if (Date.now() - data.ts > CACHE_MAX_AGE) return null;
                return data.colors;
            } catch (e) {
                return null;
            }
        }
        function writeCache(colors) {
            try { window.localStorage.setItem(CACHE_KEY, JSON.stringify({ colors: colors, ts: Date.now() })); }
            catch (e) { /* localStorage niet beschikbaar (privémodus e.d.) — negeren */ }
        }

        function makeFlatSwatch(hex) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'mkcp-detected-swatch mkcp-detected-swatch--flat js-mkcp-detected-copy';
            b.dataset.color = hex;
            b.innerHTML = '<span class="mkcp-detected-swatch-color" style="background:' + hex + '"></span>'
                + '<span class="mkcp-detected-swatch-info"><small>' + hex.toUpperCase() + '</small></span>';
            b.addEventListener('click', function() {
                if (!navigator.clipboard) return;
                navigator.clipboard.writeText(hex).then(function() {
                    b.classList.add('is-copied');
                    setTimeout(function() { b.classList.remove('is-copied'); }, 1200);
                });
            });
            return b;
        }

        function showColors(card, flatWrap, colors) {
            flatWrap.innerHTML = '';
            colors.forEach(function(hex) { flatWrap.appendChild(makeFlatSwatch(hex)); });
            flatWrap.style.display = '';
            card.style.display = '';
            var rescan = document.getElementById('mkcp-detected-rescan');
            if (rescan) rescan.style.display = '';
            refreshGenerateStyleVisibility();
        }

        // Admin-feedback: "opeens staan de resultaten er" — de live scan (tier 2)
        // kan tot 15s duren zonder dat er iets in beeld verandert. Toont daarom
        // een zoek-indicator zowel bij de allereerste scanronde als bij een
        // handmatige "Opnieuw scannen"-klik, en zet de rescan-knop in dezelfde
        // spinner-staat als de andere async-knoppen in dit bestand (Opslaan,
        // Verifiëren, Versturen).
        function setDetectedLoading(card, loading) {
            var loadingEl = document.getElementById('mkcp-detected-loading');
            var rescan    = document.getElementById('mkcp-detected-rescan');
            if (loading) {
                card.style.display = '';
                if (loadingEl) loadingEl.style.display = 'flex';
                if (rescan) {
                    if (!rescan.dataset.origHtml) rescan.dataset.origHtml = rescan.innerHTML;
                    rescan.disabled = true;
                    rescan.classList.add('is-loading');
                    rescan.innerHTML = '<svg class="mkcp-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg> Zoeken…';
                }
            } else {
                if (loadingEl) loadingEl.style.display = 'none';
                if (rescan) {
                    rescan.disabled = false;
                    rescan.classList.remove('is-loading');
                    if (rescan.dataset.origHtml) rescan.innerHTML = rescan.dataset.origHtml;
                }
            }
        }

        function runLiveScan(card, flatWrap, onDone) {
            setDetectedLoading(card, true);

            var iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:absolute;top:0;left:0;width:300px;height:300px;visibility:hidden;pointer-events:none;';
            iframe.src = mkcpAdmin.homeUrl;

            var done = false;
            function finish(colors) {
                if (done) return;
                done = true;
                if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
                writeCache(colors);
                setDetectedLoading(card, false);
                if (colors.length) {
                    showColors(card, flatWrap, colors);
                } else {
                    // Niets gevonden én geen tier-1-kleuren: kaart weer verbergen
                    // (was alleen zichtbaar gemaakt om de zoek-indicator te tonen).
                    var categorized = document.getElementById('mkcp-detected-swatches-categorized');
                    if (!categorized || !categorized.children.length) card.style.display = 'none';
                    refreshGenerateStyleVisibility();
                }
                onDone();
            }

            function rgbToHex(val) {
                var m = /^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)$/.exec(val || '');
                if (!m) return null;
                if (m[4] !== undefined && parseFloat(m[4]) < 0.5) return null;
                var r = parseInt(m[1], 10), g = parseInt(m[2], 10), b = parseInt(m[3], 10);
                return '#' + [r, g, b].map(function(v) { return v.toString(16).padStart(2, '0'); }).join('');
            }

            // Grijs/wit/zwart (lage saturatie of extreme helderheid) is zelden
            // een bewuste merkkleur — daar filteren we op.
            function isBrandLike(hex) {
                var r = parseInt(hex.slice(1, 3), 16) / 255;
                var g = parseInt(hex.slice(3, 5), 16) / 255;
                var b = parseInt(hex.slice(5, 7), 16) / 255;
                var max = Math.max(r, g, b), min = Math.min(r, g, b);
                var l = (max + min) / 2;
                var s = max === min ? 0 : (l > 0.5 ? (max - min) / (2 - max - min) : (max - min) / (max + min));
                return s > 0.15 && l > 0.08 && l < 0.92;
            }

            function scanColors(doc) {
                var selectors = [
                    'button', '.button', '.wp-block-button__link', 'input[type="submit"]',
                    '.add_to_cart_button', 'header', 'nav', '.site-header'
                ];
                var counts = {};
                selectors.forEach(function(sel) {
                    var els;
                    try { els = doc.querySelectorAll(sel); } catch (e) { return; }
                    for (var i = 0; i < Math.min(els.length, 8); i++) {
                        [ 'backgroundColor', 'color', 'borderColor' ].forEach(function(prop) {
                            var val = doc.defaultView.getComputedStyle(els[i])[prop];
                            var hex = rgbToHex(val);
                            if (hex && isBrandLike(hex)) counts[hex] = (counts[hex] || 0) + 1;
                        });
                    }
                });
                return Object.keys(counts)
                    .sort(function(a, b) { return counts[b] - counts[a]; })
                    .slice(0, 6);
            }

            // NIET op het 'load'-event van de iframe wachten: een echte
            // homepage met trackers/pixels/widgets (Facebook Pixel, Gravity
            // Forms, chatwidgets...) kan best een hangende achtergrond-request
            // hebben die 'load' minutenlang uitstelt of nooit laat vuren,
            // terwijl de pagina zelf allang klaar is om af te tasten. Poll
            // daarom op readyState — zodra de HTML geparsed is en de
            // stylesheets uit <head> zijn toegepast ("interactive" of later)
            // zijn computed styles al betrouwbaar leesbaar. Ruim getimede
            // timeout (15s): dit draait onzichtbaar op de achtergrond, dus
            // een langzame homepage (trage server, veel resources) mag gerust
            // langer krijgen zonder dat de gebruiker daar iets van merkt.
            var timeout = setTimeout(function() { clearInterval(poll); finish([]); }, 15000);
            var poll = setInterval(function() {
                var doc;
                try {
                    doc = iframe.contentDocument;
                } catch (e) {
                    clearInterval(poll);
                    clearTimeout(timeout);
                    finish([]); // cross-origin of andere blokkade — stille fallback
                    return;
                }
                // Direct na het invoegen wijst contentDocument nog even naar
                // het lege placeholder-document (readyState daarvan is
                // meteen al "complete") — pas als de URL ook echt de eigen
                // site is, is dit de PAGINA en niet het placeholder-document.
                if (!doc || doc.readyState === 'loading') return;
                if (doc.location.href === 'about:blank') return;
                clearInterval(poll);
                clearTimeout(timeout);
                try {
                    finish(scanColors(doc));
                } catch (e) {
                    finish([]);
                }
            }, 150);
            document.body.appendChild(iframe);
        }

        var colorScanDone = false;
        function startColorScan(forceRescan) {
            if (colorScanDone && !forceRescan) return;
            colorScanDone = true;

            var card = document.getElementById('mkcp-detected-colors-card');
            if ( ! card || card.dataset.mkcpScan !== '1' ) return;
            if ( typeof mkcpAdmin === 'undefined' || ! mkcpAdmin.homeUrl ) return;
            if ( mkcpAdmin.licenseTier !== 'premium' ) return; // kaart is toch tier-locked, scan overslaan

            var flatWrap = document.getElementById('mkcp-detected-swatches-flat');
            if (!flatWrap) return;

            if (!forceRescan) {
                var cached = readCache();
                if (cached) {
                    if (cached.length) showColors(card, flatWrap, cached);
                    return; // fris genoeg (< 30 dagen) — niet opnieuw scannen, ook niet als 'ie leeg was
                }
            }

            // Ook al vóórdat de scan daadwerkelijk start (die wacht evt. nog op
            // het 'load'-event) alvast de zoek-indicator tonen — anders blijft
            // de kaart in de tussentijd stil, wat precies de "opeens staan de
            // resultaten er"-klacht was.
            setDetectedLoading(card, true);

            var runScan = function() { runLiveScan(card, flatWrap, function() {}); };
            if (document.readyState === 'complete') runScan();
            else window.addEventListener('load', runScan);
        }

        var rescanBtn = document.getElementById('mkcp-detected-rescan');
        if (rescanBtn) {
            rescanBtn.addEventListener('click', function() { startColorScan(true); });
        }

        // ── Kant-en-klare stijl genereren uit gevonden kleuren ───────────────
        //
        // Admin-feedback: de vaste presets hierboven staan los van de eigen
        // site — dit leidt in plaats daarvan een combinatie af uit PRECIES de
        // kleuren die op déze site gevonden zijn (tier 1 gecategoriseerd en/of
        // tier 2 losse scan-resultaten). Niet per se elke gevonden kleur
        // gebruiken, wel een combinatie die goed samen oogt: hoofdkleur wordt
        // de meest verzadigde bruikbare kleur, achtergrond/tekst worden zo
        // nodig aangevuld met veilige defaults, en steeds gecheckt op
        // voldoende contrast via dezelfde WCAG-formule als de contrastcheck
        // hieronder bij de losse Kleuren-velden.
        function hexSaturationLightness(hex) {
            var r = parseInt(hex.slice(1, 3), 16) / 255;
            var g = parseInt(hex.slice(3, 5), 16) / 255;
            var b = parseInt(hex.slice(5, 7), 16) / 255;
            var max = Math.max(r, g, b), min = Math.min(r, g, b);
            var l = (max + min) / 2;
            var s = max === min ? 0 : (l > 0.5 ? (max - min) / (2 - max - min) : (max - min) / (max + min));
            return { s: s, l: l };
        }

        function mixHex(hexA, hexB, amount) {
            var a = hexToRgb(hexA), b = hexToRgb(hexB);
            var mix = function(x, y) { return Math.round(x + (y - x) * amount); };
            return '#' + [mix(a.r, b.r), mix(a.g, b.g), mix(a.b, b.b)]
                .map(function(v) { return v.toString(16).padStart(2, '0'); }).join('');
        }

        function pickBestTextColor(bg) {
            return contrastRatio('#ffffff', bg) >= contrastRatio('#1a1a1a', bg) ? '#ffffff' : '#1a1a1a';
        }

        function collectDetectedColors() {
            var out = [];
            document.querySelectorAll(
                '#mkcp-detected-swatches-categorized .mkcp-detected-swatch, #mkcp-detected-swatches-flat .mkcp-detected-swatch'
            ).forEach(function(el) {
                if (el.dataset.color) out.push(el.dataset.color);
            });
            return out;
        }

        function deriveStyleFromDetectedColors() {
            var colors = collectDetectedColors();
            if (!colors.length) return null;

            var candidates = colors.map(function(hex) {
                var hsl = hexSaturationLightness(hex);
                return { hex: hex, s: hsl.s, l: hsl.l };
            }).filter(function(c) { return c.l > 0.08 && c.l < 0.92; });
            if (!candidates.length) {
                candidates = colors.map(function(hex) { return { hex: hex, s: 0, l: 0.5 }; });
            }
            candidates.sort(function(a, b) { return b.s - a.s; });
            var accent = candidates[0].hex;

            // Achtergrond: default wit, tenzij een gevonden kleur duidelijk
            // een lichte achtergrondkleur is (hoge lichtheid, lage verzadiging).
            var bgCandidate = candidates.filter(function(c) { return c.l > 0.85 && c.hex !== accent; })[0];
            var bg = bgCandidate ? bgCandidate.hex : '#ffffff';

            // Tekst: default donkergrijs, tenzij een gevonden kleur duidelijk
            // donker genoeg is én voldoende contrast geeft t.o.v. de achtergrond.
            var textCandidate = candidates.filter(function(c) { return c.l < 0.25 && c.hex !== accent; })[0];
            var text = (textCandidate && contrastRatio(textCandidate.hex, bg) >= 4.5) ? textCandidate.hex : '#1a1a1a';
            if (contrastRatio(text, bg) < 4.5) text = pickBestTextColor(bg);

            var border  = mixHex(bg, accent, 0.14);
            var btnText = pickBestTextColor(accent);

            return { accent: accent, bg: bg, text: text, btnText: btnText, border: border, danger: '#d32f2f' };
        }

        // Admin-feedback: eerst schreef dit meteen (onzichtbaar) de Kleuren-
        // velden vol — een klik op "Genereer stijl" voelde daardoor niet aan
        // als een echte verandering. Bouwt daarom i.p.v. dat een ECHTE preset-
        // kaart (zelfde markup/gedrag als de vaste presets hierboven, zie
        // buildGeneratedPresetCard()) en zet die vooraan bij "Kant-en-klare
        // stijlen" neer — een klik daarop past 'm toe via dezelfde
        // applyPresetButton() als een gewone preset, dus meteen zichtbaar én
        // herkenbaar als "iets is er nu bijgekomen".
        function buildGeneratedPresetCard(style) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.id = 'mkcp-style-preset-generated';
            btn.className = 'mkcp-style-preset js-mkcp-style-preset mkcp-style-preset--generated';
            btn.dataset.preset  = 'generated';
            btn.dataset.accent  = style.accent;
            btn.dataset.bg      = style.bg;
            btn.dataset.text    = style.text;
            btn.dataset.btnText = style.btnText;
            btn.dataset.border  = style.border;
            btn.dataset.danger  = style.danger;
            btn.dataset.btnStyle = 'filled';

            btn.innerHTML =
                '<span class="mkcp-style-preset-thumb" style="background:' + style.bg + ';border-color:' + style.border + '">' +
                    '<span class="mkcp-style-preset-thumb-bar" style="border-color:' + style.border + '">' +
                        '<span style="background:' + style.text + '"></span>' +
                    '</span>' +
                    '<span class="mkcp-style-preset-thumb-lines">' +
                        '<span style="background:' + style.text + '"></span>' +
                        '<span style="background:' + style.text + '"></span>' +
                    '</span>' +
                    '<span class="mkcp-style-preset-thumb-btn" style="background:' + style.accent + ';border-color:' + style.accent + '">' +
                        '<span style="background:' + style.btnText + '"></span>' +
                    '</span>' +
                '</span>' +
                '<span class="mkcp-style-preset-label">Jouw kleuren</span>';

            return btn;
        }

        function generateStyleFromDetectedColors() {
            var style = deriveStyleFromDetectedColors();
            if (!style) return;

            var presetList = document.querySelector('.mkcp-style-preset-list');
            if (!presetList) return;

            var existing = document.getElementById('mkcp-style-preset-generated');
            if (existing) existing.remove();

            var card = buildGeneratedPresetCard(style);
            presetList.insertBefore(card, presetList.firstChild);

            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            applyPresetButton(card);
        }

        function refreshGenerateStyleVisibility() {
            var btn  = document.getElementById('mkcp-detected-generate-style');
            var hint = document.getElementById('mkcp-detected-generate-hint');
            var show = collectDetectedColors().length > 0;
            if (btn)  btn.style.display  = show ? '' : 'none';
            if (hint) hint.style.display = show ? '' : 'none';
        }

        var generateStyleBtn = document.getElementById('mkcp-detected-generate-style');
        if (generateStyleBtn) {
            generateStyleBtn.addEventListener('click', function() {
                generateStyleFromDetectedColors();
                generateStyleBtn.classList.remove('is-applying');
                void generateStyleBtn.offsetWidth;
                generateStyleBtn.classList.add('is-applying');
            });
            generateStyleBtn.addEventListener('animationend', function() {
                generateStyleBtn.classList.remove('is-applying');
            });
        }
        refreshGenerateStyleVisibility();

        // Alleen starten zodra de Styling-tab ook echt de zichtbare tab is —
        // niet bij het laden van de instellingenpagina an sich, ongeacht
        // welke tab open staat (die staan allemaal al in de DOM, alleen
        // CSS-verborgen). MutationObserver vangt elke manier waarop de tab
        // actief wordt (sidebar-klik, "ga naar"-snelkoppeling, terug-knop).
        var stylingPanel = document.querySelector('.mkcp-panel[data-panel="styling"]');
        if (stylingPanel) {
            if (stylingPanel.classList.contains('is-active')) {
                startColorScan();
            } else {
                var panelObserver = new MutationObserver(function() {
                    if (stylingPanel.classList.contains('is-active')) {
                        panelObserver.disconnect();
                        startColorScan();
                    }
                });
                panelObserver.observe(stylingPanel, { attributes: true, attributeFilter: ['class'] });
            }
        }

        applyDerivedColors();
        applyButtonStyle();
        applyPanelGlow();
        checkContrast();
        markActivePreset();
    }());

}());
