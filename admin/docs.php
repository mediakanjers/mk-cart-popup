<?php
/**
 * MK Cart Popup — Documentatie
 *
 * Volledige gebruikers- en ontwikkelaarsdocumentatie in de premium UI.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Registreer de pagina ───────────────────────────────────────────────────────

add_action( 'admin_menu', function() {
    add_submenu_page(
        null,
        'MK Cart Popup — Documentatie',
        'MK Cart Popup Docs',
        'manage_options',
        'mkcp-docs',
        'mkcp_render_docs_page'
    );
} );


// ── Paginaweergave ─────────────────────────────────────────────────────────────

function mkcp_render_docs_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Geen toegang.' );

    $scaffold_dir   = get_stylesheet_directory() . '/mk-cart-popup';
    $has_hooks           = file_exists( $scaffold_dir . '/cart-hooks.php' );
    $has_checkout_hooks  = file_exists( $scaffold_dir . '/checkout-hooks.php' );
    $has_style_css       = file_exists( $scaffold_dir . '/style.css' );
    $has_checkout_css    = file_exists( $scaffold_dir . '/checkout.css' );
    $child_theme    = get_stylesheet();
    $settings_url   = admin_url( 'admin.php?page=mkcp-settings' );
    $version        = MKCP_VER;

    $nav_sections = [
        'hoe-werkt-het' => 'Hoe werkt de plugin?',
        'instellingen'  => 'Popup instellingen',
        'checkout-doc'  => 'Cart Checkout',
        'css'           => 'CSS aanpassen',
        'scaffold'      => 'Theme override bestanden',
        'php'           => 'PHP: config filter',
        'template'      => 'PHP: template override',
        'actions'       => 'PHP: action hooks',
        'functies'      => 'Beschikbare PHP-functies',
        'problemen'     => 'Problemen oplossen',
        'licenties'     => 'Licentie activeren',
    ];

    $nav_groups = [
        'Gebruik'   => [ 'hoe-werkt-het', 'instellingen', 'checkout-doc' ],
        'Aanpassen' => [ 'css', 'php', 'template', 'actions', 'functies' ],
        'Algemeen'  => [ 'problemen', 'scaffold', 'licenties' ],
    ];

    $icons_svg = [
        'shopping-cart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
        'arrow-left'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
        'book'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        'code'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        'sliders'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
        'truck'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'alert'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'package'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'zap'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'shield'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'git-branch'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>',
        'credit-card'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    ];
    ?>

    <div id="mkcp-admin-wrap">

        <!-- ── Sidebar ──────────────────────────────────────────────────────── -->

        <aside class="mkcp-sidebar">

            <div class="mkcp-sidebar-logo">
                <div class="mkcp-logo-mark">
                    <?php echo $icons_svg['shopping-cart']; ?>
                </div>
                <div class="mkcp-logo-text">
                    <strong>Cart Popup</strong>
                    <span>by Mediakanjers</span>
                </div>
            </div>

            <nav class="mkcp-nav">

                <div class="mkcp-nav-section">Navigatie</div>

                <a href="<?php echo esc_url( $settings_url ); ?>" class="mkcp-docs-back-btn">
                    <?php echo $icons_svg['arrow-left']; ?>
                    Terug naar instellingen
                </a>

                <?php if ( defined( 'MKCP_DEV' ) && MKCP_DEV ) : ?>
                <div class="mkcp-nav-section" style="margin-top:4px">Weergave</div>
                <div style="display:flex;gap:4px;padding:0 12px 8px">
                    <button class="mkcp-docs-tab is-active" data-target="docs">Documentatie</button>
                    <button class="mkcp-docs-tab" data-target="development"><?php echo $icons_svg['git-branch']; ?> Dev</button>
                </div>
                <?php endif; ?>

                <div id="mkcp-docs-anchor-nav">
                    <?php $num = 1; foreach ( $nav_groups as $group_label => $group_anchors ) : ?>
                    <div class="mkcp-nav-section"><?php echo esc_html( $group_label ); ?></div>
                    <?php foreach ( $group_anchors as $anchor ) : ?>
                    <a href="#<?php echo esc_attr( $anchor ); ?>" class="mkcp-nav-item--anchor">
                        <span class="mkcp-docs-section-num"><?php echo $num++; ?></span>
                        <?php echo esc_html( $nav_sections[ $anchor ] ); ?>
                    </a>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

            </nav>

            <div class="mkcp-sidebar-footer">
                <div class="mkcp-version-pill">
                    <span class="dot"></span>
                    v<?php echo esc_html( $version ); ?>
                </div>
            </div>

        </aside>

        <!-- ── Main ─────────────────────────────────────────────────────────── -->

        <main class="mkcp-main">

            <div class="mkcp-panel is-active" data-panel="docs">

                <div class="mkcp-page-header">
                    <h2><?php echo $icons_svg['book']; ?> Documentatie &amp; uitleg</h2>
                    <p class="mkcp-docs-intro">Uitleg over de cart-drawer en de Cart Checkout-aanpassing, welke instellingen beschikbaar zijn, hoe je CSS en PHP overschrijft, en hoe je problemen oplost.</p>
                </div>


                <!-- ── 1. Hoe werkt de popup ─────────────────────────────── -->

                <div class="mkcp-glass" id="hoe-werkt-het" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['zap']; ?></div>
                        <h3><span class="mkcp-docs-section-num">1</span> &nbsp;Hoe werkt de plugin?</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 14px">
                            De plugin bestaat uit twee onderdelen. De <strong>cart-drawer</strong> is een slide-in zijbalk die reageert op elke winkelwagenwijziging zonder pagina te herladen. De <strong>Cart Checkout-aanpassing</strong> geeft je volledige controle over de betaalpagina: eigen header met logo, een stappenindicator en een samengestelde footer.
                        </p>

                        <code class="mkcp-docs-code"><span class="hi">Bezoeker klikt "Toevoegen"</span>
 │
 ▼
<span class="hi2">JS onderschept form.cart submit</span>   ← ook voor variabele en groepsproducten
 │
 ▼
<span class="hi2">AJAX → WooCommerce add_to_cart</span>
 │
 ▼
<span class="hi3">WC geeft fragments terug</span>           ← fragment bevat nieuwe HTML van de popup
 │
 ▼
<span class="hi2">JS vervangt #mk-cart-popup</span>         ← applyFragments() swapped de DOM
 │
 ▼
<span class="hi">Popup schuift open</span></code>

                        <div class="mkcp-docs-callout mkcp-docs-callout--info" style="margin-top:14px">
                            <strong>Cart-drawer:</strong><br>
                            <code>mk-cart-popup.php</code> — alle PHP hooks en AJAX handlers<br>
                            <code>templates/cart-popup.php</code> — HTML van de drawer (ook WC fragment)<br>
                            <code>assets/cart-popup.js</code> — open/sluit, AJAX intercept, qty/remove, kortingscodes<br>
                            <code>assets/cart-popup.css</code> — alle stijlen via CSS custom properties<br><br>
                            <strong>Cart Checkout:</strong><br>
                            <code>includes/checkout-frontend.php</code> — header, stappenindicator, footer en de "Theme hooks/CSS uitschakelen"-sweep<br>
                            <code>includes/delivery-date.php</code> — bezorgdatum kiezer (premium)<br>
                            <code>includes/pickup.php</code> — afhaallocaties, tijdvakken en de locatie-info op de checkout (premium)<br>
                            <code>includes/shipping-choice.php</code> — Ophalen/Bezorgen-keuzekaarten, zie sectie 3 (premium)<br>
                            <code>includes/abandoned-cart.php</code> — herinneringsmail bij verlaten winkelmand (premium)<br>
                            <code>admin/assets/checkout.js</code> — admin-interface voor de Checkout-instellingen
                        </div>

                    </div>
                </div>


                <!-- ── 2. Instellingen ───────────────────────────────────── -->

                <div class="mkcp-glass" id="instellingen" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['sliders']; ?></div>
                        <h3><span class="mkcp-docs-section-num">2</span> &nbsp;Popup instellingen</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 14px">
                            Alle instellingen staan op <a href="<?php echo esc_url( $settings_url ); ?>" style="color:var(--mkcp-ui-accent)">WooCommerce → Cart Popup</a> en worden opgeslagen als één WordPress optie (<code style="color:var(--mkcp-ui-accent);font-size:11px;background:rgba(99,102,241,.12);padding:1px 5px;border-radius:3px">mkcp_settings</code>).
                        </p>
                        <table class="mkcp-docs-table">
                            <thead><tr><th>Instelling</th><th>Wat het doet</th><th>Standaard</th></tr></thead>
                            <tbody>
                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Basis</td></tr>
                                <tr><td><code>Plugin ingeschakeld</code></td><td style="color:var(--mkcp-ui-text2)">Schakelt de popup volledig uit of in zonder de plugin te verwijderen.</td><td style="color:var(--mkcp-ui-text3)">Aan</td></tr>
                                <tr><td><code>Titel popup</code></td><td style="color:var(--mkcp-ui-text2)">De tekst in de header van de drawer.</td><td style="color:var(--mkcp-ui-text3)">"Jouw winkelmand"</td></tr>
                                <tr><td><code>Knop: Afrekenen</code></td><td style="color:var(--mkcp-ui-text2)">Tekst op de primaire CTA-knop naar de betaalpagina.</td><td style="color:var(--mkcp-ui-text3)">"Afrekenen"</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Winkelwagen</td></tr>
                                <tr><td><code>Kortingscode</code></td><td style="color:var(--mkcp-ui-text2)">Toont of verbergt het kortingscode-invoerveld in de popup.</td><td style="color:var(--mkcp-ui-text3)">Aan</td></tr>
                                <tr><td><code>Gratis verzending balk</code></td><td style="color:var(--mkcp-ui-text2)">Voortgangsbalk naar gratis verzending. Drempel op 0 = auto-detectie uit WC.</td><td style="color:var(--mkcp-ui-text3)">Uit / auto</td></tr>
                                <tr><td><code>Winkelwagen omleiden</code></td><td style="color:var(--mkcp-ui-text2)">Stuurt bezoekers van /cart door naar een configureerbare URL.</td><td style="color:var(--mkcp-ui-text3)">Aan → homepage</td></tr>
                                <tr><td><code>Minimum bestelbedrag</code></td><td style="color:var(--mkcp-ui-text2)">Blokkeer afrekenen-knop als subtotaal hieronder blijft. 0 = uitgeschakeld.</td><td style="color:var(--mkcp-ui-text3)">0 (uit)</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Weergave</td></tr>
                                <tr><td><code>Betaalicoontjes</code></td><td style="color:var(--mkcp-ui-text2)">Upload eigen SVG/PNG/JPG iconen. Verschijnen boven de afrekenen-knop.</td><td style="color:var(--mkcp-ui-text3)">Geen</td></tr>
                                <tr><td><code>USPs</code></td><td style="color:var(--mkcp-ui-text2)">Rij vertrouwenslabels onderaan de popup (icoon + tekst).</td><td style="color:var(--mkcp-ui-text3)">3 standaard</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Conversie &amp; tracking <span style="font-size:9px;background:var(--mkcp-ui-accent-soft);color:var(--mkcp-ui-accent);border-radius:3px;padding:1px 5px;margin-left:6px;letter-spacing:0;text-transform:none;font-weight:600">Premium</span></td></tr>
                                <tr><td><code>BTW opsplitsing</code></td><td style="color:var(--mkcp-ui-text2)">Voegt een incl./excl. BTW-schakelaar toe in de popup. Voorkeur wordt opgeslagen in <code>localStorage['mkcp_btw_pref']</code>.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Analytics (GA4 / GTM)</code></td><td style="color:var(--mkcp-ui-text2)">Stuurt <code>add_to_cart</code>, <code>remove_from_cart</code> en <code>begin_checkout</code> naar <code>window.dataLayer</code>.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Bewaar voor later <span style="font-size:9px;background:var(--mkcp-ui-accent-soft);color:var(--mkcp-ui-accent);border-radius:3px;padding:1px 5px;margin-left:6px;letter-spacing:0;text-transform:none;font-weight:600">Premium</span></td></tr>
                                <tr><td><code>Bewaar voor later</code></td><td style="color:var(--mkcp-ui-text2)">Voegt een "Bewaar voor later"-knop toe aan elk product. Items worden opgeslagen in <code>localStorage['mkcp_saved_items']</code> en verschijnen onderaan de popup.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Voorraadwaarschuwing</code></td><td style="color:var(--mkcp-ui-text2)">Toont een badge bij bewaarde items als de voorraad laag of uitverkocht is. Resultaten worden 5 minuten gecached in <code>localStorage['mkcp_stock_cache']</code>.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Voorraaddrempel</code></td><td style="color:var(--mkcp-ui-text2)">Aantal stuks waaronder de "laag op voorraad"-badge verschijnt.</td><td style="color:var(--mkcp-ui-text3)">5</td></tr>
                                <tr><td><code>Winkelwagen-icoon selector</code></td><td style="color:var(--mkcp-ui-text2)">CSS-selector voor het winkelwagen-icoon in de header waarop de hartje-badge wordt geplaatst (bijv. <code>.header-shop-icon a</code>). Leeg = automatische detectie.</td><td style="color:var(--mkcp-ui-text3)">Leeg</td></tr>
                                <tr><td><code>Hartje badge positie</code></td><td style="color:var(--mkcp-ui-text2)">Hoek waar de hartje-badge met het aantal bewaarde items verschijnt t.o.v. het winkelwagen-icoon.</td><td style="color:var(--mkcp-ui-text3)">Rechtsboven</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Herstel-link &amp; e-mail <span style="font-size:9px;background:var(--mkcp-ui-accent-soft);color:var(--mkcp-ui-accent);border-radius:3px;padding:1px 5px;margin-left:6px;letter-spacing:0;text-transform:none;font-weight:600">Premium</span></td></tr>
                                <tr><td><code>Herstel-link genereren</code></td><td style="color:var(--mkcp-ui-text2)">Voegt een "Bewaar je winkelmand"-sectie toe in de popup met een knop die een unieke herstel-URL genereert. Link wordt opgeslagen als WordPress-transient.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Mail naar mijzelf</code></td><td style="color:var(--mkcp-ui-text2)">Klanten kunnen de herstel-link naar een e-mailadres sturen. Onderwerp en inhoud zijn aanpasbaar. Verstuurd via <code>wp_mail()</code>.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Geldigheid link</code></td><td style="color:var(--mkcp-ui-text2)">Aantal dagen dat de herstel-link actief blijft (max. 30 dagen). Na verloop wordt de transient automatisch opgeschoond door WordPress.</td><td style="color:var(--mkcp-ui-text3)">7 dagen</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>


                <!-- ── 3. Cart Checkout ─────────────────────────────────── -->

                <div class="mkcp-glass" id="checkout-doc" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['credit-card']; ?></div>
                        <h3><span class="mkcp-docs-section-num">3</span> &nbsp;Cart Checkout aanpassen <span style="font-size:9px;background:var(--mkcp-ui-accent-soft);color:var(--mkcp-ui-accent);border-radius:3px;padding:1px 5px;margin-left:4px;letter-spacing:0;text-transform:none;font-weight:600">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 14px">
                            Met de Cart Checkout-instellingen geef je de WooCommerce-betaalpagina een eigen uitstraling zonder code — via <a href="<?php echo esc_url( $settings_url . '&product=checkout' ); ?>" style="color:var(--mkcp-ui-accent)">WooCommerce → Cart Popup → Checkout</a>. De aanpassingen zijn actief op elke pagina die WooCommerce als betaalpagina herkent.
                        </p>

                        <table class="mkcp-docs-table" style="margin-bottom:14px">
                            <thead><tr><th>Instelling</th><th>Wat het doet</th><th>Standaard</th></tr></thead>
                            <tbody>
                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Header</td></tr>
                                <tr><td><code>Header inschakelen</code></td><td style="color:var(--mkcp-ui-text2)">Vervangt de thema-header op de betaalpagina door een minimalistische plugin-header.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Header logo</code></td><td style="color:var(--mkcp-ui-text2)">Upload een logo dat gecentreerd in de header verschijnt. Geen logo = sitenaam als tekst.</td><td style="color:var(--mkcp-ui-text3)">Geen</td></tr>
                                <tr><td><code>Header achtergrond</code></td><td style="color:var(--mkcp-ui-text2)">Achtergrondkleur van de custom header.</td><td style="color:var(--mkcp-ui-text3)">#ffffff</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Stappenindicator</td></tr>
                                <tr><td><code>Stappenindicator</code></td><td style="color:var(--mkcp-ui-text2)">Toont een horizontale voortgangsbalk boven het betaalformulier: Winkelwagen → Gegevens → Bevestiging.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Staplabels</code></td><td style="color:var(--mkcp-ui-text2)">Tekst van de drie stappen, vrij aanpasbaar.</td><td style="color:var(--mkcp-ui-text3)">Winkelwagen / Gegevens / Bevestiging</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Footer</td></tr>
                                <tr><td><code>Footer inschakelen</code></td><td style="color:var(--mkcp-ui-text2)">Voegt een blokken-footer toe onder het WooCommerce-betaalformulier.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Footer blokken</code></td><td style="color:var(--mkcp-ui-text2)">Stel zelf de footer samen uit sleepbare blokken: tekst, HTML, afbeelding of betaalicoontjes. Volgorde aanpasbaar via drag-and-drop.</td><td style="color:var(--mkcp-ui-text3)">Geen</td></tr>
                                <tr><td><code>Betaalicoontjes</code></td><td style="color:var(--mkcp-ui-text2)">Voeg een rij betaalicoon-afbeeldingen toe als footer-blok. Upload meerdere SVG/PNG/JPG iconen.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>

                                <tr style="background:var(--mkcp-ui-surface2)"><td colspan="3" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--mkcp-ui-text3);padding:7px 12px">Schone basis</td></tr>
                                <tr>
                                    <td><code>Theme styling uitschakelen</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Verwijdert alle (child) theme stylesheets op de checkout pagina zodat <code>checkout.css</code> volledig de stijl bepaalt. Overige plugin- en WooCommerce-stylesheets blijven staan.</td>
                                    <td style="color:var(--mkcp-ui-text3)">Uit</td>
                                </tr>
                                <tr>
                                    <td><code>Theme hooks uitschakelen</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Verwijdert op de checkout alle PHP-callbacks uit het (child) thema via PHP Reflection — inclusief <code>functions.php</code> en alle bestanden die daarin worden geladen. Hooks in <code>mk-cart-popup/cart-hooks.php</code> en <code>checkout-hooks.php</code> worden uitgezonderd en blijven actief. Zo is <code>checkout-hooks.php</code> je enige, gecontroleerde bron voor checkout-logica.</td>
                                    <td style="color:var(--mkcp-ui-text3)">Uit</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="mkcp-docs-callout mkcp-docs-callout--tip">
                            <strong>Aanbevolen werkwijze:</strong> zet beide opties aan tijdens de ontwikkeling van een eigen checkout. Migreer daarna de hooks die je nodig hebt vanuit <code>functions.php</code> naar <code>mk-cart-popup/checkout-hooks.php</code>. Zo weet je precies welke code actief is op de betaalpagina.
                        </div>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:24px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Afhalen, bezorgdatum &amp; keuzekaarten</p>

                        <table class="mkcp-docs-table" style="margin-bottom:14px">
                            <thead><tr><th>Instelling</th><th>Wat het doet</th><th>Standaard</th></tr></thead>
                            <tbody>
                                <tr><td><code>Bezorgdatum inschakelen</code></td><td style="color:var(--mkcp-ui-text2)">Toont een datumkiezer op de checkout (chips + kalender), rekening houdend met cutoff-tijd, verzenddagen, geblokkeerde datums en optioneel een dagcapaciteit.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Afhalen inschakelen</code></td><td style="color:var(--mkcp-ui-text2)">Koppelt een of meer afhaallocaties (adres, tijdvakken) aan een bestaande <code>local_pickup</code>-verzendmethode via de rate-id.</td><td style="color:var(--mkcp-ui-text3)">Uit</td></tr>
                                <tr><td><code>Ophalen/Bezorgen-keuzekaarten</code></td><td style="color:var(--mkcp-ui-text2)">Zodra "Afhalen" aan staat en een verzendpakket zowel een gewone verzendmethode als een <code>local_pickup</code>-methode bevat, vervangt de plugin de standaard WooCommerce-verzendlijst automatisch door twee duidelijke keuzekaarten. Geen aparte instelling — volgt automatisch uit "Afhalen inschakelen".</td><td style="color:var(--mkcp-ui-text3)">Automatisch</td></tr>
                            </tbody>
                        </table>

                        <div class="mkcp-docs-callout mkcp-docs-callout--warn">
                            <strong>Belangrijk bij "Theme hooks uitschakelen":</strong> als je thema zélf de verzendmethode-lijst rendert (bijv. via een eigen hook buiten <code>mk-cart-popup/checkout-hooks.php</code>), verwijdert de sweep die render-hook net als elke andere thema-hook. De plugin rendert in dat geval automatisch zelf een vervangend anker zodra "Afhalen" aan staat, dus de keuzekaarten blijven zichtbaar. Zie sectie 9 als dat toch niet het geval is.
                        </div>

                        <div class="mkcp-docs-callout mkcp-docs-callout--info" style="margin-top:8px">
                            <strong>PHP:</strong> de instellingen worden opgeslagen als <code>mkcp_checkout_settings</code>. Ophalen via <code>mkcp_checkout_config()</code> — zie sectie 8 voor alle beschikbare functies.
                        </div>

                    </div>
                </div>


                <!-- ── 4. CSS aanpassen ─────────────────────────────────── -->

                <div class="mkcp-glass" id="css" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['code']; ?></div>
                        <h3><span class="mkcp-docs-section-num">4</span> &nbsp;CSS aanpassen</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 12px">
                            De plugin gebruikt CSS custom properties voor alle kleuren, maten en animaties. Overschrijf ze vanuit het thema zonder de plugin aan te passen.
                        </p>

                        <div class="mkcp-docs-callout mkcp-docs-callout--tip">
                            <strong>Beste manier:</strong> schrijf overrides in
                            <code><?php echo esc_html( $child_theme ); ?>/mk-cart-popup/style.css</code>. De plugin laadt dit bestand automatisch.
                        </div>

                        <table class="mkcp-docs-table" style="margin-top:14px">
                            <thead><tr><th>Variabele</th><th>Standaardwaarde</th><th>Waarvoor</th></tr></thead>
                            <tbody>
                                <tr><td><code>--mkcp-accent</code></td><td style="color:var(--mkcp-ui-text3)">#2e7d32</td><td style="color:var(--mkcp-ui-text2)">Accentkleur — knop, progressbalk, links</td></tr>
                                <tr><td><code>--mkcp-width</code></td><td style="color:var(--mkcp-ui-text3)">470px</td><td style="color:var(--mkcp-ui-text2)">Breedte van de drawer</td></tr>
                                <tr><td><code>--mkcp-z</code></td><td style="color:var(--mkcp-ui-text3)">10000000</td><td style="color:var(--mkcp-ui-text2)">Z-index van de popup</td></tr>
                                <tr><td><code>--mkcp-bg</code></td><td style="color:var(--mkcp-ui-text3)">#ffffff</td><td style="color:var(--mkcp-ui-text2)">Achtergrond van de drawer</td></tr>
                                <tr><td><code>--mkcp-backdrop</code></td><td style="color:var(--mkcp-ui-text3)">rgba(0,0,0,0.5)</td><td style="color:var(--mkcp-ui-text2)">Kleur van het overlay</td></tr>
                                <tr><td><code>--mkcp-text</code></td><td style="color:var(--mkcp-ui-text3)">#1a1a1a</td><td style="color:var(--mkcp-ui-text2)">Hoofdtekstkleur</td></tr>
                                <tr><td><code>--mkcp-danger</code></td><td style="color:var(--mkcp-ui-text3)">#d32f2f</td><td style="color:var(--mkcp-ui-text2)">Verwijder-knop</td></tr>
                                <tr><td><code>--mkcp-btn-p-bg</code></td><td style="color:var(--mkcp-ui-text3)">var(--mkcp-accent)</td><td style="color:var(--mkcp-ui-text2)">Achtergrond afrekenknop</td></tr>
                                <tr><td><code>--mkcp-btn-p-text</code></td><td style="color:var(--mkcp-ui-text3)">#ffffff</td><td style="color:var(--mkcp-ui-text2)">Tekstkleur afrekenknop</td></tr>
                                <tr><td><code>--mkcp-space-outer</code></td><td style="color:var(--mkcp-ui-text3)">1.5rem</td><td style="color:var(--mkcp-ui-text2)">Horizontale padding (header, footer, items)</td></tr>
                                <tr><td><code>--mkcp-space-inner</code></td><td style="color:var(--mkcp-ui-text3)">1.25rem</td><td style="color:var(--mkcp-ui-text2)">Verticale padding (header, item-rijen)</td></tr>
                                <tr><td><code>--mkcp-space-sm</code></td><td style="color:var(--mkcp-ui-text3)">0.875rem</td><td style="color:var(--mkcp-ui-text2)">Kleine tussenruimte (badges, sectie-headers)</td></tr>
                                <tr><td><code>--mkcp-font-title</code></td><td style="color:var(--mkcp-ui-text3)">1.75rem</td><td style="color:var(--mkcp-ui-text2)">Popup-koptekst</td></tr>
                                <tr><td><code>--mkcp-font-base</code></td><td style="color:var(--mkcp-ui-text3)">1.25rem</td><td style="color:var(--mkcp-ui-text2)">Standaard tekst (productnamen, prijzen)</td></tr>
                                <tr><td><code>--mkcp-font-sm</code></td><td style="color:var(--mkcp-ui-text3)">1.125rem</td><td style="color:var(--mkcp-ui-text2)">Secondaire tekst (meta, labels)</td></tr>
                                <tr><td><code>--mkcp-font-xs</code></td><td style="color:var(--mkcp-ui-text3)">1rem</td><td style="color:var(--mkcp-ui-text2)">Kleinste tekst (badges, hints)</td></tr>
                                <tr><td><code>--mkcp-img-size</code></td><td style="color:var(--mkcp-ui-text3)">5rem</td><td style="color:var(--mkcp-ui-text2)">Afmeting productafbeelding in winkelwagen-rij</td></tr>
                            </tbody>
                        </table>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:14px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Voorbeeld — brandkleur en bredere popup</p>
                        <code class="mkcp-docs-code"><span class="cm">/* In je thema CSS of in mk-cart-popup/style.css */</span>
