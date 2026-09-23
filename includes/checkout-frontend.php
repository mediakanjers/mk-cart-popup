<?php
/**
 * MK Cart Popup — Checkout Frontend
 *
 * On the WooCommerce checkout page, serves a completely custom page template
 * that does NOT call get_header() / get_footer(). This means the theme's
 * header.php and footer.php are never executed — no CSS selector guessing needed.
 *
 * Only active when a premium license is present and Cart Checkout is enabled.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Block checkout detectie ───────────────────────────────────────────────────
// Alle custom-checkout-features hier draaien op hooks/veld-ID's die alléén
// bestaan bij de klassieke (shortcode) checkout. Op het WooCommerce
// Checkout-blok (React) vuren die hooks niet af en verdwijnen features
// stilletjes — deze check maakt dat zichtbaar i.p.v. onopgemerkt.

function mkcp_checkout_uses_blocks(): bool {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    if ( ! function_exists( 'has_block' ) ) return $cache = false;

    // FSE-thema's kunnen de checkout via een wp_template (Site Editor)
    // definiëren i.p.v. pagina-inhoud. WooCommerce's eigen fallback hiervoor
    // (CartCheckoutUtils::is_checkout_block_default()) zit in een interne,
    // niet-publieke namespace — onveilig om aan te roepen. Vandaar dezelfde
    // check met alleen stabiele publieke corefuncties (beide sinds WP 5.9).
    if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && function_exists( 'get_block_templates' ) ) {
        $templates = get_block_templates( [ 'slug__in' => [ 'checkout' ] ], 'wp_template' );
        foreach ( $templates as $tpl ) {
            if ( has_block( 'woocommerce/checkout', $tpl->content ) ) return $cache = true;
        }
    }

    if ( ! function_exists( 'wc_get_page_id' ) ) return $cache = false;
    $page_id = wc_get_page_id( 'checkout' );
    if ( $page_id <= 0 ) return $cache = false;
    return $cache = has_block( 'woocommerce/checkout', $page_id );
}

// Nette admin-notice, maar die zat verstopt in de reguliere WP-notice-balk
// bovenaan het instellingenscherm — een winkelier die 'm mist ziet Cart
// Checkout gewoon "niks doen" zonder duidelijke reden. mkcp_checkout_setup_status()
// hieronder maakt dezelfde conditie ook zichtbaar als een permanent kaartje op
// het Checkout-dashboard (zie settings-page.php, Installatie-check-blok).
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'mkcp-settings' ) return;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( ! mkcp_checkout_uses_blocks() ) return;

    echo '<div class="notice notice-warning"><p>'
        . '<strong>MK Cart Popup:</strong> '
        . esc_html__( 'De checkoutpagina gebruikt het WooCommerce Checkout-blok (Cart & Checkout blocks). De "Cart Checkout"-aanpassingen van deze plugin (layout, BTW-switch, postcode-koppeling) zijn gebouwd voor de klassieke checkout en hebben geen effect op het blok. Schakel het Checkout-blok uit op de checkoutpagina om deze functies te blijven gebruiken.', 'mk-cart-popup' )
        . '</p></div>';
} );

// ── Setup-check: WordPress/WooCommerce/externe-plugin-vereisten ─────────────
//
// Zelfde patroon als mkcp_account_setup_status() (includes/account-frontend.php):
// platte, read-only array, geen caching/transients, puur voor het Installatie-
// check-kaartenblok op het Checkout-dashboard. Hergebruikt uitsluitend al
// bestaande detectiefuncties hierboven/verderop in dit bestand — geen nieuwe
// checks, alleen zichtbaar gemaakt op één centrale plek i.p.v. losse notices.
function mkcp_checkout_setup_status(): array {
    $cfg = function_exists( 'mkcp_checkout_config' ) ? mkcp_checkout_config() : [];

    return [
        // true = geen probleem. Blocks-checkout maakt de HELE Cart Checkout-
        // module onwerkzaam, ongeacht andere instellingen — vandaar los van
        // checkout_enabled zelf gecheckt (altijd relevant om te weten).
        'uses_blocks' => mkcp_checkout_uses_blocks(),

        // Alleen relevant als de bijbehorende toggle ook echt aanstaat — een
        // winkelier die deze integraties niet gebruikt hoeft geen kaartje
        // over een niet-geïnstalleerde plugin te zien.
        'postcode_checker_relevant' => ! empty( $cfg['postcode_checker_lock_fields'] ),
        'postcode_checker_active'   => function_exists( 'mkcp_postcode_checker_active' ) && mkcp_postcode_checker_active(),

        'vat_checker_relevant' => ! empty( $cfg['vat_checker_status_enabled'] ),
        'vat_checker_active'   => function_exists( 'mkcp_vat_checker_active' ) && mkcp_vat_checker_active(),

        // Adreskiezer (checkout-address-picker.php) is een pure keten-
        // afhankelijkheid van de Account-module — geen eigen instelling om aan
        // te checken, dus hier direct de onderliggende voorwaarde herhaald
        // i.p.v. mkcp_addr_picker_active() (die vereist is_checkout(), niet
        // beschikbaar op het instellingenscherm).
        'account_module_active' => function_exists( 'mkcp_account_feature_enabled' ) && mkcp_account_feature_enabled(),
    ];
}


// ── Dequeue theme stylesheets on plugin checkout page ────────────────────────
// wp_head() still fires inside the custom template, loading all theme styles.
// Strip anything sourced from the (child) theme directory; WooCommerce and
// plugin styles stay.
//
// NB: reads $wp_styles->registered directly — no public accessor exists for
// "all registered handles". Stable since WP 2.6 but undocumented, so guarded
// defensively (degrades to "theme CSS stays loaded" rather than fataling).
//
// Runs TWICE: wp_enqueue_scripts@9999 (normal case) and wp_footer@15 (safety
// net), because some theme frameworks re-enqueue CSS from a "load in footer"
// callback that fires after the first sweep. The hook-removal sweep below
// normally prevents that callback from running at all, but this stays as
// defense-in-depth for anything it doesn't reach, or if dequeue_theme_hooks
// is off while dequeue_theme_css is on.

function mkcp_checkout_dequeue_theme_styles() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    // Op een Blocks-checkout laadt template_include het custom sjabloon niet
    // meer (zie hierboven) — dan thema-CSS alsnog strippen zou de pagina
    // kapot maken zonder een compenserend eigen sjabloon.
    if ( mkcp_checkout_uses_blocks() ) return;
    if ( empty( $cfg['dequeue_theme_css'] ) ) return;

    global $wp_styles;
    if ( ! ( $wp_styles instanceof WP_Styles ) || empty( $wp_styles->registered ) ) return;

    $theme_uri = get_template_directory_uri();
    $child_uri = get_stylesheet_directory_uri();    
    $scaffold_uri = $child_uri . '/mk-cart-popup/';

    foreach ( $wp_styles->registered as $handle => $style ) {
        if ( ! isset( $style->src ) ) continue;
        $src = (string) $style->src;
        // Keep plugin's own scaffold CSS, even if it's in the theme directory.
        if ( strpos( $src, $scaffold_uri ) !== false ) continue;
        // Dequeue any other CSS from the theme or child theme.
        if ( strpos( $src, $theme_uri ) !== false || strpos( $src, $child_uri ) !== false ) {            
            wp_dequeue_style( $handle );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'mkcp_checkout_dequeue_theme_styles', 9999 );
// Priority 15: after the default wp_footer priority (10) most theme
// frameworks use for "load in footer" (confirmed via theme_scripts_footer()
// in MKTheme), but before core's wp_print_footer_scripts (priority 20).
// Priority 1 (original choice) ran too early to catch it.
add_action( 'wp_footer', 'mkcp_checkout_dequeue_theme_styles', 15 );


// ── Dequeue theme scripts on plugin checkout page ────────────────────────────
// Same idea as mkcp_checkout_dequeue_theme_styles() above, but for scripts.
// Own toggle (dequeue_theme_js) since theme JS is more likely than theme CSS
// to throw console errors here — a script written for the theme's own
// header/footer markup may reach for elements (menus, sliders, cookie
// banners) that don't exist on this custom page.
//
// The scaffold folder is exempted so a developer always has a clean place to
// add checkout JS guaranteed to survive this sweep. Dequeuing (not
// deregistering) is safe for dependencies: WP's dependency resolution still
// pulls in a theme handle when a non-theme script legitimately needs it.

function mkcp_checkout_dequeue_theme_scripts() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    // Zie de toelichting bij mkcp_checkout_dequeue_theme_styles() hierboven —
    // zelfde reden om hier ook terug te stappen op een Blocks-checkout.
    if ( mkcp_checkout_uses_blocks() ) return;
    if ( empty( $cfg['dequeue_theme_js'] ) ) return;

    global $wp_scripts;
    if ( ! ( $wp_scripts instanceof WP_Scripts ) || empty( $wp_scripts->registered ) ) return;

    $theme_uri    = get_template_directory_uri();
    $child_uri    = get_stylesheet_directory_uri();
    $scaffold_uri = $child_uri . '/mk-cart-popup/';

    foreach ( $wp_scripts->registered as $handle => $script ) {
        if ( ! isset( $script->src ) || $script->src === '' ) continue;
        $src = (string) $script->src;
        if ( strpos( $src, $scaffold_uri ) !== false ) continue;
        if ( strpos( $src, $theme_uri ) !== false || strpos( $src, $child_uri ) !== false ) {
            wp_dequeue_script( $handle );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'mkcp_checkout_dequeue_theme_scripts', 9999 );
// Priority 15 — see the comment above mkcp_checkout_dequeue_theme_styles()'s
// wp_footer registration for why (same reasoning applies here).
add_action( 'wp_footer', 'mkcp_checkout_dequeue_theme_scripts', 15 );


// ── Remove theme hooks on plugin checkout page ───────────────────────────────
// Iterates $wp_filter and removes any callback whose source file lives inside
// the active theme's directory — CHILD *and* PARENT (a parent-theme framework
// function is just as much "theme code") — but not the mk-cart-popup
// scaffold subfolder. Runs on `wp` (priority 20) after the scaffold loads.
//
// NB: uses PHP Reflection to inspect every callback site-wide — not a public
// WP API. Wrapped defensively (instance checks, per-callback + outer
// try/catch) so an unusual callback shape is skipped rather than fataling
// the checkout page.

function mkcp_checkout_remove_theme_hooks() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    // Zie de toelichting bij mkcp_checkout_dequeue_theme_styles() hierboven —
    // een site-brede hook-sweep zonder compenserend custom sjabloon zou een
    // Blocks-checkout onnodig kunnen beschadigen (bv. thema-navigatie/consent-
    // banner-hooks die ook op die pagina relevant zijn).
    if ( mkcp_checkout_uses_blocks() ) return;
    if ( empty( $cfg['dequeue_theme_hooks'] ) ) return;

    // Deze functie hangt aan 3 hooks (wp, woocommerce_checkout_update_order_
    // review, woocommerce_checkout_process) omdat elk zijn eigen moment nodig
    // heeft om ooit te vuren (zie de toelichtingen bij elke add_action
    // hieronder) — maar remove_filter() hierbinnen is permanent voor de rest
    // van het request, dus 1x is genoeg. Zonder deze guard deed de dure
    // Reflection-scan (hieronder, over ALLE site-brede hooks) zich tot 3x
    // voor bij één checkout-AJAX-interactie — precies het patroon dat elke
    // andere functie in dit bestand al met een eigen "static $registered"
    // voorkomt, hier per ongeluk gemist.
    static $done = false;
    if ( $done ) return;
    $done = true;

    $child_dir  = wp_normalize_path( get_stylesheet_directory() );
    // get_template_directory() equals get_stylesheet_directory() when no
    // child theme is active — array_unique() below then just dedupes.
    $parent_dir   = wp_normalize_path( get_template_directory() );
    $theme_dirs   = array_unique( [ $child_dir, $parent_dir ] );
    $scaffold_dir = $child_dir . '/mk-cart-popup';

    // Escape hatch: a theme hook that must survive even though its file is
    // outside the scaffold folder, e.g. because another surviving script
    // implicitly depends on it in a way WordPress has no record of. Named
    // functions only — a Closure has no stable name to filter on, and if you
    // can edit the file you can just move it into the scaffold folder
    // instead. See mk-cart-popup/checkout-hooks.php for an example.
    $exclude_names = apply_filters( 'mkcp_checkout_dequeue_exclude_functions', [] );

    // Deze WooCommerce "cart item data"-filters blijven altijd buiten schot.
    // Concreet incident: een thema-hook op woocommerce_get_item_data toonde
    // per besteld product welk kaartje/bericht de klant had gekozen — die
    // verdween stilzwijgend zodra de functie toevallig in het thema stond.
    // Qua contract retourneren deze hooks alleen een waarde die WooCommerce
    // zelf op een vaste plek in de layout zet, nooit de layout zelf — sweepen
    // kan hier dus nooit een layoutprobleem oplossen, alleen klantdata laten
    // verdwijnen.
    $protected_hooks = [
        'woocommerce_get_item_data',
        'woocommerce_cart_item_name',
        'woocommerce_cart_item_thumbnail',
        'woocommerce_cart_item_class',
        'woocommerce_cart_item_price',
        'woocommerce_cart_item_subtotal',
        'woocommerce_order_item_name',
        'woocommerce_order_item_thumbnail',
        'woocommerce_order_item_class',
    ];

    global $wp_filter;
    if ( ! is_array( $wp_filter ) && ! ( $wp_filter instanceof Traversable ) ) return;

    try {
        foreach ( $wp_filter as $hook_name => $hook_obj ) {
            if ( in_array( $hook_name, $protected_hooks, true ) ) continue;
            if ( ! ( $hook_obj instanceof WP_Hook ) || empty( $hook_obj->callbacks ) ) continue;

            foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $cb ) {
                    $func = $cb['function'] ?? null;
                    if ( is_string( $func ) && in_array( $func, $exclude_names, true ) ) continue;
                    try {
                        if ( is_array( $func ) && count( $func ) === 2 ) {
                            $ref = new ReflectionMethod( $func[0], $func[1] );
                        } elseif ( is_string( $func ) && strpos( $func, '::' ) !== false ) {
                            // Static "Class::method" string callable — ReflectionFunction
                            // does not accept this syntax, only ReflectionMethod does.
                            // Handmatig splitsen i.p.v. new ReflectionMethod( $func ) met
                            // 1 argument: dat enkele-argument-formaat is deprecated sinds
                            // PHP 8.5 (ReflectionMethod::createFromMethodName() is het
                            // vervangende alternatief, maar pas beschikbaar vanaf PHP 8.3 —
                            // deze plugin ondersteunt vanaf PHP 8.1, dus handmatig splitsen
                            // werkt op alle ondersteunde versies zonder deprecation-notice).
                            [ $cls, $method ] = explode( '::', $func, 2 );
                            $ref = new ReflectionMethod( $cls, $method );
                        } elseif ( $func instanceof Closure || is_string( $func ) ) {
                            $ref = new ReflectionFunction( $func );
                        } else {
                            continue;
                        }
                        $file = wp_normalize_path( (string) $ref->getFileName() );
                        if ( strpos( $file, $scaffold_dir ) !== false ) continue;
                        foreach ( $theme_dirs as $theme_dir ) {
                            if ( strpos( $file, $theme_dir ) !== false ) {
                                remove_filter( $hook_name, $func, $priority );
                                break;
                            }
                        }
                    } catch ( ReflectionException $e ) {
                        // Skip uninspectable callbacks (internal/built-in functions).
                    }
                }
            }
        }
    } catch ( Throwable $e ) {
        // Never let this hardening feature take down the checkout page.
    }
}
add_action( 'wp', 'mkcp_checkout_remove_theme_hooks', 20 );

// wc-ajax (?wc-ajax=update_order_review, elke adres-/verzendmethode-wijziging)
// bootstrapt WordPress zonder ooit de 'wp' action te vuren (zelfde reden als
// de BTW-switch-registratie verderop in dit bestand) — zonder deze tweede
// registratie blijven thema-hooks op die AJAX-cyclus (bv.
// woocommerce_update_order_review_fragments) voor altijd buiten bereik van de
// sweep. Prioriteit 1: vóór WC_AJAX::update_order_review() shipping/fragments
// berekent — is_checkout() klopt hier al doordat die functie zelf de
// WOOCOMMERCE_CHECKOUT-constante zet vóórdat deze hook vuurt.
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_checkout_remove_theme_hooks', 1 );

// Zelfde reden, voor het echte afrekenverzoek (?wc-ajax=checkout). Concreet
// incident: het thema's oude afhaaldatum/tijdvak-validatie (CW_Pickup_Checkout,
// cw_pickup_date/cw_pickup_timeslot) bleef verplicht terwijl die velden niet
// meer gerenderd werden, waardoor afhalen via includes/pickup.php nooit kon
// afrekenen. Prioriteit 1: vóór de eerste actie in
// WC_Checkout::process_checkout(), ruim vóór latere validatie-/opslaghooks.
add_action( 'woocommerce_checkout_process', 'mkcp_checkout_remove_theme_hooks', 1 );


// ── Helpers: locate/detach a callback regardless of which hook it's on ──────────
// Een thema kan een WooCommerce-kernfunctie (bv. woocommerce_checkout_payment)
// op elke willekeurige hook laten hangen, dus werken deze helpers op de naam
// van de callback zelf i.p.v. een vaste hooklocatie: registered_anywhere()
// leest alleen ("staat 'ie ergens?"), detach_everywhere() haalt 'm overal weg.
function mkcp_checkout_callback_registered_anywhere( $callback ) {
    global $wp_filter;
    if ( ! is_array( $wp_filter ) && ! ( $wp_filter instanceof Traversable ) ) return false;

    foreach ( $wp_filter as $hook_name => $hook_obj ) {
        if ( ! ( $hook_obj instanceof WP_Hook ) ) continue;
        if ( has_action( $hook_name, $callback ) !== false ) return true;
    }
    return false;
}

function mkcp_checkout_detach_callback_everywhere( $callback ) {
    global $wp_filter;
    if ( ! is_array( $wp_filter ) && ! ( $wp_filter instanceof Traversable ) ) return false;

    $found = false;
    foreach ( $wp_filter as $hook_name => $hook_obj ) {
        if ( ! ( $hook_obj instanceof WP_Hook ) ) continue;
        $priority = has_action( $hook_name, $callback );
        if ( $priority !== false ) {
            remove_action( $hook_name, $callback, $priority );
            $found = true;
        }
    }
    return $found;
}


// ── Claim ownership of WooCommerce's payment-section rendering ──────────────────
// Concreet incident: het thema (Mediakanjers/functions/woo/woo-checkout.php)
// verplaatst woocommerce_checkout_payment naar cobweb_after_checkout_form —
// een hook die deze scaffold nooit aanroept, waardoor #payment
// (betaalmethodes + "Plaats bestelling") spoorloos verdwijnt. Op andere sites
// (bv. TOM-Bloemen → woocommerce_before_order_notes) vuurt de verplaatste
// hook wél nog, dus blind terugzetten op de standaardplek zou daar dubbele
// rendering veroorzaken. Vandaar twee aparte, veilige mechanismen hieronder
// i.p.v. één blinde restore.
//
// Nevenbaat: alles op woocommerce_review_order_before_payment (de "above/
// below-payment" content-builder-zones) vuurt alleen als bijeffect van
// woocommerce_checkout_payment()'s rendering, en herstelt dus vanzelf mee.
function mkcp_checkout_claim_payment_section() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    if ( ! function_exists( 'woocommerce_checkout_payment' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( mkcp_checkout_uses_blocks() ) return;
    if ( empty( $cfg['dequeue_theme_hooks'] ) ) return;

    // 3-blokken layout alleen actief als minstens één visuele feature aan
    // staat — zelfde voorwaarde als mkcp_checkout_visual_layout() hieronder,
    // want alleen dan bestaat er een .mkcp-co-section--payment om in te
    // claimen.
    $has_visual = ! empty( $cfg['header_enabled'] ) || ! empty( $cfg['footer_enabled'] )
               || ! empty( $cfg['steps_enabled'] ) || ! empty( $cfg['payment_icons_enabled'] );

    static $claimed = false;
    if ( $claimed ) return;
    $claimed = true;

    if ( $has_visual ) {
        // Mechanisme A — de plugin bepaalt zelf waar de betaalsectie
        // rendert, ongeacht waar hij nu hangt: altijd weghalen, altijd op
        // de eigen sectiehook zetten. Geen gok, geen detectie nodig.
        mkcp_checkout_detach_callback_everywhere( 'woocommerce_checkout_payment' );
        add_action( 'mkcp_checkout_payment_section', 'woocommerce_checkout_payment', 10 );
    } elseif ( ! mkcp_checkout_callback_registered_anywhere( 'woocommerce_checkout_payment' ) ) {
        // Mechanisme B — geen visuele sectie om 'm in te claimen (dus geen
        // vaste plek om naartoe te verplaatsen). Alleen ingrijpen als de
        // functie werkelijk nérgens meer geregistreerd staat; staat hij
        // ergens anders (thema-eigen, mogelijk werkende plek), dan blijft
        // die met rust — anders zou dit exact de TOM-Bloemen-situatie
        // dubbel kunnen renderen.
        add_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
    }
}
add_action( 'wp', 'mkcp_checkout_claim_payment_section', 21 );
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_checkout_claim_payment_section', 2 );


// ── Fall back to WooCommerce's own checkout templates instead of theme overrides ──
//
// De sweep hierboven verwijdert alleen hooks/callbacks ($wp_filter) — een
// thema-override zoals yourtheme/woocommerce/checkout/review-order.php wordt
// echter helemaal niet via een hook geladen: wc_locate_template() zoekt zo'n
// bestand rechtstreeks op via locate_template() en include't het, volledig
// buiten het hook-systeem om. De Reflection-sweep kan dat dus per definitie
// niet zien of tegenhouden. Deze filter grijpt in op WooCommerce's eigen
// template-resolutie: zodra het gevonden bestand in de (child of parent)
// themamap zit, valt de checkout terug op WooCommerce's eigen default-
// template — hetzelfde idee als de hook-sweep, maar voor bestanden i.p.v.
// hooks. Alleen "checkout/*"-templates, andere WC-onderdelen blijven onaangeroerd.
//
// Draait OOK op woocommerce_checkout_update_order_review: wc-ajax
// (template_redirect@0, eindigt met wp_die()) vuurt nooit 'wp'. Zonder deze
// tweede registratie gold de terugval alleen voor de eerste paginalaad — bij
// de eerste AJAX-refresh zou het thema's (mogelijk kapotte) template dan
// alsnog terugkomen, zichtbaar pas ná de eerste klant-interactie.
function mkcp_checkout_register_template_fallback() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['dequeue_theme_hooks'] ) ) return;

    static $registered = false;
    if ( $registered ) return;
    $registered = true;

    add_filter( 'woocommerce_locate_template', function( $template, $template_name, $template_path, $default_path = '' ) {
        if ( strpos( (string) $template_name, 'checkout/' ) !== 0 ) return $template;

        $child_dir  = wp_normalize_path( get_stylesheet_directory() );
        $parent_dir = wp_normalize_path( get_template_directory() );
        $normalized = wp_normalize_path( (string) $template );

        if ( strpos( $normalized, $child_dir ) === false && strpos( $normalized, $parent_dir ) === false ) {
            return $template;
        }

        if ( ! $default_path && function_exists( 'WC' ) ) {
            $default_path = WC()->plugin_path() . '/templates/';
        }
        return $default_path ? $default_path . $template_name : $template;
    }, 10, 4 );
}
add_action( 'wp', 'mkcp_checkout_register_template_fallback', 5 );
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_checkout_register_template_fallback', 1 );


// ── Productthumbnail terugzetten (compenseert de template-fallback hierboven) ──
// WC's eigen checkout/review-order.php heeft geen thumbnail-kolom (alleen
// naam + totaal), dus zodra de template-fallback hierboven het thema's
// versie (die vaak wél een thumbnail heeft) vervangt, verdwijnt die mee. In
// plaats van een aparte kolom terug te zetten (colspan-risico), wordt de
// thumbnail hier binnen dezelfde naam-cel gezet via woocommerce_cart_item_name
// — kolomstructuur blijft altijd exact 2. Zelfde wp+ajax dubbele registratie
// als de template-fallback, om dezelfde reden.
function mkcp_checkout_register_thumbnail_fallback() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['dequeue_theme_hooks'] ) ) return;

    static $registered = false;
    if ( $registered ) return;
    $registered = true;

    add_filter( 'woocommerce_cart_item_name', function( $name, $cart_item ) {
        if ( strpos( $name, 'mkcp-co-item-thumb' ) !== false ) return $name;

        $product = $cart_item['data'] ?? null;
        if ( ! $product instanceof WC_Product ) return $name;

        $thumbnail = $product->get_image( 'thumbnail', [ 'class' => 'mkcp-co-item-thumb' ] );
        return $thumbnail ? $thumbnail . $name : $name;
    }, 5, 2 );
}
add_action( 'wp', 'mkcp_checkout_register_thumbnail_fallback', 5 );
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_checkout_register_thumbnail_fallback', 1 );


// ── Besteloverzicht groeperen op "wordt verzonden" / "wordt afgehaald" ───────
// Tagt elke rij van #order_review met een klasse. De filter zelf wordt
// altijd geregistreerd (geen vroege "is het wel gemengd?"-gate meer — dat
// forceerde eerder een dure, premature WC()->cart->calculate_shipping()-
// aanroep op nog niet bijgewerkte klantgegevens, zie de lange toelichting
// bij mkcp_cart_item_fulfillment_roles() in shipping-choice.php). De filter
// zelf vuurt pas TIJDENS het echte tabel-renderen (ná WC's eigen
// calculate_totals()), dus de rol-berekening daarbinnen is dan altijd al
// gebaseerd op verse, correcte gegevens — geen eigen calculate_shipping()
// nodig. Is het winkelmandje niet gemengd, dan tagt dit gewoon alles met
// dezelfde klasse; de client-side groepering hieronder doet dan sowieso
// niets (die vereist minstens 1 van elke groep om te groeperen).
function mkcp_checkout_register_fulfillment_grouping() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_shipping_choice_is_active' ) || ! mkcp_shipping_choice_is_active() ) return;
    if ( mkcp_checkout_uses_blocks() ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    static $registered = false;
    if ( $registered ) return;
    $registered = true;

    add_filter( 'woocommerce_cart_item_class', function( $class, $cart_item, $cart_item_key ) {
        $roles = mkcp_cart_item_fulfillment_roles();
        $group = ( $roles[ $cart_item_key ] ?? 'delivery' ) === 'pickup' ? 'pickup' : 'shipped';
        return trim( $class . ' mkcp-fulfil-' . $group );
    }, 10, 3 );
}
add_action( 'wp', 'mkcp_checkout_register_fulfillment_grouping', 21 );
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_checkout_register_fulfillment_grouping', 1 );


// ── BTW switch: dual price output + UI ───────────────────────────────────────
// Standalone function so it can be called from both 'wp' (normal load) and
// 'woocommerce_checkout_update_order_review' (AJAX refresh) — wc-ajax doesn't
// fire 'wp', so without the second call prices would revert to plain output
// after every field change.

function mkcp_register_btw_price_filters() {
    static $registered = false;
    if ( $registered ) return;
    $registered = true;

    // Reuse the same configurable label strings as the popup ("excl. BTW" / "incl. BTW").
    $main_cfg   = mkcp_config();
    $label_excl = $main_cfg['label_excl_tax'] ?? __( 'excl. BTW', 'mk-cart-popup' );
    $label_incl = $main_cfg['label_incl_tax'] ?? __( 'incl. BTW', 'mk-cart-popup' );

    // Checkout order review line item totals (right column).
    // WooCommerce review-order.php uses woocommerce_cart_item_subtotal (same filter as cart page).
    add_filter( 'woocommerce_cart_item_subtotal', function( $subtotal, $cart_item ) {
        if ( strpos( $subtotal, 'mkcp-co-price' ) !== false ) return $subtotal;
        $excl = wc_price( $cart_item['line_total'] );
        $incl = wc_price( $cart_item['line_total'] + $cart_item['line_tax'] );
        return '<span class="mkcp-co-price">'
             . '<span class="price-incl-tax">' . $incl . '</span>'
             . '<span class="price-excl-tax">' . $excl . '</span>'
             . '</span>';
    }, 20, 2 );

    // Unit price (stuk prijs) injected directly after the product name link.
    // Uses woocommerce_cart_item_name so the price appears BELOW the name and
    // ABOVE the ×N quantity badge (which is output by a separate filter after).
    add_filter( 'woocommerce_cart_item_name', function( $name, $cart_item ) use ( $label_excl, $label_incl ) {
        $product = $cart_item['data'] ?? null;
        if ( ! $product instanceof WC_Product ) return $name;
        $unit_excl = wc_get_price_excluding_tax( $product, [ 'qty' => 1 ] );
        $unit_incl = wc_get_price_including_tax( $product, [ 'qty' => 1 ] );
        $price     = '<span class="mkcp-co-unit-price mkcp-co-price">'
                   . '<span class="price-incl-tax">' . wc_price( $unit_incl ) . ' <span class="mkcp-tax-label">' . esc_html( $label_incl ) . '</span></span>'
                   . '<span class="price-excl-tax">' . wc_price( $unit_excl ) . ' <span class="mkcp-tax-label">' . esc_html( $label_excl ) . '</span></span>'
                   . '</span>';
        return $name . $price;
    }, 20, 2 );

    // Cart subtotal row — with "incl. BTW" / "excl. BTW" label, matching the popup.
    add_filter( 'woocommerce_cart_subtotal', function( $subtotal, $compound, $cart ) use ( $label_excl, $label_incl ) {
        if ( strpos( $subtotal, 'mkcp-co-price' ) !== false ) return $subtotal;
        $excl = wc_price( $cart->get_subtotal() );
        $incl = wc_price( $cart->get_subtotal() + $cart->get_subtotal_tax() );
        return '<span class="mkcp-co-price">'
             . '<span class="price-incl-tax">' . $incl . ' <span class="mkcp-tax-label">' . esc_html( $label_incl ) . '</span></span>'
             . '<span class="price-excl-tax">' . $excl . ' <span class="mkcp-tax-label">' . esc_html( $label_excl ) . '</span></span>'
             . '</span>';
    }, 20, 3 );

    // Order total — dual amounts + small "incl. BTW" / "excl. BTW" note below.
    add_filter( 'woocommerce_cart_totals_order_total_html', function( $html ) use ( $label_excl, $label_incl ) {
        if ( strpos( $html, 'mkcp-co-price' ) !== false ) return $html;
        $cart  = WC()->cart;
        $total = (float) $cart->get_total( 'edit' );
        $tax   = (float) $cart->get_total_tax();
        $incl  = wc_price( $total );
        $excl  = wc_price( $total - $tax );
        $dual  = '<span class="mkcp-co-price">'
               . '<span class="price-incl-tax">' . $incl . '</span>'
               . '<span class="price-excl-tax">' . $excl . '</span>'
               . '</span>';
        $note  = '<small class="mkcp-btw-note mkcp-btw-incl-only">' . esc_html( $label_incl ) . '</small>'
               . '<small class="mkcp-btw-note mkcp-btw-excl-only">' . esc_html( $label_excl ) . '</small>';
        return '<strong>' . $dual . '</strong>' . $note;
    }, 20 );
}

// Fires on normal page load.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    // Effective BTW: follow popup setting unless admin explicitly overrides in checkout.
    $follow_popup   = isset( $cfg['btw_follow_popup'] ) ? (bool) $cfg['btw_follow_popup'] : true;
    $popup_btw_on   = ! empty( mkcp_config()['btw_split'] );
    $btw_active     = $follow_popup ? $popup_btw_on : ! empty( $cfg['btw_switch'] );
    if ( ! $btw_active ) return;
    if ( ! wc_tax_enabled() ) return;

    mkcp_register_btw_price_filters();


    // BTW switch UI — priority 5: renders BEFORE #order_review (prio 10) so the
    // DOM order itself places it above the card. Uses identical HTML + classes
    // as the popup's checkbox-toggle (templates/cart-popup.php) so cart-popup.css
    // styles apply without duplication — same visual switch on both.
    add_action( 'woocommerce_checkout_order_review', function() {
        // Account-link icoontje ernaast — zelfde opzet/instelling als de
        // winkelwagenpopup: één URL werkt voor zowel uitgelogde bezoekers
        // (WooCommerce toont dan vanzelf het inlogformulier) als ingelogde
        // klanten (dashboard), alleen het label/icoon-tekstje verschilt.
        $account_link_url  = '';
        $account_link_name = '';
        $main_cfg = mkcp_config();
        if (
            ! empty( $main_cfg['account_link_enabled'] )
            && function_exists( 'mkcp_account_feature_enabled' ) && mkcp_account_feature_enabled()
        ) {
            $account_link_url = get_permalink( wc_get_page_id( 'myaccount' ) );
            if ( is_user_logged_in() ) {
                $current_user      = wp_get_current_user();
                $account_link_name = $current_user->first_name ?: $current_user->display_name;
            }
        }
        ?>
        <div class="mk-cart-popup__btw-switch mkcp-co-btw-switch">
            <label class="mk-cart-popup__btw-toggle">
                <input type="checkbox" class="js-mkcp-btw-toggle" aria-label="<?php esc_attr_e( 'Inclusief BTW', 'mk-cart-popup' ); ?>">
                <span class="mk-cart-popup__btw-toggle-track">
                    <span class="mk-cart-popup__btw-toggle-thumb">
                        <svg class="mk-cart-popup__btw-toggle-check" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                    </span>
                </span>
            </label>
            <span class="mk-cart-popup__btw-label"><?php esc_html_e( 'Inclusief BTW', 'mk-cart-popup' ); ?></span>
            <?php if ( $account_link_url ) : ?>
            <div class="mk-cart-popup__utility-icons">
                <?php
                $account_link_label = $account_link_name
                    ? sprintf( /* translators: %s: voornaam van de klant */ __( 'Hoi %s — naar account', 'mk-cart-popup' ), $account_link_name )
                    : __( 'Inloggen', 'mk-cart-popup' );
                ?>
                <a href="<?php echo esc_url( $account_link_url ); ?>" class="mk-cart-popup__utility-icon mk-cart-popup__utility-icon--account" aria-label="<?php echo esc_attr( $account_link_label ); ?>" title="<?php echo esc_attr( $account_link_label ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    <span class="mk-cart-popup__utility-icon-label"><?php echo esc_html( $account_link_label ); ?></span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }, 5 );

    // Enqueue BTW switch script.
    add_action( 'wp_enqueue_scripts', function() {
        wp_enqueue_script(
            'mkcp-checkout-btw',
            MKCP_URL . 'assets/checkout-btw.js',
            [],
            MKCP_VER, // was filemtime() — onnodige stat()-syscall, inconsistent met de rest van de plugin
            true
        );
    } );
}, 5 );


