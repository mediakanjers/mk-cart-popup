<?php
    /**
     * MK Cart Popup — Account sjabloon (Fase 1, stap 1 fundament; sidebar/
     * bottom-nav/theme-toggle-shell toegevoegd bij het "Mijn Account
     * Dashboard"-herontwerp)
     *
     * Geladen via template_include. Doet NIET get_header()/get_footer(), zodat
     * het thema's header.php/footer.php nooit uitvoeren — zelfde aanpak als
     * templates/checkout-page.php.
     *
     * De navigatie (sidebar + mobiele bottom-nav) staat hier server-side vast
     * — beide delen dezelfde [data-route]-knoppen/links, dus de bestaande
     * routing-/click-delegatie in assets/account.js (gebonden op het hele
     * #mkcp-account-nav-element) werkt voor allebei zonder aparte JS.
     *
     * De theme-toggle staat BUITEN #mkcp-account-content (dat door de router
     * bij elke navigatie wordt vervangen) zodat 'm ook na het wisselen van
     * tab blijft staan — in het ontwerp stond de knop alleen in de Dashboard-
     * header, maar dan zou je 'm op elke andere pagina kwijt zijn.
     */
    if ( ! defined( 'ABSPATH' ) ) exit;

    $mkcp_user     = wp_get_current_user();
    $mkcp_unread   = function_exists( 'mkcp_account_get_unread_notifications_count' ) ? mkcp_account_get_unread_notifications_count( $mkcp_user->ID ) : 0;
    $mkcp_initials = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( $mkcp_user->first_name ?: $mkcp_user->display_name, 0, 1 ) . mb_substr( $mkcp_user->last_name, 0, 1 ) )
        : strtoupper( substr( $mkcp_user->display_name, 0, 1 ) );
    $mkcp_since    = $mkcp_user->user_registered ? mysql2date( 'Y', $mkcp_user->user_registered ) : '';

    // Eén array i.p.v. de sidebar/bottom-nav-markup dubbel uit te schrijven —
    // route/label/icoon per item, allebei de navs lopen 'm hieronder simpelweg af.
    $mkcp_nav_items = [
        'dashboard'     => [ 'label' => __( 'Dashboard', 'mk-cart-popup' ),        'icon' => 'home' ],
        'orders'        => [ 'label' => __( 'Bestellingen', 'mk-cart-popup' ),     'icon' => 'box' ],
        'wishlist'      => [ 'label' => __( 'Wishlist', 'mk-cart-popup' ),         'icon' => 'heart' ],
        'addresses'     => [ 'label' => __( 'Adressen', 'mk-cart-popup' ),         'icon' => 'pin' ],
        'notifications' => [ 'label' => __( 'Meldingen', 'mk-cart-popup' ),        'icon' => 'bell', 'badge' => $mkcp_unread ],
        'profile'       => [ 'label' => __( 'Accountgegevens', 'mk-cart-popup' ),  'icon' => 'sliders' ],
    ];

    // Uitgezette modules (admin/views/settings-page.php, data-panel=
    // "account-modules") verdwijnen uit beide navs — het loopje hieronder
    // dat de sidebar/bottom-nav rendert slaat ontbrekende routes al
    // stilzwijgend over (regel ~117), dus unset() hier is genoeg.
    if ( function_exists( 'mkcp_account_module_enabled' ) ) {
        if ( ! mkcp_account_module_enabled( 'wishlist' ) )      unset( $mkcp_nav_items['wishlist'] );
        if ( ! mkcp_account_module_enabled( 'notifications' ) ) unset( $mkcp_nav_items['notifications'] );
    }

    // Sidebar-groepering — puur visuele hiërarchie (geen routing-impact, de
    // bottom-nav hieronder blijft de platte lijst gebruiken want daar is
    // simpelweg geen ruimte voor sectielabels).
    $mkcp_nav_groups = [
        'winkelen' => [ 'label' => __( 'Winkelen', 'mk-cart-popup' ), 'routes' => [ 'dashboard', 'orders', 'wishlist' ] ],
        'account'  => [ 'label' => __( 'Account', 'mk-cart-popup' ),  'routes' => [ 'addresses', 'notifications', 'profile' ] ],
    ];

    $mkcp_icons = [
        'home'    => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'box'     => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>',
        'heart'   => '<path d="M12 20s-7-4.35-9.5-8.5C1 8 2 4.5 5.5 4.5c2 0 3.5 1.5 4.5 3 1-1.5 2.5-3 4.5-3 3.5 0 4.5 3.5 3 7C19 15.65 12 20 12 20z"/>',
        'pin'     => '<path d="M12 21s7-7.2 7-12a7 7 0 10-14 0c0 4.8 7 12 7 12z"/><circle cx="12" cy="11" r="2"/>',
        'bell'    => '<path d="M6 8a6 6 0 1112 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 21a2 2 0 004 0"/>',
        'sliders' => '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="18" r="2"/>',
        'logout'  => '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
        'sun'     => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'    => '<path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>',
    ];

    /** Klein herbruikbaar icoontje, bewust géén losse functie/bestand — leeft alleen hier, in beide navs. */
    $mkcp_icon = function( string $name, string $stroke = 'currentColor' ) use ( $mkcp_icons ) {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $stroke ) . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ( $mkcp_icons[ $name ] ?? '' ) . '</svg>';
    };