:root {
    <span class="ck">--mkcp-accent</span>    : <span class="cv">#e63946</span>;
    <span class="ck">--mkcp-width</span>     : <span class="cv">520px</span>;
    <span class="ck">--mkcp-btn-p-bg</span>  : <span class="cv">#e63946</span>;
}</code>

                    </div>
                </div>


                <!-- ── 5. PHP aanpassen ──────────────────────────────────── -->

                <div class="mkcp-glass" id="php" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['code']; ?></div>
                        <h3><span class="mkcp-docs-section-num">5</span> &nbsp;PHP: config filter</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-docs-callout mkcp-docs-callout--info">
                            <strong>Tip:</strong> het <code>mkcp_config</code> filter wordt uitgevoerd <em>nadat</em> de instellingen uit de database zijn geladen. Wijzigingen via dit filter hebben prioriteit boven de admin-instellingen.
                        </div>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:16px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Configuratie overschrijven</p>
                        <code class="mkcp-docs-code"><span class="cf">add_filter</span>( <span class="cv">'mkcp_config'</span>, <span class="cf">function</span>( $c ) {

    <span class="cm">// Popup-titel aanpassen:</span>
    $c[<span class="cv">'title'</span>] = <span class="cv">'Winkelmand'</span>;

    <span class="cm">// Kortingscode altijd verbergen:</span>
    $c[<span class="cv">'show_coupon'</span>] = <span class="cv">false</span>;

    <span class="cm">// Popup uitschakelen op een specifieke pagina:</span>
    <span class="cf">if</span> ( <span class="cf">is_page</span>( <span class="cv">'actiepagina'</span> ) ) {
        $c[<span class="cv">'enabled'</span>] = <span class="cv">false</span>;
    }

    <span class="cf">return</span> $c;
} );</code>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:16px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Alle beschikbare config-sleutels</p>
                        <table class="mkcp-docs-table">
                            <thead><tr><th>Sleutel</th><th>Type</th><th>Beschrijving</th></tr></thead>
                            <tbody>
                                <tr><td><code>enabled</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Popup tonen of verbergen</td></tr>
                                <tr><td><code>title</code></td><td style="color:var(--mkcp-ui-text3)">string</td><td style="color:var(--mkcp-ui-text2)">Tekst in de header</td></tr>
                                <tr><td><code>btn_checkout</code></td><td style="color:var(--mkcp-ui-text3)">string</td><td style="color:var(--mkcp-ui-text2)">Tekst op de afrekenknop</td></tr>
                                <tr><td><code>show_coupon</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Kortingscode-veld tonen</td></tr>
                                <tr><td><code>free_shipping_bar</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Progressbalk tonen</td></tr>
                                <tr><td><code>free_shipping_threshold</code></td><td style="color:var(--mkcp-ui-text3)">float</td><td style="color:var(--mkcp-ui-text2)">Drempel (0 = auto-detecteer uit WC)</td></tr>
                                <tr><td><code>redirect_cart</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">/cart omleiden</td></tr>
                                <tr><td><code>redirect_cart_url</code></td><td style="color:var(--mkcp-ui-text3)">string</td><td style="color:var(--mkcp-ui-text2)">Waarheen omleiden</td></tr>
                                <tr><td><code>btw_split</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Excl./incl. BTW opsplitsing</td></tr>
                                <tr><td><code>min_order_amount</code></td><td style="color:var(--mkcp-ui-text3)">float</td><td style="color:var(--mkcp-ui-text2)">Minimum bestelbedrag (0 = uit)</td></tr>
                                <tr><td><code>analytics_enabled</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">GA4/GTM dataLayer events</td></tr>
                                <tr><td><code>payment_icons</code></td><td style="color:var(--mkcp-ui-text3)">array</td><td style="color:var(--mkcp-ui-text2)">Array van <code>['url' => ..., 'label' => ...]</code></td></tr>
                                <tr><td><code>usps</code></td><td style="color:var(--mkcp-ui-text3)">array</td><td style="color:var(--mkcp-ui-text2)">Array van <code>['icon' => ..., 'text' => ...]</code></td></tr>
                                <tr><td><code>save_for_later</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Bewaar voor later-functie inschakelen</td></tr>
                                <tr><td><code>stock_indicator</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Voorraadwaarschuwing op bewaarde items</td></tr>
                                <tr><td><code>stock_threshold</code></td><td style="color:var(--mkcp-ui-text3)">int</td><td style="color:var(--mkcp-ui-text2)">Stuks waaronder "laag op voorraad" verschijnt</td></tr>
                                <tr><td><code>cart_icon_selector</code></td><td style="color:var(--mkcp-ui-text3)">string</td><td style="color:var(--mkcp-ui-text2)">CSS-selector voor het winkelwagen-icoon in de header</td></tr>
                                <tr><td><code>cart_badge_position</code></td><td style="color:var(--mkcp-ui-text3)">string</td><td style="color:var(--mkcp-ui-text2)"><code>top-right</code> / <code>top-left</code> / <code>bottom-right</code> / <code>bottom-left</code></td></tr>
                                <tr><td><code>save_cart_url</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Herstel-link genereren inschakelen</td></tr>
                                <tr><td><code>save_cart_email</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Mail naar mijzelf inschakelen</td></tr>
                                <tr><td><code>save_cart_email_subject</code></td><td style="color:var(--mkcp-ui-text3)">string</td><td style="color:var(--mkcp-ui-text2)">Onderwerpregel van de winkelmand-mail</td></tr>
                                <tr><td><code>save_cart_email_body</code></td><td style="color:var(--mkcp-ui-text3)">string</td><td style="color:var(--mkcp-ui-text2)">Introductietekst; gebruik <code>{expiry_days}</code> als plaatshouder</td></tr>
                                <tr><td><code>save_cart_expiry_days</code></td><td style="color:var(--mkcp-ui-text3)">int</td><td style="color:var(--mkcp-ui-text2)">Geldigheid herstel-link in dagen (1–30)</td></tr>
                            </tbody>
                        </table>

                    </div>
                </div>


                <!-- ── 6. Template override ─────────────────────────────── -->

                <div class="mkcp-glass" id="template" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['package']; ?></div>
                        <h3><span class="mkcp-docs-section-num">6</span> &nbsp;PHP: volledige template override</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 12px">
                            Wil je de volledige HTML-structuur van de popup beheren vanuit het thema? Maak dan een bestand aan op:
                        </p>

                        <code class="mkcp-docs-code"><?php echo esc_html( $child_theme ); ?>/mk-cart-popup/cart-popup.php</code>

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:14px 0 12px">
                            De plugin laadt dit bestand automatisch als het bestaat — het vervangt volledig <code>templates/cart-popup.php</code>. De eigen plugin-template fungeert als fallback.
                        </p>

                        <div class="mkcp-docs-callout mkcp-docs-callout--warn">
                            <strong>Let op bij plugin-updates:</strong> een template override erft geen nieuwe features (bijv. een nieuw blok of een nieuw datakenmerk) die de plugin toevoegt. Vergelijk je override na elke plugin-update met de meegeleverde <code>templates/cart-popup.php</code> via een diff-tool.
                        </div>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:16px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Aanbevolen startpunt</p>
                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 8px">
                            Kopieer het originele template als startpunt en pas daarna aan:
                        </p>
                        <code class="mkcp-docs-code"><span class="cm"># Kopieer het originele template naar je child-thema:</span>