// ── Filter-pillen "Alles / Wordt verzonden / Wordt afgehaald" ────────────────
// Zelfde gate als mkcp_checkout_register_fulfillment_grouping() hierboven
// (alleen tonen bij een écht gemengd winkelmandje), maar los geregistreerd:
// dit hoeft niet vroeg (vóór het renderen) geregistreerd te staan zoals de
// woocommerce_cart_item_class-filter — dit IS zelf al de renderfunctie, dus
// simpelweg gewoon aan de hook hangen (geen wp/ woocommerce_checkout_update_
// order_review-dubbelregistratie nodig, die hook vuurt toch al bij zowel de
// normale paginalaad als elke AJAX-ververting).
add_action( 'woocommerce_checkout_order_review', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_shipping_choice_is_active' ) || ! mkcp_shipping_choice_is_active() ) return;
    if ( function_exists( 'mkcp_checkout_uses_blocks' ) && mkcp_checkout_uses_blocks() ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( ! function_exists( 'mkcp_cart_has_mixed_fulfillment' ) || ! mkcp_cart_has_mixed_fulfillment() ) return;

    $roles  = mkcp_cart_item_fulfillment_roles();
    $counts = [ 'shipped' => 0, 'pickup' => 0 ];
    foreach ( $roles as $role ) {
        $counts[ 'pickup' === $role ? 'pickup' : 'shipped' ]++;
    }

    // Hergebruikt de EXACTE classes van de "Ik ben al klant / Ik ben
    // nieuw"-switcher (mkcp-checkout-switcher/mkcp-switcher-tabs/
    // mkcp-switcher-tab/mkcp-switcher-indicator, zie addTab() rond regel
    // 1664) i.p.v. eigen, benaderende CSS — twee eerdere, zelfgemaakte
    // versies kwamen niet overeen qua grootte/kleur en het schuivende
    // balkje matchte niet. Door letterlijk dezelfde classes + dezelfde
    // window.mkcpSwitcher.updateIndicator()-functie te hergebruiken (zie
    // wp_footer hieronder) is er geen eigen, opnieuw af te stemmen CSS meer
    // nodig — dit ERFT gewoon de bestaande, al goedgekeurde styling.
    // data-mkcp-filter (i.p.v. data-target) voorkomt dat de vaste
    // login/account-volgorde-regel (checkout.scss, [data-target=...]) hier
    // toevallig ook op reageert.
    $icon_all     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>';
    $icon_shipped = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="6" width="14" height="11"/><path d="M15 9h4l3 3v5h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17.5" cy="19" r="2"/></svg>';
    $icon_pickup  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1-5h16l1 5"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M9 21v-8h6v8"/></svg>';
    // Geen chevron hier — dat pijltje communiceert "dit leidt ergens heen"
    // (klopt bij de login/account-switcher, die een modal opent) maar niet
    // bij een filter dat gewoon ter plekke de lijst aanpast.
    ?>
    <div class="mkcp-checkout-switcher mkcp-fulfil-switcher">
        <div class="mkcp-switcher-tabs" role="group" aria-label="<?php esc_attr_e( 'Filter op afhandeling', 'mk-cart-popup' ); ?>">
            <button type="button" class="mkcp-switcher-tab is-selected" data-mkcp-filter="all" aria-pressed="true">
                <?php echo $icon_all; ?>
                <span><?php echo esc_html( sprintf( /* translators: %d: aantal producten */ __( 'Alles (%d)', 'mk-cart-popup' ), count( $roles ) ) ); ?></span>
            </button>
            <button type="button" class="mkcp-switcher-tab" data-mkcp-filter="shipped" aria-pressed="false">
                <?php echo $icon_shipped; ?>
                <span><?php echo esc_html( sprintf( /* translators: %d: aantal producten */ __( 'Verzenden (%d)', 'mk-cart-popup' ), $counts['shipped'] ) ); ?></span>
            </button>
            <button type="button" class="mkcp-switcher-tab" data-mkcp-filter="pickup" aria-pressed="false">
                <?php echo $icon_pickup; ?>
                <span><?php echo esc_html( sprintf( /* translators: %d: aantal producten */ __( 'Afhalen (%d)', 'mk-cart-popup' ), $counts['pickup'] ) ); ?></span>
            </button>
            <span class="mkcp-switcher-indicator"></span>
        </div>
    </div>
    <?php
}, 7 );


// ── Scroll-hint-pijltje: strak-passende wikkel-div om ALLEEN de tabel ────────
// De tabel zelf (met de scrollende, gemaskeerde tbody) rendert op priority 10
// (WC's eigen woocommerce_order_review()) — deze twee kleine haakjes (9 open,
// 11 dicht) wikkelen daar EXACT omheen, niets anders. Bewust NIET om de hele
// #order_review (die bevat ook de BTW-switch/filterschakelaar hierboven) —
// eerdere pogingen positioneerden het pijltje t.o.v. dat te grote, onvoor-
// spelbare ankerpunt en kwamen daardoor herhaaldelijk op de verkeerde plek
// terecht. Het pijltje zelf staat BUITEN de gemaskeerde tbody (position:
// absolute binnen deze wikkel-div, geen kind van tbody) — kan zich daardoor
// per definitie niet meer aan tbody's mask-image-fade "vervuilen" (een mask
// werkt altijd op de HELE inhoud van een element, een kind kan zich daar
// nooit van uitzonderen — dat was de kern van eerdere pogingen die faalden).
add_action( 'woocommerce_checkout_order_review', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    if ( function_exists( 'mkcp_checkout_uses_blocks' ) && mkcp_checkout_uses_blocks() ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    echo '<div class="mkcp-review-scroll-shell">';
}, 9 );

add_action( 'woocommerce_checkout_order_review', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    if ( function_exists( 'mkcp_checkout_uses_blocks' ) && mkcp_checkout_uses_blocks() ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    ?>
    <div class="mkcp-scroll-hint-chevron-overlay" style="display:none" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    </div><?php // sluit .mkcp-review-scroll-shell (priority 9 hierboven) ?>
    <?php
}, 11 );


// ── Cross-sell onder het orderoverzicht ───────────────────────────────────────
// Eigen aan/uit-instelling (checkout_crosssell_enabled), maar hergebruikt
// bewust de modus/aantal-instelling van de winkelwagenpopup (geen dubbele
// instelling nodig). Werkt op elk checkout-sjabloon, niet alleen het eigen.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['checkout_crosssell_enabled'] ) ) return;
    if ( ! function_exists( 'mkcp_get_crosssell_products' ) ) return;

    // Priority 20: na de besteltabel (10) en een eventuele betaaliconen-strip
    // (15, alleen op het eigen sjabloon) — onderaan het orderoverzicht.
    add_action( 'woocommerce_checkout_order_review', function() use ( $cfg ) {
        mkcp_checkout_render_crosssell( $cfg );
    }, 20 );

    add_action( 'wp_enqueue_scripts', function() {
        wp_enqueue_script(
            'mkcp-checkout-crosssell',
            MKCP_URL . 'assets/checkout-crosssell.js',
            [ 'jquery' ],
            MKCP_VER, // was filemtime() — onnodige stat()-syscall, inconsistent met de rest van de plugin
            true
        );
    } );
}, 5 );

function mkcp_checkout_render_crosssell( $cfg ) {
    $main_cfg = mkcp_config();
    $products = mkcp_get_crosssell_products(
        (int) ( $main_cfg['crosssell_limit'] ?? 3 ),
        $main_cfg['crosssell_mode'] ?? 'category'
    );
    if ( empty( $products ) ) return;
    ?>
    <div class="mkcp-co-crosssell">
        <h3 class="mkcp-co-crosssell__title"><?php echo esc_html( $cfg['checkout_crosssell_title'] ?: __( 'Misschien ook interessant?', 'mk-cart-popup' ) ); ?></h3>
        <div class="mkcp-co-crosssell__list">
            <?php foreach ( $products as $cs_product ) :
                $cs_is_simple = in_array( $cs_product->get_type(), [ 'simple', 'external' ], true );
                $cs_url       = $cs_product->get_permalink();
                $cs_name      = $cs_product->get_name();
                $cs_price     = wc_price( wc_get_price_to_display( $cs_product ) );
            ?>
            <div class="mkcp-co-crosssell__item">
                <a href="<?php echo esc_url( $cs_url ); ?>" class="mkcp-co-crosssell__img" tabindex="-1">
                    <?php echo wp_kses_post( $cs_product->get_image( 'woocommerce_thumbnail' ) ); ?>
                </a>
                <div class="mkcp-co-crosssell__info">
                    <a href="<?php echo esc_url( $cs_url ); ?>" class="mkcp-co-crosssell__name"><?php echo esc_html( $cs_name ); ?></a>
                    <span class="mkcp-co-crosssell__price"><?php echo $cs_price; ?></span>
                </div>
                <?php if ( $cs_is_simple && $cs_product->is_purchasable() ) : ?>
                <button type="button"
                    class="mkcp-co-crosssell__atc js-mkcp-co-crosssell-atc"
                    data-product-id="<?php echo esc_attr( $cs_product->get_id() ); ?>"
                    data-product-name="<?php echo esc_attr( $cs_name ); ?>"
                    aria-label="<?php echo esc_attr( sprintf( /* translators: %s: productnaam */ __( '%s toevoegen', 'mk-cart-popup' ), $cs_name ) ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
                <?php else : ?>
                <a href="<?php echo esc_url( $cs_url ); ?>" class="mkcp-co-crosssell__view"><?php esc_html_e( 'Bekijken', 'mk-cart-popup' ); ?></a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}


// Fires during WooCommerce AJAX order review refresh — wc-ajax bypasses 'wp'.
add_action( 'woocommerce_checkout_update_order_review', function() {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    $follow_popup = isset( $cfg['btw_follow_popup'] ) ? (bool) $cfg['btw_follow_popup'] : true;
    $btw_active   = $follow_popup ? ! empty( mkcp_config()['btw_split'] ) : ! empty( $cfg['btw_switch'] );
    if ( ! $btw_active || ! wc_tax_enabled() ) return;
    mkcp_register_btw_price_filters();
} );


// ── Zwevende labels (floating labels) op billing/shipping velden ────────────
// Onafhankelijk van de postcode-checker integratie hieronder — stond eerder
// ten onrechte genest in die gate, waardoor is-focused/has-value classes
// nergens verschenen zonder de WP Overnight Postcode Checker-plugin. Hoort
// bij de algemene checkout-styling, dus alleen gated op de generieke
// "Cart Checkout actief"-voorwaarden.

add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            var FIELD_SELECTOR = '.form-row input.input-text, .form-row select, .form-row textarea';

            function mkcp_updateHasValue( el ) {
                var row = el.closest( '.form-row' );
                if ( ! row ) return;
                if ( el.value && el.value.trim() ) row.classList.add( 'has-value' );
                else row.classList.remove( 'has-value' );
            }

            function mkcp_updateFloatingLabels() {
                document.querySelectorAll( FIELD_SELECTOR ).forEach( mkcp_updateHasValue );
            }
            window.mkcpUpdateFloatingLabels = mkcp_updateFloatingLabels;

            // Gedelegeerd op document, niet per-element: wc-country-select.js
            // vervangt het Staat/provincie-veld bij elke landwissel door een
            // nieuw DOM-element, waardoor een direct gebonden listener wees
            // zou raken. focusin/focusout bubbelen (focus/blur niet), dus
            // delegatie werkt ook daarvoor.
            document.addEventListener( 'focusin', function ( e ) {
                var el = e.target;
                if ( ! el.matches || ! el.matches( FIELD_SELECTOR ) ) return;
                var row = el.closest( '.form-row' );
                if ( row ) row.classList.add( 'is-focused' );
            } );
            document.addEventListener( 'focusout', function ( e ) {
                var el = e.target;
                if ( ! el.matches || ! el.matches( FIELD_SELECTOR ) ) return;
                var row = el.closest( '.form-row' );
                if ( row ) row.classList.remove( 'is-focused' );
                mkcp_updateHasValue( el );
            } );
            document.addEventListener( 'input', function ( e ) {
                if ( ! e.target.matches || ! e.target.matches( FIELD_SELECTOR ) ) return;
                mkcp_updateHasValue( e.target );
            } );
            document.addEventListener( 'change', function ( e ) {
                if ( ! e.target.matches || ! e.target.matches( FIELD_SELECTOR ) ) return;
                mkcp_updateHasValue( e.target );
            } );

            // Initieel alle velden controleren (ook bij terugkeer en browser-autofill)
            mkcp_updateFloatingLabels();
        })();
        </script>
        <?php
    } );
}, 5 );


// ── WP Overnight postcode checker integratie ─────────────────────────────────
// Detecteert of de WP Overnight NL Postcode Checker actief is en maakt
// billing_street_name + billing_city readonly wanneer de admin dat instelt.
//
// BELANGRIJK: billing_house_number/_suffix en billing_street_name (en
// shipping_-varianten) zijn GEEN WC-kernvelden, ze bestaan alleen als die
// plugin actief is. Zonder de plugin doen alle CSS/JS die ernaar verwijzen
// stilzwijgend niets. Detectie via ongedocumenteerde class-/constantennamen
// die WP Overnight kan hernoemen — vandaar de admin-waarschuwing hieronder.

function mkcp_postcode_checker_active() {
    // WP Overnight WC Postcode Checker (wc-postcode-checker)
    if ( defined( 'WPO_WCNLPC_VERSION' ) )             return true;
    if ( class_exists( 'WPO_WC_Postcode_Checker' ) )   return true;
    // Oudere / alternatieve varianten
    if ( defined( 'WCNLPC_VERSION' ) )                  return true;
    if ( class_exists( 'WC_NL_Postcode_Checker' ) )     return true;
    if ( class_exists( 'WCNLPC' ) )                     return true;
    if ( function_exists( 'wcnlpc_add_fields' ) )       return true;
    // Plugin slug check als fallback
    $active = (array) get_option( 'active_plugins', [] );
    foreach ( $active as $p ) {
        if ( strpos( $p, 'wc-postcode-checker' ) !== false ) return true;
        if ( strpos( $p, 'wcnlpc' ) !== false )               return true;
    }
    return false;
}

// Waarschuw in wp-admin i.p.v. de koppeling stil te laten falen wanneer de
// admin "postcode-checker velden vergrendelen" heeft aangezet maar de
// detectie de WP Overnight-plugin niet (meer) herkent.
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'mkcp-settings' ) return;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) || empty( $cfg['postcode_checker_lock_fields'] ) ) return;
    if ( mkcp_postcode_checker_active() ) return;

    echo '<div class="notice notice-warning"><p>'
        . '<strong>MK Cart Popup:</strong> '
        . esc_html__( '"Postcode-checker velden vergrendelen" staat aan, maar de WP Overnight NL Postcode Checker-plugin wordt niet gedetecteerd. Straatnaam/plaats-velden worden daarom niet vergrendeld en de adres-lookup werkt niet totdat die plugin actief is (of de detectie is bijgewerkt).', 'mk-cart-popup' )
        . '</p></div>';
} );


// ── Internationale adressen (niet-NL) ─────────────────────────────────────────
// De vaste grid-indeling + postcode/huisnummer/straatnaam-velden hierboven
// zijn gebouwd voor het NL-adresformaat. Andere landen tonen WC's eigen
// adres_1/adres_2/"Staat"-velden zonder eigen grid-area (brak de layout), en
// de NL postcode checker vindt daar niks, waardoor Straatnaam/Plaats voor
// altijd readonly bleef zonder manier om ze in te vullen.
//
// mkcp-intl-address staat NIET op <body> maar los op elke
// .woocommerce-billing/shipping-fields__field-wrapper, aangestuurd door zijn
// EIGEN _country-veld — factuur- en verzendadres worden dus volledig los
// beoordeeld (bv. NL-factuuradres + DE-verzendadres toont beide correct).
// checkout.scss heeft voor beide modi een eigen volledige grid-template
// (postcode+plaats staan internationaal náást elkaar i.p.v. in de NL
// huisnummer/toevoeging-rij-indeling, kan niet binnen dezelfde area-namen).
// Alleen JS-gedreven (geen server-side klasse) — verwaarloosbare flits bij
// eerste laden, maar blijft zo altijd synchroon met de gekozen landen.
//
// WC's eigen adres_1-placeholder ("Huisnummer en straatnaam") klopt niet
// voor landen waar straat+huisnummer in één regel gaan — leeg is hier
// duidelijker. Adres_2 krijgt om dezelfde reden een lege placeholder, plus
// een zichtbaar label i.p.v. WC's screen-reader-only label_class.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_filter( 'woocommerce_checkout_fields', function( $fields ) {
        foreach ( [ 'billing', 'shipping' ] as $group ) {
            $key1 = $group . '_address_1';
            if ( isset( $fields[ $group ][ $key1 ] ) ) {
                $fields[ $group ][ $key1 ]['placeholder'] = '';
            }
            $key2 = $group . '_address_2';
            if ( isset( $fields[ $group ][ $key2 ] ) ) {
                $fields[ $group ][ $key2 ]['placeholder']  = '';
                $fields[ $group ][ $key2 ]['label_class']  = [];
            }
        }
        return $fields;
    }, 100 );
}, 5 );

// De hook hierboven bepaalt alleen de EERSTE server-gerenderde HTML. Bij een
// landwissel herstelt WC's wc-address-i18n.js de placeholder onafhankelijk
// daarvan. Een woocommerce_get_country_locale-filter werkt niet: WC_Countries
// cachet zijn resultaat zodra hij één keer draait, en een andere plugin doet
// dat al vóór onze hooks. Luisteren naar wc_address_i18n_ready werkt ook
// niet: dat vuurt maar één keer bij het laden van het script zelf, niet per
// landwissel. De echte update gebeurt in dat script z'n handler op
// 'country_to_state_changing' — en omdat WC's script 'defer' laadt, bindt
// het zijn handler pas ná onze synchrone inline <script>, dus onze correctie
// zou eerst lopen en direct overschreven worden. Oplossing: hetzelfde event
// afluisteren maar via setTimeout(...,0) naar de volgende tick verplaatsen,
// die altijd ná alle synchrone handlers loopt.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            var MKCP_ADDRESS_FIELD_IDS = [
                'billing_address_1', 'shipping_address_1',
                'billing_address_2', 'shipping_address_2'
            ];

            function mkcp_clearAddressPlaceholders() {
                MKCP_ADDRESS_FIELD_IDS.forEach( function ( id ) {
                    var el = document.getElementById( id );
                    if ( ! el ) return;
                    el.setAttribute( 'placeholder', '' );
                    el.removeAttribute( 'data-placeholder' );
                } );
            }
            mkcp_clearAddressPlaceholders();

            if ( window.jQuery ) {
                jQuery( document.body ).on( 'country_to_state_changing', function () {
                    setTimeout( mkcp_clearAddressPlaceholders, 0 );
                } );
            }
        })();
        </script>
        <?php
    } );
}, 5 );


// ── EU/UK VAT Validation Manager integratie ───────────────────────────────────
// Spiegelt de postcode-checker-integratie hierboven, maar voor de EU/UK VAT
// Validation Manager for WooCommerce-plugin (WPFactory): detecteert of de
// plugin actief is en toont bij billing_eu_vat_number dezelfde
// .mkcp-pc-status balk als de postcode checker, i.p.v. de kale plugin-tekst.
//
// BELANGRIJK: billing_eu_vat_number is GEEN WC-kernveld, bestaat alleen als
// die plugin actief is. Zonder de plugin doen alle CSS/JS hieronder stilzwijgend niets.

function mkcp_vat_checker_active() {
    // EU/UK VAT Validation Manager for WooCommerce (WPFactory)
    if ( defined( 'WPFACTORY_WC_EU_VAT_VERSION' ) ) return true;
    if ( function_exists( 'wpfactory_wc_eu_vat' ) ) return true;
    if ( class_exists( 'WPFactory_WC_EU_VAT' ) )     return true;
    // Plugin slug check als fallback
    $active = (array) get_option( 'active_plugins', [] );
    foreach ( $active as $p ) {
        if ( strpos( $p, 'eu-vat-for-woocommerce' ) !== false ) return true;
    }
    return false;
}

// Waarschuw in wp-admin i.p.v. de koppeling stil te laten falen wanneer de
// admin "BTW-integratie" heeft aangezet maar de detectie de VAT-plugin niet
// (meer) herkent.
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'mkcp-settings' ) return;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) || empty( $cfg['vat_checker_status_enabled'] ) ) return;
    if ( mkcp_vat_checker_active() ) return;

    echo '<div class="notice notice-warning"><p>'
        . '<strong>MK Cart Popup:</strong> '
        . esc_html__( '"BTW-integratie" staat aan, maar de EU/UK VAT Validation Manager-plugin wordt niet gedetecteerd. De statusbalk, BTW-verlegging en veldregels doen daarom niets totdat die plugin actief is (of de detectie is bijgewerkt).', 'mk-cart-popup' )
        . '</p></div>';
} );

// LET OP — vat_checker_status_enabled is een MASTER-SWITCH voor de hele
// BTW-integratie (statusbalk + verlegging + veldregels), niet alleen de
// balk. Vroeger bestuurde hij alleen de balk terwijl de verlegging altijd
// doordraaide — in combinatie met een (door de bedrijfsnaam-regel) verborgen
// veld met een sessie-bewaard geldig nummer zat een klant dan onzichtbaar
// vast op excl. BTW. UIT betekent nu écht uit. Sleutelnaam is historisch; in
// de admin heet dit inmiddels "BTW-integratie".

// Placeholder-tekst van de VAT-plugin zelf weghalen. Prioriteit 100: de
// VAT-plugin registreert zijn eigen woocommerce_checkout_fields-filter op
// prioriteit 99 (voegt het veld toe), dus deze moet daarná draaien om de
// placeholder van dat net toegevoegde veld te kunnen overschrijven.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['vat_checker_status_enabled'] ) ) return;
    if ( ! mkcp_vat_checker_active() ) return;

    add_filter( 'woocommerce_checkout_fields', function( $fields ) {
        if ( isset( $fields['billing']['billing_eu_vat_number'] ) ) {
            $fields['billing']['billing_eu_vat_number']['placeholder'] = '';
        }
        return $fields;
    }, 100 );
}, 5 );

// BTW-integratie UIT: het veld hoort dan nérgens op de checkout te zien te
// zijn — geen "kaal WPFactory-veld", geen bedrijfsnaam-afhankelijkheid, punt
// uit. (Eerdere opzet liet WPFactory's eigen veld bij master UIT gewoon
// onvoorwaardelijk zichtbaar; dat bleek verwarrend — "uit" moet ook echt
// "uit" betekenen.) Onvoorwaardelijk verbergen én een eventuele
// sessie-bewaarde waarde legen, om dezelfde val te vermijden als hieronder
// bij de bedrijfsnaam-afhankelijke variant: een onzichtbaar veld mag nooit
// stiekem een BTW-nummer blijven mee-POSTen.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( ! empty( $cfg['vat_checker_status_enabled'] ) ) return; // integratie AAN: regelt zichtbaarheid zelf, zie hieronder
    if ( ! mkcp_vat_checker_active() ) return;

    add_action( 'wp_head', function() {
        echo '<style>body.mkcp-distraction-free-checkout #billing_eu_vat_number_field{display:none !important}</style>';
    } );

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            var vatEl = document.getElementById( 'billing_eu_vat_number' );
            if ( vatEl && vatEl.value.trim() ) {
                vatEl.value = '';
                if ( window.jQuery ) jQuery( vatEl ).trigger( 'input' );
            }
        })();
        </script>
        <?php
    } );
}, 5 );