?>

<!DOCTYPE html>

<html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <script>
        // Vóór de eerste render het opgeslagen thema al zetten (i.p.v. pas na
        // het laden van account.js in de footer) — anders flitst de pagina
        // eerst kort in het lichte thema en springt daarna naar donker, bij
        // elke paginalading opnieuw. localStorage is hier synchroon
        // beschikbaar, dus dit <script> in de <head> kan dat vóór CSS-verf
        // nog net op tijd voorkomen.
        (function () {
            try {
                var stored = localStorage.getItem('mkcp_account_theme');
                var theme  = stored || ( window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light' );
                document.documentElement.setAttribute('data-mkcp-theme', theme);
            } catch ( e ) {}
        })();
        </script>
        <?php wp_head(); ?>
    </head>
    <body <?php body_class( 'mkcp-account-page' ); ?>>
        <?php wp_body_open(); ?>
        <div id="mkcp-account-app" class="mkcp-account-app">

            <aside class="mkcp-account-sidebar">
                <div class="mkcp-account-brand">
                    <span class="mkcp-account-brand__mark" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                    <span class="mkcp-account-brand__label"><?php esc_html_e( 'Mijn Account', 'mk-cart-popup' ); ?></span>
                </div>

                <div class="mkcp-account-profile-card">
                    <span class="mkcp-account-profile-card__avatar"><?php echo esc_html( $mkcp_initials ); ?></span>
                    <span class="mkcp-account-profile-card__info">
                        <span class="mkcp-account-profile-card__name"><?php echo esc_html( $mkcp_user->first_name ?: $mkcp_user->display_name ); ?></span>
                        <?php if ( $mkcp_since ) : ?>
                            <span class="mkcp-account-profile-card__since"><?php
                                /* translators: %s: jaartal */
                                printf( esc_html__( 'klant sinds %s', 'mk-cart-popup' ), esc_html( $mkcp_since ) );
                            ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <nav id="mkcp-account-nav" class="mkcp-account-nav" aria-label="<?php esc_attr_e( 'Accountnavigatie', 'mk-cart-popup' ); ?>">
                    <?php foreach ( $mkcp_nav_groups as $group ) : ?>
                        <span class="mkcp-account-nav__group-label"><?php echo esc_html( $group['label'] ); ?></span>
                        <?php foreach ( $group['routes'] as $route ) :
                            $item = $mkcp_nav_items[ $route ] ?? null;
                            if ( ! $item ) continue;
                            ?>
                            <a href="#/<?php echo esc_attr( $route ); ?>" data-route="<?php echo esc_attr( $route ); ?>" data-route-label="<?php echo esc_attr( $item['label'] ); ?>" class="mkcp-account-nav__link<?php echo $route === 'dashboard' ? ' is-active' : ''; ?>">
                                <?php echo $mkcp_icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <span class="mkcp-account-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
                                <?php if ( isset( $item['badge'] ) ) : ?>
                                    <span class="mkcp-account-nav__badge js-mkcp-account-nav-badge" id="mkcp-account-nav-badge"<?php echo $item['badge'] > 0 ? '' : ' hidden'; ?>><?php echo esc_html( (string) $item['badge'] ); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="mkcp-account-nav__link mkcp-account-nav__link--logout">
                        <?php echo $mkcp_icon( 'logout' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span class="mkcp-account-nav__label"><?php esc_html_e( 'Uitloggen', 'mk-cart-popup' ); ?></span>
                    </a>
                </nav>
            </aside>

            <main class="mkcp-account-main">
                <div class="mkcp-account-topbar">
                    <?php
                    // Klein "kruimelpad" i.p.v. nóg een <h1> — elk fragment
                    // heeft al zijn eigen echte paginatitel; dit is puur een
                    // klein oriëntatiepunt bovenaan (en vult de eerder helemaal
                    // lege topbar, die naast de thema-knop nergens inhoud had).
                    ?>
                    <div class="mkcp-account-topbar__crumb" id="mkcp-account-topbar-title" aria-hidden="true">
                        <span class="mkcp-account-topbar__crumb-root"><?php esc_html_e( 'Account', 'mk-cart-popup' ); ?></span>
                        <span class="mkcp-account-topbar__crumb-sep">/</span>
                        <span class="mkcp-account-topbar__crumb-current"><?php esc_html_e( 'Dashboard', 'mk-cart-popup' ); ?></span>
                    </div>
                    <button type="button" id="mkcp-account-theme-toggle" class="mkcp-account-theme-toggle" aria-label="<?php esc_attr_e( 'Wissel tussen licht en donker thema', 'mk-cart-popup' ); ?>">
                        <span class="mkcp-account-theme-toggle__icon mkcp-account-theme-toggle__icon--sun"><?php echo $mkcp_icon( 'sun' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                        <span class="mkcp-account-theme-toggle__icon mkcp-account-theme-toggle__icon--moon"><?php echo $mkcp_icon( 'moon' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                    </button>
                </div>

                <div id="mkcp-account-content" class="mkcp-account-content" role="tabpanel" aria-live="polite">
                    <!-- Gevuld door assets/account.js via de AJAX-fragmentdispatcher -->
                </div>
            </main>

            <nav class="mkcp-account-bottomnav" aria-label="<?php esc_attr_e( 'Accountnavigatie', 'mk-cart-popup' ); ?>">
                <?php foreach ( array_slice( $mkcp_nav_items, 0, 5, true ) as $route => $item ) : ?>
                    <a href="#/<?php echo esc_attr( $route ); ?>" data-route="<?php echo esc_attr( $route ); ?>" class="mkcp-account-bottomnav__link<?php echo $route === 'dashboard' ? ' is-active' : ''; ?>">
                        <?php echo $mkcp_icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span><?php echo esc_html( $item['label'] ); ?></span>
                        <?php if ( isset( $item['badge'] ) ) : ?>
                            <span class="mkcp-account-nav__badge mkcp-account-bottomnav__badge js-mkcp-account-nav-badge"<?php echo $item['badge'] > 0 ? '' : ' hidden'; ?>><?php echo esc_html( (string) $item['badge'] ); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            // Eigen gestylede bevestigingsdialoog i.p.v. de kale, niet-thema-
            // bare window.confirm() — gebruikt door assets/account.js voor elke
            // destructieve actie (adres/wishlist verwijderen). Staat BUITEN
            // #mkcp-account-content zodat 'm bij elke route herbruikt kan
            // worden i.p.v. per fragment opnieuw op te bouwen. Zelfde
            // inert/focus-restore-toegankelijkheidspatroon als de login-modal
            // op de checkout (includes/checkout-frontend.php).
            ?>
            <div id="mkcp-account-confirm" class="mkcp-account-confirm" inert>
                <div class="mkcp-account-confirm__backdrop"></div>
                <div class="mkcp-account-confirm__dialog" role="alertdialog" aria-modal="true" aria-labelledby="mkcp-account-confirm-message" tabindex="-1">
                    <p id="mkcp-account-confirm-message" class="mkcp-account-confirm__message"></p>
                    <div class="mkcp-account-confirm__actions">
                        <button type="button" class="mkcp-btn mkcp-btn--text" id="mkcp-account-confirm-cancel"><?php esc_html_e( 'Annuleren', 'mk-cart-popup' ); ?></button>
                        <button type="button" class="mkcp-btn mkcp-btn--danger" id="mkcp-account-confirm-ok"><?php esc_html_e( 'Verwijderen', 'mk-cart-popup' ); ?></button>
                    </div>
                </div>
            </div>

        </div>
        <?php wp_footer(); ?>
    </body>
</html>