cp <?php echo esc_html( MKCP_PATH ); ?>templates/cart-popup.php \
   <?php echo esc_html( $scaffold_dir ); ?>/cart-popup.php</code>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:16px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Beschikbare variabelen in het template</p>
                        <table class="mkcp-docs-table">
                            <thead><tr><th>Variabele</th><th>Type</th><th>Beschrijving</th></tr></thead>
                            <tbody>
                                <tr><td><code>$config</code></td><td style="color:var(--mkcp-ui-text3)">array</td><td style="color:var(--mkcp-ui-text2)">Volledige plugin-configuratie, resultaat van <code>mkcp_config()</code></td></tr>
                                <tr><td><code>$cart</code></td><td style="color:var(--mkcp-ui-text3)">WC_Cart</td><td style="color:var(--mkcp-ui-text2)">WooCommerce-winkelwagen-object</td></tr>
                                <tr><td><code>$cart_count</code></td><td style="color:var(--mkcp-ui-text3)">int</td><td style="color:var(--mkcp-ui-text2)">Aantal producten in de winkelmand</td></tr>
                                <tr><td><code>$threshold</code></td><td style="color:var(--mkcp-ui-text3)">float</td><td style="color:var(--mkcp-ui-text2)">Gratis-verzenddrempel (0 = uitgeschakeld)</td></tr>
                                <tr><td><code>$remaining</code></td><td style="color:var(--mkcp-ui-text3)">float</td><td style="color:var(--mkcp-ui-text2)">Nog te besteden voor gratis verzending</td></tr>
                                <tr><td><code>$free_shipping_met</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Drempel bereikt?</td></tr>
                                <tr><td><code>$progress_pct</code></td><td style="color:var(--mkcp-ui-text3)">int</td><td style="color:var(--mkcp-ui-text2)">Voortgang als percentage (0–100)</td></tr>
                                <tr><td><code>$min_order</code></td><td style="color:var(--mkcp-ui-text3)">float</td><td style="color:var(--mkcp-ui-text2)">Minimaal bestelbedrag (0 = uit)</td></tr>
                                <tr><td><code>$min_order_met</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">Minimum bereikt?</td></tr>
                                <tr><td><code>$btw_split</code></td><td style="color:var(--mkcp-ui-text3)">bool</td><td style="color:var(--mkcp-ui-text2)">BTW-opsplitsing ingeschakeld?</td></tr>
                            </tbody>
                        </table>

                    </div>
                </div>


                <!-- ── 7. Action hooks ───────────────────────────────────── -->

                <div class="mkcp-glass" id="actions" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['zap']; ?></div>
                        <h3><span class="mkcp-docs-section-num">7</span> &nbsp;PHP: action hooks</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 12px">
                            De plugin biedt action hooks op strategische plekken in het template. Gebruik ze om eigen HTML te injecteren zonder het volledige template te kopiëren — ze werken ook als het thema <em>geen</em> template override heeft.
                        </p>

                        <table class="mkcp-docs-table" style="margin-bottom:20px">
                            <thead><tr><th>Hook</th><th>Positie</th><th>Parameters</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><code>mkcp_before_drawer</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Net vóór het openen van de drawer-div (na het backdrop-overlay)</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$config</code></td>
                                </tr>
                                <tr>
                                    <td><code>mkcp_after_header</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Direct na de header (titel + sluiten-knop)</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$config</code></td>
                                </tr>
                                <tr>
                                    <td><code>mkcp_before_items</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Vlak vóór de lijst met winkelwagen-items (na kolomkoppen)</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$config</code>, <code>$cart</code></td>
                                </tr>
                                <tr>
                                    <td><code>mkcp_cart_item_after_info</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Binnen elk item, na naam/prijs/knoppen — vóór de prijskolom</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$cart_item</code>, <code>$product</code>, <code>$cart_item_key</code>, <code>$config</code></td>
                                </tr>
                                <tr>
                                    <td><code>mkcp_after_items</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Direct ná de itemlijst, vóór <code>below-items</code> zone</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$config</code>, <code>$cart</code></td>
                                </tr>
                                <tr>
                                    <td><code>mkcp_before_footer</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Vóór de footer (kortingscode, totalen, afrekenknop)</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$config</code>, <code>$cart</code></td>
                                </tr>
                                <tr>
                                    <td><code>mkcp_after_footer</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Direct ná de footer, vóór het lege-winkelmand-scherm</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$config</code></td>
                                </tr>
                            </tbody>
                        </table>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:0 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Voorbeeld — loyalty-banner boven de items</p>
                        <code class="mkcp-docs-code"><span class="cf">add_action</span>( <span class="cv">'mkcp_before_items'</span>, <span class="cf">function</span>( $config, $cart ) {
    $total = (float) $cart->get_cart_contents_total();
    <span class="cf">if</span> ( $total >= <span class="cv">50</span> ) {
        echo <span class="cv">'&lt;div class="mijn-loyalty-banner"&gt;🎁 Je hebt een gratis cadeau verdiend!&lt;/div&gt;'</span>;
    }
}, <span class="cv">10</span>, <span class="cv">2</span> );</code>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:16px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Voorbeeld — badge per product op basis van meta</p>
                        <code class="mkcp-docs-code"><span class="cf">add_action</span>( <span class="cv">'mkcp_cart_item_after_info'</span>, <span class="cf">function</span>( $item, $product, $key, $config ) {
    $label = $product->get_meta( <span class="cv">'_promo_label'</span> );
    <span class="cf">if</span> ( $label ) {
        echo <span class="cv">'&lt;span class="mijn-promo-badge"&gt;'</span> . esc_html( $label ) . <span class="cv">'&lt;/span&gt;'</span>;
    }
}, <span class="cv">10</span>, <span class="cv">4</span> );</code>

                        <div class="mkcp-docs-callout mkcp-docs-callout--info" style="margin-top:14px">
                            <strong>Waar je de code plaatst:</strong> in <code><?php echo esc_html( $child_theme ); ?>/mk-cart-popup/cart-hooks.php</code> — dat bestand wordt automatisch geladen. Alle WordPress-functies en WooCommerce-functies zijn beschikbaar omdat het pas laadt bij <code>plugins_loaded</code> (prioriteit 20).
                        </div>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:24px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Filter hooks — bezorgdatum kiezer (Cart Checkout, premium)</p>

                        <table class="mkcp-docs-table" style="margin-bottom:14px">
                            <thead><tr><th>Hook</th><th>Wanneer</th><th>Parameters</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><code>mkcp_dd_available_dates</code></td>
                                    <td style="color:var(--mkcp-ui-text2)">Ná cutoff-tijd, aanlooptijd, verzenddagen, geblokkeerde datums én de ingebouwde capaciteitslimiet — vlak vóór de lijst wordt teruggegeven aan de checkout-pagina</td>
                                    <td style="color:var(--mkcp-ui-text3)"><code>$available</code> (string[], Y-m-d), <code>$rate_id</code> (string|null, bv. <code>"flat_rate:2"</code>)</td>
                                </tr>
                            </tbody>
                        </table>

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 12px">
                            De instelling <strong>"Geblokkeerde datums"</strong> (Cart Checkout → Bezorgdatum) dekt alleen een statische lijst die de admin met de hand invult. Deze filter is de plek om datums <em>programmatisch</em> uit te sluiten — bijvoorbeeld op basis van voorraad, een externe feestdagen-API, of vervoerder-capaciteit die niet in de admin-instellingen te vangen is. Eerder kon dit helemaal niet; elke aanpassing vereiste het overschrijven van de hele functie.
                        </p>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:0 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Voorbeeld — kerstavond altijd uitsluiten, ongeacht andere instellingen</p>
                        <code class="mkcp-docs-code"><span class="cf">add_filter</span>( <span class="cv">'mkcp_dd_available_dates'</span>, <span class="cf">function</span>( $available, $rate_id ) {
    <span class="cf">return</span> array_values( array_diff( $available, [ <span class="cv">'2026-12-24'</span> ] ) );
}, <span class="cv">10</span>, <span class="cv">2</span> );</code>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:16px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Voorbeeld — datums uitsluiten op basis van voorraad van een specifiek product</p>
                        <code class="mkcp-docs-code"><span class="cf">add_filter</span>( <span class="cv">'mkcp_dd_available_dates'</span>, <span class="cf">function</span>( $available, $rate_id ) {
    $product = wc_get_product( <span class="cv">123</span> );
    <span class="cf">if</span> ( $product &amp;&amp; $product->get_stock_quantity() &lt;= <span class="cv">0</span> ) {
        <span class="cf">return</span> array_slice( $available, <span class="cv">7</span> ); <span class="cm">// eerste week overslaan bij backorder</span>
    }
    <span class="cf">return</span> $available;
}, <span class="cv">10</span>, <span class="cv">2</span> );</code>

                        <div class="mkcp-docs-callout mkcp-docs-callout--info" style="margin-top:14px">
                            <strong>Waar je de code plaatst:</strong> in <code><?php echo esc_html( $child_theme ); ?>/mk-cart-popup/checkout-hooks.php</code>. Dit is puur PHP-side filtering — er is geen aparte instelling voor nodig in de admin.
                        </div>

                    </div>
                </div>


                <!-- ── 8. Beschikbare functies ───────────────────────────── -->

                <div class="mkcp-glass" id="functies" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['zap']; ?></div>
                        <h3><span class="mkcp-docs-section-num">8</span> &nbsp;Beschikbare PHP-functies</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <table class="mkcp-docs-table">
                            <thead><tr><th>Functie</th><th>Wat het doet</th></tr></thead>
                            <tbody>
                                <tr><td><code>mkcp_config()</code></td><td style="color:var(--mkcp-ui-text2)">Geeft de volledige plugin-configuratie terug als array. Gecached per request. Gebruik <code>mkcp_config</code> filter voor overrides.</td></tr>
                                <tr><td><code>mkcp_is_enabled()</code></td><td style="color:var(--mkcp-ui-text2)">Geeft <code>true</code> als de popup ingeschakeld is.</td></tr>
                                <tr><td><code>mkcp_get_free_shipping_threshold()</code></td><td style="color:var(--mkcp-ui-text2)">Geeft het gratis-verzenddrempelbedrag terug. Leest handmatige instelling, anders auto-detectie uit WC-methoden.</td></tr>
                                <tr><td><code>mkcp_icon( $key )</code></td><td style="color:var(--mkcp-ui-text2)">Echoet inline SVG voor USP-icoon (shield, truck, phone, star, check).</td></tr>
                                <tr><td><code>mkcp_get_fragment()</code></td><td style="color:var(--mkcp-ui-text2)">Rendert het popup-template als WC-fragment array. Intern gebruikt door alle AJAX-handlers.</td></tr>
                                <tr><td><code>mkcp_scaffold_create( $overwrite )</code></td><td style="color:var(--mkcp-ui-text2)">Maakt de scaffold bestanden aan in het child thema. Geeft <code>['created' => [], 'errors' => []]</code> terug.</td></tr>
                                <tr><td><code>mkcp_checkout_config()</code></td><td style="color:var(--mkcp-ui-text2)">Geeft de volledige Cart Checkout-configuratie terug als array (header, stappenindicator, footer, betaalicoontjes). Gecached per request.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>


                <!-- ── 9. Problemen oplossen ─────────────────────────────── -->

                <div class="mkcp-glass" id="problemen" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['alert']; ?></div>
                        <h3><span class="mkcp-docs-section-num">9</span> &nbsp;Problemen oplossen</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php
                        $issues = [
                            [
                                'title' => 'Popup opent niet',
                                'tag'   => 'JS / WooCommerce',
                                'steps' => [
                                    'Controleer of WooCommerce actief is en de plugin is ingeschakeld.',
                                    'Open de browser-console (F12) en zoek naar JavaScript-fouten.',
                                    'Controleer of een caching-plugin de JS cached zonder cache-busting — leeg de cache en hertest.',
                                ],
                            ],
                            [
                                'title' => 'Popup bijwerkt niet na hoeveelheid wijzigen of verwijderen',
                                'tag'   => 'Cache / Nonce',
                                'steps' => [
                                    'Waarschijnlijk een nonce-fout door een object-cache (Varnish, Redis).',
                                    'Sluit nonce-generatie uit van de paginacache, of gebruik een fragment-gebaseerde nonce.',
                                ],
                            ],
                            [
                                'title' => 'CSS overrides werken niet',
                                'tag'   => 'CSS',
                                'steps' => [
                                    'Controleer of <code>style.css</code> in de scaffold-map bestaat en wordt geladen.',
                                    'Als specificiteit het probleem is, voeg <code>body</code> als prefix toe: <code>body .mk-cart-popup__btn {}</code>',
                                ],
                            ],
                            [
                                'title' => 'Gratis-verzending drempel klopt niet',
                                'tag'   => 'Instellingen',
                                'steps' => [
                                    'Auto-detectie leest de eerste ingeschakelde "Gratis verzending" methode uit WooCommerce.',
                                    'Stel een handmatige drempelwaarde in via de plugin-instellingen voor een vaste waarde.',
                                ],
                            ],
                            [
                                'title' => 'Kortingscode geeft geen feedback',
                                'tag'   => 'WooCommerce',
                                'steps' => [
                                    'De feedback komt uit het WooCommerce notice-systeem — controleer of de coupon actief is.',
                                    'Controleer of WC-notices niet worden onderdrukt door het thema of een andere plugin.',
                                ],
                            ],
                            [
                                'title' => 'Betaalicoontjes zijn te groot of te klein',
                                'tag'   => 'CSS',
                                'steps' => [
                                    'Overschrijf de afmeting in je thema-CSS: <code>.mk-cart-popup__payment-icons img { width: Xpx; height: Ypx; }</code>',
                                ],
                            ],
                            [
                                'title' => 'Analytics events komen niet aan in GTM',
                                'tag'   => 'Analytics',
                                'steps' => [
                                    '"Analytics inschakelen" staat aan in de plugin-instellingen.',
                                    'GTM is geladen vóór de plugin-JS — controleer de volgorde.',
                                    'In GTM staat een trigger op Custom Event <code>add_to_cart</code>.',
                                ],
                            ],
                            [
                                'title' => 'cart-hooks.php wordt niet geladen',
                                'tag'   => 'Scaffold',
                                'steps' => [
                                    'Bestand moet staan op: <code>' . esc_html( $scaffold_dir . '/cart-hooks.php' ) . '</code>',
                                    'Controleer schrijfrechten — het bestand moet <code>644</code> of <code>664</code> zijn.',
                                ],
                            ],
                            [
                                'title' => 'Verzendmethode-keuze (Ophalen/Bezorgen-kaarten) verdwijnt of toont niet',
                                'tag'   => 'Theme hooks / Checkout',
                                'steps' => [
                                    'Dit gebeurt bijna altijd door thema-JS die na elke <code>updated_checkout</code>-event de checkout herindeelt (bijv. datumpicker/betaalblok verplaatsen) en daarbij de container met de verzendkeuze niet kent — die container wordt dan met de rest van het blok verborgen in plaats van verplaatst.',
                                    'Controleer of het thema ergens een vaste lijst DOM-ID\'s hanteert die na een AJAX-refresh verplaatst worden (zoek op <code>updated_checkout</code> in de thema-JS) en of de verzendkeuze-container (<code>#mkcp-shipping-choice-container</code>) daar wél in staat.',
                                    'Open de Netwerk-tab (F12) bij een checkout-AJAX-call (<code>wc-ajax=update_order_review</code>) en controleer of het fragment voor <code>#mkcp-shipping-choice-container</code> daadwerkelijk de keuzekaarten bevat — zo ja, dan is het probleem zeker DOM/CSS ná de render, niet de server-kant.',
                                    'Werkt "Afhalen" wel op een andere pagina/thema? Dan ligt het aan thema-specifieke JS, niet aan de plugin-instelling zelf.',
                                ],
                            ],
                            [
                                'title' => 'Template override wordt niet opgepikt',
                                'tag'   => 'Template',
                                'steps' => [
                                    'Bestand moet staan op: <code>' . esc_html( $scaffold_dir . '/cart-popup.php' ) . '</code>',
                                    'Controleer of het een actief child-thema betreft — bij parent-thema\'s werkt <code>locate_template()</code> niet hetzelfde.',
                                    'Wis eventuele plugin- of object-caches die het template kunnen bewaren.',
                                    'Vergelijk na elke plugin-update je override met <code>templates/cart-popup.php</code> om gemiste features te detecteren.',
                                ],
                            ],
                            [
                                'title' => 'Bewaarde items verdwijnen',
                                'tag'   => 'localStorage',
                                'steps' => [
                                    'Items worden opgeslagen in <code>localStorage[\'mkcp_saved_items\']</code> — browserspecifiek.',
                                    'Ze verdwijnen als de bezoeker zijn browserdata wist, maar blijven staan bij paginaverversing en herbezoek.',
                                ],
                            ],
                            [
                                'title' => 'Hartje badge verschijnt niet',
                                'tag'   => 'CSS / Selector',
                                'steps' => [
                                    'Stel de juiste CSS-selector in bij "Winkelwagen-icoon selector", bijv. <code>.header-shop-icon a</code>.',
                                    'Inspecteer het winkelwagen-element in je thema en gebruik de exacte selector.',
                                    'Controleer of het element <code>position: relative</code> heeft — anders valt de badge er buiten.',
                                ],
                            ],
                            [
                                'title' => 'Herstel-link werkt niet',
                                'tag'   => 'Transient / Cache',
                                'steps' => [
                                    'De link bevat een unieke hash die als WordPress-transient is opgeslagen.',
                                    'Controleer of een object-cache de transients weggooit (Varnish, Redis, WP Rocket).',
                                    'Controleer of de link nog niet verlopen is — standaard geldig 7 dagen.',
                                    'Let op: elke bezoeker die de link opent herstelt de cart en de transient wordt daarna verwijderd.',
                                ],
                            ],
                            [
                                'title' => 'Winkelmand-mail komt in spam',
                                'tag'   => 'E-mail / SMTP',
                                'steps' => [
                                    'De standaard <code>wp_mail()</code> gebruikt PHP\'s <code>mail()</code> zonder authenticatie — dat is de hoofdoorzaak.',
                                    'Installeer een SMTP-plugin: <strong>WP Mail SMTP</strong>, <strong>FluentSMTP</strong> of <strong>Post SMTP</strong> (alle gratis).',
                                    'Verbind met een transactionele mailservice: <strong>SendGrid</strong>, <strong>Mailgun</strong> of <strong>Brevo</strong>.',
                                    'Stel een <strong>SPF-record</strong> en <strong>DKIM-signing</strong> in — de mailservice geeft de exacte DNS-waarden.',
                                    'Verstuur altijd vanaf een adres op je eigen domein, bijv. <code>noreply@jouwshop.nl</code>.',
                                    'Test deliverability gratis via <strong>mail-tester.com</strong>.',
                                ],
                            ],
                        ];
                        ?>

                        <div style="display:flex;flex-direction:column;gap:10px">
                        <?php foreach ( $issues as $issue ) : ?>
                            <div style="background:var(--mkcp-ui-surface2);border:1px solid var(--mkcp-ui-border);border-radius:var(--mkcp-ui-radius-sm);padding:14px 16px">
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                    <strong style="font-size:13px;color:var(--mkcp-ui-text)"><?php echo $issue['title']; ?></strong>
                                    <span style="font-size:10px;font-weight:600;background:var(--mkcp-ui-surface3);color:var(--mkcp-ui-text3);border-radius:3px;padding:2px 6px;white-space:nowrap"><?php echo esc_html( $issue['tag'] ); ?></span>
                                </div>
                                <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:3px">
                                    <?php foreach ( $issue['steps'] as $step ) : ?>
                                    <li style="font-size:12px;color:var(--mkcp-ui-text2);line-height:1.6"><?php echo $step; ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        <?php endforeach; ?>
                        </div>

                    </div>
                </div>



                <!-- ── 10. Theme override bestanden ─────────────────────── -->

                <div class="mkcp-glass" id="scaffold" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['package']; ?></div>
                        <h3><span class="mkcp-docs-section-num">10</span> &nbsp;Theme override bestanden (scaffold)</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 14px">
                            De plugin maakt bestanden aan in je child thema die van toepassing zijn op de hele plugin — zowel de popup als de checkout. Ze worden automatisch geladen en blijven staan bij plugin-updates.
                        </p>

                        <div class="mkcp-file-list">
                            <?php
                            $has_template   = file_exists( $scaffold_dir . '/cart-popup.php' );
                            $scaffold_items = [
                                [ 'file' => 'style.css',          'desc' => 'CSS overrides voor de popup — auto-geladen na de plugin-CSS',              'exists' => $has_style_css       ],
                                [ 'file' => 'checkout.css',       'desc' => 'CSS overrides voor de checkout — auto-geladen na de checkout-CSS (premium)', 'exists' => $has_checkout_css    ],
                                [ 'file' => 'cart-hooks.php',     'desc' => 'Algemene cart/popup hooks — auto-geladen bij plugins_loaded',                'exists' => $has_hooks           ],
                                [ 'file' => 'checkout-hooks.php', 'desc' => 'Checkout-specifieke hooks — auto-geladen bij plugins_loaded',                 'exists' => $has_checkout_hooks  ],
                                [ 'file' => 'cart-popup.php',     'desc' => 'Volledige template override — vervangt templates/cart-popup.php',            'exists' => $has_template        ],
                            ];
                            $check_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>';
                            $code_icon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>';
                            foreach ( $scaffold_items as $item ) :
                            ?>
                            <div class="mkcp-file-item">
                                <div class="mkcp-file-icon <?php echo $item['exists'] ? 'mkcp-file-icon--found' : 'mkcp-file-icon--missing'; ?>">
                                    <?php echo $item['exists'] ? $check_icon : $code_icon; ?>
                                </div>
                                <div class="mkcp-file-info">
                                    <code><?php echo esc_html( $child_theme . '/mk-cart-popup/' . $item['file'] ); ?></code>
                                    <small><?php echo esc_html( $item['desc'] ); ?></small>
                                </div>
                                <span class="mkcp-status <?php echo $item['exists'] ? 'mkcp-status--green' : 'mkcp-status--amber'; ?>">
                                    <span class="mkcp-status-dot"></span>
                                    <?php echo $item['exists'] ? 'Gevonden' : 'Ontbreekt'; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ( ! $has_hooks || ! $has_style_css ) : ?>
                        <div class="mkcp-docs-callout mkcp-docs-callout--warn" style="margin-top:14px">
                            Nog niet alle bestanden zijn aangemaakt. <a href="<?php echo esc_url( $settings_url . '&tab=overrides' ); ?>">→ Ga naar Theme Overrides</a> om ze aan te maken.
                        </div>
                        <?php else : ?>
                        <div class="mkcp-docs-callout mkcp-docs-callout--tip" style="margin-top:14px">
                            Alle scaffold bestanden zijn aanwezig en worden automatisch geladen.
                        </div>
                        <?php endif; ?>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:16px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Werkstroom voor CSS</p>
                        <code class="mkcp-docs-code"><span class="cm"># Bewerk direct het CSS bestand in je thema:</span>