// BTW-nummerveld alleen tonen zodra er een bedrijfsnaam is ingevuld — zonder
// bedrijfsnaam is er niks om te valideren. Bestaat #billing_company niet
// (toggle staat uit), dan blijft het BTW-veld permanent verborgen.
//
// De verberg-CSS staat bewust als conditionele wp_head-echo, niet in
// checkout.scss: die laadt altijd, dus een onvoorwaardelijke regel daar hield
// het veld ook verborgen wanneer deze integratie niet draait — dezelfde val
// als hierboven met een onzichtbaar veld en een sessie-bewaard BTW-nummer.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['vat_checker_status_enabled'] ) ) return;
    if ( ! mkcp_vat_checker_active() ) return;

    add_action( 'wp_head', function() {
        echo '<style>body.mkcp-distraction-free-checkout:not(.mkcp-vat-company-filled) #billing_eu_vat_number_field{display:none !important}</style>';
    } );

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            var companyEl = document.getElementById( 'billing_company' );
            var vatWrapEl = document.getElementById( 'billing_eu_vat_number_field' );
            var vatEl     = document.getElementById( 'billing_eu_vat_number' );
            if ( ! vatWrapEl ) return;

            function mkcp_syncVatVisibility() {
                var hasCompany = !! ( companyEl && companyEl.value.trim() );
                document.body.classList.toggle( 'mkcp-vat-company-filled', hasCompany );

                // Verborgen veld met een (mogelijk sessie-bewaard) nummer ook
                // echt wissen — anders blijft de BTW-verlegging/vrijstelling
                // onzichtbaar actief. jQuery-trigger zodat WPFactory's
                // delegated handler het ook verwerkt; guard voorkomt een lus.
                if ( ! hasCompany && vatEl && vatEl.value.trim() ) {
                    vatEl.value = '';
                    if ( window.jQuery ) jQuery( vatEl ).trigger( 'input' );
                }
            }

            mkcp_syncVatVisibility();
            if ( companyEl ) {
                companyEl.addEventListener( 'input', mkcp_syncVatVisibility );
                companyEl.addEventListener( 'change', mkcp_syncVatVisibility );
            }
        })();
        </script>
        <?php
    } );
}, 5 );

// ── Gedeelde statusbalk-factory ─────────────────────────────────────────────
//
// window.mkcpFieldStatus.create(config) bouwt één losse show/hide-status-
// instantie (aparte statusEl, timer en aria-koppeling per aanroep) — gebruikt
// door zowel de BTW- als de postcode-checker-integratie hieronder, die vóór
// deze factory elk hun eigen, bijna identieke kopie van dezelfde 60 regels
// hadden. Eén plek voor opmaak/animatie/aria-logica, geen twee (of straks
// meer) systemen die uit de pas kunnen gaan lopen.
//
// config:
//   statusId      - uniek id voor de statusbalk-div
//   extraClass    - extra CSS-klasse (bv. ' mkcp-pc-status--vat'), of ''
//   insert(el)    - plaatst el in de DOM; return false om te annuleren
//                   (bv. omdat het ankerelement er niet meer is)
//   getTargetFields() - array van velden waarop aria-invalid/-describedby komt
//   guard()       - optioneel; false = showStatus() doet niets
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_action( 'wp_footer', function() {
        ?>
        <script>
        window.mkcpFieldStatus = window.mkcpFieldStatus || {};
        window.mkcpFieldStatus.create = function ( config ) {
            var statusEl        = null;
            var statusHideTimer = null;

            function setFieldStatus( invalid ) {
                config.getTargetFields().forEach( function ( el ) {
                    el.setAttribute( 'aria-describedby', config.statusId );
                    el.setAttribute( 'aria-invalid', invalid ? 'true' : 'false' );
                } );
            }
            function clearFieldStatus() {
                config.getTargetFields().forEach( function ( el ) {
                    el.removeAttribute( 'aria-describedby' );
                    el.removeAttribute( 'aria-invalid' );
                } );
            }
            function showStatus( type, title, sub ) {
                if ( config.guard && ! config.guard() ) return;
                clearTimeout( statusHideTimer );
                if ( ! statusEl ) {
                    var el = document.createElement( 'div' );
                    el.id  = config.statusId;
                    el.setAttribute( 'role', 'alert' );
                    if ( config.insert( el ) === false ) return;
                    statusEl = el;
                }
                statusEl.className = 'mkcp-pc-status' + ( config.extraClass || '' ) + ' mkcp-pc-status--' + type;
                var iconHtml = type === 'loading'
                    ? '<span class="mkcp-pc-spinner"></span>'
                    : type === 'error'
                        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
                        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>';
                statusEl.innerHTML =
                    '<span class="mkcp-pc-status__icon">' + iconHtml + '</span>' +
                    '<span class="mkcp-pc-status__text"><strong>' + title + '</strong>' +
                    ( sub ? '<small>' + sub + '</small>' : '' ) + '</span>';
                setFieldStatus( type === 'error' );
            }
            function hideStatus( delay ) {
                if ( ! statusEl ) return;
                clearTimeout( statusHideTimer );
                if ( ! delay ) {
                    if ( statusEl.parentNode ) statusEl.parentNode.removeChild( statusEl );
                    statusEl = null;
                    clearFieldStatus();
                    return;
                }
                statusHideTimer = setTimeout( function () {
                    if ( ! statusEl ) return;
                    statusEl.classList.add( 'is-leaving' );
                    setTimeout( function () {
                        if ( statusEl && statusEl.parentNode ) statusEl.parentNode.removeChild( statusEl );
                        statusEl = null;
                        clearFieldStatus();
                    }, 320 );
                }, delay );
            }

            return { showStatus: showStatus, hideStatus: hideStatus };
        };
        </script>
        <?php
    }, 8 );
}, 4 );


// ── Top-level foutmelding: samenvatten i.p.v. elk veld apart opsommen ─────────
// WC's submit_error() zet bij meerdere lege verplichte velden élk veld als
// los <li data-id="..."> in de foutenlijst, terwijl hetzelfde data-id ook al
// een inline melding onder dat veld toont — dubbele info. Alleen samenvatten
// als de <li>'s echt 1-op-1 bij een bestaand veld horen (niet-veld-gebonden
// fouten als ongeldige coupon blijven altijd los zichtbaar), en pas vanaf 2
// velden. checkout_error vuurt pas NA het invoegen van de notice-HTML.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function ($) {
            if (!window.jQuery) return;

            function mkcp_summarizeErrors() {
                var list = document.querySelector('.woocommerce-NoticeGroup-checkout .woocommerce-error, form.checkout > .woocommerce-error');
                if (!list) return;

                var items = Array.prototype.slice.call(list.querySelectorAll(':scope > li'));
                var fieldItems = items.filter(function (li) {
                    var id = li.getAttribute('data-id');
                    return id && document.getElementById(id);
                });
                var otherItems = items.filter(function (li) { return fieldItems.indexOf(li) === -1; });

                if (fieldItems.length < 2) return;

                list.innerHTML = '';
                otherItems.forEach(function (li) { list.appendChild(li); });

                var summary = document.createElement('li');
                summary.className = 'mkcp-error-summary';
                summary.textContent = 'Er ontbreken nog ' + fieldItems.length + ' verplichte velden. Bekijk de gemarkeerde velden hieronder.';
                summary.tabIndex = 0;
                summary.setAttribute('role', 'button');
                summary.addEventListener('click', function () { mkcp_scrollToFirstInvalid(true); });
                summary.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); mkcp_scrollToFirstInvalid(true); }
                });
                list.appendChild(summary);
            }

            function mkcp_scrollToFirstInvalid(shake) {
                var firstInvalid = document.querySelector('.form-row.woocommerce-invalid');
                if (!firstInvalid) return;
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var field = firstInvalid.querySelector('input, select, textarea');
                if (field) field.focus({ preventScroll: true });

                // Reflow forceren tussen remove/add: zonder dat speelt de
                // animatie een 2e keer niet af als hetzelfde veld twee
                // mislukte pogingen na elkaar fout blijft (de browser ziet
                // anders geen klasse-wijziging om op te reageren).
                if (shake) {
                    firstInvalid.classList.remove('mkcp-shake');
                    void firstInvalid.offsetWidth;
                    firstInvalid.classList.add('mkcp-shake');
                }
            }

            // checkout_error vuurt zowel bij lege verplichte velden als bij
            // niet-veld-gebonden fouten (bv. ongeldige coupon) - alleen
            // automatisch scrollen/shaken als er ook echt een gemarkeerd
            // veld is om naartoe te gaan (mkcp_scrollToFirstInvalid checkt
            // dat zelf al via de querySelector-guard).
            $(document.body).on('checkout_error', function () {
                mkcp_summarizeErrors();
                mkcp_scrollToFirstInvalid(true);
            });
        })(window.jQuery);
        </script>
        <?php
    }, 9 );
}, 4 );


// ── Adresvelden invullen: auto-advance + telefoon-opmaak + e-mail-typo ───────
// Drie kleine, op zichzelf staande UX-versnellers voor het adresblok:
// 1. Zodra de postcode-checker (wc-postcode-checker) straat/plaats succesvol
//    heeft ingevuld, springt de focus door naar het telefoonnummer - scheelt
//    een klik, en alleen als de klant nog echt in die adresvelden zit (niet
//    ongevraagd wegkapen als hij intussen al verder is).
// 2. Telefoonnummer wordt live opgemaakt terwijl je typt (06 12 34 56 78 /
//    +31 6 12 34 56 78) i.p.v. een kale cijferreeks.
// 3. E-mail-typo-suggestie ("Bedoelde je gmail.com?") - zelfde mechanisme
//    als op het account-inlogscherm (assets/account-login.js,
//    addEmailTypoSuggestion), hier bewust opnieuw (klein) geïmplementeerd
//    i.p.v. dat bestand op de checkout te enqueuen: dat bestand bevat ook
//    login/registratie-specifieke logica (wachtwoordsterkte, confetti) die
//    hier niet relevant is.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function ($) {
            if (!window.jQuery) return;

            // 1. Auto-advance ná succesvolle postcode-opzoeking ------------------
            var mkcpLastPostcodePrefix = null;
            ['billing_', 'shipping_'].forEach(function (prefix) {
                ['house_number', 'house_number_suffix'].forEach(function (suffix) {
                    var el = document.getElementById(prefix + suffix);
                    if (el) el.addEventListener('input', function () { mkcpLastPostcodePrefix = prefix; });
                });
            });

            $('body').on('wpo_wcnlpc_fields_updated', function () {
                var prefix = mkcpLastPostcodePrefix;
                if (!prefix) return;

                // Niet ongevraagd wegkapen als de klant intussen al verder
                // is getypt (de AJAX-lookup kost even tijd) - alleen
                // doorspringen zolang de focus nog echt in dit adresblok zit.
                var active = document.activeElement;
                var stillHere = active && (
                    active.id === prefix + 'house_number' ||
                    active.id === prefix + 'house_number_suffix' ||
                    active.id === prefix + 'postcode'
                );
                if (!stillHere) return;

                var streetField = document.getElementById(prefix + 'street_name_field');
                var streetInput = document.getElementById(prefix + 'street_name');
                if (!streetField || !streetInput) return;
                if (streetField.classList.contains('wcnlpc-not-found')) return; // niet gevonden - blijf hier
                if (!streetInput.value) return;

                var phone = document.getElementById(prefix + 'phone');
                if (phone && !phone.value) phone.focus();
            });

            // 2. Telefoonnummer live opmaken + landvlag ---------------------------
            // Ondersteunt de 3 landen uit de land-dropdown (#billing_country/
            // #shipping_country: NL/BE/DE) - het gekozen land bepaalt zowel de
            // opmaakregels als het vlagicoontje naast het veld, en wisselt live
            // mee zodra de klant van land verandert (geen paginaherlading nodig).
            //
            // NL: elk vast nummer is 10 cijfers incl. voorloop-nul (bron: ACM-
            // nummerplan / Wikipedia "Lijst van Nederlandse netnummers", 2x
            // onafhankelijk gecontroleerd). "Grote" netnummers zijn 3 tekens
            // incl. nul (bv. "020") + 7-cijferig abonneenummer, alle overige
            // netnummers zijn 4 tekens incl. nul (bv. "0521") + 6-cijferig
            // abonneenummer (aangenomen - geen compacte officiële lijst van
            // alle ~300 4-cijferige netnummers). Mobiel (06) is ondubbelzinnig.
            //
            // BE: vaste nummers zijn ALTIJD 9 cijfers incl. voorloop-nul (bron:
            // Wikipedia "Lijst van Belgische zonenummers", 2x gecontroleerd).
            // De 4 grote steden (02 Brussel, 03 Antwerpen, 04 Luik, 09 Gent)
            // hebben een 1-cijferig zonenummer + 7-cijferig abonneenummer, alle
            // andere zones een 2-cijferig zonenummer + 6-cijferig abonneenummer.
            // Mobiel begint met 04 + 8 cijfers (10 totaal) - te onderscheiden
            // van het Luikse "04"-zonenummer puur op lengte (10 vs. 9 cijfers).
            //
            // DE: GEEN vaste totale lengte, netnummers variëren 2-5 cijfers over
            // duizenden regio's (Bundesnetzagentur) - niet compact/betrouwbaar
            // te bronnen zoals bij NL/BE. Een zelfverzekerd ogende maar mogelijk
            // foute netnummer-gok is hier erger dan een neutrale, altijd-
            // correcte (want niet-beweren) opmaak - zie mkcp_formatPhoneDE.
            // SVG i.p.v. vlag-emoji: Windows rendert de samengestelde
            // regional-indicator-tekens (🇳🇱 e.d.) zonder emoji-lettertype in
            // de font-stack vaak letterlijk als platte letters ("NL") i.p.v.
            // een vlaggetje - een klein, zelfgetekend SVG-vlagje rendert
            // overal consistent, zelfde aanpak als de rest van de plugin
            // (die gebruikt ook overal inline SVG i.p.v. icon-fonts/emoji).
            var MKCP_PHONE_COUNTRIES = {
                NL: {
                    cc: '31',
                    name: 'Nederland',
                    // Geen vaste breedte/hoogte meer - CSS geeft __icon (de
                    // knop-variant) een vast rond formaat met overflow:hidden,
                    // preserveAspectRatio="xMidYMid slice" hier is het SVG-
                    // equivalent van object-fit:cover (vult de cirkel, kropt
                    // de zijkanten i.p.v. uit te rekken). .mkcp-phone-country-
                    // option__flag (optielijst) heeft zijn eigen vaste 18x12.
                    flag: '<svg viewBox="0 0 30 20" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="30" height="20" fill="#21468B"/><rect width="30" height="13.33" fill="#FFFFFF"/><rect width="30" height="6.67" fill="#AE1C28"/></svg>'
                },
                BE: {
                    cc: '32',
                    name: 'België',
                    flag: '<svg viewBox="0 0 30 20" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="30" height="20" fill="#FFD90C"/><rect width="10" height="20" fill="#000000"/><rect x="20" width="10" height="20" fill="#ED2939"/></svg>'
                },
                DE: {
                    cc: '49',
                    name: 'Duitsland',
                    flag: '<svg viewBox="0 0 30 20" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><rect width="30" height="20" fill="#FFCE00"/><rect width="30" height="13.33" fill="#DD0000"/><rect width="30" height="6.67" fill="#000000"/></svg>'
                }
            };
            var MKCP_PHONE_COUNTRY_ORDER = ['NL', 'BE', 'DE'];
            var MKCP_NL_3DIGIT_AREACODES = [
                '010', '013', '015', '020', '023', '024', '026', '030', '033', '035',
                '036', '038', '040', '043', '045', '046', '050', '053', '055', '058',
                '070', '071', '072', '073', '074', '075', '076', '077', '078', '079'
            ];
            var MKCP_BE_1DIGIT_ZONES = ['2', '3', '4', '9']; // Brussel/Antwerpen/Luik/Gent

            function mkcp_chunk(str, sizes) {
                var out = [], i = 0, s = 0;
                while (i < str.length) {
                    var size = sizes[Math.min(s, sizes.length - 1)];
                    out.push(str.substr(i, size));
                    i += size;
                    s++;
                }
                return out;
            }

            function mkcp_getSelectedCountry(prefix) {
                var sel = document.getElementById(prefix + 'country');
                var val = sel ? sel.value : 'NL';
                return MKCP_PHONE_COUNTRIES[val] ? val : 'NL';
            }

            // Cijfers herleiden tot "nationaal formaat" (met voorloop-nul,
            // zonder landcode) - gedeeld tussen opmaak en validatie hieronder,
            // zodat ze nooit uit de pas kunnen lopen.
            function mkcp_toNational(raw, countryCode) {
                var cc      = MKCP_PHONE_COUNTRIES[countryCode].cc;
                var hasPlus = raw.trim().charAt(0) === '+';
                var digits  = raw.replace(/\D/g, '');
                if (digits.indexOf('00' + cc) === 0) { digits = digits.slice(2); hasPlus = true; }
                var national = (hasPlus && digits.indexOf(cc) === 0) ? ('0' + digits.slice(cc.length)) : digits;
                return { national: national, hasPlus: hasPlus, cc: cc };
            }

            function mkcp_formatPhoneNL(national, hasPlus, cc) {
                var groups;
                if (national.charAt(1) === '6') {
                    groups = ['6'].concat(mkcp_chunk(national.slice(2), [2]));
                } else if (MKCP_NL_3DIGIT_AREACODES.indexOf(national.slice(0, 3)) !== -1) {
                    groups = [national.slice(1, 3)].concat(mkcp_chunk(national.slice(3), [3, 4]));
                } else {
                    // 4-tekens-netnummer (aangenomen) - het abonneenummer als
                    // ÉÉN blok, niet in paren: dit soort nummers (bv. 0541)
                    // staat gangbaar zo geschreven, bv. "0541 537595" i.p.v.
                    // "0541 53 75 95".
                    groups = [national.slice(1, 4), national.slice(4)];
                }
                return (hasPlus ? '+' + cc + ' ' : '0') + groups.join(' ');
            }

            function mkcp_formatPhoneBE(national, hasPlus, cc) {
                var groups;
                if (national.charAt(1) === '4' && national.length === 10) {
                    // Mobiel: 04 + 8 cijfers = 10 totaal (i.t.t. het Luikse
                    // "04"-zonenummer, dat met zijn 7-cijferig abonneenummer
                    // op 9 totaal uitkomt - vandaar de lengte-check hier).
                    groups = [national.slice(1, 4)].concat(mkcp_chunk(national.slice(4), [2]));
                } else if (MKCP_BE_1DIGIT_ZONES.indexOf(national.charAt(1)) !== -1) {
                    groups = [national.charAt(1)].concat(mkcp_chunk(national.slice(2), [3, 2, 2]));
                } else {
                    groups = [national.slice(1, 3)].concat(mkcp_chunk(national.slice(3), [2]));
                }
                return (hasPlus ? '+' + cc + ' ' : '0') + groups.join(' ');
            }

            function mkcp_formatPhoneDE(national, hasPlus, cc) {
                // Duitse vaste netnummers hebben geen vaste lengte (zie de
                // uitleg bovenaan dit blok) - mobiel (015x/016x/017x) is wél
                // betrouwbaar 4 tekens, maar om voorspelbaar te blijven
                // hanteren we voor vast ÉN mobiel dezelfde neutrale knip na
                // 3 cijfers: voor mobiel toevallig correct, voor vast een
                // bewuste, onbeweerde gok - de cijfers zelf blijven intact.
                var groups = [national.slice(1, 4), national.slice(4)];
                return (hasPlus ? '+' + cc + ' ' : '0') + groups.join(' ');
            }

            function mkcp_formatPhoneIntl(raw, countryCode) {
                var parsed = mkcp_toNational(raw, countryCode);
                if (parsed.national.charAt(0) !== '0') {
                    // Nog niet herkenbaar (net begonnen te typen, of evident
                    // een ander land) - geen aannames, gewoon per 2 groeperen.
                    return (parsed.hasPlus ? '+' : '') + mkcp_chunk(raw.replace(/\D/g, ''), [2]).join(' ');
                }
                if (countryCode === 'BE') return mkcp_formatPhoneBE(parsed.national, parsed.hasPlus, parsed.cc);
                if (countryCode === 'DE') return mkcp_formatPhoneDE(parsed.national, parsed.hasPlus, parsed.cc);
                return mkcp_formatPhoneNL(parsed.national, parsed.hasPlus, parsed.cc);
            }

            function mkcp_isValidNationalLength(national, countryCode) {
                if (national.charAt(0) !== '0') return false;
                if (countryCode === 'BE') return national.length === 9 || national.length === 10;
                if (countryCode === 'DE') {
                    // Geen vaste lengte in Duitsland - alleen een ruime,
                    // veilige boven-/ondergrens, geen exacte match.
                    return national.length >= 9 && national.length <= 12;
                }
                return national.length === 10; // NL
            }

            // Zachte, niet-blokkerende waarschuwing (zelfde stijl-taal als de
            // e-mail-typo-hint, maar amber i.p.v. accentkleur) als het aantal
            // cijfers niet op een geldig nummer voor het gekozen land uitkomt -
            // pas bij het verlaten van het veld gecheckt, niet tijdens het
            // typen (anders is elk tussentijds, nog onvolledig aantal cijfers
            // al "fout"). Puur een cijfertelling, geen giswerk over
            // "verdachte" patronen zoals herhalende cijfers - dat geeft te
            // makkelijk terechte nummers ten onrechte als fout aan.
            function mkcp_bindPhoneWarning(field, prefix) {
                var warn = document.createElement('div');
                warn.className = 'mkcp-phone-warning mkcp-phone-warning--hidden';
                warn.textContent = 'Klopt dit nummer?';
                (field.closest('.woocommerce-input-wrapper') || field.parentNode).insertAdjacentElement('afterend', warn);

                field.addEventListener('blur', function () {
                    if (!field.value) { warn.classList.add('mkcp-phone-warning--hidden'); return; }
                    var countryCode = mkcp_getSelectedCountry(prefix);
                    var national    = mkcp_toNational(field.value, countryCode).national;
                    warn.classList.toggle('mkcp-phone-warning--hidden', mkcp_isValidNationalLength(national, countryCode));
                });
                field.addEventListener('input', function () {
                    warn.classList.add('mkcp-phone-warning--hidden');
                });
            }

            function mkcp_bindPhoneFormatter(field, prefix) {
                field.addEventListener('input', function () {
                    var oldValue = field.value;
                    var cursor   = field.selectionStart;
                    var digitsBeforeCursor = oldValue.slice(0, cursor).replace(/\D/g, '').length;

                    var formatted = mkcp_formatPhoneIntl(oldValue, mkcp_getSelectedCountry(prefix));
                    field.value = formatted;

                    var seen = 0, pos = formatted.length;
                    if (digitsBeforeCursor === 0) {
                        pos = formatted.charAt(0) === '+' ? 1 : 0;
                    } else {
                        for (var i = 0; i < formatted.length; i++) {
                            if (/\d/.test(formatted.charAt(i))) {
                                seen++;
                                if (seen === digitsBeforeCursor) { pos = i + 1; break; }
                            }
                        }
                    }
                    field.setSelectionRange(pos, pos);
                });
            }

            // Vlagicoontje naast het veld - los element i.p.v. background-
            // image op de input zelf: browsers schilderen bij autofill-
            // suggesties hun eigen achtergrond over het veld heen, wat een
            // background-image onzichtbaar maakt (zelfde aanpak/reden als de
            // icoontjes in assets/account-login.js). Wisselt live mee met de
            // land-dropdown, en herformatteert een al ingetypt nummer meteen
            // volgens de nieuwe landsnotatie.
            // Klikbare vlag: opent een klein paneel om het telefoonnummer-
            // land HANDMATIG te kiezen, los van het factuur-/afleveradres
            // (bv. een Belgisch mobiel nummer bij een Nederlands adres - de
            // vorige, puur-passieve versie kon dat niet). Zodra de klant zelf
            // kiest, wint die keuze voortaan van de automatische adres-sync
            // (manualOverride hieronder) - tot dat moment blijft het gedrag
            // exact zoals eerst: gewoon meevolgen met de adres-land-select.
            function mkcp_bindPhoneCountryPicker(field, prefix) {
                // .woocommerce-input-wrapper (de <span> direct om de input)
                // i.p.v. .form-row: die laatste bleek in de praktijk EXTRA
                // hoogte te hebben t.o.v. het zichtbare veld (te lage vlag),
                // terwijl de wrapper-span zónder expliciete display:block
                // (zie checkout.scss) juist te weinig hoogte gaf (te hoge
                // vlag) - een <span> is standaard inline, en de gerenderde
                // hoogte daarvan voor absolute-positionering-doeleinden is
                // onbetrouwbaar. Met display:block op de wrapper (nu gezet)
                // matcht die exact de hoogte van de input erin.
                var row = field.closest('.woocommerce-input-wrapper') || field.parentNode;

                var manualOverride = false;
                var currentCountry = mkcp_getSelectedCountry(prefix);

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'mkcp-phone-country-flag';
                button.setAttribute('aria-haspopup', 'listbox');
                button.setAttribute('aria-expanded', 'false');
                button.setAttribute('aria-label', 'Land van het telefoonnummer wijzigen');
                // Los vlag-element (wordt bij elke landwissel ververst) + een
                // vaste chevron ernaast - maakt in één oogopslag duidelijk
                // dat dit een dropdown is, niet alleen een decoratief icoon.
                // Zelfde draai-op-open-patroon als elders in checkout (bv.
                // .mkcp-co-review-toggle__chevron, .mkcp-scroll-hint-chevron-
                // overlay--up).
                var buttonFlag = document.createElement('span');
                buttonFlag.className = 'mkcp-phone-country-flag__icon';
                var chevron = document.createElement('span');
                chevron.className = 'mkcp-phone-country-flag__chevron';
                chevron.setAttribute('aria-hidden', 'true');
                chevron.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
                button.appendChild(buttonFlag);
                button.appendChild(chevron);
                row.appendChild(button);

                var panel = document.createElement('div');
                panel.className = 'mkcp-phone-country-panel mkcp-phone-country-panel--hidden';
                panel.setAttribute('role', 'listbox');
                panel.setAttribute('aria-label', 'Kies het land van het telefoonnummer');
                var options = MKCP_PHONE_COUNTRY_ORDER.map(function (code) {
                    var cfg = MKCP_PHONE_COUNTRIES[code];
                    var opt = document.createElement('button');
                    opt.type = 'button';
                    opt.className = 'mkcp-phone-country-option';
                    opt.setAttribute('role', 'option');
                    opt.dataset.country = code;
                    opt.innerHTML =
                        '<span class="mkcp-phone-country-option__flag">' + cfg.flag + '</span>' +
                        '<span class="mkcp-phone-country-option__name">' + cfg.name + '</span>' +
                        '<span class="mkcp-phone-country-option__check" aria-hidden="true">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                        '</span>';
                    panel.appendChild(opt);
                    return opt;
                });
                row.appendChild(panel);

                function paintSelection() {
                    options.forEach(function (opt) {
                        var isSelected = opt.dataset.country === currentCountry;
                        opt.classList.toggle('is-selected', isSelected);
                        opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                    });
                }

                function applyCountry(code, reformat) {
                    currentCountry = code;
                    buttonFlag.innerHTML = MKCP_PHONE_COUNTRIES[code].flag;
                    if (reformat && field.value) field.value = mkcp_formatPhoneIntl(field.value, code);
                    paintSelection();
                }
                applyCountry(currentCountry, false);

                function openPanel() {
                    panel.classList.remove('mkcp-phone-country-panel--hidden');
                    button.setAttribute('aria-expanded', 'true');
                    button.classList.add('mkcp-phone-country-flag--open');
                    var current = options.filter(function (o) { return o.dataset.country === currentCountry; })[0];
                    (current || options[0]).focus();
                }
                function closePanel(returnFocus) {
                    panel.classList.add('mkcp-phone-country-panel--hidden');
                    button.setAttribute('aria-expanded', 'false');
                    button.classList.remove('mkcp-phone-country-flag--open');
                    if (returnFocus) button.focus();
                }
                function isOpen() { return !panel.classList.contains('mkcp-phone-country-panel--hidden'); }

                button.addEventListener('click', function () {
                    if (isOpen()) closePanel(false); else openPanel();
                });

                options.forEach(function (opt, index) {
                    opt.addEventListener('click', function () {
                        manualOverride = true;
                        applyCountry(opt.dataset.country, true);
                        closePanel(true);
                    });
                    opt.addEventListener('keydown', function (e) {
                        if (e.key === 'ArrowDown') { e.preventDefault(); (options[index + 1] || options[0]).focus(); }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); (options[index - 1] || options[options.length - 1]).focus(); }
                        else if (e.key === 'Home') { e.preventDefault(); options[0].focus(); }
                        else if (e.key === 'End') { e.preventDefault(); options[options.length - 1].focus(); }
                        else if (e.key === 'Escape') { e.preventDefault(); closePanel(true); }
                        else if (e.key === 'Tab') { closePanel(false); }
                    });
                });

                document.addEventListener('click', function (e) {
                    if (isOpen() && !row.contains(e.target)) closePanel(false);
                });

                // Automatische adres-sync - stopt zodra de klant hierboven
                // zelf handmatig een land heeft gekozen (manualOverride).
                function syncFromAddress() {
                    if (manualOverride) return;
                    applyCountry(mkcp_getSelectedCountry(prefix), true);
                }
                var countrySelect = document.getElementById(prefix + 'country');
                if (countrySelect) countrySelect.addEventListener('change', syncFromAddress);

                // #billing_country/#shipping_country zijn Select2-widgets -
                // de klant kiest via Select2's eigen (custom) dropdown-UI,
                // niet via de verborgen native <select> zelf. Select2 zet de
                // waarde daar wel op en vuurt doorgaans ook een native
                // 'change'-event (hierboven), maar WooCommerce's EIGEN
                // 'country_to_state_changed'-event (op document.body, al
                // elders in de plugin gebruikt, zie checkout-blocks.js) is
                // het signaal dat WC zelf hiervoor bedoelt en dus het meest
                // betrouwbare - dubbele dekking i.p.v. op één mechanisme
                // te vertrouwen.
                if (window.jQuery) {
                    jQuery(document.body).on('country_to_state_changed', syncFromAddress);
                }
            }

            ['billing_', 'shipping_'].forEach(function (prefix) {
                var field = document.getElementById(prefix + 'phone');
                if (field) {
                    mkcp_bindPhoneFormatter(field, prefix);
                    mkcp_bindPhoneWarning(field, prefix);
                    mkcp_bindPhoneCountryPicker(field, prefix);
                }
            });

            // 3. E-mail-typo-suggestie --------------------------------------------
            // Zelfde domeinenlijst/Levenshtein-afstand als assets/account-login.js
            // (addEmailTypoSuggestion) - bewust hier gedupliceerd, zie comment
            // bovenaan dit blok.
            var MKCP_KNOWN_EMAIL_DOMAINS = [
                'gmail.com', 'hotmail.com', 'hotmail.nl', 'outlook.com', 'yahoo.com',
                'live.com', 'live.nl', 'icloud.com', 'me.com',
                'ziggo.nl', 'kpn.com', 'kpnmail.nl', 'home.nl', 'planet.nl', 'hetnet.nl',
                'telfort.nl', 'xs4all.nl', 'online.nl', 'chello.nl', 'quicknet.nl'
            ];

            function mkcp_levenshtein(a, b) {
                var m = a.length, n = b.length, dp = [], i, j;
                for (i = 0; i <= m; i++) dp[i] = [i];
                for (j = 0; j <= n; j++) dp[0][j] = j;
                for (i = 1; i <= m; i++) {
                    for (j = 1; j <= n; j++) {
                        dp[i][j] = a[i - 1] === b[j - 1]
                            ? dp[i - 1][j - 1]
                            : 1 + Math.min(dp[i - 1][j], dp[i][j - 1], dp[i - 1][j - 1]);
                    }
                }
                return dp[m][n];
            }

            function mkcp_addEmailTypoSuggestion(field) {
                var wrap = field.closest('.woocommerce-input-wrapper') || field.parentNode;

                var hint = document.createElement('button');
                hint.type = 'button';
                hint.className = 'mkcp-email-typo-hint mkcp-email-typo-hint--hidden';
                wrap.insertAdjacentElement('afterend', hint);

                function suggestion(value) {
                    var at = value.lastIndexOf('@');
                    if (at === -1) return null;
                    var domain = value.slice(at + 1).toLowerCase();
                    if (!domain || MKCP_KNOWN_EMAIL_DOMAINS.indexOf(domain) !== -1) return null;

                    var best = null, bestDist = 3;
                    for (var i = 0; i < MKCP_KNOWN_EMAIL_DOMAINS.length; i++) {
                        var known = MKCP_KNOWN_EMAIL_DOMAINS[i];
                        if (Math.abs(known.length - domain.length) >= bestDist) continue;
                        var dist = mkcp_levenshtein(domain, known);
                        if (dist > 0 && dist < bestDist) { bestDist = dist; best = known; }
                    }
                    return best ? value.slice(0, at + 1) + best : null;
                }

                var currentFix = null;

                field.addEventListener('input', function () {
                    currentFix = suggestion(field.value);
                    if (currentFix) {
                        hint.textContent = 'Bedoelde je ' + currentFix + '?';
                        hint.classList.remove('mkcp-email-typo-hint--hidden');
                    } else {
                        hint.classList.add('mkcp-email-typo-hint--hidden');
                    }
                });

                hint.addEventListener('click', function () {
                    if (currentFix) {
                        field.value = currentFix;
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    hint.classList.add('mkcp-email-typo-hint--hidden');
                    field.focus();
                });

                var form = field.closest('form');
                if (form) {
                    form.addEventListener('submit', function () {
                        if (currentFix) {
                            field.value = currentFix;
                            hint.classList.add('mkcp-email-typo-hint--hidden');
                        }
                    });
                }
            }

            var billingEmail = document.getElementById('billing_email');
            if (billingEmail) mkcp_addEmailTypoSuggestion(billingEmail);
        })(window.jQuery);
        </script>
        <?php
    }, 9 );
}, 4 );


// BTW-verlegging + statusbalk. Draait alleen als de master-switch
// (vat_checker_status_enabled) aanstaat én de VAT-plugin actief is. Vroeger
// draaide dit altijd zodra de VAT-plugin actief was, waardoor de verlegging
// kon vergrendelen zonder zichtbare feedback (klant vast op excl. BTW). Het
// gevaar "uit terwijl er nog een vergrendeling staat" is nu gedekt door
// window.mkcpVatIntegrationActive hieronder: ontbreekt die marker, dan ruimt
// checkout-btw.js elke achtergebleven vergrendeling zelf op.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['vat_checker_status_enabled'] ) ) return;
    if ( ! mkcp_vat_checker_active() ) return;

    // Verberg de eigen (kale) statustekst/detailslijst van de VAT-plugin —
    // anders staat straks dezelfde info twee keer op de pagina: één keer kaal
    // van de plugin zelf, één keer in de opgemaakte .mkcp-pc-status-balk
    // hieronder.
    add_action( 'wp_head', function() {
        echo '<style>body.mkcp-distraction-free-checkout #wpfactory_wc_eu_vat_progress,body.mkcp-distraction-free-checkout #wpfactory_wc_eu_vat_details{display:none!important}</style>';
    } );

    add_action( 'wp_footer', function() {
        ?>
        <script>
        // Marker voor assets/checkout-btw.js (laadt verderop in de footer,
        // dus ná dit inline blok): "de BTW-verlegging draait op deze pagina".
        // Ontbreekt deze marker, dan ruimt reconcileStaleVatLock() daar elke
        // achtergebleven vergrendeling uit localStorage op — een lock mag
        // nooit langer leven dan het script dat 'm kan opheffen.
        window.mkcpVatIntegrationActive = true;
        (function () {
            var FIELD_ID  = 'billing_eu_vat_number';
            var inputEl   = document.getElementById( FIELD_ID );
            var wrapperEl = document.getElementById( FIELD_ID + '_field' );
            if ( ! inputEl || ! wrapperEl ) return;

            var STATUS_ID = 'mkcp-pc-status-' + FIELD_ID;

            // aria-describedby laat de statusbalk voorlezen bij focus op het
            // BTW-veld; aria-invalid alleen bij een echte fout (niet bij
            // loading/success) — zie window.mkcpFieldStatus.create hierboven.
            var fieldStatus = window.mkcpFieldStatus.create( {
                statusId: STATUS_ID,
                extraClass: ' mkcp-pc-status--vat',
                insert: function ( el ) {
                    wrapperEl.parentNode.insertBefore( el, wrapperEl.nextSibling );
                },
                getTargetFields: function () { return [ inputEl ]; }
            } );
            var mkcp_showStatus = fieldStatus.showStatus;
            var mkcp_hideStatus = fieldStatus.hideStatus;

            function mkcp_readCompanyName() {
                var details = document.getElementById( 'wpfactory_wc_eu_vat_details' );
                if ( details && details.textContent.trim() ) return details.textContent.trim();
                var company = document.getElementById( 'billing_company' );
                return company ? company.value.trim() : '';
            }

            // BELANGRIJK: woocommerce-validated/-invalid op het invoerveld
            // zelf zijn GEEN betrouwbare signalen — de VAT-plugin voegt
            // woocommerce-validated alleen toe, verwijdert 'm nooit, dus die
            // class blijft voorgoed staan na één geslaagde check (leek dan
            // altijd "geldig"). #wpfactory_wc_eu_vat_progress wordt door de
            // plugin wél steeds eerst leeggemaakt vóór een nieuwe statusklasse
            // — dat is dus de enige betrouwbare bron.
            // BTW-verlegging: bij een geldig nummer forceren + vergrendelen we
            // de prijsweergave op "excl. BTW" via window.mkcpBtwSwitch
            // (assets/checkout-btw.js, altijd aanwezig bij checkout_enabled).
            function mkcp_setReverseCharge( active ) {
                if ( ! window.mkcpBtwSwitch ) return;
                if ( active ) window.mkcpBtwSwitch.lock( 'excl' );
                else window.mkcpBtwSwitch.unlock();
            }

            function mkcp_syncFromProgress() {
                // Zonder ingevoerd nummer nooit een status tonen — de plugin
                // kan #wpfactory_wc_eu_vat_progress bij een AJAX-refresh met
                // de klasse van een inmiddels gewiste invoer herstellen,
                // waardoor "geldig" verscheen bij een leeg veld.
                if ( ! inputEl.value.trim() ) {
                    mkcp_hideStatus( 0 );
                    mkcp_setReverseCharge( false );
                    return;
                }
                var progressEl = document.getElementById( 'wpfactory_wc_eu_vat_progress' );
                if ( ! progressEl ) return;
                if ( progressEl.classList.contains( 'wpfactory-wc-eu-vat-validating' ) ) {
                    mkcp_showStatus( 'loading', 'BTW-nummer controleren…', 'Even geduld, we checken dit nummer' );
                } else if ( progressEl.classList.contains( 'wpfactory-wc-eu-vat-valid' ) ) {
                    mkcp_showStatus( 'success', 'BTW-nummer geldig', mkcp_readCompanyName() );
                    mkcp_setReverseCharge( true );
                } else if ( progressEl.classList.contains( 'wpfactory-wc-eu-vat-not-valid' ) ) {
                    mkcp_showStatus( 'error', 'Ongeldig BTW-nummer', 'Controleer het nummer en probeer het opnieuw' );
                    mkcp_setReverseCharge( false );
                }
            }

            // Bij een leeg veld ruimt de VAT-plugin zelf niets op (AJAX-
            // aanroep wordt overgeslagen) — dus zelf balk verbergen + knoppen
            // ontgrendelen zodra de klant alles wist.
            inputEl.addEventListener( 'input', function () {
                if ( ! inputEl.value.trim() ) {
                    mkcp_hideStatus( 0 );
                    mkcp_setReverseCharge( false );
                }
            } );

            // #wpfactory_wc_eu_vat_progress wordt pas ná page-load door de
            // VAT-plugin's eigen script aangemaakt — subtree + childList
            // vangt zowel het ontstaan van dat element als latere klasse-
            // wijzigingen erop, ongeacht welk script als eerste draait.
            new MutationObserver( mkcp_syncFromProgress ).observe( wrapperEl, {
                childList: true, subtree: true, attributes: true, attributeFilter: [ 'class' ]
            } );
        })();
        </script>
        <?php
    } );
}, 10 );


// ── Bestelknop-tekst ───────────────────────────────────────────────────────────
// Leeg veld (default) = WooCommerce's eigen standaardtekst, niet overschreven.
//
// Let op: NIET via is_checkout() gate op de 'wp'-hook registreren. De AJAX-call
// die dit gebied ververst (wc-ajax=update_order_review, bv. bij het wisselen van
// postcode of verzendmethode) draait op de home-URL met een ?wc-ajax=-parameter,
// niet op de checkout-URL zelf — is_checkout() geeft daar false terug, waardoor
// de aangepaste tekst na elke ververing terugsprong naar de WC-standaardtekst.
// De 'woocommerce_order_button_text'-filter vuurt sowieso alleen tijdens het
// renderen van de bestelknop, dus een aparte pagina-check is hier overbodig.
add_filter( 'woocommerce_order_button_text', function( $button_text ) {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return $button_text;
    if ( ! mkcp_license_has( 'premium' ) ) return $button_text;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return $button_text;
    if ( $cfg['checkout_button_text'] === '' ) return $button_text;

    return $cfg['checkout_button_text'];
} );

// ── Land-veld verbergen/vergrendelen ─────────────────────────────────────────
//
// Eigen instelling (country_field_visible / country_field_locked), volledig
// los van de postcode-checker integratie hieronder — stond hier voorheen
// onterecht in genest, waardoor "Land veld verbergen/vergrendelen" alleen
// werkte op sites met de WP Overnight NL Postcode Checker-plugin actief én
// "Postcode-checker velden vergrendelen" aangevinkt.

add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    $country_visible = isset( $cfg['country_field_visible'] ) ? (bool) $cfg['country_field_visible'] : true;
    $country_locked  = ! empty( $cfg['country_field_locked'] );

    if ( ! $country_visible || $country_locked ) {
        add_action( 'wp_head', function() use ( $country_visible, $country_locked ) {
            echo '<style>';
            if ( ! $country_visible ) {
                echo 'body.mkcp-distraction-free-checkout #billing_country_field{display:none!important}';
            } elseif ( $country_locked ) {
                echo 'body.mkcp-distraction-free-checkout #billing_country{pointer-events:none!important;background:#f3f4f6!important;color:#9ca3af!important;cursor:not-allowed!important;border-color:#e5e7eb!important}';
            }
            echo '</style>';
        } );
    }
}, 5 );


// ── Bedrijfsnaam-veld aan/uit ─────────────────────────────────────────────────
//
// billing_company/shipping_company bestaan normaal standaard in WooCommerce,
// maar kunnen op sommige sites al door een andere plugin (bv. "Checkout
// Field Editor for WooCommerce") uit de checkout-fields-array zijn gehaald —
// dan doet een aan/uit-toggle die alleen een al-bestaand veld aanpast niets
// zodra hij op "aan" staat. Daarom hieronder het veld altijd zelf expliciet
// (opnieuw) neerzetten i.p.v. aan te nemen dat het er nog is, op prioriteit
// 20 zodat dit wint van een eerder verwijderende plugin.
// checkout.scss heeft van zichzelf geen grid-area voor deze velden (zie de
// "fn/ln/pc/hn/..."-grid-template-areas verderop) — zonder positie vallen ze
// terug op de browser-standaard grid-auto-placement, wat er onbedoeld/kapot
// uitziet. Uit (default): veld verwijderd, geen halfbakken onopgemaakt veld.
// Aan: veld staat er (opnieuw), grid-area "co" (checkout.scss) plaatst 'm
// netjes onder Voornaam/Achternaam.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_filter( 'woocommerce_checkout_fields', function( $fields ) use ( $cfg ) {
        foreach ( [ 'billing', 'shipping' ] as $group ) {
            $key = $group . '_company';

            if ( empty( $cfg['company_field_enabled'] ) ) {
                unset( $fields[ $group ][ $key ] );
                continue;
            }

            $fields[ $group ][ $key ] = array_merge(
                [
                    'type'     => 'text',
                    'label'    => __( 'Bedrijfsnaam', 'mk-cart-popup' ),
                    'required' => false,
                ],
                $fields[ $group ][ $key ] ?? [],
                [ 'class' => [ 'form-row-wide' ] ]
            );
        }
        return $fields;
    }, 20 );
}, 5 );


// ── Bestelnotities-veld aan/uit ───────────────────────────────────────────────
//
// order_comments blijft gewoon geregistreerd in de "order"-fieldset (nodig,
// want WC_Checkout::get_posted_data() verwerkt/bewaart alleen velden die in
// die array staan) — alleen de VISUELE weergave op WooCommerce's eigen
// standaardplek (checkout/form-shipping.php, onderaan de verzendvelden) wordt
// via woocommerce_enable_order_notes_field uitgezet. In plaats daarvan
// renderen we het veld zelf op woocommerce_after_checkout_billing_form: die
// hook vuurt binnen de factuurgegevens-kolom, vlak vóór de verzendvelden
// beginnen — dus direct onder Telefoon/E-mail en boven "Verzenden naar een
// ander adres?", zoals gevraagd. Beide velden heten "order_comments"; omdat
// alleen deze render-aanroep daadwerkelijk in de pagina terechtkomt (de
// standaardplek staat uit), ontstaat er geen dubbel veld met dezelfde name
// dat elkaars waarde zou overschrijven bij het versturen van het formulier.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    $enabled = ! empty( $cfg['order_notes_enabled'] );

    // Onderdrukt WooCommerce's eigen standaardplek (onderaan de verzendvelden)
    // altijd — aan of uit, wíj bepalen of/waar het veld verschijnt, nooit die
    // standaardplek (anders staat het er bij "aan" zowel daar als hieronder
    // dubbel, én staat WooCommerce's eigen default — WC's "Enable order
    // comments"-instelling is standaard "ja" — het veld gewoon daar te tonen
    // zodra "Bestelnotities veld tonen" hier op "uit" staat).
    add_filter( 'woocommerce_enable_order_notes_field', '__return_false', 999 );

    if ( ! $enabled ) return;

    add_action( 'woocommerce_after_checkout_billing_form', function( $checkout ) {
        woocommerce_form_field( 'order_comments', [
            'type'        => 'textarea',
            'class'       => [ 'form-row-wide' ],
            'label'       => __( 'Bestelnotities', 'mk-cart-popup' ),
            'placeholder' => '',
            'required'    => false,
        ], $checkout->get_value( 'order_comments' ) );
    } );
}, 5 );

// ── Gedeelde schakelaar-helpers (login-tab + account-tab) ───────────────────
//
// De twee blokken hieronder ("Account aanmaken?" en "Terugkerende klant?")
// zijn losse wp_footer-closures zonder gedeelde JS-scope, maar moeten in
// DEZELFDE schakelaar landen. Voorheen droeg elk blok een woordelijke kopie
// van deze vier helpers mee; nu staan ze één keer op window.mkcpSwitcher.
// Prioriteit 15 zodat dit blok gegarandeerd vóór de twee gebruikers (beide
// prioriteit 20) in de HTML staat, ongeacht registratievolgorde.
//
// Alle functies zijn idempotent (hergebruiken wat er al staat) i.p.v. een
// "bestaat-ie al?"-early-return op de hele opbouw — anders zou wie het eerst
// draait de ander buitensluiten.
add_action( 'wp_footer', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    ?>
    <script>
    (function () {
        function ensure() {
            var anchor = document.getElementById('customer_details');
            if ( ! anchor || ! anchor.parentNode ) return null;
            var outer = document.querySelector('.mkcp-login-and-details');
            if ( ! outer ) {
                outer = document.createElement('div');
                outer.className = 'mkcp-login-and-details';
                anchor.parentNode.insertBefore( outer, anchor );
            }
            var switcher = outer.querySelector('.mkcp-checkout-switcher');
            if ( ! switcher ) {
                switcher = document.createElement('div');
                switcher.className = 'mkcp-checkout-switcher';
                switcher.innerHTML = '<div class="mkcp-switcher-tabs"></div>';
                outer.insertBefore( switcher, outer.firstChild );
            }
            if ( anchor.parentNode !== outer ) outer.appendChild( anchor );
            return switcher;
        }

        // Eén tab toevoegen (idempotent) — CSS "order" (checkout.scss)
        // regelt een vaste volgorde (login altijd eerst), ongeacht welk
        // van de twee scripts hier het eerst bij is.
        //
        // Vaste chevron aan het eind: alleen de tekst "Ik ben al klant" /
        // "Ik ben nieuw" liet niet zien dát er iets gebeurt bij een klik
        // (feedback: "oké, en dan?") — het pijltje maakt duidelijk dat
        // dit een actie is, net als de "→"-knoppen die er eerst stonden.
        function addTab( switcher, key, label, iconHtml ) {
            var tabs = switcher.querySelector('.mkcp-switcher-tabs');
            if ( tabs.querySelector('[data-target="' + key + '"]') ) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mkcp-switcher-tab';
            btn.dataset.target = key;
            btn.innerHTML = iconHtml + '<span>' + label + '</span>'
                + '<svg class="mkcp-switcher-tab__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';
            tabs.appendChild( btn );
        }

        // Bindt de klik van één specifieke tab aan de popup-open-functie
        // van dat script — apart van addTab() zodat elk script
        // (login/account) zijn EIGEN open-functie kan meegeven zonder dat
        // de gedeelde helpers daar iets van hoeven te weten. Markeert de
        // tab meteen als "geselecteerd" en schuift het accent-balkje mee —
        // dat is de "switch"-animatie.
        function bindTab( switcher, key, onOpen ) {
            var btn = switcher.querySelector('.mkcp-switcher-tab[data-target="' + key + '"]');
            if ( ! btn || btn.dataset.mkcpBound ) return;
            btn.dataset.mkcpBound = '1';
            btn.addEventListener( 'click', function () {
                switcher.querySelectorAll('.mkcp-switcher-tab').forEach( function ( t ) {
                    t.classList.toggle( 'is-selected', t === btn );
                } );
                updateIndicator( switcher );
                onOpen( btn );
            } );
        }

        // Positioneert/verbreedt het schuivende accent-balkje onder de
        // tabs — bij 1 tab altijd vol-breed op die ene plek, bij 2 tabs
        // een halve breedte die naar links/rechts schuift al naar
        // gelang welke tab (nog steeds) geselecteerd is. Op vaste
        // "slots" i.p.v. DOM-volgorde: de CSS "order" (checkout.scss)
        // bepaalt de visuele volgorde (login altijd links), en DOM-
        // volgorde kan daarvan afwijken al naar gelang welk script het
        // eerst zijn tab toevoegde.
        function updateIndicator( switcher ) {
            var tabsEl = switcher.querySelector('.mkcp-switcher-tabs');
            var tabs   = tabsEl.querySelectorAll('.mkcp-switcher-tab');
            var indicator = tabsEl.querySelector('.mkcp-switcher-indicator');
            if ( ! indicator ) {
                indicator = document.createElement('span');
                indicator.className = 'mkcp-switcher-indicator';
                tabsEl.appendChild( indicator );
            }
            if ( ! tabs.length ) return;
            indicator.style.width = ( 100 / tabs.length ) + '%';
            var selected = tabsEl.querySelector('.mkcp-switcher-tab.is-selected');
            var key      = selected ? selected.dataset.target : 'login';
            var slot     = ( tabs.length > 1 && key === 'account' ) ? 1 : 0;
            indicator.style.transform = 'translateX(' + ( slot * 100 ) + '%)';
        }

        window.mkcpSwitcher = {
            ensure: ensure,
            addTab: addTab,
            bindTab: bindTab,
            updateIndicator: updateIndicator
        };
    })();
    </script>
    <?php
}, 15 );