<?php echo esc_html( $scaffold_dir ); ?>/style.css

<span class="cm"># De plugin laadt style.css automatisch.</span>
<span class="cm"># Versie via filemtime — geen cache-busting nodig.</span></code>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:20px 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Hooks isolatie op de checkout</p>

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 10px">
                            Met <strong>Theme hooks uitschakelen</strong> (Checkout → Styling) bouw je een schone checkout waarbij alleen de scaffold bestanden actief zijn. De plugin verwijdert alle PHP-callbacks waarvan het bronbestand in het thema-pad valt — de scaffold-map <code>mk-cart-popup/</code> is hiervan uitgezonderd.
                        </p>

                        <table class="mkcp-docs-table" style="margin-bottom:12px">
                            <thead><tr><th>Bestand</th><th>Status bij "hooks uitschakelen"</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><code>mk-cart-popup/cart-hooks.php</code></td>
                                    <td style="color:var(--mkcp-ui-green)">✓ Altijd actief — uitgezonderd van de sweep</td>
                                </tr>
                                <tr>
                                    <td><code>mk-cart-popup/checkout-hooks.php</code></td>
                                    <td style="color:var(--mkcp-ui-green)">✓ Altijd actief — uitgezonderd van de sweep</td>
                                </tr>
                                <tr>
                                    <td><code>functions.php</code> en alle includes</td>
                                    <td style="color:var(--mkcp-ui-text3)">Verwijderd op de checkout</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="mkcp-docs-callout mkcp-docs-callout--tip">
                            <strong>Migratiestrategie:</strong> kopieer hooks die je wél nodig hebt op de checkout vanuit <code>functions.php</code> naar <code>checkout-hooks.php</code>. Zo bouw je de betaalpagina stap voor stap op met volledige controle over wat er actief is.
                        </div>

                    </div>
                </div>


                <!-- ── 11. Licentie activeren ──────────────────────────── -->

                <div class="mkcp-glass" id="licenties" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['shield']; ?></div>
                        <h3><span class="mkcp-docs-section-num">11</span> &nbsp;Licentie activeren</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 16px">
                            Je licentiesleutel ontvang je van Mediakanjers. Voer hem in via <a href="<?php echo esc_url( admin_url( 'admin.php?page=mkcp-settings' ) ); ?>" style="color:var(--mkcp-ui-accent)">WooCommerce → Cart Popup</a> → tabblad <strong>Licentie</strong>. Na opslaan verifieert de plugin de sleutel automatisch.
                        </p>

                        <div class="mkcp-docs-callout mkcp-docs-callout--tip" style="margin-bottom:20px">
                            Twijfel of je sleutel geldig is? Ga naar het <strong>Licentie</strong>-tabblad en klik op <strong>Verifieer nu</strong> om de cache direct te wissen en opnieuw te valideren.
                        </div>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:0 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Wat is er beschikbaar per licentie?</p>
                        <table class="mkcp-docs-table" style="margin-bottom:4px">
                            <thead><tr><th>Licentie</th><th>Wat je kunt gebruiken</th></tr></thead>
                            <tbody>
                                <tr><td><code>Geen</code></td><td style="color:var(--mkcp-ui-text2)">Plugin is geïnstalleerd maar geblokkeerd. Voer een licentiesleutel in om te activeren.</td></tr>
                                <tr><td><code>Basic</code></td><td style="color:var(--mkcp-ui-text2)">Alle basisinstellingen beschikbaar. Premium-functies (BTW-split, Analytics, Bewaar voor later, Winkelmand delen, Voorraad indicator, Content Builder) zijn vergrendeld.</td></tr>
                                <tr><td><code>Premium</code></td><td style="color:var(--mkcp-ui-text2)">Alle functies ontgrendeld.</td></tr>
                            </tbody>
                        </table>

                    </div>
                </div>

            </div><!-- /mkcp-panel docs -->

            <?php if ( defined( 'MKCP_DEV' ) && MKCP_DEV ) : ?>
            <div class="mkcp-panel" data-panel="development">

                <div class="mkcp-page-header">
                    <h2><?php echo $icons_svg['git-branch']; ?> Voor ontwikkelaars</h2>
                    <p class="mkcp-docs-intro">Git-branches, release-workflow en bestandsoverzicht voor de ontwikkeling van de plugin.</p>
                </div>

                <div class="mkcp-glass" id="ontwikkeling" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['git-branch']; ?></div>
                        <h3>GitHub &amp; release-workflow</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-docs-callout mkcp-docs-callout--info" style="margin-bottom:20px">
                            Volledige technische documentatie staat in <code>DEVELOPMENT.md</code> in de plugin-root, en op GitHub:
                            <a href="https://github.com/mediakanjers/mk-cart-popup/blob/main/DEVELOPMENT.md" target="_blank" rel="noopener" style="color:var(--mkcp-ui-accent)">mediakanjers/mk-cart-popup → DEVELOPMENT.md</a>
                        </div>


                        <!-- Branches -->
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:0 0 6px;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Git-branches</p>
                        <table class="mkcp-docs-table" style="margin-bottom:20px">
                            <thead><tr><th>Branch</th><th>Doel</th></tr></thead>
                            <tbody>
                                <tr><td><code>main</code></td><td style="color:var(--mkcp-ui-text2)">Stabiele releases — wat klanten ontvangen via de auto-updater</td></tr>
                                <tr><td><code>dev</code></td><td style="color:var(--mkcp-ui-text2)">Lopende ontwikkeling — hier worden features gebouwd en getest</td></tr>
                            </tbody>
                        </table>


                        <!-- Release checklist -->
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:0 0 6px;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Een nieuwe versie uitbrengen</p>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px">
                            <?php
                            $steps = [
                                [ 'n' => '1', 'title' => 'Bump versienummer', 'body' => 'Pas <code>Version:</code> in de plugin-header én <code>MKCP_VER</code> in <code>mk-cart-popup.php</code> aan. Beide moeten identiek zijn.' ],
                                [ 'n' => '2', 'title' => 'Ontwikkel &amp; test op <code>dev</code>', 'body' => 'Bouw de builder indien nodig: <code>npm run build</code>. Test grondig op een eigen test-WordPress, nooit rechtstreeks op een klantsite.' ],
                                [ 'n' => '3', 'title' => 'Merge <code>dev</code> → <code>main</code>', 'body' => 'Push naar GitHub. Op dit moment gaat er nog niets naar klanten.' ],
                                [ 'n' => '4', 'title' => 'Publiceer een GitHub Release', 'body' => 'Ga naar <strong>github.com/mediakanjers/mk-cart-popup → Releases → Draft a new release</strong>. Kies tag <code>v{versienummer}</code>, bijv. <code>v1.2.1</code>, en klik <strong>Publish release</strong>.' ],
                                [ 'n' => '✓', 'title' => 'GitHub Action doet de rest automatisch', 'body' => 'De Action bouwt de plugin-zip, voegt hem toe aan de Release en werkt <code>mk-cart-popup-update.json</code> op <code>main</code> bij. Klanten ontvangen de update bij de volgende WordPress-updatecheck (max. 6 uur).' ],
                            ];
                            foreach ( $steps as $step ) :
                            ?>
                            <div style="display:flex;gap:12px;background:var(--mkcp-ui-surface2);border:1px solid var(--mkcp-ui-border);border-radius:var(--mkcp-ui-radius-sm);padding:12px 14px;align-items:flex-start">
                                <span style="min-width:24px;height:24px;background:var(--mkcp-ui-accent-soft);color:var(--mkcp-ui-accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0"><?php echo $step['n']; ?></span>
                                <div>
                                    <strong style="font-size:13px;color:var(--mkcp-ui-text);display:block;margin-bottom:2px"><?php echo $step['title']; ?></strong>
                                    <span style="font-size:12px;color:var(--mkcp-ui-text2);line-height:1.6"><?php echo $step['body']; ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>


                        <!-- Bestandsoverzicht -->
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:0 0 6px;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Sleutelbestanden</p>
                        <table class="mkcp-docs-table" style="margin-bottom:20px">
                            <thead><tr><th>Bestand</th><th>Build-stap?</th><th>Omschrijving</th></tr></thead>
                            <tbody>
                                <tr><td><code>src/admin/builder/index.js</code></td><td style="color:var(--mkcp-ui-text3)">Ja — Vite</td><td style="color:var(--mkcp-ui-text2)">Bronbestand van de Content Builder</td></tr>
                                <tr><td><code>admin/assets/builder.js</code></td><td style="color:var(--mkcp-ui-text3)">Gegenereerd</td><td style="color:var(--mkcp-ui-text2)">Gebouwde builder — nooit handmatig bewerken</td></tr>
                                <tr><td><code>admin/assets/settings.js</code></td><td style="color:var(--mkcp-ui-text3)">Nee</td><td style="color:var(--mkcp-ui-text2)">JS voor de instellingenpagina</td></tr>
                                <tr><td><code>admin/assets/settings.css</code></td><td style="color:var(--mkcp-ui-text3)">Nee</td><td style="color:var(--mkcp-ui-text2)">CSS voor de admin-UI</td></tr>
                                <tr><td><code>admin/assets/checkout.js</code></td><td style="color:var(--mkcp-ui-text3)">Nee</td><td style="color:var(--mkcp-ui-text2)">JS voor de Cart Checkout admin</td></tr>
                                <tr><td><code>mk-cart-popup-update.json</code></td><td style="color:var(--mkcp-ui-text3)">Automatisch</td><td style="color:var(--mkcp-ui-text2)">Update-manifest — door de GitHub Action bijgewerkt</td></tr>
                                <tr><td><code>.github/workflows/release.yml</code></td><td style="color:var(--mkcp-ui-text3)">—</td><td style="color:var(--mkcp-ui-text2)">GitHub Action voor geautomatiseerde releases</td></tr>
                            </tbody>
                        </table>


                        <!-- Lokale ontwikkeling -->
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:0 0 6px;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Lokale ontwikkeling — builder</p>
                        <code class="mkcp-docs-code"><span class="cm"># Eenmalig — installeer build-dependencies</span>