// ── "Een account aanmaken?"-checkbox: aan/uit + toelichtingstekst + verplaatsen ─
//
// De checkbox zelf (en de eventuele wachtwoordvelden erachter) is WooCommerce-
// core-markup (checkout/form-billing.php, niet door een thema of deze plugin
// overschreven) — die rendert altijd in .col-1, vlak na de factuurvelden,
// zodra WooCommerce's eigen "Sta klanten toe een account aan te maken tijdens
// het afrekenen"-instelling aanstaat. Twee dingen die WooCommerce zelf niet
// biedt: 'm helemaal verbergen ongeacht die instelling (zonder de instelling
// zelf te hoeven aanpassen), en 'm ergens anders laten landen dan waar
// WooCommerce 'm nu eenmaal neerzet.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    if ( empty( $cfg['createaccount_enabled'] ) ) {
        // Verbergen i.p.v. de WC-instelling zelf aan te passen — die blijft
        // z'n eigen betekenis houden (of registreren via checkout überhaupt
        // kan), dit is puur een visuele aan/uit voor de checkbox-rij zelf.
        add_action( 'wp_footer', function() {
            echo '<style>.woocommerce-account-fields{display:none!important}</style>';
        }, 20 );
        return;
    }

    // Toelichtingskader — woocommerce_before_checkout_registration_form is de
    // enige hook die binnen .woocommerce-account-fields vuurt (form-billing.php
    // heeft er geen vóór de checkbox), dus het kader rendert hier server-side
    // ná de checkbox. De JS hieronder verplaatst 'm vervolgens vóór de
    // checkbox — zelfde soort DOM-move als de hele sectie al krijgt, alleen
    // dan één stap verder binnen dezelfde wrapper.
    $has_info = '' !== trim( (string) ( $cfg['createaccount_info_text'] ?? '' ) )
             || '' !== trim( (string) ( $cfg['createaccount_info_title'] ?? '' ) );
    if ( $has_info ) {
        add_action( 'woocommerce_before_checkout_registration_form', function() use ( $cfg ) {
            echo '<div class="mkcp-createaccount-info">';
            if ( '' !== trim( (string) $cfg['createaccount_info_title'] ) ) {
                echo '<p class="mkcp-createaccount-info__title">' . esc_html( $cfg['createaccount_info_title'] ) . '</p>';
            }
            if ( '' !== trim( (string) $cfg['createaccount_info_text'] ) ) {
                echo '<p class="mkcp-createaccount-info__text">' . wp_kses_post( nl2br( esc_html( $cfg['createaccount_info_text'] ) ) ) . '</p>';
            }
            // Live e-mailadres-regel — begint verborgen, de JS hieronder vult
            // 'm en toont 'm zodra billing_email een waarde heeft (en verbergt
            // 'm weer bij een leeg/ongeldig veld). Maakt de statische
            // toelichtingstekst hierboven concreet: niet "je ontvangt een
            // e-mail" in het algemeen, maar zichtbaar mét het adres dat de
            // klant net zelf heeft ingevuld.
            echo '<p class="mkcp-createaccount-info__email" hidden>'
                . esc_html__( 'Je ontvangt op ', 'mk-cart-popup' )
                . '<strong class="mkcp-createaccount-info__email-value"></strong>'
                . esc_html__( ' een melding zodra je account is aangemaakt.', 'mk-cart-popup' )
                . '</p>';
            echo '</div>';
        } );
    }

    // K3: samengevoegd met "Terugkerende klant?" bovenaan i.p.v. onder de
    // besteltabel, als één schakelaar met 2 tabs ("Ik ben al klant" / "Ik
    // ben nieuw") i.p.v. twee losse pillen die allebei een modal openden —
    // die twee losse prompts oogden als concurrerende opties, terwijl wie
    // inlogt sowieso geen account meer hoeft aan te maken. Neutraal bij
    // binnenkomst (geen van beide vooraf getoond), en het gekozen tabblad
    // klapt inline open i.p.v. in een popup — past beter bij "dit is één
    // keuze" dan een aparte overlay per optie.
    //
    // .woocommerce-account-fields zelf is WooCommerce-core-markup (zie
    // toelichting hierboven) — nog steeds dezelfde move-aanpak (héle blok
    // verplaatsen, checkbox+eventuele wachtwoordvelden gaan gewoon mee),
    // alleen landt het nu in het paneel i.p.v. onder de besteltabel of in
    // een modal.
    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            // Schakelaar-helpers staan gedeeld op window.mkcpSwitcher (zie de
            // wp_footer-hook op prioriteit 15 hierboven) — dit blok en het
            // "Terugkerende klant?"-blok hieronder landen zo in DEZELFDE
            // schakelaar, ongeacht welk van de twee als eerste draait.
            var ICON_ACCOUNT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>';

            var modal, dialog;

            // BUGFIX: bouwde eerst de modal-huls ÉÉN keer en sloeg daarna
            // altijd over (early-return op "bestaat de modal al?"). Na een
            // updated_checkout-AJAX-ververs kan WooCommerce echter een
            // volledig NIEUWE .woocommerce-account-fields renderen (bv. bij
            // een landwissel die het factuurformulier herbouwt) — dat verse
            // element bleef dan gewoon op zijn oorspronkelijke plek staan
            // i.p.v. de modal in. Nu de huls maar één keer aanmaken, maar de
            // velden-verplaatsing zelf bij ELKE aanroep herhalen.
            function mkcpBuildAccountModal() {
                var fields = document.querySelector('.woocommerce-account-fields');
                if ( ! fields ) return;

                if ( ! modal ) {
                    modal = document.createElement('div');
                    modal.id = 'mkcp-account-modal';
                    modal.className = 'mkcp-login-modal';
                    modal.setAttribute( 'inert', '' );
                    modal.innerHTML =
                        '<div class="mkcp-login-modal__backdrop"></div>' +
                        '<div class="mkcp-login-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_js( __( 'Account aanmaken', 'mk-cart-popup' ) ); ?>" tabindex="-1">' +
                            '<button type="button" class="mkcp-login-modal__close" aria-label="<?php echo esc_js( __( 'Sluiten', 'mk-cart-popup' ) ); ?>">' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                            '</button>' +
                        '</div>';
                    dialog = modal.querySelector('.mkcp-login-modal__dialog');

                    // #createaccount / account_username / account_password
                    // zijn GEWONE velden binnen het hoofd-checkout-formulier —
                    // die moeten daar dus binnen blijven (anders stuurt de
                    // browser ze bij "Bestelling plaatsen" niet mee). De modal
                    // zelf hangt daarom in het <form>, niet los op <body> —
                    // position:fixed (cart-popup.scss) laat 'm alsnog als
                    // full-screen overlay renderen.
                    var checkoutForm = document.querySelector( 'form.checkout' ) || document.querySelector( '.woocommerce-checkout' ) || document.body;
                    checkoutForm.appendChild( modal );

                    modal.querySelector('.mkcp-login-modal__backdrop').addEventListener('click', mkcpCloseAccountModal);
                    modal.querySelector('.mkcp-login-modal__close').addEventListener('click', mkcpCloseAccountModal);

                    document.addEventListener('keydown', function (e) {
                        if ( ! modal.classList.contains('is-open') ) return;
                        if ( e.key === 'Escape' ) { mkcpCloseAccountModal(); return; }
                        if ( e.key !== 'Tab' ) return;
                        var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
                        var items = dialog.querySelectorAll( FOCUSABLE );
                        if ( ! items.length ) return;
                        var first = items[0], last = items[ items.length - 1 ];
                        if ( e.shiftKey && document.activeElement === first ) {
                            e.preventDefault(); last.focus();
                        } else if ( ! e.shiftKey && document.activeElement === last ) {
                            e.preventDefault(); first.focus();
                        }
                    });
                }

                if ( fields.parentNode !== dialog ) {
                    dialog.appendChild( fields );
                }

                // Toelichtingskader (indien aanwezig) vóór de checkbox
                // zetten, én de checkbox ZELF in datzelfde kader schuiven —
                // anders ontstaan er twee losse, los-ogende doosjes onder
                // elkaar i.p.v. één kader met de checkbox erin.
                // ".create-account" bestaat dubbel in deze markup (form-
                // billing.php): de checkbox-<p> én de (meestal lege) extra-
                // accountvelden-<div> — vandaar het specifieke
                // "p.create-account" i.p.v. de kale class-selector.
                var info        = fields.querySelector('.mkcp-createaccount-info');
                var checkbox    = fields.querySelector('p.create-account');
                var extraFields = fields.querySelector('div.create-account');
                if ( info && fields.firstElementChild !== info ) {
                    fields.insertBefore( info, fields.firstElementChild );
                }
                if ( info && checkbox && checkbox.parentNode !== info ) {
                    info.appendChild( checkbox );
                }
                if ( info && extraFields && extraFields.parentNode !== info ) {
                    info.appendChild( extraFields );
                }
            }

            var lastFocusedTrigger;

            function mkcpOpenAccountModal( trigger ) {
                if ( ! modal ) return;
                lastFocusedTrigger = ( trigger && trigger.nodeType === 1 ) ? trigger : document.activeElement;
                modal.removeAttribute('inert');
                modal.classList.add('is-open');
                document.documentElement.classList.add('mkcp-login-modal-open');
                document.body.classList.add('mkcp-login-modal-open');
                dialog.focus();
            }

            function mkcpCloseAccountModal() {
                if ( ! modal || ! modal.classList.contains('is-open') ) return;
                modal.classList.remove('is-open');
                modal.setAttribute('inert', '');
                document.documentElement.classList.remove('mkcp-login-modal-open');
                document.body.classList.remove('mkcp-login-modal-open');
                if ( lastFocusedTrigger && document.body.contains( lastFocusedTrigger ) ) {
                    lastFocusedTrigger.focus();
                }
                lastFocusedTrigger = null;
            }

            function mkcpBuildAccountTrigger() {
                var fields = document.querySelector('.woocommerce-account-fields');
                if ( ! fields ) return; // ingelogde klant o.i.d. — WC rendert de velden dan niet
                if ( ! window.mkcpSwitcher ) return;
                var switcher = window.mkcpSwitcher.ensure();
                if ( ! switcher ) return;

                window.mkcpSwitcher.addTab( switcher, 'account', '<?php echo esc_js( __( 'Ik ben nieuw', 'mk-cart-popup' ) ); ?>', ICON_ACCOUNT );
                mkcpBuildAccountModal();
                window.mkcpSwitcher.bindTab( switcher, 'account', mkcpOpenAccountModal );
                window.mkcpSwitcher.updateIndicator( switcher );
            }

            setTimeout( mkcpBuildAccountTrigger, 120 );
            if ( window.jQuery ) {
                jQuery( document.body ).on( 'updated_checkout', function () {
                    setTimeout( mkcpBuildAccountTrigger, 80 );
                } );
            }

            // Zichtbaar houden dat je voor een account hebt gekozen, ook als
            // de popup weer dicht is (of je intussen naar de login-tab
            // kijkt) — .is-selected hierboven volgt alleen welke tab je 't
            // laatst opende, niet de daadwerkelijke checkbox-keuze. Event
            // delegation op document i.p.v. een listener op één vastgepakte
            // checkbox-referentie: na een updated_checkout-AJAX-ververs kan
            // WooCommerce #createaccount vervangen door een nieuw element —
            // een directe listener zou dan op het oude, losgekoppelde
            // element blijven hangen en nooit meer vuren.
            document.addEventListener( 'change', function ( e ) {
                if ( ! e.target || e.target.id !== 'createaccount' ) return;
                var tab = document.querySelector('.mkcp-switcher-tab[data-target="account"]');
                if ( ! tab ) return;
                var label = tab.querySelector('span');
                tab.classList.toggle( 'is-confirmed', e.target.checked );
                if ( label ) {
                    label.textContent = e.target.checked
                        ? '<?php echo esc_js( __( 'Account wordt aangemaakt', 'mk-cart-popup' ) ); ?>'
                        : '<?php echo esc_js( __( 'Ik ben nieuw', 'mk-cart-popup' ) ); ?>';
                }
            } );

            // Live e-mailadres in het toelichtingskader — vult/toont
            // .mkcp-createaccount-info__email zodra billing_email een
            // geldig-ogend adres bevat (simpele "bevat een @ + iets erna"-
            // check, geen volledige RFC-validatie nodig — WooCommerce's
            // eigen veldvalidatie bij het versturen dekt dat al af, dit is
            // puur een live-preview terwijl je typt).
            function mkcpUpdateCreateAccountEmail() {
                var emailField = document.getElementById('billing_email');
                var note       = document.querySelector('.mkcp-createaccount-info__email');
                var valueEl    = document.querySelector('.mkcp-createaccount-info__email-value');
                if ( ! emailField || ! note || ! valueEl ) return;
                var val = emailField.value.trim();
                if ( /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( val ) ) {
                    valueEl.textContent = val;
                    note.hidden = false;
                } else {
                    note.hidden = true;
                }
            }
            // Gebruikersnaam vooraf invullen met het ingevulde e-mailadres —
            // alleen zolang de klant het gebruikersnaamveld nog niet zelf
            // heeft aangeraakt (mkcpUsernameTouched), anders zou elke
            // toetsaanslag in billing_email een handmatig gekozen
            // gebruikersnaam overschrijven. Het veld bestaat alleen wanneer
            // WooCommerce's "gebruikersnaam automatisch genereren"-instelling
            // uit staat (anders toont WC dit veld sowieso niet).
            var mkcpUsernameTouched = false;
            function mkcpPrefillUsername() {
                var emailField = document.getElementById('billing_email');
                var userField  = document.getElementById('account_username');
                if ( ! emailField || ! userField || mkcpUsernameTouched ) return;
                var val = emailField.value.trim();
                if ( val === userField.value ) return;
                userField.value = val;
                if ( window.mkcpUpdateFloatingLabels ) window.mkcpUpdateFloatingLabels();
            }
            document.addEventListener('input', function (e) {
                if ( ! e.target ) return;
                if ( e.target.id === 'billing_email' ) {
                    mkcpUpdateCreateAccountEmail();
                    mkcpPrefillUsername();
                } else if ( e.target.id === 'account_username' ) {
                    mkcpUsernameTouched = true;
                }
            });
            setTimeout( function () {
                mkcpUpdateCreateAccountEmail(); // veld kan al vooringevuld zijn (ingelogde klant met opgeslagen adres, browser-autofill)
                mkcpPrefillUsername();
            }, 120 );
        })();
        </script>
        <?php
    }, 20 );
}, 5 );


// ── "Terugkerende klant?"-inlogformulier: aan/uit + eigen toelichting ────────
//
// Zelfde soort behandeling als de "Account aanmaken?"-checkbox hierboven,
// voor het andere WooCommerce-core-blok dat alleen uitgelogde bezoekers zien
// (global/form-login.php, aangestuurd door WooCommerce's eigen "Sta inloggen
// tijdens checkout toe"-instelling). Geen verplaatsing nodig — dit blok
// rendert al bovenaan de checkout, vóór de klantgegevens, wat de logische
// plek is voor een "log eerst in"-prompt.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    if ( empty( $cfg['login_reminder_enabled'] ) ) {
        add_action( 'wp_footer', function() {
            echo '<style>.woocommerce-form-login-toggle,.woocommerce-form-login{display:none!important}</style>';
        }, 20 );
        return;
    }

    // woocommerce_login_form_start vuurt als allereerste regel binnen
    // <form class="woocommerce-form-login">, vóór WooCommerce's eigen
    // introductietekst en de gebruikersnaam-/wachtwoordvelden — precies waar
    // een eigen titel/toelichting bovenaan het formulier moet landen.
    if ( '' !== trim( (string) ( $cfg['login_reminder_info_text'] ?? '' ) ) || '' !== trim( (string) ( $cfg['login_reminder_info_title'] ?? '' ) ) ) {
        add_action( 'woocommerce_login_form_start', function() use ( $cfg ) {
            echo '<div class="mkcp-login-reminder-info">';
            if ( '' !== trim( (string) $cfg['login_reminder_info_title'] ) ) {
                echo '<p class="mkcp-login-reminder-info__title">' . esc_html( $cfg['login_reminder_info_title'] ) . '</p>';
            }
            if ( '' !== trim( (string) $cfg['login_reminder_info_text'] ) ) {
                echo '<p class="mkcp-login-reminder-info__text">' . wp_kses_post( nl2br( esc_html( $cfg['login_reminder_info_text'] ) ) ) . '</p>';
            }
            echo '</div>';
        } );
        // WooCommerce's eigen introductiezin ("Als je eerder bij ons hebt
        // gewinkeld...") is niet filterbaar (geen hook/filter beschikbaar voor
        // die specifieke tekst in global/form-login.php) — onderdrukt daarom
        // via CSS i.p.v. PHP, zie ".woocommerce-form-login:has(.mkcp-login-
        // reminder-info) > p:first-child" in checkout.scss, alleen actief
        // wanneer er ook echt een eigen kader is ingevoegd.
    }

    // Vertrouwenssignaal onder de knop ("Veilig inloggen") — woocommerce_
    // login_form_end vuurt vlak vóór </form>, dus als laatste element in de
    // grid (zie ".mkcp-login-trust" / grid-area "trust" in checkout.scss).
    add_action( 'woocommerce_login_form_end', function() {
        echo '<p class="mkcp-login-trust"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> '
            . esc_html__( 'Veilig inloggen', 'mk-cart-popup' ) . '</p>';
    } );

    // Drie interactie-verbeteringen die pure styling niet kan oplossen:
    // 1) autofocus op gebruikersnaam zodra het formulier opengaat (scheelt
    //    een klik) — WooCommerce's eigen showlogin-handler (checkout.js) doet
    //    zelf al de slideToggle()+scroll, hier alleen de focus erna.
    // 2) laadstatus op de knop bij versturen — dit is een gewone, volledige
    //    POST (geen AJAX), dus zonder dit geeft een klik geen enkele
    //    feedback tijdens de round-trip naar de server.
    // 3) direct na een mislukte inlogpoging ($_POST['login'] gezet, WC
    //    rendert het formulier dan hoe dan ook zichtbaar) naar het kaartje
    //    scrollen + kort schudden — zonder dit kan WooCommerce's foutmelding
    //    boven de pagina-fold verdwijnen na de volledige paginaherlaad.
    // WooCommerce's eigen foutmelding (".woocommerce-error", boven de
    // pagina) is overbodig zodra de mislukte-inlogpoging-feedback hieronder
    // (scroll + shake + rood randje op het kaartje zelf) er al is — anders
    // meldt de pagina twee keer hetzelfde. Alleen onderdrukken wanneer dit
    // request-cyclus een inlogpoging WAS ($_POST['login']) — checkout-eigen
    // validatiefouten (bv. verplicht veld leeg bij "Bestelling plaatsen")
    // lopen via een aparte AJAX-cyclus zonder $_POST['login'] en blijven dus
    // gewoon zichtbaar.
    $mkcp_just_attempted_login = isset( $_POST['login'] );
    if ( $mkcp_just_attempted_login ) {
        add_action( 'wp_footer', function() {
            echo '<style>.woocommerce-checkout > .woocommerce-error{display:none!important}</style>';
        }, 20 );
    }

    add_action( 'wp_footer', function() use ( $mkcp_just_attempted_login ) {
        ?>
        <script>
        (function () {
            // Schakelaar-helpers staan gedeeld op window.mkcpSwitcher (zie de
            // wp_footer-hook op prioriteit 15 hierboven) — dit blok en het
            // "Account aanmaken?"-blok hierboven landen zo in DEZELFDE
            // schakelaar, ongeacht welk van de twee als eerste draait.
            var ICON_LOGIN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

            var modal, dialog, lastFocusedTrigger;

            function mkcpBuildLoginModal() {
                var form = document.querySelector('.woocommerce-form-login');
                if ( ! form || document.getElementById('mkcp-login-modal') ) return;

                modal = document.createElement('div');
                modal.id = 'mkcp-login-modal';
                modal.className = 'mkcp-login-modal';
                modal.setAttribute( 'inert', '' );
                modal.innerHTML =
                    '<div class="mkcp-login-modal__backdrop"></div>' +
                    '<div class="mkcp-login-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_js( __( 'Inloggen', 'mk-cart-popup' ) ); ?>" tabindex="-1">' +
                        '<button type="button" class="mkcp-login-modal__close" aria-label="<?php echo esc_js( __( 'Sluiten', 'mk-cart-popup' ) ); ?>">' +
                            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
                        '</button>' +
                    '</div>';
                dialog = modal.querySelector('.mkcp-login-modal__dialog');
                // WooCommerce rendert dit formulier standaard al met inline
                // style="display:none" (global/form-login.php, 'hidden' =>
                // !$show_form) — die stijl reist gewoon mee bij het verplaatsen
                // en overleefde de modal-wrapper's eigen zichtbaarheids-CSS,
                // met een lege dialoog tot gevolg. Zichtbaarheid is nu
                // volledig de verantwoordelijkheid van .mkcp-login-modal.is-
                // open, dus de eigen inline stijl van het formulier moet weg.
                form.style.display = '';
                dialog.appendChild( form );
                document.body.appendChild( modal );

                modal.querySelector('.mkcp-login-modal__backdrop').addEventListener('click', mkcpCloseLoginModal);
                modal.querySelector('.mkcp-login-modal__close').addEventListener('click', mkcpCloseLoginModal);

                form.addEventListener('submit', function () {
                    form.classList.add('is-submitting');
                });

                document.addEventListener('keydown', function (e) {
                    if ( ! modal.classList.contains('is-open') ) return;
                    if ( e.key === 'Escape' ) { mkcpCloseLoginModal(); return; }
                    if ( e.key !== 'Tab' ) return;
                    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
                    var items = dialog.querySelectorAll( FOCUSABLE );
                    if ( ! items.length ) return;
                    var first = items[0], last = items[ items.length - 1 ];
                    if ( e.shiftKey && document.activeElement === first ) {
                        e.preventDefault(); last.focus();
                    } else if ( ! e.shiftKey && document.activeElement === last ) {
                        e.preventDefault(); first.focus();
                    }
                });
            }

            function mkcpOpenLoginModal( trigger ) {
                if ( ! modal ) return;
                lastFocusedTrigger = ( trigger && trigger.nodeType === 1 ) ? trigger : document.activeElement;
                modal.removeAttribute('inert');
                modal.classList.add('is-open');
                document.documentElement.classList.add('mkcp-login-modal-open');
                document.body.classList.add('mkcp-login-modal-open');
                dialog.focus();
                setTimeout( function () {
                    var el = document.getElementById('username');
                    if ( el && el.offsetParent !== null ) el.focus();
                }, 50 );
            }

            function mkcpCloseLoginModal() {
                if ( ! modal || ! modal.classList.contains('is-open') ) return;
                modal.classList.remove('is-open');
                modal.setAttribute('inert', '');
                document.documentElement.classList.remove('mkcp-login-modal-open');
                document.body.classList.remove('mkcp-login-modal-open');
                if ( lastFocusedTrigger && document.body.contains( lastFocusedTrigger ) ) {
                    lastFocusedTrigger.focus();
                }
                lastFocusedTrigger = null;
            }

            function mkcpBuildLoginTrigger() {
                var form = document.querySelector('.woocommerce-form-login');
                if ( ! form ) return; // ingelogde klant o.i.d. — WC rendert het formulier dan niet
                if ( ! window.mkcpSwitcher ) return;
                var switcher = window.mkcpSwitcher.ensure();
                if ( ! switcher ) return;

                window.mkcpSwitcher.addTab( switcher, 'login', '<?php echo esc_js( __( 'Ik ben al klant', 'mk-cart-popup' ) ); ?>', ICON_LOGIN );
                mkcpBuildLoginModal();
                window.mkcpSwitcher.bindTab( switcher, 'login', mkcpOpenLoginModal );
                window.mkcpSwitcher.updateIndicator( switcher );
            }

            setTimeout( mkcpBuildLoginTrigger, 120 );
            if ( window.jQuery ) {
                jQuery( document.body ).on( 'updated_checkout', function () {
                    setTimeout( mkcpBuildLoginTrigger, 80 );
                } );
            }

            <?php if ( $mkcp_just_attempted_login ) : ?>
            // Mislukte inlogpoging: de pagina is net volledig herladen met
            // $_POST['login'] gezet — open de modal meteen weer, selecteer
            // de login-tab (het accent-balkje schuift mee) en schud kort,
            // i.p.v. te verwachten dat de klant 'm zelf opnieuw aanklikt.
            setTimeout( function () {
                var tab = document.querySelector('.mkcp-switcher-tab[data-target="login"]');
                if ( tab ) tab.click(); // zet is-selected + schuift de indicator + opent de modal
                if ( ! modal ) return;
                dialog.classList.add('mkcp-login-shake');
                dialog.addEventListener('animationend', function () {
                    dialog.classList.remove('mkcp-login-shake');
                }, { once: true });
            }, 200 );
            <?php endif; ?>
        })();
        </script>
        <?php
    }, 20 );
}, 5 );


add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['postcode_checker_lock_fields'] ) ) return;
    if ( ! mkcp_postcode_checker_active() ) return;

    // WP Overnight kapt zijn eigen lookup-XHR standaard al na 8s af — op deze
    // checkout te kort: langlopende update_order_review-refreshes (o.a. door
    // de WPFactory VAT-plugin die er na elke validatie-cyclus één triggert)
    // kunnen het lookup-request zó lang uithongeren dat het antwoord er nog
    // gewoon aankomt, maar nét na die 8s. Ruimer zetten zodat een trage-maar-
    // geslaagde lookup alsnog als succes landt; ons eigen zoekvangnet
    // (mkcp_onSearchTimeout hieronder) wacht net iets langer dan dit.
    add_filter( 'woocommerce_postcode_checker_xhr_timeout', function() {
        return 20000;
    } );

    add_filter( 'woocommerce_checkout_fields', function( $fields ) {
        foreach ( [ 'billing', 'shipping' ] as $group ) {
            if ( empty( $fields[ $group ] ) ) continue;

            foreach ( [ $group . '_street_name', $group . '_city' ] as $id ) {
                if ( isset( $fields[ $group ][ $id ] ) ) {
                    $fields[ $group ][ $id ]['custom_attributes'] = array_merge(
                        $fields[ $group ][ $id ]['custom_attributes'] ?? [],
                        [ 'readonly' => 'readonly' ]
                    );
                }
            }
            // Placeholder + mobiel toetsenbord
            if ( isset( $fields[ $group ][ $group . '_postcode' ] ) ) {
                $fields[ $group ][ $group . '_postcode' ]['custom_attributes']['autocomplete'] = 'postal-code';
            }
            if ( isset( $fields[ $group ][ $group . '_house_number' ] ) ) {
                $fields[ $group ][ $group . '_house_number' ]['custom_attributes']['inputmode']    = 'numeric';
                $fields[ $group ][ $group . '_house_number' ]['custom_attributes']['autocomplete'] = 'off';
            }
            // Geen standaard autocomplete-token voor een toevoeging (bv. "A",
            // "2hs") — expliciet uit i.p.v. aan het toeval van de browser over
            // te laten wat 'ie daar zou willen voorstellen.
            if ( isset( $fields[ $group ][ $group . '_house_number_suffix' ] ) ) {
                $fields[ $group ][ $group . '_house_number_suffix' ]['custom_attributes']['autocomplete'] = 'off';
            }
        }
        return $fields;
    } );

    // JS re-zet readonly na elke postcode-lookup (WP Overnight verwijdert het attribuut bij invullen)
    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {

            // Zwevende labels worden door een onafhankelijke wp_footer-hook geladen
            // (altijd actief op de checkout, los van de postcode-checker instelling).
            function mkcp_updateFloatingLabels() {
                if ( window.mkcpUpdateFloatingLabels ) window.mkcpUpdateFloatingLabels();
            }

            /* ── Postcode-checker per adresgroep (billing / shipping) — alleen
               relevant bij Nederland. Factuur- en verzendadres worden
               bewust ONAFHANKELIJK van elkaar beoordeeld (elk leest zijn
               eigen _country-veld, mkcp-intl-address komt op de eigen
               .woocommerce-{prefix}-fields__field-wrapper te staan, niet op
               <body>) — een bestelling met NL als factuuradres maar een
               ander land als verzendadres (of andersom) moet in élke kolom
               de juiste velden tonen, niet de kolom die toevallig het eerst
               is gecontroleerd. */
            function mkcp_initPostcodeChecker(prefix) {
                var LOCK_IDS         = [prefix + '_street_name', prefix + '_city'];
                var wrapperSelector  = '.woocommerce-' + prefix + '-fields__field-wrapper';
                var wrapperEl        = document.querySelector(wrapperSelector);
                var countryEl        = document.getElementById(prefix + '_country');
                var searchTimer      = null;
                var manualEntry      = false;
                var searchInProgress = false;
                var foundFired       = false;
                var notFoundFired    = false;
                var notFoundTimer    = null;

                function mkcp_isIntlAddress() {
                    return !! ( wrapperEl && wrapperEl.classList.contains( 'mkcp-intl-address' ) );
                }

                function mkcp_syncIntlAddressMode() {
                    if ( ! wrapperEl ) return;
                    var isIntl = !! ( countryEl && countryEl.value && countryEl.value !== 'NL' );
                    wrapperEl.classList.toggle( 'mkcp-intl-address', isIntl );

                    // Bij het omschakelen náár een ander land moet een eventuele
                    // Nederlandse readonly-vergrendeling van een vorige sessie
                    // meteen verdwijnen — anders blijft bv. Plaats voor altijd
                    // grijs en leeg staan zonder enige manier om 'm in te vullen.
                    if ( isIntl ) {
                        LOCK_IDS.forEach( function ( id ) {
                            var el = document.getElementById( id );
                            if ( el ) el.removeAttribute( 'readonly' );
                        } );
                        mkcp_hideStatus( 0 );
                    }
                    mkcp_updateFloatingLabels();
                }

                // Select2 wisselt de waarde met jQuery's .trigger('change') — dat
                // stuurt in de praktijk GEEN echt native change-event de DOM in
                // (bevestigd via live test: een gewone addEventListener('change')
                // hoort hier niets, terwijl jQuery(...).on('change') het wél
                // opvangt). Met alleen addEventListener bleef mkcp-intl-address
                // daardoor permanent op zijn staat bij page-load hangen, ongeacht
                // hoe vaak de klant van land wisselde — vandaar via jQuery binden.
                if ( countryEl ) {
                    if ( window.jQuery ) {
                        jQuery( countryEl ).on( 'change', mkcp_syncIntlAddressMode );
                    } else {
                        countryEl.addEventListener( 'change', mkcp_syncIntlAddressMode );
                    }
                }
                mkcp_syncIntlAddressMode();

                /* ── Readonly lock ── */
                function mkcp_lockPostcodeFields() {
                    if (manualEntry || mkcp_isIntlAddress()) return;
                    LOCK_IDS.forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) el.setAttribute('readonly', 'readonly');
                    });
                }

                LOCK_IDS.forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    new MutationObserver(function () {
                        if (!manualEntry && !mkcp_isIntlAddress() && !el.hasAttribute('readonly')) el.setAttribute('readonly', 'readonly');
                    }).observe(el, { attributes: true, attributeFilter: ['readonly'] });
                });

                mkcp_lockPostcodeFields();

                /* ── Statusbalk ── */
                var STATUS_ID = 'mkcp-pc-status-' + prefix;

                // De klant corrigeert bij een foutmelding hier de postcode/het
                // huisnummer (niet street_name/city — dat zijn de door de lookup
                // ingevulde uitvoervelden) — dus daar hangt aria-invalid/
                // -describedby aan, ongeacht waar de balk zelf visueel staat.
                function mkcp_statusTargetFields() {
                    var fields = [];
                    var pc = document.getElementById(prefix + '_postcode');
                    var nr = document.getElementById(prefix + '_house_number');
                    if (pc) fields.push(pc);
                    if (nr) fields.push(nr);
                    return fields;
                }

                var fieldStatus = window.mkcpFieldStatus.create({
                    statusId: STATUS_ID,
                    extraClass: '',
                    // Nooit een postcode-status tonen bij een niet-Nederlands adres
                    // (bv. "Ongeldige postcode" op een Duitse postcode) — dit hele
                    // veld is dan sowieso verborgen, zie .mkcp-intl-address in
                    // checkout.scss.
                    guard: function () { return !mkcp_isIntlAddress(); },
                    insert: function (el) {
                        var anchor = document.getElementById(prefix + '_street_name_field');
                        if (!anchor) return false;
                        anchor.parentNode.insertBefore(el, anchor);
                    },
                    getTargetFields: mkcp_statusTargetFields
                });
                var mkcp_showStatus = fieldStatus.showStatus;
                var mkcp_hideStatus = fieldStatus.hideStatus;

                /* ── Visuele feedback tijdens lookup ── */
                var loadingTimer = null;

                function mkcp_setLoading(on) {
                    clearTimeout(loadingTimer);
                    if (on) {
                        // Laadstatus pas tonen na 400ms — snelle responses flikkeren niet
                        loadingTimer = setTimeout(function () {
                            LOCK_IDS.forEach(function (id) {
                                var w = document.getElementById(id + '_field');
                                if (w) w.classList.add('mkcp-pc-loading');
                            });
                            mkcp_showStatus('loading', 'Adres ophalen…', 'Even geduld, we zoeken je adresgegevens op');
                        }, 400);
                    } else {
                        LOCK_IDS.forEach(function (id) {
                            var w = document.getElementById(id + '_field');
                            if (w) w.classList.remove('mkcp-pc-loading');
                        });
                    }
                }

                function mkcp_setSuccess() {
                    var street = (document.getElementById(prefix + '_street_name') || {}).value || '';
                    var city   = (document.getElementById(prefix + '_city')        || {}).value || '';
                    var sub    = (street && city) ? street + ', ' + city : (street || city || '');
                    mkcp_showStatus('success', 'Adres gevonden!', sub);
                }

                var debounceTimer = null;

                function mkcp_validPostcode() {
                    var pc = document.getElementById(prefix + '_postcode');
                    return pc && /^\d{4}\s?[A-Za-z]{2}$/.test(pc.value.trim());
                }

                function mkcp_bothFilled() {
                    var pc = document.getElementById(prefix + '_postcode');
                    var nr = document.getElementById(prefix + '_house_number');
                    return pc && nr && pc.value.trim() && nr.value.trim();
                }

                function mkcp_clearAddressFields() {
                    LOCK_IDS.forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    mkcp_setLoading(false);
                    mkcp_hideStatus(0);
                    clearTimeout(searchTimer);
                    clearTimeout(notFoundTimer);
                    clearInterval(pollTimer);
                    foundFired       = false;
                    notFoundFired    = false;
                    manualEntry      = false;
                    searchInProgress = false;
                    mkcp_lockPostcodeFields();
                    mkcp_updateFloatingLabels();
                }

                // Vangnet als de lookup te lang duurt (bv. doordat de server
                // druk is met langlopende update_order_review-refreshes — de
                // WPFactory VAT-plugin triggert die na elke validatie-cyclus
                // opnieuw, waardoor het postcode-request lang kan uithongeren).
                // Niet stil verdwijnen maar dezelfde foutbalk als "adres niet
                // gevonden" tonen, mét de handmatig-invullen-link — anders
                // staart de klant naar readonly straat/plaats-velden zonder
                // uitweg. 22s: net voorbij WP Overnight's XHR-timeout, die we
                // via woocommerce_postcode_checker_xhr_timeout op 20s hebben
                // gezet (was 8s — te kort op deze checkout, waardoor "duurde
                // te lang" verscheen terwijl het antwoord er alsnog aankwam).
                function mkcp_onSearchTimeout() {
                    if (!searchInProgress) return;
                    searchInProgress = false;
                    clearTimeout(searchTimer);
                    clearTimeout(notFoundTimer);
                    clearInterval(pollTimer);
                    mkcp_setLoading(false);
                    mkcp_showStatus('error', 'Adres ophalen duurde te lang',
                        'Probeer het opnieuw, of <a href="#" class="mkcp-manual-entry">vul handmatig in</a>');
                }

                function mkcp_triggerSearch() {
                    if (mkcp_isIntlAddress()) return;
                    if (!mkcp_bothFilled() || !mkcp_validPostcode()) return;
                    manualEntry      = false;
                    foundFired       = false;
                    searchInProgress = true;
                    mkcp_setLoading(true);
                    mkcp_startPoll();
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(mkcp_onSearchTimeout, 22000);
                    setTimeout(mkcp_lockPostcodeFields, 300);
                    setTimeout(mkcp_lockPostcodeFields, 1500);
                }

                // Handmatige invoer: readonly opheven na "vul handmatig in" klik
                // (gescoped op de eigen fields__field-wrapper zodat billing/shipping
                // elkaars status-knop niet oppikken)
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.mkcp-manual-entry');
                    if (!btn || !btn.closest(wrapperSelector)) return;
                    e.preventDefault();
                    manualEntry = true;
                    LOCK_IDS.forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) el.removeAttribute('readonly');
                    });
                    mkcp_hideStatus(0);
                    var first = document.getElementById(LOCK_IDS[0]);
                    if (first) first.focus();
                });

                /* ── Postcode auto-format ── */
                function mkcp_formatPostcode() {
                    var pc = document.getElementById(prefix + '_postcode');
                    if (!pc) return;
                    var m = pc.value.trim().toUpperCase().match(/^(\d{4})\s?([A-Z]{2})$/);
                    if (m) pc.value = m[1] + ' ' + m[2];
                }

                /* ── Veld-events ── */

                // Postcode
                var pcEl = document.getElementById(prefix + '_postcode');
                if (pcEl) {
                    pcEl.addEventListener('focus', function () { mkcp_hideStatus(0); });

                    pcEl.addEventListener('input', function () {
                        clearTimeout(debounceTimer);
                        // Auto-uppercase tijdens typen
                        var pos = pcEl.selectionStart;
                        pcEl.value = pcEl.value.toUpperCase();
                        try { pcEl.selectionStart = pcEl.selectionEnd = pos; } catch (ignore) {}
                        // Altijd adres wissen bij aanpassing postcode
                        mkcp_clearAddressFields();
                    });

                    pcEl.addEventListener('blur', function () {
                        clearTimeout(debounceTimer);
                        if (!pcEl.value.trim()) { mkcp_clearAddressFields(); return; }
                        mkcp_formatPostcode(); // 1234AB → 1234 AB
                        if (!mkcp_validPostcode()) {
                            mkcp_showStatus('error', 'Ongeldige postcode', 'Gebruik het formaat 1234 AB');
                            return;
                        }
                        if (!mkcp_bothFilled()) return;
                        debounceTimer = setTimeout(mkcp_triggerSearch, 300);
                    });

                    // Autofill via browser vuurt change zonder blur
                    pcEl.addEventListener('change', function () {
                        mkcp_formatPostcode();
                        clearTimeout(debounceTimer);
                        if (!mkcp_validPostcode() || !mkcp_bothFilled()) return;
                        debounceTimer = setTimeout(mkcp_triggerSearch, 300);
                    });
                }

                // Huisnummer
                var nrEl = document.getElementById(prefix + '_house_number');
                if (nrEl) {
                    nrEl.addEventListener('input', function () {
                        clearTimeout(debounceTimer);
                        if (!mkcp_bothFilled()) mkcp_clearAddressFields();
                    });
                    nrEl.addEventListener('blur', function () {
                        clearTimeout(debounceTimer);
                        if (!mkcp_bothFilled()) return;
                        if (!mkcp_validPostcode()) {
                            mkcp_showStatus('error', 'Ongeldige postcode', 'Gebruik het formaat 1234 AB');
                            return;
                        }
                        debounceTimer = setTimeout(mkcp_triggerSearch, 300);
                    });
                }

                // Toevoeging: alleen wissen als combinatie leeg wordt
                var sfxEl = document.getElementById(prefix + '_house_number_suffix');
                if (sfxEl) {
                    sfxEl.addEventListener('input', function () {
                        clearTimeout(debounceTimer);
                        if (!mkcp_bothFilled()) mkcp_clearAddressFields();
                    });
                }

                /* ── WP Overnight detectie (3 strategieën) ── */

                // De postcode-checker (WP Overnight) markeert bij een mislukte
                // lookup óók de toevoeging (huisnummer-suffix) als ongeldig,
                // maar zijn eigen succes-afhandeling ruimt alleen postcode en
                // huisnummer zelf weer op — de suffix blijft daardoor voorgoed
                // met een rode rand/kruisje staan na een latere, geslaagde
                // correctie. Vangen wij hier op, want dit is code van een
                // derde partij die we niet aanpassen.
                function mkcp_clearSuffixInvalidState() {
                    var sfxWrap = document.getElementById(prefix + '_house_number_suffix_field');
                    if (!sfxWrap) return;
                    sfxWrap.classList.remove('woocommerce-invalid', 'woocommerce-invalid-required-field');
                    sfxWrap.classList.add('woocommerce-validated');
                }

                function mkcp_onFound() {
                    if (!searchInProgress) return;
                    if (foundFired) return;
                    var street = (document.getElementById(prefix + '_street_name') || {}).value;
                    var city   = (document.getElementById(prefix + '_city')        || {}).value;
                    if (!street && !city) return;
                    // Annuleer een eventueel lopende not-found vertraging
                    clearTimeout(notFoundTimer);
                    notFoundFired    = false;
                    foundFired       = true;
                    searchInProgress = false;
                    clearTimeout(searchTimer);
                    clearInterval(pollTimer);
                    mkcp_setLoading(false);
                    mkcp_setSuccess();
                    mkcp_clearSuffixInvalidState();
                    mkcp_updateFloatingLabels();
                    setTimeout(mkcp_lockPostcodeFields, 50);
                    setTimeout(function () { foundFired = false; }, 500);
                }

                function mkcp_onNotFound() {
                    if (!searchInProgress) return;
                    if (notFoundFired) return;
                    notFoundFired = true;
                    // WP Overnight zet soms eerst wcnlpc-not-found en daarna wcnlpc-validated.
                    // Wacht 300ms zodat mkcp_onFound() nog kan annuleren.
                    clearTimeout(notFoundTimer);
                    notFoundTimer = setTimeout(function () {
                        if (!notFoundFired) return;    // geannuleerd door mkcp_onFound
                        if (!searchInProgress) return; // al afgehandeld
                        // Als velden toch ingevuld zijn, toon dan succes
                        var street = (document.getElementById(prefix + '_street_name') || {}).value;
                        var city   = (document.getElementById(prefix + '_city')        || {}).value;
                        if (street || city) {
                            notFoundFired = false;
                            mkcp_onFound();
                            return;
                        }
                        foundFired       = true;
                        searchInProgress = false;

                        clearTimeout(searchTimer);
                        clearInterval(pollTimer);
                        mkcp_setLoading(false);
                        mkcp_showStatus('error', 'Adres niet gevonden',
                            'Controleer je postcode en huisnummer, of <a href="#" class="mkcp-manual-entry">vul handmatig in</a>');
                        setTimeout(function () { notFoundFired = false; foundFired = false; }, 600);
                    }, 300);
                }

                // 1. MutationObserver op wrapper-klasse
                LOCK_IDS.forEach(function (id) {
                    var wrapper = document.getElementById(id + '_field');
                    if (!wrapper) return;
                    new MutationObserver(function () {
                        if (wrapper.classList.contains('wcnlpc-validated'))  mkcp_onFound();
                        if (wrapper.classList.contains('wcnlpc-not-found'))  mkcp_onNotFound();
                    }).observe(wrapper, { attributes: true, attributeFilter: ['class'] });
                });

                // 2. jQuery delegated change (vangt .trigger('change') wél)
                if (window.jQuery) {
                    jQuery(document).on('change', '#' + prefix + '_street_name, #' + prefix + '_city', mkcp_onFound);
                }

                // 3. Polling als laatste vangnet (elke 250ms, max 22s — zelfde
                // horizon als searchTimer; mkcp_onSearchTimeout is idempotent
                // via searchInProgress, dus wie het eerst afgaat wint)
                var pollTimer = null;
                function mkcp_startPoll() {
                    clearInterval(pollTimer);
                    var ticks = 0;
                    pollTimer = setInterval(function () {
                        ticks++;
                        var street = (document.getElementById(prefix + '_street_name') || {}).value;
                        var city   = (document.getElementById(prefix + '_city')        || {}).value;
                        if (street || city) { clearInterval(pollTimer); setTimeout(mkcp_onFound, 80); return; }
                        if (ticks > 88)     { clearInterval(pollTimer); mkcp_onSearchTimeout(); }
                    }, 250);
                }

                if (window.jQuery) {
                    jQuery(document.body).on('updated_checkout', function () {
                        mkcp_lockPostcodeFields();
                        mkcp_updateFloatingLabels();
                    });
                }
            }

            // De verzendadres-velden bestaan pas zodra "Afwijkend verzendadres"
            // aangevinkt is — die worden door WooCommerce via updated_checkout
            // (her)opgebouwd, ná deze eerste run. Daarom niet alleen nu maar ook
            // na elke ververs proberen; het initialized-register houdt het
            // idempotent (anders zou elke ververs een extra set listeners en
            // pollers binden op dezelfde velden).
            var mkcp_pcInitialized = {};
            function mkcp_tryInitPostcodeChecker(prefix) {
                if (mkcp_pcInitialized[prefix]) return;
                if (!document.getElementById(prefix + '_postcode') || !document.getElementById(prefix + '_house_number')) return;
                mkcp_pcInitialized[prefix] = true;
                mkcp_initPostcodeChecker(prefix);
            }

            mkcp_initPostcodeChecker('billing');
            mkcp_pcInitialized.billing = true;
            mkcp_tryInitPostcodeChecker('shipping');

            if (window.jQuery) {
                jQuery(document.body).on('updated_checkout', function () {
                    mkcp_tryInitPostcodeChecker('shipping');
                });
            }
        })();
        </script>
        <?php
    } );
}, 10 );


// ── 3-blokken layout: delivery + payment secties ─────────────────────────────
//
// Injecteert twee extra blokken als directe grid-children van .woocommerce-checkout:
//   • .mkcp-co-section--delivery (grid-row:3, col 1) — bezorgdatum + checkout-info
//   • .mkcp-co-section--payment  (grid-row:4, col 1) — JS verplaatst #payment hierin
//
// Delivery date render hook wordt verplaatst van woocommerce_review_order_before_submit
// naar mkcp_checkout_delivery_section zodat het widget buiten #order_review staat
// (en dus niet wordt gewist bij elke WooCommerce AJAX-refresh).