npm install

<span class="cm"># Tijdens het ontwikkelen — herbouwt automatisch bij elke opslag</span>
npm run dev

<span class="cm"># Vóór een release — definitieve geoptimaliseerde build</span>
npm run build</code>

                        <div class="mkcp-docs-callout mkcp-docs-callout--warn" style="margin-top:14px">
                            <strong>Let op:</strong> ontwikkel nooit rechtstreeks in de <code>plugins/</code>-map van een klantsite. Gebruik een eigen test-WordPress. Zie <code>DEVELOPMENT.md</code> voor de aanbevolen mapstructuur.
                        </div>

                    </div>
                </div>


                <!-- ── Licentiebeheer (intern) ──────────────────────────── -->

                <div class="mkcp-glass" style="scroll-margin-top:24px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons_svg['shield']; ?></div>
                        <h3>Licentiebeheer</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 14px">
                            Sleutels worden aangemaakt en beheerd via het <strong>MK Licentiebeheer</strong>-dashboard. De plugin valideert elke sleutel tegen het endpoint op <code>support.mediakanjers.nl</code>.
                        </p>

                        <div class="mkcp-docs-callout mkcp-docs-callout--info" style="margin-bottom:16px">
                            <strong>Validatie-endpoint:</strong><br>
                            <code><?php echo esc_html( MKCP_LICENSE_URL ); ?></code><br><br>
                            <strong>Licentiedashboard:</strong><br>
                            <code>support.mediakanjers.nl/wp-admin</code> → Licentiebeheer
                        </div>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:0 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Een sleutel aanmaken</p>
                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 10px">
                            Ga naar <strong>support.mediakanjers.nl/wp-admin → Licentiebeheer → Nieuwe sleutel</strong>. Vul in:
                        </p>
                        <table class="mkcp-docs-table" style="margin-bottom:14px">
                            <thead><tr><th>Veld</th><th>Waarden</th><th>Toelichting</th></tr></thead>
                            <tbody>
                                <tr><td><code>Tier</code></td><td style="color:var(--mkcp-ui-text3)"><code>basic</code> of <code>premium</code></td><td style="color:var(--mkcp-ui-text2)">Bepaalt welke features de plugin ontgrendelt</td></tr>
                                <tr><td><code>Domein</code></td><td style="color:var(--mkcp-ui-text3)"><code>domein.nl</code> of <code>*</code></td><td style="color:var(--mkcp-ui-text2)">Zonder <code>https://</code> of <code>www.</code> — of <code>*</code> voor elk domein</td></tr>
                                <tr><td><code>Vervaldatum</code></td><td style="color:var(--mkcp-ui-text3)"><code>YYYY-MM-DD</code> of leeg</td><td style="color:var(--mkcp-ui-text2)">Leeg = nooit verlopen</td></tr>
                                <tr><td><code>Notitie</code></td><td style="color:var(--mkcp-ui-text3)">vrije tekst</td><td style="color:var(--mkcp-ui-text2)">Klantnaam of omschrijving — alleen intern zichtbaar</td></tr>
                            </tbody>
                        </table>
                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 16px">
                            Het dashboard genereert automatisch een unieke sleutel in het formaat <code>MK-BASIC-XXXX-XXXX-XXXX</code> of <code>MK-PREM-XXXX-XXXX-XXXX</code>. Geef de sleutel door aan de klant — hij vult hem in onder <strong>WooCommerce → Cart Popup → Licentie</strong>.
                        </p>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:0 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Sleutel intrekken of wijzigen</p>
                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 16px">
                            Ga naar <strong>Licentiebeheer</strong> in de WP-admin van <code>support.mediakanjers.nl</code>. Je kunt een sleutel deactiveren, de vervaldatum aanpassen of verwijderen. De plugin merkt dit bij het volgende cache-vervallmoment (standaard 24 uur). Om het direct te laten ingaan: vraag de klant te klikken op <strong>Licentie → Verifieer nu</strong>.
                        </p>

                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:0 0 6px; font-weight:600; text-transform:uppercase; letter-spacing:.4px">Beveiliging — geen apart geheim, wel misbruikdetectie</p>
                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 10px">
                            De licentiesleutel zelf is het enige credential — er zit geen extra gedeeld geheim/HMAC in de plugin (dat zou toch net zo goed leesbaar zijn voor iedereen met bestandstoegang tot een klant-server als wanneer het gewoon in de publieke GitHub-repo stond). <code>validate.php</code> checkt sleutel-status, vervaldatum, domein-match en past rate limiting toe per IP/sleutel.
                        </p>
                        <div class="mkcp-docs-callout mkcp-docs-callout--tip">
                            Wordt een sleutel gebruikt op een ander domein dan waarvoor 'm is uitgegeven, dan weigert de server het verzoek én markeert de sleutel als <strong>verdacht</strong> in het Licentiebeheer-dashboard (rode badge + teller). Dat is het signaal dat een sleutel mogelijk gelekt/gedeeld is — de aanpak is dan: intrekken en een nieuwe sleutel uitgeven, niet een geheim opnieuw uitwisselen.
                        </div>

                    </div>
                </div>

            </div><!-- /mkcp-panel development -->
            <?php endif; ?>

        </main>

    </div>

    <style>
        .mkcp-docs-tab {
            flex: 1;
            padding: 6px 4px;
            border-radius: 6px;
            border: 1px solid var(--mkcp-ui-border);
            background: transparent;
            color: var(--mkcp-ui-text2);
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: background .15s, color .15s, border-color .15s;
        }
        .mkcp-docs-tab svg { width: 12px; height: 12px; }
        .mkcp-docs-tab.is-active {
            background: var(--mkcp-ui-accent-soft);
            color: var(--mkcp-ui-accent);
            border-color: var(--mkcp-ui-accent);
        }
    </style>

    <script>
    (function() {
        var tabs     = document.querySelectorAll('.mkcp-docs-tab');
        var panels   = document.querySelectorAll('[data-panel]');
        var anchorNav = document.getElementById('mkcp-docs-anchor-nav');

        function activate(target) {
            tabs.forEach(function(t) {
                t.classList.toggle('is-active', t.dataset.target === target);
            });
            panels.forEach(function(p) {
                p.classList.toggle('is-active', p.dataset.panel === target);
            });
            if (anchorNav) {
                anchorNav.style.display = target === 'docs' ? '' : 'none';
            }
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var target = this.dataset.target;
                history.replaceState(null, '', location.pathname + location.search +
                    (target === 'development' ? '#ontwikkeling' : ''));
                activate(target);
            });
        });

        if (location.hash === '#ontwikkeling') {
            activate('development');
        }
    })();
    </script>

    <?php
}