add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    // Zelfde reden als bij template_include hierboven: deze sectie hangt aan
    // classic-only hooks die op een Blocks-checkout toch nooit vuren, dus dit
    // is puur defensief/consistent — geen functionele wijziging.
    if ( mkcp_checkout_uses_blocks() ) return;

    // 3-blokken layout alleen injecteren als de custom checkout template ook actief is
    // (template_include vereist dat minstens één visuele feature aan staat).
    $has_visual = ! empty( $cfg['header_enabled'] ) || ! empty( $cfg['footer_enabled'] )
               || ! empty( $cfg['steps_enabled'] ) || ! empty( $cfg['payment_icons_enabled'] );

    if ( ! $has_visual ) return;

    // Fase 2: geen verplaatsing meer nodig — de bezorgdatum-/afhaal-widget(s)
    // renderen nu al direct in de juiste sectie, als onderdeel van de per-
    // pakket verzendkeuze-kaarten zelf (templates/cart-shipping-choice.php),
    // die zowel in de standaard- als in de 3-blokken-layout al op de juiste
    // plek terechtkomen (zie shipping-choice.php se eigen hook-keuze).

    // "Verzending en levering" sectie — na #customer_details, als grid col-1 item.
    add_action( 'woocommerce_checkout_after_customer_details', function() {
        ?>
        <div class="mkcp-co-section mkcp-co-section--delivery">
            <div class="mkcp-co-section__body">
                <h3 class="mkcp-co-section__title"><?php esc_html_e( 'Verzending en levering', 'mk-cart-popup' ); ?></h3>
                <?php
                do_action( 'mkcp_checkout_info' );
                do_action( 'mkcp_checkout_delivery_section' );
                ?>
            </div>
        </div>
        <?php
    } );

    // "Betaling" sectie — vóór #order_review, als grid col-1 item.
    // mkcp_checkout_claim_payment_section() hierboven heeft
    // woocommerce_checkout_payment al op mkcp_checkout_payment_section
    // gezet (ongeacht waar een thema 'm zelf had opgehangen), dus #payment
    // rendert hier direct — de JS-verplaatsing (mkco_reorganize, stap 3)
    // blijft als defensief vangnet staan, maar hoeft in de praktijk niets
    // meer te doen.
    add_action( 'woocommerce_checkout_before_order_review', function() {
        ?>
        <div class="mkcp-co-section mkcp-co-section--payment">
            <div class="mkcp-co-section__body">
                <h3 class="mkcp-co-section__title"><?php esc_html_e( 'Betaling', 'mk-cart-popup' ); ?></h3>
                <?php do_action( 'mkcp_checkout_payment_section' ); ?>
            </div>
        </div>
        <?php
    } );

    // Inklapbare kop voor #order_review op mobiel/tablet (< 900px) — optioneel,
    // want niet elke site vindt dit wenselijk. Wrapt de volledige inhoud van
    // #order_review (BTW-switch, besteltabel, betaalicons-strip) in een
    // toggle-body: priority 1 (vóór de BTW-switch op 5) opent de wrapper,
    // priority 100 (ná de betaalicons-strip op 15) sluit hem weer.
    if ( ! empty( $cfg['order_review_collapsible_mobile'] ) ) {
        add_action( 'woocommerce_checkout_order_review', function() {
            ?>
            <button type="button" class="mkcp-co-review-toggle" aria-expanded="false" aria-controls="mkcp-co-review-body">
                <span class="mkcp-co-review-toggle__title"><?php esc_html_e( 'Overzicht van je bestelling', 'mk-cart-popup' ); ?></span>
                <span class="mkcp-co-review-toggle__chevron">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </button>
            <div class="mkcp-co-review-body" id="mkcp-co-review-body">
            <?php
        }, 1 );

        add_action( 'woocommerce_checkout_order_review', function() {
            ?>
            </div><?php // sluit .mkcp-co-review-body
        }, 100 );
    }

    // JS: verplaats elementen uit col-2 naar de juiste sectieblokken.
    //
    // Werkelijke HTML-structuur: #payment, checkout-info en datumpicker zitten
    // allemaal in #customer_details > .col-2 > .woocommerce-additional-fields,
    // NIET in #order_review. WC AJAX ververst alleen #order_review (rechterkolom),
    // dus de moves zijn eenmalig maar worden ook herhaald na updated_checkout.
    $mkcp_orderbar_main_cfg = mkcp_config();
    $mkcp_orderbar_label_excl = $mkcp_orderbar_main_cfg['label_excl_tax'] ?? __( 'excl. BTW', 'mk-cart-popup' );
    $mkcp_orderbar_label_incl = $mkcp_orderbar_main_cfg['label_incl_tax'] ?? __( 'incl. BTW', 'mk-cart-popup' );
    add_action( 'wp_footer', function() use ( $mkcp_orderbar_label_excl, $mkcp_orderbar_label_incl ) {
        // is_checkout() geldt ook op de bedankt-pagina (order-received) — daar
        // is er geen #place_order-knop meer om door te klikken, dus de balk
        // hoort daar niet thuis.
        $on_thankyou = function_exists( 'is_order_received_page' ) && is_order_received_page();
        if ( $on_thankyou ) return;
        ?>
        <!-- Vaste balk onderaan op mobiel/tablet (< 900px) met het totaalbedrag
             en een knop die de echte #place_order-knop "doorklikt", zodat de
             klant niet steeds naar beneden hoeft te scrollen om te bestellen. -->
        <div class="mkcp-mobile-orderbar" id="mkcp-mobile-orderbar">
            <div class="mkcp-mobile-orderbar__total">
                <span class="mkcp-mobile-orderbar__label"><?php esc_html_e( 'Totaal', 'mk-cart-popup' ); ?></span>
                <span class="mkcp-mobile-orderbar__amount" id="mkcp-mobile-orderbar-amount"></span>
            </div>
            <button type="button" class="mkcp-mobile-orderbar__review-btn" id="mkcp-mobile-orderbar-review-btn" aria-haspopup="dialog" aria-expanded="false" aria-controls="order_review" title="<?php esc_attr_e( 'Bekijk bestelling', 'mk-cart-popup' ); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M16 2H4a1 1 0 0 0-1 1v18l3-2 2 2 2-2 2 2 2-2 2 2 2-2 3 2V3a1 1 0 0 0-1-1z"/><line x1="7" y1="8" x2="17" y2="8"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="13" y2="16"/></svg>
                <span class="mkcp-mobile-orderbar__review-btn-label"><?php esc_html_e( 'Bekijk bestelling', 'mk-cart-popup' ); ?></span>
            </button>
            <button type="button" class="mkcp-mobile-orderbar__btn" id="mkcp-mobile-orderbar-btn">
                <span class="mkcp-mobile-orderbar__btn-spinner"></span>
                <span class="mkcp-mobile-orderbar__btn-label"><?php esc_html_e( 'Bestellen', 'mk-cart-popup' ); ?></span>
            </button>
        </div>
        <div class="mkcp-review-modal-backdrop" id="mkcp-review-modal-backdrop"></div>
        <script>
        (function () {
            var _done = false;

            // Verrijkt het native WooCommerce-vinkje ("Verzenden naar een ander
            // adres?") eenmalig met een icoontje + een toggle-switch rechts,
            // in dezelfde stijl als de BTW-switch elders in de checkout —
            // i.p.v. het kale checkbox-vierkantje. Puur presentatie: de echte
            // <input type="checkbox"> blijft functioneel ongewijzigd, alleen
            // visueel verborgen (zie checkout.scss); de aan/uit-status van de
            // toggle volgt 'm 1-op-1 via de CSS :has(input:checked)-regel.
            // Idempotent (guard op .mkcp-ship-toggle__track) zodat herhaalde
            // mkco_reorganize()-cycli 'm niet steeds opnieuw opbouwen.
            function mkcpEnhanceShipToggle(shipToggle) {
                if (!shipToggle) return;
                var label = shipToggle.querySelector('label');
                if (!label || label.querySelector('.mkcp-ship-toggle__track')) return;

                label.classList.add('mkcp-ship-toggle');

                var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                icon.setAttribute('class', 'mkcp-ship-toggle__icon');
                icon.setAttribute('viewBox', '0 0 24 24');
                icon.setAttribute('fill', 'none');
                icon.setAttribute('stroke', 'currentColor');
                icon.setAttribute('stroke-width', '2');
                icon.setAttribute('stroke-linecap', 'round');
                icon.setAttribute('stroke-linejoin', 'round');
                icon.innerHTML = '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>';

                var track = document.createElement('span');
                track.className = 'mkcp-ship-toggle__track';
                track.innerHTML =
                    '<span class="mkcp-ship-toggle__thumb">' +
                        '<svg class="mkcp-ship-toggle__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
                    '</span>';

                var input = label.querySelector('input');
                if (input) {
                    input.insertAdjacentElement('afterend', icon);
                } else {
                    label.insertBefore(icon, label.firstChild);
                }
                label.appendChild(track);
            }

            function mkco_reorganize() {
                var secDel = document.querySelector('.mkcp-co-section--delivery .mkcp-co-section__body');
                var secPay = document.querySelector('.mkcp-co-section--payment .mkcp-co-section__body');
                if (!secDel || !secPay) return;

                // 0. Verzendkeuze-kaarten (.woocommerce-shipping-totals.shipping,
                //    zie includes/shipping-choice.php): op de allereerste
                //    paginalaad renderen deze al rechtstreeks in de leverings-
                //    sectie (mkcp_checkout_delivery_section) — niets te doen.
                //    Na een AJAX-refresh levert het verborgen anker
                //    (#shipping-choice-ajax-anchor) via WooCommerce's fragment-
                //    replaceWith een VERSE kopie af, ergens buiten de
                //    leveringssectie (replaceWith vervangt het ankerelement
                //    zelf — dat heeft geen eigen id meer om op te zoeken — en
                //    de kaarten-<div> is bovendien ongeldige HTML direct
                //    binnen de <table>, dus de browser tilt 'm er bij het
                //    parsen uit). Zoek daarom op klasse i.p.v. op het anker-id.
                //
                //    Bij meerdere verzendpakketten (bv. een deel alleen af te
                //    halen naast een deel te bezorgen) rendert
                //    mkcp_render_all_shipping_choice_cards() per AJAX-cyclus
                //    EEN kaartgroep-<div> per pakket — dus "alles wat nog niet
                //    in de leveringssectie staat" kan er meerdere zijn, niet
                //    maar één. Vroeger hield deze code alleen de láátste over
                //    en verwijderde de rest, waardoor bij 2+ pakketten steeds
                //    één kaartgroep spoorloos verdween (of allebei dezelfde,
                //    laatst-gerenderde inhoud leken te tonen). Nu: alle verse
                //    kopieën behouden, in dezelfde volgorde als gerenderd, en
                //    ALLE oude kopieën (kan er ook meer dan één zijn) opruimen.
                var shipOutside = Array.prototype.filter.call(
                    document.querySelectorAll('.woocommerce-shipping-totals.shipping'),
                    function (el) { return !el.closest('.mkcp-co-section--delivery'); }
                );
                if (shipOutside.length) {
                    Array.prototype.forEach.call(
                        secDel.querySelectorAll('.woocommerce-shipping-totals.shipping'),
                        function (el) { el.remove(); }
                    );
                    // Ná de sectietitel invoegen (die staat nu, samen met de rest,
                    // ín .mkcp-co-section__body) — niet als allereerste kind, anders
                    // schuift de verzendkeuze-kaart vóór "Verzending en levering".
                    var secDelTitle = secDel.querySelector('.mkcp-co-section__title');
                    var shipAnchor = secDelTitle ? secDelTitle.nextSibling : secDel.firstChild;
                    shipOutside.forEach(function (el) {
                        secDel.insertBefore(el, shipAnchor);
                    });
                }

                // 1. (Fase 2, vervallen) De bezorgdatum-/afhaal-widget(s) renderen
                //    sinds Fase 2 al direct ALS KIND van .woocommerce-shipping-
                //    totals.shipping (zie templates/cart-shipping-choice.php) —
                //    ze reizen dus automatisch mee met stap 0 hierboven en landen
                //    nooit meer los in #payment. Geen aparte verplaatsing meer nodig.

                // 1b. "Verzenden naar een ander adres?" (native WooCommerce-
                //     markup uit checkout/form-shipping.php: <h3 id="ship-to-
                //     different-address"> met het vinkje, gevolgd door een
                //     losse <div class="shipping_address"> met de adresvelden
                //     — geen ouder/kind-relatie tussen die twee, en WC's eigen
                //     checkout.js schakelt ze ook via losse, globale selectors
                //     ($('div.shipping_address'), '#ship-to-different-address
                //     input') — dus ze mogen zonder problemen naar verschillende
                //     DOM-plekken verplaatst worden.
                //
                //     Het vinkje moet, ná stap 0 hierboven, ALTIJD als SIBLING
                //     van de verzendkeuze-kaart(en) staan — NOOIT als kind van
                //     de bezorglocatie-box (#mkcp-dd-address) of van iets anders
                //     dat ín de kaart zit. De bezorglocatie-box zelf zit ín de
                //     kaart, en die kaart wordt bij een AJAX-cyclus die ná de
                //     eerste paginalaad plaatsvindt (bv. WooCommerce's eigen
                //     automatische verzendberekening vlak na het laden — vuurt
                //     niet altijd, vandaar "soms wel, soms niet") door stap 0
                //     hierboven verwijderd via el.remove(). Stond het vinkje
                //     daar op dat moment ALS KIND in (eerdere versie, met de
                //     bedoeling het optisch bij de box te laten horen), dan werd
                //     het gewoon meegesleurd — en omdat el.remove() 'm uit de
                //     live document haalt, vindt getElementById() 'm daarna
                //     nooit meer terug om opnieuw te plaatsen: definitief weg
                //     tot een harde refresh. Het "hoort optisch bij de box"-
                //     effect wordt daarom puur met CSS bereikt (zie checkout.scss,
                //     .mkcp-pu-location--ship-toggle-below), niet met DOM-nesting.
                //
                //     De adresvelden (die bij aanvinken verschijnen) staan om
                //     dezelfde reden ook als sibling, niet als kind.
                //
                //     Beide zitten van zichzelf in #customer_details, dat
                //     WooCommerce's AJAX nooit vervangt (dat raakt alleen
                //     #order_review) — dus geen "eerst terug naar huis"-truc
                //     nodig zoals bij écht AJAX-vervangen content.
                var shipToggle = document.getElementById('ship-to-different-address');
                var addrBox    = secDel.querySelector('#mkcp-dd-address');
                var shipCards  = secDel.querySelectorAll('.woocommerce-shipping-totals.shipping');
                var lastCard   = shipCards.length ? shipCards[shipCards.length - 1] : null;

                if (shipToggle && lastCard && shipToggle.parentNode !== secDel) {
                    secDel.insertBefore(shipToggle, lastCard.nextSibling);
                } else if (shipToggle && !lastCard && shipToggle.parentNode !== secDel) {
                    secDel.appendChild(shipToggle);
                }
                if (addrBox) {
                    addrBox.classList.toggle('mkcp-pu-location--ship-toggle-below', !!shipToggle);
                }
                mkcpEnhanceShipToggle(shipToggle);

                var shipAddrFields = document.querySelector('.shipping_address');
                if (shipAddrFields && shipAddrFields.parentNode !== secDel) {
                    // Ná het vinkje indien aanwezig (zelfde sibling-van-secDel
                    // niveau), anders ná de kaart, anders gewoon onderaan.
                    var addrFieldsAnchor = (shipToggle && shipToggle.parentNode === secDel) ? shipToggle : lastCard;
                    if (addrFieldsAnchor) {
                        secDel.insertBefore(shipAddrFields, addrFieldsAnchor.nextSibling);
                    } else {
                        secDel.appendChild(shipAddrFields);
                    }
                }

                // 2. Checkout-info (dynamic-checkout-messages) → leveringssectie, ná de
                //    verzendmethode-keuze (stap 0) indien aanwezig, anders bovenaan.
                var info = document.getElementById('dynamic-checkout-messages');
                if (info && !info.closest('.mkcp-co-section--delivery')) {
                    var infoOld = secDel.querySelector('#dynamic-checkout-messages');
                    if (infoOld) infoOld.remove();
                    var shipContainer = secDel.querySelector('.woocommerce-shipping-totals.shipping');
                    if (shipContainer && shipContainer.nextSibling) {
                        secDel.insertBefore(info, shipContainer.nextSibling);
                    } else if (shipContainer) {
                        secDel.appendChild(info);
                    } else {
                        secDel.insertBefore(info, secDel.firstChild);
                    }
                }

                // 3. #payment (nu zonder datumpicker) → betaalsectie.
                var pay = document.getElementById('payment');
                if (pay && !pay.closest('.mkcp-co-section--payment')) {
                    var payOld = secPay.querySelector('#payment');
                    if (payOld) payOld.remove();
                    secPay.appendChild(pay);
                }

                // 4. (Fase 2, vervallen) Zelfde reden als stap 1 hierboven.

                // 5. Vangnet: verplaats wat na stap 1-3 nog overblijft (bv. content
                //    van een toekomstige feature die hier nog niet met naam
                //    bekend is) mee naar de leveringssectie, in plaats van het
                //    stilzwijgend te verbergen — anders verdwijnt zulke content
                //    zonder foutmelding.
                //    NOTE: Dit is uitgeschakeld voor betere compatibiliteit. Het
                //    agressief verplaatsen en verbergen van .woocommerce-additional-fields
                //    kan de werking van andere plugins (bv. voor cadeaubonnen of
                //    extra checkout-velden) verstoren.

                // 6. De verplaatsingen hierboven kunnen de breedte van de
                //    leveringskolom veranderen (bv. #payment dat wegschuift
                //    naar de betaalsectie) — ná dat moment pas is de
                //    uiteindelijke kolombreedte bekend. delivery-date.js zet de
                //    bezorgdatum-/afhaalkaarten op een vaste pixelbreedte o.b.v.
                //    de kaarten-viewport-breedte op het moment van renderen
                //    (applyCardWidths(), draait vóór deze reorganisatie —
                //    "Wacht even zodat delivery-date.js zijn renderCards() eerst
                //    uitvoert" hierboven), dus met een verouderde breedte als
                //    die daarna nog verandert. Resultaat: kaarten die na een
                //    refresh soms net iets breder/smaller ogen dan een refresh
                //    ervoor. Een synthetische resize hergebruikt delivery-
                //    date.js' eigen window-resize-listener (die alle
                //    kaartinstanties opnieuw sizet) i.p.v. een nieuwe, losse
                //    koppeling tussen de twee bestanden.
                if (window.jQuery) jQuery(window).trigger('resize');

                _done = true;
            }

            // Wacht even zodat delivery-date.js zijn renderCards() eerst uitvoert.
            setTimeout(mkco_reorganize, 120);

            // Na WooCommerce AJAX-refresh opnieuw uitvoeren.
            if (window.jQuery) {
                jQuery(document).on('updated_checkout', function () {
                    setTimeout(mkco_reorganize, 80);
                });
            }

            // Skeleton-laadstatus voor de leveringssectie — alléén wanneer er
            // ook daadwerkelijk iets in die sectie kan wijzigen. Voorheen
            // luisterde dit op het generieke "update_checkout"-event, dat bij
            // ELKE WooCommerce-verversing vuurt — ook wanneer alleen de
            // betaalmethode wisselt (sommige betaalgateways triggeren zelf
            // ook update_checkout, bv. voor een eventuele toeslag) terwijl de
            // leveringssectie dan helemaal niets te verversen heeft. Bind
            // daarom rechtstreeks op het wisselen van de verzendmethode zelf
            // (bezorgen ↔ afhalen, of tussen verzendopties) — zie de
            // toelichting bij .mkcp-co-section__body.is-loading in
            // checkout.scss: WC's eigen blockUI dimt alleen #payment/
            // #order_review, niet deze door mkco_reorganize() verplaatste
            // sectie, dus zonder deze eigen skeleton lijkt de sectie
            // bevroren tijdens een échte verzendwissel.
            if (window.jQuery) {
                jQuery(document.body).on('change', 'input[name^="shipping_method"]', function () {
                    var body = document.querySelector('.mkcp-co-section--delivery .mkcp-co-section__body');
                    if (body) body.classList.add('is-loading');
                });
                jQuery(document.body).on('updated_checkout', function () {
                    var body = document.querySelector('.mkcp-co-section--delivery .mkcp-co-section__body');
                    if (body) body.classList.remove('is-loading');
                });
            }

            // Omgekeerd geval: WooCommerce's EIGEN blockUI dimt bij élke
            // update_checkout-cyclus altijd de volledige besteltabel
            // (.woocommerce-checkout-review-order-table) — inclusief de
            // productregels, die bij een betaalmethode-wissel nooit
            // veranderen (alleen een eventuele gateway-toeslag in de
            // totaalregel kan wijzigen). Sommige betaalgateways triggeren
            // zelf 'update_checkout' bij selectie (zie checkout.js), ook als
            // er voor die specifieke gateway geen toeslag is — de hele
            // productlijst laten "bevriezen" voor iets dat niet verandert is
            // dan onlogisch. Bij een echte wijziging (bedrag) toont de tabel
            // dat toch vanzelf zodra de AJAX-respons de rijen vervangt; hier
            // gaat het puur om het overbodige dim/blur-effect ervoor.
            // Vast op 20ms: WC's eigen debounce vóór blockUI is 5ms, dus dit
            // draait altijd ná die block()-aanroep.
            if (window.jQuery) {
                jQuery(document.body).on('change', 'input[name="payment_method"]', function () {
                    setTimeout(function () {
                        jQuery('.woocommerce-checkout-review-order-table').unblock();
                    }, 20);
                });
            }

            // Omgekeerde geval: WC's blockUI dimt bij élke update_checkout-
            // cyclus ook altijd het betaalblok (.woocommerce-checkout-payment)
            // — dus ook bij het wisselen van verzendmethode (bezorgen ↔
            // afhalen, of tussen verzendopties). De beschikbare betaalmethoden
            // worden in deze site niet gefilterd op verzendmethode, dus normaal
            // verandert daar niets. Enige uitzondering: een betaalgateway met
            // een percentage-toeslag (bv. Mollie's surcharge-optie) toont een
            // bedrag ín de betaalmethode-lijst dat meebeweegt met de
            // verzendkosten — momenteel bij geen enkele gateway geconfigureerd.
            // Mocht dat ooit wél aanstaan, dan toont de AJAX-respons de
            // bijgewerkte toeslag alsnog zodra die binnenkomt; hier gaat het
            // puur om het overbodige dim/blur-effect ervoor, net als hierboven.
            if (window.jQuery) {
                jQuery(document.body).on('change', 'input[name^="shipping_method"]', function () {
                    setTimeout(function () {
                        jQuery('.woocommerce-checkout-payment').unblock();
                    }, 20);
                });
            }

            // Inklapbare #order_review-kop (mobiel/tablet). Event delegation,
            // want de knop wordt bij elke WooCommerce AJAX-refresh opnieuw
            // gerenderd (dus een directe listener zou verloren gaan).
            function mkco_toggleReview() {
                var btn = document.querySelector('.mkcp-co-review-toggle');
                if (!btn) return;
                var expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                return btn;
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('.mkcp-co-review-toggle');
                if (!btn) return;
                mkco_toggleReview();
            });

            // Mobiele besteldbalk: totaalbedrag synchroniseren met de echte
            // besteltabel, en de knop stuurt gewoon een klik door naar de
            // echte #place_order-knop (dezelfde validatie/AJAX-afhandeling).
            //
            // Het "incl./excl. BTW"-label onder het bedrag wordt bewust NIET
            // uit de gekloonde HTML overgenomen (was eerder wel zo, en bleef
            // dan altijd op "excl. BTW" vastzitten): die HTML bevat twee
            // <small>-labels (.mkcp-btw-incl-only/.mkcp-btw-excl-only) waarvan
            // de zichtbaarheid wordt geregeld door een CSS-regel die alleen
            // BINNEN #order_review matcht (checkout.scss) — deze balk staat
            // daarbuiten (wp_footer, los element), dus die regel deed hier
            // niets en het overgebleven label (na het — ook nog eens maar
            // half werkende — verwijderen van "small") stond permanent vast.
            // Los label, direct in JS op basis van de huidige voorkeur gezet
            // en herrekend bij elke wijziging: geen CSS-scoping-afhankelijkheid.
            var MKCP_BTW_LABEL_INCL = '<?php echo esc_js( $mkcp_orderbar_label_incl ); ?>';
            var MKCP_BTW_LABEL_EXCL = '<?php echo esc_js( $mkcp_orderbar_label_excl ); ?>';

            function mkco_currentBtwPref() {
                try { return localStorage.getItem('mkcp_btw_pref') || 'incl'; } catch (e) { return 'incl'; }
            }

            function mkco_applyOrderBarBtwNote() {
                var note = document.getElementById('mkcp-mobile-orderbar-btw-note');
                if (!note) return;
                note.textContent = mkco_currentBtwPref() === 'excl' ? MKCP_BTW_LABEL_EXCL : MKCP_BTW_LABEL_INCL;
            }

            function mkco_syncOrderBar() {
                var amountEl = document.getElementById('mkcp-mobile-orderbar-amount');
                var totalTd  = document.querySelector('.order-total td');
                if (!amountEl || !totalTd) return;
                var clone = totalTd.cloneNode(true);
                clone.querySelectorAll('small').forEach(function (el) { el.remove(); });

                var newHtml = clone.innerHTML + '<small class="mkcp-mobile-orderbar__btw-note" id="mkcp-mobile-orderbar-btw-note"></small>';
                if (newHtml === amountEl.innerHTML) { mkco_applyOrderBarBtwNote(); return; }

                var isFirstSync = amountEl.innerHTML === '';
                amountEl.innerHTML = newHtml;
                mkco_applyOrderBarBtwNote();

                // Kort oplichten zodat duidelijk is dat het bedrag écht is
                // bijgewerkt (bv. na het wisselen van verzendmethode) — niet
                // bij de allereerste keer vullen op de pagina.
                if (!isFirstSync) {
                    amountEl.classList.remove('is-updated');
                    // eslint-disable-next-line no-unused-expressions
                    amountEl.offsetWidth; // reflow forceren zodat de animatie opnieuw start
                    amountEl.classList.add('is-updated');
                }
            }

            // Live meebewegen met de BTW-switch: zelfde tik (dit tabblad) via
            // 'change' op de toggle, en een wissel in een ANDER tabblad via
            // 'storage' (zelfde mechanisme als checkout-btw.js zelf gebruikt).
            document.addEventListener('change', function (e) {
                if (e.target.closest && e.target.closest('.js-mkcp-btw-toggle')) {
                    mkco_applyOrderBarBtwNote();
                }
            });
            window.addEventListener('storage', function (e) {
                if (e.key === 'mkcp_btw_pref') mkco_applyOrderBarBtwNote();
            });

            mkco_syncOrderBar();
            setTimeout(mkco_syncOrderBar, 200);

            // "Bekijk bestelling"-popup: #order_review zelf wordt op mobiel
            // (<900px, zie checkout.scss) de modal-paneel — geen kopie/DOM-
            // verplaatsing nodig, position:fixed haalt 'm puur visueel uit de
            // grid-flow, dus WooCommerce's eigen AJAX-refresh van #order_review
            // (wholesale vervangen bij elke adres-/verzendwijziging) blijft
            // ongewijzigd werken. Alleen de sluit-kop erbovenop moet na zo'n
            // refresh opnieuw ingevoegd worden, vandaar de aparte ensure-fn.
            var REVIEW_CLOSE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

            function mkco_ensureReviewModalHeader() {
                var orderReview = document.getElementById('order_review');
                if (!orderReview || orderReview.querySelector('.mkcp-review-modal-header')) return;
                var header = document.createElement('div');
                header.className = 'mkcp-review-modal-header';
                header.innerHTML =
                    '<span class="mkcp-review-modal-grabber" aria-hidden="true"></span>' +
                    '<span class="mkcp-review-modal-header__row">' +
                    '<span class="mkcp-review-modal-header__title"><?php echo esc_js( __( 'Overzicht van je bestelling', 'mk-cart-popup' ) ); ?></span>' +
                    '<button type="button" class="mkcp-review-modal-close" aria-label="<?php echo esc_js( __( 'Sluiten', 'mk-cart-popup' ) ); ?>">' + REVIEW_CLOSE_SVG + '</button>' +
                    '</span>';
                orderReview.insertBefore(header, orderReview.firstChild);
            }

            function mkco_openReviewModal() {
                mkco_ensureReviewModalHeader();
                document.documentElement.classList.add('mkcp-review-modal-open');
                document.body.classList.add('mkcp-review-modal-open');
                var reviewBtn = document.getElementById('mkcp-mobile-orderbar-review-btn');
                if (reviewBtn) reviewBtn.setAttribute('aria-expanded', 'true');
            }

            function mkco_closeReviewModal() {
                document.documentElement.classList.remove('mkcp-review-modal-open');
                document.body.classList.remove('mkcp-review-modal-open');
                var reviewBtn = document.getElementById('mkcp-mobile-orderbar-review-btn');
                if (reviewBtn) reviewBtn.setAttribute('aria-expanded', 'false');
            }

            // Sleepbaar omlaag sluiten (swipe-to-close), zelfde gevoel als de
            // winkelwagen-pop-up (cart-popup-mobile.js) maar een eigen,
            // eenvoudigere state machine — hier is maar één sleepgebaar nodig
            // (geen rij-swipe/peek-tab). Slepen mag vanaf de kop (grabber/
            // titelbalk) of, als je in de inhoud zelf begint, alleen zolang
            // #order_review nog bovenaan staat (scrollTop 0) — anders wint
            // gewoon het native scrollen van de lijst.
            var RV = { active: false, dragging: false, startY: 0, lastY: 0, lastT: 0, vel: 0, sheetH: 0 };

            function mkco_reviewSheetEl() { return document.getElementById('order_review'); }

            function mkco_reviewTrackVelocity(y, t) {
                var dt = Math.max(1, t - RV.lastT);
                RV.vel = 0.6 * ((y - RV.lastY) / dt) + 0.4 * RV.vel;
                RV.lastY = y;
                RV.lastT = t;
            }

            document.addEventListener('touchstart', function (e) {
                if (!document.body.classList.contains('mkcp-review-modal-open')) return;
                if (e.touches.length !== 1) return;
                var sheet = mkco_reviewSheetEl();
                if (!sheet) return;
                var target = e.target;
                if (!(target instanceof Element) || !sheet.contains(target)) return;

                var onHandle = !!target.closest('.mkcp-review-modal-grabber, .mkcp-review-modal-header');
                if (!onHandle && sheet.scrollTop > 0) return;

                RV.active   = true;
                RV.dragging = false;
                RV.startY   = e.touches[0].clientY;
                RV.lastY    = RV.startY;
                RV.lastT    = e.timeStamp;
                RV.vel      = 0;
                RV.sheetH   = sheet.getBoundingClientRect().height || 1;
            }, { passive: true });

            document.addEventListener('touchmove', function (e) {
                if (!RV.active) return;
                var y  = e.touches[0].clientY;
                var dy = y - RV.startY;
                var sheet = mkco_reviewSheetEl();
                if (!sheet) { RV.active = false; return; }

                if (!RV.dragging) {
                    if (dy <= 8) return; // pas na een kleine drempel (tegen ruis/tikken)
                    RV.dragging = true;
                    sheet.style.transition = 'none';
                    sheet.style.animation  = 'none';
                }

                e.preventDefault(); // blokkeert pagina-scroll zodra dit een sleepgebaar is
                var ty = dy >= 0 ? dy : dy * 0.15; // 1:1 omlaag, rubber-band omhoog
                sheet.style.transform = 'translateY(' + ty + 'px)';
                var backdrop = document.getElementById('mkcp-review-modal-backdrop');
                if (backdrop) backdrop.style.opacity = String(Math.max(0, 1 - Math.max(0, ty) / RV.sheetH));
                mkco_reviewTrackVelocity(y, e.timeStamp);
            }, { passive: false });

            document.addEventListener('touchend', function () {
                if (!RV.active) return;
                var sheet = mkco_reviewSheetEl();
                var wasDragging = RV.dragging;
                RV.active   = false;
                RV.dragging = false;
                if (!wasDragging || !sheet) return;

                var m  = /translateY\(([-\d.]+)px\)/.exec(sheet.style.transform);
                var ty = m ? parseFloat(m[1]) : 0;
                var armed = ty > RV.sheetH * 0.3;
                var flick = RV.vel > 0.5 && ty > 30;
                var backdrop = document.getElementById('mkcp-review-modal-backdrop');

                if (armed || flick) {
                    // Meteen sluiten — geen terugveer nodig, de modal verdwijnt
                    // toch (display:none via het verwijderen van de open-klasse).
                    sheet.style.transition = '';
                    sheet.style.animation  = '';
                    sheet.style.transform  = '';
                    if (backdrop) backdrop.style.opacity = '';
                    mkco_closeReviewModal();
                } else {
                    // Terugveren naar de volledig open stand.
                    sheet.style.transition = 'transform 220ms cubic-bezier(0.34, 1.56, 0.64, 1)';
                    sheet.style.transform  = '';
                    if (backdrop) {
                        backdrop.style.transition = 'opacity 220ms ease';
                        backdrop.style.opacity = '';
                    }
                    setTimeout(function () {
                        sheet.style.transition = '';
                        if (backdrop) backdrop.style.transition = '';
                    }, 240);
                }
            }, { passive: true });

            document.addEventListener('click', function (e) {
                if (e.target.closest && (e.target.closest('.mkcp-review-modal-close') || e.target.closest('#mkcp-review-modal-backdrop'))) {
                    mkco_closeReviewModal();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && document.body.classList.contains('mkcp-review-modal-open')) {
                    mkco_closeReviewModal();
                }
            });

            // Vangnet-timer die de "Bestellen"-knop weer vrijgeeft. Staat hier
            // in de buitenste scope omdat zowel de checkout_error-listener
            // hieronder als de klik-handler verderop 'm gebruiken.
            var orderBarTimer = null;

            if (window.jQuery) {
                jQuery(document).on('updated_checkout', function () {
                    setTimeout(mkco_syncOrderBar, 80);
                    setTimeout(mkco_ensureReviewModalHeader, 80);
                });

                // Laadstatus op de "Bestellen"-knop: WooCommerce blokkeert bij
                // een mislukte validatie/AJAX-call het formulier weer en
                // vuurt 'checkout_error' — dat is het signaal om de knop weer
                // vrij te geven (orderBarTimer hierboven is het vangnet voor
                // het geval dat event om wat voor reden dan ook niet komt).
                jQuery(document.body).on('checkout_error', function () {
                    clearTimeout(orderBarTimer);
                    var bar = document.getElementById('mkcp-mobile-orderbar');
                    if (bar) bar.classList.remove('is-placing');
                });
            }

            document.addEventListener('click', function (e) {
                var reviewBtn = e.target.closest && e.target.closest('#mkcp-mobile-orderbar-review-btn');
                if (reviewBtn) {
                    mkco_openReviewModal();
                    return;
                }

                var totalEl = e.target.closest && e.target.closest('.mkcp-mobile-orderbar__total');
                if (totalEl) {
                    // De oude inklap-kop (Instellingen → "Overzicht van je
                    // bestelling standaard inklappen op mobiel") is CSS-
                    // verborgen nu #order_review zelf al standaard verborgen
                    // is buiten de popup om (zie checkout.scss) — het
                    // totaalbedrag opent nu altijd gewoon dezelfde popup.
                    mkco_openReviewModal();
                    return;
                }

                var barBtn = e.target.closest && e.target.closest('#mkcp-mobile-orderbar-btn');
                if (!barBtn) return;

                var bar = document.getElementById('mkcp-mobile-orderbar');
                if (bar) bar.classList.add('is-placing');
                clearTimeout(orderBarTimer);
                orderBarTimer = setTimeout(function () {
                    if (bar) bar.classList.remove('is-placing');
                }, 15000);

                var realBtn = document.getElementById('place_order');
                if (realBtn) realBtn.click();
            });
        })();
        </script>
        <?php
    }, 30 );

}, 20 );


// ── Serve custom template on checkout (premium only) ─────────────────────────

add_filter( 'template_include', function( $template ) {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return $template;

    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return $template;
    if ( ! mkcp_license_has( 'premium' ) ) return $template;

    $cfg = mkcp_checkout_config();

    // Master toggle: Cart Checkout must be enabled.
    if ( empty( $cfg['checkout_enabled'] ) ) return $template;

    // Checkoutpagina gebruikt het WooCommerce Checkout-blok — dit sjabloon is
    // gebouwd voor de klassieke shortcode-checkout ([woocommerce_checkout])
    // en zou anders een lege pagina renderen (do_shortcode() vindt de
    // shortcode niet meer terug, want die is op een blocks-pagina vervangen
    // door het blok zelf). Stap terug en laat WordPress/WooCommerce Blocks
    // het gewoon zelf renderen.
    if ( mkcp_checkout_uses_blocks() ) return $template;

    // Only override the template when at least one feature is enabled.
    if ( empty( $cfg['header_enabled'] ) && empty( $cfg['footer_enabled'] ) && empty( $cfg['steps_enabled'] ) && empty( $cfg['payment_icons_enabled'] ) ) return $template;

    return MKCP_PATH . 'templates/checkout-page.php';
} );

// De iconenstrip-registratie stond eerder ALLEEN in de template_include-
// callback hierboven — die filter vuurt uitsluitend bij een normale, volledige
// paginalaad, nooit tijdens WC_AJAX::update_order_review() (elke adres-/
// verzend-/betaalmethode-wijziging, én de automatische update_checkout die
// WooCommerce's eigen checkout.js al vlak ná het laden zelf triggert, zie
// checkout.js init_checkout()). #order_review wordt bij zo'n cyclus wholesale
// vervangen door WooCommerce's fragment-replaceWith — zonder herregistratie
// hier verdween de iconenstrip dus bij de EERSTVOLGENDE ververting, blijvend,
// tot een harde refresh: "iconen ontbreken helemaal". Zelfde twee-hakenpatroon
// (normale laad + AJAX-ververting) als mkcp_checkout_claim_payment_section()
// hierboven lost dit op.
function mkcp_checkout_maybe_register_payment_icons_strip() {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    if ( mkcp_checkout_uses_blocks() ) return;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    if ( empty( $cfg['payment_icons_enabled'] ) ) return;

    // Priority 15: fires after #order_review closes (priority 10) but before
    // #payment renders (priority 20), keeping icons between the two.
    add_action( 'woocommerce_checkout_order_review', 'mkcp_checkout_render_payment_icons_strip', 15 );
}
add_action( 'wp', 'mkcp_checkout_maybe_register_payment_icons_strip', 21 );
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_checkout_maybe_register_payment_icons_strip', 1 );


// ── Custom header ─────────────────────────────────────────────────────────────

function mkcp_checkout_render_header() {
    $cfg          = mkcp_checkout_config();
    $bg           = esc_attr( $cfg['header_bg'] ?: '#ffffff' );
    $show_steps   = ! empty( $cfg['steps_enabled'] );
    $steps_labels = $cfg['steps_labels'] ?? [ 'Winkelwagen', 'Gegevens', 'Bevestiging' ];
    // Deze header wordt ook op de bedankt-pagina gerenderd (is_checkout() geldt
    // daar ook) — daar is stap 3 ("Bevestiging") de juiste actieve stap, niet
    // stap 2 zoals op de eigenlijke checkout.
    $on_thankyou  = function_exists( 'is_order_received_page' ) && is_order_received_page();
    $show_ssl     = ! empty( $cfg['ssl_badge_enabled'] );
    $ssl_text     = $cfg['ssl_badge_text'] ?? 'SSL-versleuteling';
    $show_split   = $show_steps || $show_ssl;

    // Logo: eigen upload → site logo → sitenaam als fallback.
    $logo = '';
    if ( ! empty( $cfg['header_logo_id'] ) ) {
        $logo = wp_get_attachment_image(
            $cfg['header_logo_id'], 'medium', false,
            [ 'class' => 'mkcp-checkout-header__logo', 'alt' => esc_attr( get_bloginfo( 'name' ) ) ]
        );
    }
    if ( ! $logo ) {
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $logo = wp_get_attachment_image(
                $logo_id, 'medium', false,
                [ 'class' => 'mkcp-checkout-header__logo', 'alt' => esc_attr( get_bloginfo( 'name' ) ) ]
            );
        }
    }
    if ( ! $logo ) {
        $logo = '<span class="mkcp-checkout-header__site-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
    }

    ?>
    <div class="mkcp-checkout-header" style="background:<?php echo $bg; ?>">
        <div class="mkcp-checkout-header__inner<?php echo $show_split ? ' mkcp-checkout-header__inner--split' : ''; ?>">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="mkcp-checkout-header__logo-link">
                <?php echo $logo; ?>
            </a>
            <?php if ( $show_steps ) : ?>
            <nav class="mkcp-checkout-steps" aria-label="<?php esc_attr_e( 'Afrekenstappen', 'mk-cart-popup' ); ?>">
                <?php foreach ( $steps_labels as $i => $label ) :
                    $num    = $i + 1;
                    $active = $on_thankyou ? ( $num === 3 ) : ( $num === 2 );
                    $done   = $on_thankyou ? ( $num < 3 )   : ( $num === 1 );
                    $class  = $active ? 'is-active' : ( $done ? 'is-done' : '' );
                    if ( $i > 0 ) : ?>
                    <span class="mkcp-checkout-steps__arrow" aria-hidden="true">›</span>
                    <?php endif; ?>
                    <span class="mkcp-checkout-step <?php echo esc_attr( $class ); ?>"
                        <?php if ( $done ) : ?>role="link" tabindex="0" onclick="location.href='<?php echo esc_url( wc_get_cart_url() ); ?>'" onkeydown="if(event.key==='Enter')location.href='<?php echo esc_url( wc_get_cart_url() ); ?>'"<?php endif; ?>>
                        <span class="mkcp-checkout-step__num" aria-hidden="true"><?php echo absint( $num ); ?></span>
                        <span class="mkcp-checkout-step__label"><?php echo esc_html( $label ); ?></span>
                    </span>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <?php if ( $show_ssl ) : ?>
            <span class="mkcp-checkout-header__ssl">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 127.03 140.02" aria-hidden="true">
                    <path fill="currentColor" d="M63.44,140.02c-.74,0-1.43-.06-2.12-.17-2.08-.5-3.67-1.16-5.13-2.06C39.54,129.08.14,104.68.14,70.09v-27.47c-.37-3.92,0-8.29,1.11-12.54,1.31-3.52,3.17-6.21,5.58-8.33,4.01-2.87,7.97-4.76,12.21-5.95L57.62,1.35c1.06-.47,2.43-.9,3.83-1.18,1.49-.23,2.7-.22,3.89-.05,1.67.33,3.04.76,4.34,1.33l38.76,14.49c3.82,1.05,7.77,2.94,11.35,5.46,2.84,2.46,4.7,5.15,5.84,8.14,1.28,4.78,1.65,9.15,1.27,13.53l.06,27.1h0c0,34.66-39.39,58.96-56.32,67.79-1.15.72-2.75,1.38-4.44,1.8-1.01.17-1.91.25-2.76.25ZM63.52,10.38c-.69.14-1.36.36-2.01.64l-39.24,14.68c-3.51,1-6.39,2.38-8.99,4.21-.7.66-1.58,1.93-2.12,3.34-.64,2.55-.91,5.73-.63,8.92l.02,27.93c0,29.71,38.85,52.39,50.76,58.64.85.5,1.47.75,2.11.92-.04-.02.32-.02.68-.08.24-.08.85-.33,1.4-.66,12.21-6.39,51.03-28.96,51.03-58.72l-.04-27.55c.3-3.66.03-6.84-.77-9.92-.38-.88-1.25-2.15-2.39-3.15-2.16-1.49-5.05-2.86-8.13-3.73l-39.42-14.72c-.89-.38-1.57-.59-2.26-.73ZM56.29,89.89h0c-1.38,0-2.71-.55-3.68-1.53l-14.59-14.59c-2.04-2.04-2.04-5.33,0-7.37s5.33-2.04,7.37,0l10.9,10.91,25.41-25.41c2.04-2.04,5.33-2.04,7.37,0s2.04,5.33,0,7.37l-29.1,29.1c-.98.98-2.3,1.53-3.68,1.53Z"/>
                </svg>
                <?php echo esc_html( $ssl_text ); ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}


// ── Betaalmethode iconen: strip buiten #order_review (checkout grid kolom 2) ──

function mkcp_checkout_render_payment_icons_strip() {
    $icons = array_filter( mkcp_config()['payment_icons'] ?? [], fn( $p ) => ! empty( $p['url'] ) );
    if ( empty( $icons ) ) return;
    echo '<div class="mkcp-co-payment-icons">';
    foreach ( $icons as $pi ) {
        echo '<img src="' . esc_url( $pi['url'] ) . '" alt="' . esc_attr( $pi['label'] ?? '' ) . '" loading="lazy">';
    }
    echo '</div>';
}


// ── Coupon-rij: badge-stijl label ─────────────────────────────────────────────
//
// WooCommerce's eigen "Waardebon: {code}"-label is platte tekst. Het
// woocommerce_cart_totals_coupon_label-filter wordt door WC ongefilterd
// geëchood (geen wp_kses erop), dus hier kan gewoon een label-icoon in
// dezelfde stroke-stijl als de rest van de checkout worden toegevoegd. De
// bedrag/verwijder-cel (woocommerce_cart_totals_coupon_html) loopt bij WC wél
// door wp_kses( ..., 'post' ) — <svg> staat niet in die toegestane tags-lijst
// — dus het verwijder-icoon wordt daar bewust puur via CSS (::before)
// opgelost, niet via ingevoegde markup.
add_filter( 'woocommerce_cart_totals_coupon_label', function( $label, $coupon ) {
    if ( ! function_exists( 'mkcp_is_distraction_free_checkout' ) || ! mkcp_is_distraction_free_checkout() ) return $label;

    return '<span class="mkcp-co-coupon-label">'
         . '<svg class="mkcp-co-coupon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41 13 21 3 11V3h8l9.59 9.59a2 2 0 0 1 0 2.82z"/><circle cx="7.5" cy="7.5" r="1.5" fill="currentColor" stroke="none"/></svg>'
         . '<span>' . esc_html__( 'Waardebon:', 'mk-cart-popup' ) . ' <strong>' . esc_html( $coupon->get_code() ) . '</strong></span>'
         . '</span>';
}, 10, 2 );


// ── Overzichtstabel: colspan generiek herstellen op basis van echte kolomtelling ──
//
// De checkout-tabel-CSS (col.product-thumbnail/product-quantity/product-total,
// table-layout:fixed in checkout.scss) gaat uit van een thema-override met
// meerdere productkolommen (thumbnail/naam/aantal/totaal) — precies zoals bv.
// het MKTheme/Mediakanjers-framework dat doet, en zoals andere thema's dat net
// zo goed kunnen doen, maar dan met hun EIGEN klassenamen. Een eerdere versie
// van deze fix mikte op vaste rij-klassen (cart-subtotal, order-total, WC's
// eigen woocommerce-shipping-totals) — maar bleek op een site waarvan het
// thema de verzendrij zelf "shipping-costs" noemt (i.p.v. WC's standaardnaam)
// gewoon niets te doen, want die klasse matchte simpelweg niet. Daarnaast
// bleek de <thead> zelf óók maar 2 cellen te hebben terwijl <tbody> 4 echte
// kolommen rendert — zonder colspan op de header ontstaat dan exact dezelfde
// overlap als in de footer-rijen.
//
// In plaats van steeds meer rij-/kolomnamen te blijven verzamelen, telt deze
// versie het WERKELIJKE aantal kolommen af aan de hand van een echte
// productrij (<tbody> — dat heeft nooit colspan, dus altijd betrouwbaar) en
// past dat generiek toe. BELANGRIJK, empirisch vastgesteld (gemeten met
// Playwright tegen de daadwerkelijke tabel-CSS): colspan="99" lijkt een
// veilige "span de rest van de rij"-truc, maar breekt in combinatie met
// table-layout:fixed + kolommen met een expliciete pixelbreedte (col.product-
// thumbnail/quantity/total) de breedteberekening van de ENIGE kolom zonder
// vaste breedte (product-name) — die stort dan in tot ~1px, met woord-voor-
// woord afbrekende tekst tot gevolg. Gemeten patroon: hoe groter de colspan-
// waarde afwijkt van de colspan die de header zelf al gebruikt, hoe erger de
// inzakking (colspan=2 op een 4-koloms tabel: gezond; colspan=4, 10 of 99:
// steeds smaller). Colspan exact laten MATCHEN met de header se eigen
// verdeling (dezelfde firstSpan/restSpan hieronder) voorkomt dit volledig,
// want dan is het rij-overspanningspatroon voor de hele tabel consistent.
//
//   • de header (ervan uitgaande dat 'ie de gebruikelijke 2 kopjes heeft:
//     productinfo | totaal) wordt evenredig verdeeld over het echte aantal
//     kolommen — bv. 4 kolommen → 2 + 2;
//   • elke <tfoot>-rij met minder cellen dan dat aantal krijgt DEZELFDE
//     verdeling als de header (niet "99") — ongeacht welke klasse het thema
//     aan die rij geeft (cart-subtotal, order-total, shipping-costs,
//     woocommerce-shipping-totals, een eigen fee-rij, etc.).
// WooCommerce biedt geen filter voor het <th>/<td>-element zelf (alleen voor
// de inhoud erin, bv. woocommerce_cart_totals_order_total_html), dus dit kan
// alleen via JS na het renderen.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            function mkcp_fixReviewTableColspan() {
                document.querySelectorAll('.woocommerce-checkout-review-order-table').forEach(function (table) {
                    // :not(.mkcp-fulfil-header) — anders telt een eventuele
                    // groeperings-kopregel (1 cel, zie mkcp_groupFulfillmentRows
                    // hieronder) als "de" productrij en klopt realCols niet meer.
                    var bodyRow = table.querySelector('tbody tr:not(.mkcp-fulfil-header)');
                    if (!bodyRow) return;

                    var realCols = bodyRow.children.length;
                    if (realCols <= 2) return; // standaard WC-tabel: niets te herstellen

                    var firstSpan  = Math.ceil(realCols / 2);
                    var secondSpan = realCols - firstSpan;

                    // Header: 2 kopjes (productinfo | totaal) evenredig
                    // verdelen — bv. 4 kolommen → 2 + 2, 3 kolommen → 2 + 1.
                    var headCells = table.querySelector('thead tr');
                    headCells = headCells ? headCells.children : null;
                    if (headCells && headCells.length === 2) {
                        headCells[0].setAttribute('colspan', firstSpan);
                        headCells[1].setAttribute('colspan', secondSpan);
                    }

                    // Footer: elke rij met minder cellen dan het echte aantal
                    // kolommen krijgt DEZELFDE verdeling als de header, zodat
                    // het overspanningspatroon voor de hele tabel consistent
                    // blijft (zie de lange toelichting hierboven voor waarom
                    // een losstaande colspan="99" hier juist averechts werkt).
                    table.querySelectorAll('tfoot tr').forEach(function (tr) {
                        var cells = tr.children;
                        if (cells.length === 2 && cells.length < realCols) {
                            cells[0].setAttribute('colspan', firstSpan);
                            cells[1].setAttribute('colspan', secondSpan);
                        } else if (cells.length > 0 && cells.length < realCols) {
                            // Onverwacht celaantal (niet de gebruikelijke
                            // label+bedrag) — verdeel evenredig als vangnet,
                            // laatste cel krijgt het restant.
                            var per = Math.floor(realCols / cells.length);
                            Array.prototype.forEach.call(cells, function (cell, i) {
                                var isLast = i === cells.length - 1;
                                cell.setAttribute('colspan', isLast ? (realCols - per * i) : per);
                            });
                        }
                    });
                });
            }

            mkcp_fixReviewTableColspan();
            if (window.jQuery) {
                jQuery(document).on('updated_checkout', mkcp_fixReviewTableColspan);
            }
        })();
        </script>
        <?php
    }, 25 );
}, 5 );


// ── Besteloverzicht groeperen: kopjes + herordening (client-side) ────────────
// Vervolg op mkcp_checkout_register_fulfillment_grouping() hierboven — die
// tagt elke <tr> alleen met een klasse (mkcp-fulfil-shipped/-pickup), zonder
// de rijen te herordenen of kopjes toe te voegen. Dat gebeurt hier, ná render:
// WC vervangt #order_review bij elke wc-ajax-ververting toch al wholesale
// (fragment-replaceWith), dus dit draait gewoon opnieuw op elke
// 'updated_checkout' — geen state om bij te houden, geen DOM-nesting, alleen
// bestaande <tr>-siblings binnen dezelfde tbody herordenen (zie
// feedback_checkout_dom_move_safety.md-precedent: nooit nesten, altijd
// simpele sibling-verplaatsing). Puur feature-detect op de aanwezige
// klassen — als de PHP-kant grouping niet heeft geregistreerd (winkelmandje
// niet gemengd), zijn er geen getagde rijen en doet dit niets.
add_action( 'wp', function() {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return;
    if ( ! mkcp_license_has( 'premium' ) ) return;
    // Zelfde poort als de scroll-hint-wikkel/pijltje hierboven
    // (mkcp-review-scroll-shell, priority 9/11) — die rendert NIET op een
    // Blocks-checkout, dus deze JS (die naar diezelfde shell zoekt) moet
    // daar ook niet voor niets proberen te draaien. Ontbrak hier eerder.
    if ( function_exists( 'mkcp_checkout_uses_blocks' ) && mkcp_checkout_uses_blocks() ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    add_action( 'wp_footer', function() {
        $label_shipped = esc_js( __( 'Wordt verzonden', 'mk-cart-popup' ) );
        $label_pickup  = esc_js( __( 'Wordt afgehaald', 'mk-cart-popup' ) );
        // Zelfde icoontjes als op de filterschakelaar hierboven (mkcp_checkout_
        // render_fulfillment_filter_pills()) — apart hier gedefinieerd omdat
        // dit een los wp_footer-blok is, geen gedeelde PHP-variabele.
        // BELANGRIJK: wp_json_encode(), NIET esc_js() — esc_js() HTML-
        // encodeert intern via _wp_specialchars() (< wordt &lt; etc.), prima
        // voor platte tekst maar het verminkt hier de SVG-markup zelf tot
        // zichtbare kapotte tekst i.p.v. een icoon. json_encode() escaped
        // wél veilig voor een JS-stringliteral (quotes/backslashes) zonder
        // de HTML-tags aan te raken, en levert de omringende quotes meteen
        // mee — vandaar dat de aanroepen hieronder geen eigen '...' meer
        // om deze twee variabelen heen zetten.
        $icon_shipped_js = wp_json_encode( '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="6" width="14" height="11"/><path d="M15 9h4l3 3v5h-7z"/><circle cx="6" cy="19" r="2"/><circle cx="17.5" cy="19" r="2"/></svg>' );
        $icon_pickup_js   = wp_json_encode( '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1-5h16l1 5"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M9 21v-8h6v8"/></svg>' );
        ?>
        <script>
        (function () {
            function mkcp_groupFulfillmentRows() {
                document.querySelectorAll('.woocommerce-checkout-review-order-table').forEach(function (table) {
                    var tbody = table.querySelector('tbody');
                    if (!tbody) return;

                    // Headerrijen van een vorige run altijd eerst opruimen —
                    // WC vervangt #order_review NIET altijd wholesale (soms
                    // wordt alleen de inhoud bijgewerkt terwijl bestaande
                    // <tr>-elementen, inclusief onze eerder toegevoegde
                    // headers, blijven staan), dus zonder dit stapelen de
                    // kopjes zich op bij elke 'updated_checkout'.
                    tbody.querySelectorAll('tr.mkcp-fulfil-header').forEach(function (tr) { tr.remove(); });

                    // Een eerder toegepast pil-filter (display:none, zie
                    // mkcp-fulfil-pill-klik hieronder) altijd resetten — de
                    // pillen zelf worden bij elke ververting vers vanuit PHP
                    // gerenderd met "Alles" actief, dus de rijen moeten weer
                    // matchen met die staat i.p.v. verborgen te blijven staan.
                    tbody.querySelectorAll('tr.mkcp-fulfil-shipped, tr.mkcp-fulfil-pickup').forEach(function (tr) {
                        tr.style.display = '';
                    });

                    var shipped = Array.prototype.slice.call(tbody.querySelectorAll('tr.mkcp-fulfil-shipped'));
                    var pickup  = Array.prototype.slice.call(tbody.querySelectorAll('tr.mkcp-fulfil-pickup'));
                    if (!shipped.length || !pickup.length) return; // niet gemengd — niets te groeperen

                    var firstRow = tbody.querySelector('tr');
                    var realCols = firstRow ? firstRow.children.length : 2;

                    function makeHeaderRow(label, iconSvg) {
                        var tr = document.createElement('tr');
                        tr.className = 'mkcp-fulfil-header';
                        var td = document.createElement('td');
                        td.setAttribute('colspan', realCols);
                        td.innerHTML = iconSvg + '<span></span>'; // label via textContent hieronder — geen HTML-injectie via de vertaalstring
                        td.querySelector('span').textContent = label;
                        tr.appendChild(td);
                        return tr;
                    }

                    // Herbouwen in vaste volgorde: verzonden-groep eerst, dan
                    // afhaal-groep — appendChild op een bestaand kind verplaatst
                    // het (geen clone), dus de rijen zelf blijven intact met al
                    // hun bestaande event-listeners/data.
                    tbody.appendChild(makeHeaderRow('<?php echo $label_shipped; ?>', <?php echo $icon_shipped_js; ?>));
                    shipped.forEach(function (tr) { tbody.appendChild(tr); });
                    tbody.appendChild(makeHeaderRow('<?php echo $label_pickup; ?>', <?php echo $icon_pickup_js; ?>));
                    pickup.forEach(function (tr) { tbody.appendChild(tr); });
                });
            }

            // Scrollbalk zelf onzichtbaar (CSS, checkout.scss) — de hint dat
            // er meer te scrollen valt komt in plaats daarvan van een fade-
            // out onderaan de lijst (CSS mask-image, klasse mkcp-scroll-
            // hint), maar ALLEEN zolang er daadwerkelijk meer te scrollen
            // valt: weg zodra de lijst kort genoeg is om helemaal te passen,
            // en weg zodra je al helemaal onderaan bent gescrold.
            //
            // Het pijltje (.mkcp-scroll-hint-chevron-overlay, PHP-gerenderd
            // als sibling van de tabel binnen .mkcp-review-scroll-shell, zie
            // hierboven) staat BEWUST buiten tbody: mask-image werkt op de
            // HELE inhoud van een element, een kind kan zich daar niet aan
            // onttrekken — met het pijltje er zelf in (eerdere versie, een
            // sticky <tr>) vervaagde het dus mee. Nu ontkoppeld, dus dat kan
            // niet meer gebeuren. Positie wordt maar 1x per layout-wijziging
            // herrekend (init/resize/AJAX-ververting) — tbody's eigen
            // buitenkant verandert niet tijdens het scrollen daarbinnen.
            function mkcp_positionScrollHintChevron(tbody, chevron) {
                var shell = tbody.closest('.mkcp-review-scroll-shell');
                if (!shell) return;
                var shellRect = shell.getBoundingClientRect();
                var tbodyRect = tbody.getBoundingClientRect();
                // chevron.offsetHeight i.p.v. een hardgecodeerd getal: de svg
                // is 22px maar met padding (4+4) en rand (1.5px×2) erbij is
                // het werkelijk gerenderde rondje ~33px, niet 22px — een vast
                // "-11" (bedoeld als halve hoogte) klopte dus niet.
                chevron.style.top = Math.round(tbodyRect.bottom - shellRect.top - (chevron.offsetHeight / 2)) + 'px';
            }

            function mkcp_updateScrollHint(tbody) {
                var hasOverflow = tbody.scrollHeight > tbody.clientHeight + 1;
                var atBottom    = tbody.scrollHeight - tbody.scrollTop - tbody.clientHeight < 4;
                tbody.classList.toggle('mkcp-scroll-hint', hasOverflow && !atBottom);

                var shell    = tbody.closest('.mkcp-review-scroll-shell');
                var chevron  = shell ? shell.querySelector('.mkcp-scroll-hint-chevron-overlay') : null;
                if (chevron) {
                    chevron.style.display = hasOverflow ? '' : 'none';
                    chevron.classList.toggle('mkcp-scroll-hint-chevron-overlay--up', atBottom);
                }
            }

            function mkcp_initScrollHints() {
                document.querySelectorAll('.woocommerce-checkout-review-order-table tbody').forEach(function (tbody) {
                    var shell   = tbody.closest('.mkcp-review-scroll-shell');
                    var chevron = shell ? shell.querySelector('.mkcp-scroll-hint-chevron-overlay') : null;

                    if (!tbody.dataset.mkcpScrollBound) {
                        tbody.dataset.mkcpScrollBound = '1';
                        tbody.addEventListener('scroll', function () { mkcp_updateScrollHint(tbody); });

                        // init/resize/AJAX dekken niet alles: productthumbnails
                        // (img) laden asynchroon na, en zolang WC's eigen
                        // blockUI-overlay actief is (bv. tijdens de automatische
                        // update_order_review-call bij het laden van de pagina)
                        // is tbody's scrollHeight/clientHeight allebei 0 - dus
                        // "atBottom" (0-0-0 < 4) is dan foutief tijdelijk waar.
                        // ResizeObserver ziet elke wijziging in tbody's eigen
                        // boxgrootte, ongeacht de oorzaak, en herrekent dan pas -
                        // maar moet ook mkcp_updateScrollHint() opnieuw draaien
                        // (niet alleen de positie), anders blijft een intussen
                        // foutief bepaalde zichtbaarheid/omdraai-status hangen
                        // tot de volgende volledige resize/AJAX-ververting.
                        if (chevron && window.ResizeObserver) {
                            new ResizeObserver(function () {
                                mkcp_updateScrollHint(tbody);
                                mkcp_positionScrollHintChevron(tbody, chevron);
                            }).observe(tbody);
                        }
                    }
                    mkcp_updateScrollHint(tbody);
                    if (chevron) mkcp_positionScrollHintChevron(tbody, chevron);
                });

                // WPFactory (BTW-plugin) blokkeert bij een BTW-check/landwissel
                // #order_review rechtstreeks, via zijn EIGEN AJAX-actie
                // (wpfactory_wc_eu_vat_validate_action, admin-ajax.php) - dat
                // loopt volledig BUITEN WooCommerce's eigen update_checkout-
                // cyclus om, dus jQuery's 'updated_checkout' vuurt daar niet
                // voor. CSS verbergt het pijltje al zolang die blokkade actief
                // is (checkout.scss), maar zodra 'm weggaat moet de positie
                // ook echt herrekend worden - anders blijft de laatst bekende
                // (mogelijk verouderde) stand staan. #order_review zelf wordt
                // door WC's eigen AJAX wél eens in de zoveel tijd wholesale
                // vervangen, dus de observer opnieuw aankoppelen bij elke
                // mkcp_initScrollHints()-aanroep i.p.v. one-time — de dataset-
                // vlag voorkomt dubbel observeren van hetzelfde (nog levende)
                // element.
                var orderReview = document.getElementById('order_review');
                if (orderReview && !orderReview.dataset.mkcpBlockObserved && window.MutationObserver) {
                    orderReview.dataset.mkcpBlockObserved = '1';
                    new MutationObserver(function () {
                        mkcp_initScrollHints();
                    }).observe(orderReview, { childList: true });
                }
            }

            // Filterschakelaar (mkcp_checkout_render_fulfillment_filter_pills,
            // priority 7, dus vóór de tabel) hergebruikt de mkcp-checkout-
            // switcher/mkcp-switcher-tab-classes, maar NIET meer window.
            // mkcpSwitcher.updateIndicator() — die verdeelt de balk in
            // gelijke, vaste breedtes (100/aantal tabs), wat hier als "veel
            // te lang" voor "Alles" oogde. Deze tabs zijn content-breed
            // (flex:0, zie checkout.scss), dus het balkje moet de
            // daadwerkelijke, gemeten breedte/positie van de actieve knop
            // volgen i.p.v. een vast 1/3-segment.
            function mkcp_initFulfilIndicators() {
                document.querySelectorAll('.mkcp-fulfil-switcher').forEach(function (switcher) {
                    var active    = switcher.querySelector('.mkcp-switcher-tab.is-selected');
                    var indicator = switcher.querySelector('.mkcp-switcher-indicator');
                    if (!active || !indicator) return;
                    indicator.style.width     = active.offsetWidth + 'px';
                    indicator.style.transform = 'translateX(' + active.offsetLeft + 'px)';
                });
            }

            function mkcp_applyFulfilFilter(switcher, filter) {
                switcher.querySelectorAll('.mkcp-switcher-tab').forEach(function (b) {
                    var isActive = b.getAttribute('data-mkcp-filter') === filter;
                    b.classList.toggle('is-selected', isActive);
                    b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                mkcp_initFulfilIndicators();
                document.querySelectorAll('.woocommerce-checkout-review-order-table tbody').forEach(function (tbody) {
                    tbody.querySelectorAll('tr.mkcp-fulfil-shipped, tr.mkcp-fulfil-pickup').forEach(function (tr) {
                        var show = filter === 'all' || tr.classList.contains('mkcp-fulfil-' + filter);
                        tr.style.display = show ? '' : 'none';
                    });
                    tbody.querySelectorAll('tr.mkcp-fulfil-header').forEach(function (tr) {
                        tr.style.display = filter === 'all' ? '' : 'none';
                    });
                });
                mkcp_initScrollHints(); // aantal zichtbare regels kan veranderd zijn
            }

            if (window.jQuery) {
                jQuery(document.body).on('click', '.mkcp-fulfil-switcher .mkcp-switcher-tab', function () {
                    var switcher = this.closest('.mkcp-fulfil-switcher');
                    if (!switcher) return;
                    mkcp_applyFulfilFilter(switcher, this.getAttribute('data-mkcp-filter') || 'all');
                });
            }
            mkcp_initFulfilIndicators();

            mkcp_groupFulfillmentRows();
            mkcp_initScrollHints();
            if (window.jQuery) {
                jQuery(document).on('updated_checkout', function () {
                    mkcp_groupFulfillmentRows();
                    mkcp_initScrollHints();
                    mkcp_initFulfilIndicators(); // tabs zijn vers meegerenderd (weer "Alles" actief)
                });
            }
            window.addEventListener('resize', mkcp_initScrollHints);
        })();
        </script>
        <?php
    }, 26 ); // ná mkcp_fixReviewTableColspan (25), zodat realCols al klopt.
}, 5 );


// ── Content-builder — checkout-zones ────────────────────────────────────────
//
// Vier structurele invoegpunten (via bestaande WooCommerce-template-hooks)
// plus per-veld plaatsing via de woocommerce_form_field-filter, die voor
// ieder gerenderd checkout-veld voorbij komt met de veld-key — daarmee kan
// een blok na een specifiek veld (bv. "field:billing_email") worden
// geplaatst zonder de checkout-templates zelf aan te passen.

add_action( 'woocommerce_checkout_before_order_review', function() {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() || ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    mkcp_render_zone( 'above-order-review', $cfg['checkout_blocks'] ?? [] );
} );

add_action( 'woocommerce_review_order_after_cart_contents', function() {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() || ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    // Binnen <tbody> van de besteloverzicht-tabel: als tabelrij renderen,
    // een <div> zou hier ongeldige HTML zijn (zie mkcp_render_zone_row()).
    mkcp_render_zone_row( 'below-order-review', $cfg['checkout_blocks'] ?? [] );
} );

// Prioriteit 20: na de bezorgdatum-samenvatting (includes/delivery-date.php
// hangt op de standaard prioriteit 10 op dezelfde hook), zodat de volgorde
// op de pagina voorspelbaar blijft.
add_action( 'woocommerce_review_order_before_payment', function() {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() || ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    mkcp_render_zone( 'above-payment', $cfg['checkout_blocks'] ?? [] );
}, 20 );

// Prioriteit 20: na het bezorgdatum-veld (hangt op prioriteit 5 op dezelfde
// hook), zodat eigen blokken altijd ná de bezorgdatumkiezer verschijnen.
add_action( 'woocommerce_review_order_before_submit', function() {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() || ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;
    mkcp_render_zone( 'below-payment', $cfg['checkout_blocks'] ?? [] );
}, 20 );

add_filter( 'woocommerce_form_field', function( $field, $key, $args, $value ) {
    if ( ! is_checkout() ) return $field; // dit veld-filter vuurt ook op bv. de "adres bewerken"-pagina in Mijn account
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() || ! mkcp_license_has( 'premium' ) ) return $field;

    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) || empty( $cfg['checkout_blocks'] ) ) return $field;

    // NB: het blok kan niet ín de <p class="form-row"> genest worden — een
    // <p> mag geen blok-elementen (<div>) bevatten, de browser zou 'm dan
    // zelf alsnog vóór het blok afsluiten (dezelfde HTML-parsingregel als
    // bij de <tr>/<td>-fix elders). Het blijft dus een sibling ná de </p>;
    // de kolom-uitlijning wordt in checkout.scss opgelost door het blok
    // exact dezelfde grid-column te geven als het veld waar het bij hoort
    // (zie ".mkcp-zone-render--field" + data-mkcp-field-selectors).
    $extra = mkcp_render_zone_html( 'field:' . $key, $cfg['checkout_blocks'] );
    return $extra !== '' ? $field . $extra : $field;
}, 10, 4 );

// De woocommerce_form_field-filter hierboven vuurt alleen bij het eerste,
// server-side gerenderde formulier. WooCommerce (of een thema/plugin dat
// meeluistert op update_checkout, bv. bij het wisselen van land) kan de
// veldenmarkup daarna opnieuw opbouwen — daarmee verdwijnt een puur
// server-side toegevoegd blok. Stuur de kant-en-klare HTML daarom ook mee
// naar de front-end, zodat delivery-date-achtige JS 'm na elke checkout-
// refresh kan terugzetten als 'ie er niet meer staat.
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() || ! mkcp_license_has( 'premium' ) ) return;
    $cfg = mkcp_checkout_config();
    if ( empty( $cfg['checkout_enabled'] ) ) return;

    $field_blocks = array_filter( $cfg['checkout_blocks'] ?? [], fn( $b ) =>
        ! empty( $b['enabled'] ) && strpos( (string) ( $b['zone'] ?? '' ), 'field:' ) === 0
    );
    if ( empty( $field_blocks ) ) return;

    $by_field = [];
    foreach ( $field_blocks as $block ) {
        $field_key = substr( $block['zone'], strlen( 'field:' ) );
        $by_field[ $field_key ] = ( $by_field[ $field_key ] ?? '' ) . mkcp_render_zone_html( $block['zone'], [ $block ] );
    }

    wp_enqueue_script(
        'mkcp-checkout-blocks',
        MKCP_URL . 'assets/checkout-blocks.js',
        [ 'jquery' ],
        MKCP_VER,
        true
    );
    wp_localize_script( 'mkcp-checkout-blocks', 'mkcpCheckoutBlocks', [ 'fields' => $by_field ] );
}, 20 );


// ── G2: wisselende teksten op het "Bestelling verwerken…"-laadscherm ───────

add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_checkout() ) return;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() || ! mkcp_license_has( 'premium' ) ) return;
    if ( ! function_exists( 'mkcp_is_distraction_free_checkout' ) || ! mkcp_is_distraction_free_checkout() ) return;

    $cfg      = mkcp_checkout_config();
    $messages = array_filter( array_map( 'trim', explode( "\n", (string) ( $cfg['loading_messages'] ?? '' ) ) ) );

    wp_enqueue_script(
        'mkcp-checkout-loading-messages',
        MKCP_URL . 'assets/checkout-loading-messages.js',
        [],
        MKCP_VER,
        true
    );
    wp_localize_script( 'mkcp-checkout-loading-messages', 'mkcpLoadingMessages', [
        'messages' => array_values( $messages ),
    ] );
}, 20 );


// ── Custom footer ─────────────────────────────────────────────────────────────

function mkcp_checkout_render_footer() {
    $cfg    = mkcp_checkout_config();
    $blocks = array_filter( $cfg['footer_blocks'] ?? [], fn( $b ) => ! empty( $b['enabled'] ) );
    if ( empty( $blocks ) ) return;

    ?>
    <div class="mkcp-checkout-footer">
        <div class="mkcp-checkout-footer__inner">
            <?php foreach ( $blocks as $block ) :
                $type = $block['type'] ?? '';
                switch ( $type ) :
                    case 'text': ?>
                        <div class="mkcp-checkout-footer__text"><?php echo wp_kses_post( $block['content'] ?? '' ); ?></div>
                    <?php break;
                    case 'divider':
                        $style = in_array( $block['style'] ?? 'solid', [ 'solid','dashed','dotted' ], true ) ? $block['style'] : 'solid'; ?>
                        <hr class="mkcp-checkout-footer__divider is-<?php echo esc_attr( $style ); ?>">
                    <?php break;
                    case 'usp':
                        if ( ! empty( $block['text'] ) ) : ?>
                        <div class="mkcp-checkout-footer__usp">
                            <?php if ( ! empty( $block['icon'] ) ) : ?>
                            <span class="mkcp-checkout-footer__usp-icon"><?php mkcp_icon( $block['icon'] ); ?></span>
                            <?php endif; ?>
                            <span><?php echo esc_html( $block['text'] ); ?></span>
                        </div>
                        <?php endif; break;
                    case 'image':
                        if ( ! empty( $block['url'] ) ) :
                            $img = '<img src="' . esc_url( $block['url'] ) . '" alt="' . esc_attr( $block['alt'] ?? '' ) . '" class="mkcp-checkout-footer__img" loading="lazy">';
                            if ( ! empty( $block['link'] ) ) : ?>
                            <a href="<?php echo esc_url( $block['link'] ); ?>" target="_blank" rel="noopener"><?php echo $img; ?></a>
                            <?php else : echo $img; endif;
                        endif; break;
                endswitch;
            endforeach; ?>
        </div>
    </div>
    <?php
}
