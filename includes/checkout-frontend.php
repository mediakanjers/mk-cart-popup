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
//
// Alles hierin (custom layout, BTW-switch, postcode-checker-koppeling, custom
// template) draait op hooks/veld-ID's die alléén bestaan bij de klassieke
// (shortcode) WooCommerce-checkout. Zodra de checkoutpagina het WooCommerce
// "Checkout"-blok gebruikt (Cart & Checkout blocks, React-gerenderd), vuren
// die hooks niet meer af — de features verdwijnen dan stilletjes, zonder
// foutmelding. Deze check maakt dat zichtbaar in wp-admin i.p.v. dat het
// onopgemerkt blijft bij een toekomstige checkout-migratie.

function mkcp_checkout_uses_blocks(): bool {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    if ( ! function_exists( 'has_block' ) ) return $cache = false;

    // FSE/blok-thema's kunnen de checkout via een wp_template (Site Editor)
    // definiëren i.p.v. via de pagina-inhoud — een pagina-inhoud-check alleen
    // mist dat geval. WooCommerce heeft hiervoor intern wel de juiste
    // fallback-logica (CartCheckoutUtils::is_checkout_block_default()), maar
    // die klasse zit in een niet-publieke, interne namespace en is dus
    // onveilig om rechtstreeks aan te roepen (kan breken bij een WooCommerce-
    // update). Dezelfde logica hier met alleen stabiele, publieke
    // WordPress-corefuncties (beide sinds WP 5.9).
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


// ── Dequeue theme stylesheets on plugin checkout page ────────────────────────
//
// wp_head() still fires inside the custom template, which loads all theme
// styles. We strip anything sourced from the (child) theme directory so the
// checkout starts from a clean slate; WooCommerce and plugin styles stay.
//
// NB: reads the internal $wp_styles->registered property directly (no public
// WP_Styles accessor exists for "all registered handles"). This has been
// stable since WP 2.6, but is not a documented API — guarded defensively so
// a future core change degrades to "theme CSS stays loaded" instead of a
// fatal error.
//
// Runs TWICE: once on wp_enqueue_scripts@9999 (catches the normal case), and
// again on wp_footer@15 as a safety net. Reason: some theme frameworks have a
// "load styles in the footer" performance option that re-enqueues theme CSS
// from a wp_footer callback — i.e. AFTER the first sweep already ran. The
// hook-removal sweep below normally prevents that callback from ever running
// at all (its file lives in the theme directory), but this second pass stays
// as defense-in-depth for anything enqueued late by code the hook-removal
// sweep doesn't reach (e.g. a non-theme plugin, or a function deliberately
// exempted via the mkcp_checkout_dequeue_exclude_functions filter below), or
// if dequeue_theme_hooks is switched off while dequeue_theme_css stays on.

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
// Priority 15: AFTER the default wp_footer priority (10) most theme
// frameworks use for a "load in footer" callback (confirmed here — see
// theme_scripts_footer() in MKTheme), but BEFORE WordPress core's own
// wp_print_footer_scripts (priority 20, wp-includes/default-filters.php)
// actually prints the queue. Priority 1 (the original choice) ran too
// early to catch anything enqueued by a default-priority footer hook.
add_action( 'wp_footer', 'mkcp_checkout_dequeue_theme_styles', 15 );


// ── Dequeue theme scripts on plugin checkout page ────────────────────────────
//
// Same idea as mkcp_checkout_dequeue_theme_styles() above, but for scripts.
// Own toggle (dequeue_theme_js) so a site can keep theme CSS but still want
// theme JS gone (or vice versa) — theme JS is more likely than theme CSS to
// throw console errors on a page whose markup this plugin controls, since a
// theme script written for the theme's own header/footer markup may reach
// for elements (menus, sliders, cookie banners) that simply don't exist here.
//
// The scaffold folder (mk-cart-popup/script.js + checkout.js, auto-enqueued
// by the plugin — see admin/scaffold.php) is explicitly exempted so a
// developer always has a clean place to add checkout JS that's guaranteed to
// survive this sweep, without needing the mkcp_checkout_dequeue_exclude_functions
// filter for every new script.
//
// Dequeuing (not deregistering) is safe for dependencies: if a non-theme
// script still enqueued elsewhere legitimately depends on a theme handle,
// WordPress's own dependency resolution still pulls it in when printing,
// regardless of whether it's in the immediate queue.

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
//
// Iterates $wp_filter and removes any callback whose source file lives inside
// the active theme's directory — CHILD *and* PARENT theme when a child theme
// is active, since a callback defined in the parent (e.g. the framework's own
// core/functions/*.php) is just as much "theme code" as one in the child —
// but NOT inside the mk-cart-popup scaffold subfolder, so cart-hooks.php /
// checkout-hooks.php remain fully active. Runs on `wp` (priority 20) after
// plugins_loaded has loaded the scaffold.
//
// NB: this uses PHP Reflection to inspect every registered callback site-wide
// — not a public WP API. It's wrapped defensively at every level (instance
// checks, per-callback try/catch, outer try/catch) so an unusual callback
// shape (future PHP version, first-class callable syntax, enum method, etc.)
// is skipped rather than fataling the whole checkout page.

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

    $child_dir  = wp_normalize_path( get_stylesheet_directory() );
    // get_template_directory() equals get_stylesheet_directory() when no
    // child theme is active — deduping via array_unique() below then just
    // means the loop checks the same path twice, which is harmless.
    $parent_dir   = wp_normalize_path( get_template_directory() );
    $theme_dirs   = array_unique( [ $child_dir, $parent_dir ] );
    $scaffold_dir = $child_dir . '/mk-cart-popup';

    // Escape hatch for the rare case where a theme hook must survive even
    // though its file lives outside the scaffold folder — e.g. a function
    // that another (also-surviving) script/hook implicitly depends on via a
    // relationship WordPress itself has no record of (no wp_enqueue_script
    // dependency, just "script B calls a function script A defines"), so
    // this plugin can't detect the dependency automatically. Named functions
    // only — a Closure has no stable name to filter on, and if you can edit
    // the file to add a Closure you can just as easily move it into the
    // scaffold folder instead. See mk-cart-popup/checkout-hooks.php for an
    // example use of this filter.
    $exclude_names = apply_filters( 'mkcp_checkout_dequeue_exclude_functions', [] );

    // WooCommerce's eigen "cart item data"-filters staan hier altijd buiten
    // schot, ongeacht welk thema/plugin erop haakt. Gevonden n.a.v. een
    // concreet incident: een thema-hook op woocommerce_get_item_data die per
    // besteld product toonde welk kaartje/bericht de klant had gekozen ("Ja
    // (+ €1,50)" / bericht / type) verdween stilzwijgend van de checkout —
    // niet omdat het een layout-conflict was, maar simpelweg omdat de functie
    // toevallig in het thema stond. Deze hooks zijn qua contract altijd
    // "geef de weer te geven waarde voor dít item terug" (filters die een
    // string/array retourneren, WooCommerce plaatst het resultaat zelf op een
    // vaste, al door de checkout-layout gecontroleerde plek) — nooit een
    // wrapper/structuur-hook die de opmaak van de pagina zelf verandert. Er is
    // dus geen scenario waarin verwijdering hiervan een layout-probleem
    // oplost, alleen scenario's waarin het klantdata laat verdwijnen.
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
                            $ref = new ReflectionMethod( $func );
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

// wc-ajax (?wc-ajax=update_order_review, elke adres-/verzendmethode-wijziging
// op de checkout) bootstrapt WordPress zonder ooit de 'wp' action te vuren —
// zie de opmerking bij de BTW-switch verderop in dit bestand, die om
// dezelfde reden apart op deze hook draait. Zonder deze tweede registratie
// blijft een thema-hook die op een hook zit die tijdens deze AJAX-cyclus
// vuurt (bv. woocommerce_update_order_review_fragments) daardoor voor altijd
// buiten bereik van "Verwijder thema-hooks", ongeacht hoe vaak de sweep op
// een normale paginalaad al gedraaid heeft. Prioriteit 1: vóór
// WC_AJAX::update_order_review() de shipping/fragments berekent (zie
// class-wc-ajax.php) — is_checkout() klopt hier al, want die functie
// definieert zelf de WOOCOMMERCE_CHECKOUT-constante waar is_checkout() op
// leunt, nog vóórdat deze hook vuurt.
add_action( 'woocommerce_checkout_update_order_review', 'mkcp_checkout_remove_theme_hooks', 1 );

// Zelfde reden, maar dan voor het daadwerkelijke afreken-verzoek zelf
// (?wc-ajax=checkout, WC_AJAX::checkout()): ook dát bootstrapt WordPress
// zonder ooit de 'wp' action te vuren. Zonder deze derde registratie blijven
// thema-hooks die op woocommerce_checkout_process/woocommerce_after_checkout_
// validation/woocommerce_checkout_create_order/woocommerce_checkout_update_
// order_meta zitten daardoor altijd actief tijdens het echte afrekenen — ook
// als de sweep op een normale paginalaad allang gedraaid heeft. Concreet
// incident: het thema's oude afhaaldatum/tijdvak-validatie (CW_Pickup_Checkout,
// gebaseerd op cw_pickup_date/cw_pickup_timeslot) bleef hierdoor verplicht
// blijven, terwijl die velden allang niet meer gerenderd worden — waardoor
// afhalen via de plugin's eigen afhaal-tijdvak-systeem (includes/pickup.php)
// nooit kon afrekenen. Prioriteit 1: vóór woocommerce_checkout_process zelf
// iets doet (dat is letterlijk de eerste actie in WC_Checkout::process_checkout()),
// dus ruim vóór alle latere validatie-/opslaghooks in dezelfde requestcyclus.
add_action( 'woocommerce_checkout_process', 'mkcp_checkout_remove_theme_hooks', 1 );


// ── Helpers: locate/detach a callback regardless of which hook it's on ──────────
//
// Een thema kan een WooCommerce-kernfunctie (bv. woocommerce_checkout_payment)
// op elke willekeurige hook laten hangen — de plugin kan dus niet uitgaan van
// één vaste locatie om te controleren of hij er nog is. Deze twee helpers
// werken op de naam van de callback zelf, niet op een specifieke hook:
// - callback_registered_anywhere(): alleen lezen, voor "staat 'ie ergens, ja
//   of nee" — gebruikt waar we een bestaande (mogelijk thema-eigen, werkende)
//   plek met rust willen laten.
// - detach_callback_everywhere(): haalt de callback overal weg waar hij
//   gevonden wordt — gebruikt waar de plugin de locatie zelf gaat bepalen.
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
//
// Concreet incident: Mediakanjers/functions/woo/woo-checkout.php doet
//     remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
//     add_action( 'cobweb_after_checkout_form', 'woocommerce_checkout_payment', 20 );
// — bedoeld voor het thema's eigen (oude) checkoutlayout. Deze scaffold kent
// die hook niet en roept 'm nergens aan, waardoor #payment (betaalmethodes +
// "Plaats bestelling"-knop) spoorloos verdwijnt. Belangrijk: hetzelfde patroon
// (payment-hook verplaatst) komt ook voor op sites waar het WEL werkt (bv.
// TOM-Bloemen → woocommerce_before_order_notes, een hook die nog gewoon
// vuurt) — dus simpelweg "terugzetten op de standaardplek als hij daar
// ontbreekt" zou op zulke sites dubbele rendering veroorzaken. Vandaar twee
// aparte, veilige mechanismen hieronder i.p.v. één blinde restore.
//
// Nevenbaat: alles dat hangt op woocommerce_review_order_before_payment
// (de content-builder-zones "above/below-payment") vuurt uitsluitend als
// bijeffect van woocommerce_checkout_payment()'s eigen rendering. Dit lost
// dus niet alleen #payment op, maar die afhankelijke onderdelen automatisch
// mee.
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
// template-resolutie: zodra het gevonden bestand ergens in de (child of
// parent) themamap zit, valt de checkout terug op WooCommerce's eigen
// default-template — hetzelfde uitgangspunt als de hook-sweep, maar dan voor
// bestanden i.p.v. hooks. Alleen "checkout/*"-templates, zodat overrides voor
// andere WooCommerce-onderdelen (winkel, product, cart) buiten de checkout
// onaangeroerd blijven.
//
// Net als de hook-sweep hierboven moet dit filter OOK op woocommerce_checkout_
// update_order_review draaien: wc-ajax (?wc-ajax=update_order_review, bij élke
// postcode-/adreswijziging, wisselen van verzendmethode, coupon toepassen)
// verloopt via WC_AJAX::do_wc_ajax(), gehaakt op 'template_redirect'@0, die het
// request meteen met wp_die() beëindigt — 'wp' vuurt dan dus nooit. Stond dit
// filter alléén op 'wp' geregistreerd, dan gold de terugval naar WooCommerce's
// eigen template alleen voor de allereerste paginalaad: bij de eerstvolgende
// AJAX-refresh berekent WC_AJAX::update_order_review() de review-order/
// payment-fragmenten opnieuw via wc_get_template(), en zonder dit filter actief
// grijpt het thema's eigen (mogelijk kapotte) checkout-template dan alsnog
// terug — precies de kolom-/dubbele-content-problematiek die dit filter juist
// moet voorkomen, maar dan pas zichtbaar ná de eerste klant-interactie.
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
//
// WooCommerce's eigen (niet-thema-)checkout/review-order.php heeft geen aparte
// thumbnail-kolom — alleen product-name en product-total (2 kolommen). Zodra
// mkcp_checkout_register_template_fallback() hierboven het thema's eigen
// review-order.php (dat vaak wél een thumbnail-kolom heeft, zoals het
// MKTheme/Mediakanjers-framework) vervangt door WC's eigen versie, verdwijnt
// de thumbnail dus mee. In plaats van een aparte kolom terug te zetten (en
// daarmee opnieuw kolomtelling-/colspan-gedoe te riskeren op elk thema dat dit
// draait), wordt de thumbnail hier vóór de productnaam gezet, BINNEN dezelfde
// cel via de woocommerce_cart_item_name-filter — de kolomstructuur blijft dus
// altijd exact 2, wat de generieke colspan-fix elders in dit bestand al
// aankan. Zelfde dubbele registratie (wp + ajax-hook) als de template-
// fallback: deze filter fireert bij elke productrij in review-order.php, dat
// via de standaard AJAX-fragment ververst — zonder de ajax-registratie zou
// de thumbnail na de eerste AJAX-refresh weer verdwijnen.
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


// ── BTW switch: dual price output + UI ───────────────────────────────────────
//
// mkcp_register_btw_price_filters() is a standalone function so it can be
// called from both the 'wp' action (normal page load) AND from
// 'woocommerce_checkout_update_order_review' (AJAX refresh). WooCommerce's
// wc-ajax endpoint does not fire the 'wp' action, so without the second call
// prices would revert to plain WooCommerce output after every field change.

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
    // as the popup so cart-popup.css styles apply without duplication.
    add_action( 'woocommerce_checkout_order_review', function() {
        ?>
        <div class="mk-cart-popup__btw-switch mkcp-co-btw-switch">
            <span class="mk-cart-popup__btw-label">Prijzen:</span>
            <div class="mk-cart-popup__btw-pills">
                <button type="button" class="mk-cart-popup__btw-opt js-mkcp-btw" data-pref="incl">incl. BTW</button>
                <button type="button" class="mk-cart-popup__btw-opt js-mkcp-btw" data-pref="excl">excl. BTW</button>
            </div>
        </div>
        <?php
    }, 5 );

    // Enqueue BTW switch script.
    add_action( 'wp_enqueue_scripts', function() {
        wp_enqueue_script(
            'mkcp-checkout-btw',
            MKCP_URL . 'assets/checkout-btw.js',
            [],
            filemtime( MKCP_PATH . 'assets/checkout-btw.js' ),
            true
        );
    } );
}, 5 );

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
//
// Onafhankelijk van de postcode-checker integratie hieronder — voorheen zat
// deze JS ten onrechte genest in de postcode-checker gate, waardoor de
// is-focused/has-value classes nergens verschenen op sites zonder de WP
// Overnight NL Postcode Checker-plugin (of met "Postcode-checker velden
// vergrendelen" uitgeschakeld). Hoort bij de algemene checkout-styling, dus
// alleen gated op de generieke "Cart Checkout is actief"-voorwaarden.

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

            // Gedelegeerd op document i.p.v. per-element gebonden bij het laden:
            // WooCommerce's eigen wc-country-select.js VERVANGT het Staat/
            // provincie-veld bij elke landwissel volledig door een nieuw DOM-
            // element (input <-> select, afhankelijk van of dat land een
            // statenlijst heeft) — een eenmalige, per-element gebonden listener
            // raakte dan wees op het oude (verwijderde) element, en het nieuwe
            // element had nooit een listener gekregen. Focus/blur bubbelen zelf
            // niet, focusin/focusout wel — daarmee werkt delegatie ook daarvoor.
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
//
// Detecteert of de WP Overnight NL Postcode Checker actief is en maakt
// billing_street_name + billing_city readonly wanneer de admin dat instelt.
//
// BELANGRIJK — externe afhankelijkheid: billing_house_number,
// billing_house_number_suffix en billing_street_name (en hun shipping_-
// tegenhangers) zijn GEEN WooCommerce-kernvelden. Ze bestaan uitsluitend
// omdat de WP Overnight NL Postcode Checker-plugin ze toevoegt via
// woocommerce_checkout_fields. Zonder die plugin actief bestaan deze
// veld-ID's niet en doen alle CSS-regels/JS in dit bestand die ernaar
// verwijzen (grid-layout, readonly-lock, adres-lookup) stilzwijgend niets —
// er verschijnt geen foutmelding. mkcp_postcode_checker_active() detecteert
// de plugin via interne class-/constantennamen die ongedocumenteerd zijn en
// door WP Overnight op elk moment hernoemd kunnen worden; zie de
// admin-waarschuwing hieronder die dat zichtbaar maakt i.p.v. het stil te
// laten mislukken.

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
//
// De vaste grid-indeling + postcode/huisnummer/straatnaam-velden hierboven
// zijn gebouwd voor het Nederlandse adresformaat (via de WP Overnight
// postcode checker). Voor andere landen toont WooCommerce zelf andere/extra
// velden (adres_1/adres_2, evt. "Staat/provincie") die geen eigen grid-area
// hebben — dat brak de layout — en de (Nederlandse) postcode checker kan er
// toch niks vinden, waardoor Straatnaam/Plaats voor altijd op readonly bleef
// staan zonder enige manier om ze in te vullen.
//
// mkcp-intl-address is de klasse die dit omschakelt — maar NIET op <body>:
// die staat rechtstreeks op de eigen .woocommerce-billing-fields__field-
// wrapper / .woocommerce-shipping-fields__field-wrapper, elk aangestuurd
// door zijn EIGEN _country-veld (zie mkcp_initPostcodeChecker() in het
// postcode-checker-script hieronder). Factuur- en verzendadres worden dus
// volledig los van elkaar beoordeeld: een bestelling met Nederland als
// factuuradres maar bv. Duitsland als verzendadres toont in de
// factuurkolom gewoon de Nederlandse velden en in de verzendkolom de
// Duitse — precies andersom werkt ook. checkout.scss heeft voor beide
// modi een eigen, volledige grid-template-areas (i.p.v. één gedeelde
// template met wisselende velden — postcode+plaats moeten in de
// internationale indeling namelijk náást elkaar staan i.p.v. in de
// Nederlandse rij-indeling met huisnummer/toevoeging, en dat kan niet
// binnen dezelfde area-namen).
// Alleen JS-gedreven (geen server-side initiële klasse meer) — het geeft
// een verwaarloosbare flits bij de allereerste keer laden, maar blijft zo
// altijd exact synchroon met de daadwerkelijk geselecteerde landen.
//
// WooCommerce's eigen placeholder voor adres_1 ("Huisnummer en straatnaam")
// is geschreven voor het Nederlandse formaat en klopt niet voor landen waar
// straat en huisnummer gewoon in één regel worden getypt — leeg is hier
// duidelijker dan een misleidende suggestie. Adres_2 ("Appartement, suite,
// unit enz.") krijgt om dezelfde reden een lege placeholder, én een gewoon
// zichtbaar label i.p.v. WooCommerce's standaard label_class=screen-reader-
// text — anders staat er straks nergens meer tekst bij dat veld.
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

// De hook hierboven bepaalt alleen de EERSTE server-gerenderde HTML. Zodra de
// klant het land wijzigt, herstelt WooCommerce's eigen wc-address-i18n.js de
// placeholder ONAFHANKELIJK daarvan, uit wc_address_i18n_params (JS-data,
// opgebouwd via WC_Countries::get_country_locale()). Geprobeerd om dat via
// een woocommerce_get_country_locale-filter te corrigeren, maar WC_Countries
// cachet zijn resultaat in een private property zodra hij één keer is
// aangeroepen — een andere plugin blijkt dat al vóór onze eigen hooks te
// doen, dus onze filter-waarde komt nooit meer aan bod, ongeacht prioriteit.
//
// Ook de daaropvolgende poging — luisteren naar wc_address_i18n_ready —
// bleek niet te werken: dat event vuurt maar één keer, meteen bij het laden
// van address-i18n.js zelf (als "ik ben klaar met mijn EIGEN handler
// registreren"), niet telkens na een landwissel. De daadwerkelijke update
// gebeurt in diezelfde script z'n handler op 'country_to_state_changing' op
// document.body — hetzelfde event waar WooCommerce's eigen wc-country-
// select.js het op triggert. Omdat WooCommerce's script 'defer' geladen
// wordt, bindt het zijn handler pas ná onze eigen (synchrone) inline
// <script>, dus zonder ingreep zou onze correctie eerst lopen en direct
// daarna weer overschreven worden door hún handler. Oplossing: dezelfde
// 'country_to_state_changing' afluisteren, maar de eigen correctie via
// setTimeout(...,0) naar de volgende tick verplaatsen — die loopt altijd ná
// alle synchrone handlers van dit event, ongeacht bindvolgorde.
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
//
// Spiegelt de postcode-checker-integratie hierboven, maar dan voor de
// EU/UK VAT Validation Manager for WooCommerce-plugin (WPFactory,
// eu-vat-for-woocommerce): detecteert of de plugin actief is en toont —
// wanneer de admin dat aanzet — bij het billing_eu_vat_number-veld dezelfde
// .mkcp-pc-status laad-/succes-/foutmelding-balk als de postcode checker,
// i.p.v. de eigen (kale) statustekst van die plugin.
//
// BELANGRIJK — externe afhankelijkheid: billing_eu_vat_number is GEEN
// WooCommerce-kernveld. Het bestaat uitsluitend omdat de VAT-plugin het
// toevoegt via woocommerce_checkout_fields. Zonder die plugin actief bestaat
// dit veld-ID niet en doet alle CSS/JS hieronder die ernaar verwijst
// stilzwijgend niets — er verschijnt geen foutmelding.

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
// BTW-integratie (statusbalk + BTW-verlegging + veldregels hieronder), niet
// alleen voor de statusbalk. Voorheen bestuurde hij alleen de balk en draaide
// de verlegging altijd door zolang de WPFactory-plugin actief was — maar in
// combinatie met het (door de bedrijfsnaam-regel) verborgen BTW-veld en een
// door de WooCommerce-sessie bewaard geldig nummer zat een klant dan
// onzichtbaar en onontkoombaar vast op excl. BTW. UIT betekent nu écht uit:
// WPFactory's eigen kale veld/voortgangstekst komt dan gewoon terug en wij
// bemoeien ons nergens meer mee. De sleutelnaam is historisch (geen
// migratie nodig); het label in de admin heet inmiddels "BTW-integratie".

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
// bedrijfsnaam is er niks om te valideren. Onafhankelijk van "Bedrijfsnaam
// veld tonen": bestaat #billing_company niet (die toggle staat uit), dan
// blijft het BTW-veld permanent verborgen — logisch, want dan kan een klant
// sowieso nooit een bedrijfsnaam invullen.
//
// De verberg-CSS staat hier bewust als conditionele wp_head-echo en NIET in
// checkout.scss: die stylesheet laadt altijd, dus een onvoorwaardelijke regel
// daar hield het veld óók verborgen wanneer deze integratie (en daarmee de
// body-klasse-sync hieronder) helemaal niet draait — precies de val waarbij
// een verborgen veld met een sessie-bewaard geldig BTW-nummer de checkout
// onzichtbaar op excl. BTW vergrendelde.
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

                // Zodra het veld verborgen wordt terwijl er nog een (mogelijk
                // door de WooCommerce-sessie bewaard) nummer in staat, dat
                // nummer ook echt wissen. Een onzichtbaar veld dat stiekem een
                // geldig BTW-nummer blijft mee-POSTen houdt anders zowel de
                // BTW-verlegging hierónder als WPFactory's server-side
                // BTW-vrijstelling actief, zonder dat de klant iets ziet of er
                // iets aan kan doen. Via jQuery triggeren zodat WPFactory's
                // delegated input-handler (en onze eigen) het lege veld ook
                // echt verwerken; guard op niet-lege waarde voorkomt een lus.
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
//
// WooCommerce's eigen submit_error() zet bij meerdere lege verplichte velden
// gewoon élk veld als los <li data-id="..."> in de .woocommerce-error-lijst
// (checkout.js, show_inline_errors()) — hetzelfde data-id wordt ook gebruikt
// om vlak onder dát veld zelf een .checkout-inline-error-message te tonen
// (zie hierboven in checkout.scss). Bij bv. 8 lege velden staat dezelfde
// info dus twee keer: een lange lijst bovenaan ÉN een regeltje bij elk veld.
// Geen enkele high-end checkout (Shopify, Apple, Stripe) doet dat — die
// houden de top-melding kort en laten de velden zelf het werk doen.
//
// Voorwaarde: alleen samenvatten als de <li>'s ECHT 1-op-1 bij een veld
// horen (data-id + dat veld bestaat ook echt in de DOM) — een niet-veld-
// gebonden fout (bv. ongeldige coupon, voorraadprobleem) heeft geen data-id
// en blijft dus altijd gewoon zichtbaar, individueel, nooit samengevat.
// Bij precies 1 veldfout is samenvatten zinloos (er is toch al maar 1 regel),
// dus pas vanaf 2 velden. checkout_error is WC's eigen event, vuurt pas NA
// het invoegen van de nieuwe notice-HTML in de DOM (submit_error() in
// checkout.js) — dus op dat moment staat de originele lijst al klaar om
// aangepast te worden.
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
                summary.addEventListener('click', mkcp_scrollToFirstInvalid);
                summary.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); mkcp_scrollToFirstInvalid(); }
                });
                list.appendChild(summary);
            }

            function mkcp_scrollToFirstInvalid() {
                var firstInvalid = document.querySelector('.form-row.woocommerce-invalid');
                if (!firstInvalid) return;
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var field = firstInvalid.querySelector('input, select, textarea');
                if (field) field.focus({ preventScroll: true });
            }

            $(document.body).on('checkout_error', mkcp_summarizeErrors);
        })(window.jQuery);
        </script>
        <?php
    }, 9 );
}, 4 );


// BTW-verlegging + statusbalk. Draait alleen als de master-switch
// ("BTW-integratie", vat_checker_status_enabled) aanstaat én de VAT-plugin
// actief is. Historie: dit blok draaide ooit altijd zodra de VAT-plugin
// actief was (de toggle bestuurde alleen de banner), maar dat betekende dat
// de verlegging kon vergrendelen zonder enige zichtbare feedback — met een
// verborgen BTW-veld vol sessie-waarde zat een klant dan muurvast op excl.
// BTW. Het klassieke gevaar van "uit terwijl er nog een vergrendeling
// staat" (de reden van die altijd-aan-koppeling) is nu structureel gedekt
// door window.mkcpVatIntegrationActive hieronder: assets/checkout-btw.js
// ruimt bij het ontbreken van die marker elke achtergebleven vergrendeling
// zelf op, vóór de eerste weergave.
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
            // zelf zijn GEEN betrouwbare signalen — de VAT-plugin voegt bij
            // een geslaagde controle alleen woocommerce-validated tóé
            // (vat_input.addClass('woocommerce-validated')) zonder ooit een
            // eerder gezette woocommerce-invalid te verwijderen, en
            // andersom. Na één geslaagde controle blijft woocommerce-
            // validated dus voorgoed op het veld staan, ook als daarna een
            // duidelijk ongeldig nummer wordt ingevuld — dat gaf de bug
            // waarbij alles altijd "geldig" leek. #wpfactory_wc_eu_vat_progress
            // (alleen aanwezig als de VAT-plugin's eigen "voortgangstekst"-
            // instelling aan staat, wat de default is) wordt door de plugin
            // wél steeds eerst met removeClass() leeggemaakt vóór een nieuwe
            // status-klasse wordt gezet — dat is dus de enige klasse die
            // altijd de actuele, correcte uitkomst weerspiegelt.
            // BTW-verlegging: bij een geldig BTW-nummer forceren we de prijs-
            // weergave (incl./excl. BTW-knoppen bovenaan de bestelling) naar
            // "excl. BTW" en vergrendelen we de knoppen — de klant kan dan
            // niet meer terugschakelen naar incl. BTW zolang het nummer
            // geldig is. window.mkcpBtwSwitch komt uit assets/checkout-btw.js
            // (altijd aanwezig zodra "checkout_enabled" aanstaat, ongeacht
            // deze VAT-integratie).
            function mkcp_setReverseCharge( active ) {
                if ( ! window.mkcpBtwSwitch ) return;
                if ( active ) window.mkcpBtwSwitch.lock( 'excl' );
                else window.mkcpBtwSwitch.unlock();
            }

            function mkcp_syncFromProgress() {
                // Zonder ingevoerd nummer nooit een status tonen — de VAT-plugin
                // maakt #wpfactory_wc_eu_vat_progress al bij het laden van de
                // pagina aan (nog vóór er iets getypt is) en kan 'm bij een
                // WooCommerce-AJAX-refresh met een klasse van een vórige,
                // ondertussen alweer gewiste invoer herstellen — zonder deze
                // check verscheen daardoor soms al "BTW-nummer geldig" terwijl
                // het veld nog leeg was.
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

            // Bij een leeg veld ruimt de VAT-plugin zelf niets op (de AJAX-
            // aanroep wordt dan simpelweg overgeslagen) — dus zelf de balk
            // verbergen en de BTW-knoppen ontgrendelen zodra de klant alles
            // wist.
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

    // Verplaatsen naar onder de besteltabel — .woocommerce-account-fields zit
    // in .col-1 (klantgegevens), de besteltabel in .col-2/#order_review; een
    // CSS-grid-verplaatsing kan dat niet overbruggen (andere grid-container),
    // vandaar dezelfde DOM-move-in-JS-aanpak als mkco_reorganize() hierboven
    // gebruikt voor de 3-blokken-layout — maar hier los daarvan, want deze
    // verplaatsing hoort niet bij die (optionele) layout.
    add_action( 'wp_footer', function() {
        ?>
        <script>
        (function () {
            function mkcpMoveCreateAccount() {
                var fields = document.querySelector('.woocommerce-account-fields');
                var table  = document.querySelector('.shop_table.woocommerce-checkout-review-order-table');
                if ( ! fields || ! table || ! table.parentNode ) return;
                if ( fields.previousElementSibling !== table ) {
                    table.parentNode.insertBefore( fields, table.nextSibling );
                }
                // Toelichtingskader (indien aanwezig) vóór de checkbox zetten,
                // én de checkbox ZELF in datzelfde kader schuiven — anders
                // ontstaan er twee losse, los-ogende doosjes onder elkaar i.p.v.
                // één kader met de checkbox erin. ".create-account" bestaat
                // dubbel in deze markup (form-billing.php): de checkbox-<p> én
                // de (meestal lege) extra-accountvelden-<div> — vandaar het
                // specifieke "p.create-account" i.p.v. de kale class-selector.
                var info      = fields.querySelector('.mkcp-createaccount-info');
                var checkbox  = fields.querySelector('p.create-account');
                // "div.create-account" is WooCommerce's eigen wrapper om de
                // account_username/account_password-velden (alleen aanwezig
                // wanneer WC's "gebruikersnaam/wachtwoord automatisch
                // genereren"-instellingen uit staan) — zonder deze move bleef
                // dit blokje als los, ongestyled element ONDER het kader
                // staan i.p.v. er visueel bij te horen.
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
            setTimeout( mkcpMoveCreateAccount, 120 );
            if ( window.jQuery ) {
                jQuery( document.body ).on( 'updated_checkout', function () {
                    setTimeout( mkcpMoveCreateAccount, 80 );
                } );
            }

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
            var FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
            var lastFocusedTrigger = null;
            var modal, dialog;

            // De melding gaat boven #customer_details staan, maar beide
            // samen in ÉÉN gedeelde wrapper (.mkcp-login-and-details) i.p.v.
            // los als twee grid-items — CSS Grid deelt rijhoogtes over ALLE
            // kolommen, dus een eigen grid-rij voor de melding zou de
            // rechterkolom (Prijzen/besteloverzicht) net zo goed mee omlaag
            // duwen. Als één gezamenlijk grid-item (normale block-flow
            // erbinnen) blijft de rechterkolom precies uitgelijnd met de
            // bovenkant van #customer_details, zoals vóór deze toevoeging.
            function mkcpMoveLoginToggle() {
                var toggle = document.querySelector('.woocommerce-form-login-toggle');
                var anchor = document.getElementById('customer_details');
                if ( ! anchor || ! anchor.parentNode ) return;
                if ( document.querySelector('.mkcp-login-and-details') ) return; // al verplaatst

                // #customer_details in DIENS eigen ouder opzoeken i.p.v. aan
                // te nemen dat .woocommerce-checkout die ouder is — sommige
                // plugins (bv. "Checkout Field Editor for WooCommerce") zetten
                // er een extra #checkout_form_inner-wrapper tussen (zie ook de
                // display:contents-regel hiervoor in checkout.scss).
                //
                // De wrapper moet er ALTIJD komen, ook zonder .toggle (bv. een
                // ingelogde klant, die ziet nooit een "Terugkerende klant?"-
                // melding) — de grid-plaatsing van #customer_details loopt via
                // .mkcp-login-and-details (zie checkout.scss), dus zonder
                // wrapper viel #customer_details terug op de standaard grid-
                // auto-plaatsing en schoof de hele rechterkolom mee omlaag.
                var outer = document.createElement('div');
                outer.className = 'mkcp-login-and-details';
                anchor.parentNode.insertBefore( outer, anchor );

                if ( toggle ) {
                    var wrap = document.createElement('div');
                    wrap.className = 'mkcp-login-area';
                    wrap.appendChild( toggle );
                    outer.appendChild( wrap );
                }
                outer.appendChild( anchor );
            }
            mkcpMoveLoginToggle();

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

                modal.querySelector('.mkcp-login-modal__backdrop').addEventListener('click', closeLoginModal);
                modal.querySelector('.mkcp-login-modal__close').addEventListener('click', closeLoginModal);

                form.addEventListener('submit', function () {
                    form.classList.add('is-submitting');
                });
            }

            function openLoginModal( trigger ) {
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

            function closeLoginModal() {
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

            mkcpBuildLoginModal();

            // WooCommerce's eigen showlogin-handler (checkout.js) luistert via
            // EVENT DELEGATION op document.body ($(document.body).on('click',
            // 'a.showlogin', ...)) — die hangt dus niet op de link zelf, en
            // clone+replace (wat wél op een DIRECT gebonden listener had
            // gewerkt) doet daar niets tegen. Zonder tegenmaatregel vuurden
            // beide handlers op elke klik: onze popup ging open, terwijl
            // WooCommerce's eigen slideToggle() tegelijk de (inmiddels in
            // de modal verplaatste) formulier-inhoud weer inklapte — vandaar
            // de soms lege dialoog. e.stopPropagation() hier, vóór de bubbel
            // ooit bij document.body aankomt, voorkomt dat WC's handler
            // überhaupt nog gevuurd wordt.
            document.querySelectorAll('.showlogin').forEach( function ( link ) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openLoginModal( link );
                });
            });

            document.addEventListener('keydown', function (e) {
                if ( ! modal || ! modal.classList.contains('is-open') ) return;
                if ( e.key === 'Escape' ) { closeLoginModal(); return; }
                if ( e.key !== 'Tab' ) return;
                // Eenvoudige focus-trap: bij Tab voorbij het laatste/eerste
                // focusbare element weer naar het andere uiteinde springen.
                var items = dialog.querySelectorAll( FOCUSABLE_SELECTOR );
                if ( ! items.length ) return;
                var first = items[0], last = items[ items.length - 1 ];
                if ( e.shiftKey && document.activeElement === first ) {
                    e.preventDefault(); last.focus();
                } else if ( ! e.shiftKey && document.activeElement === last ) {
                    e.preventDefault(); first.focus();
                }
            });

            <?php if ( $mkcp_just_attempted_login ) : ?>
            // Mislukte inlogpoging: de pagina is net volledig herladen met
            // $_POST['login'] gezet — open de modal meteen weer, met een
            // korte shake, i.p.v. te verwachten dat de klant 'm zelf opnieuw
            // aanklikt.
            if ( modal ) {
                openLoginModal( null );
                dialog.classList.add('mkcp-login-shake');
                dialog.addEventListener('animationend', function () {
                    dialog.classList.remove('mkcp-login-shake');
                }, { once: true });
            }
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

            mkcp_initPostcodeChecker('billing');
            if (document.getElementById('shipping_postcode') && document.getElementById('shipping_house_number')) {
                mkcp_initPostcodeChecker('shipping');
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
    add_action( 'wp_footer', function() {
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
            <button type="button" class="mkcp-mobile-orderbar__btn" id="mkcp-mobile-orderbar-btn">
                <span class="mkcp-mobile-orderbar__btn-spinner"></span>
                <span class="mkcp-mobile-orderbar__btn-label"><?php esc_html_e( 'Bestellen', 'mk-cart-popup' ); ?></span>
            </button>
        </div>
        <script>
        (function () {
            var _done = false;

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

            // Skeleton-laadstatus voor de leveringssectie tijdens WooCommerce's
            // "update_checkout"-AJAX (land/verzendmethode wijzigen) — zie de
            // toelichting bij .mkcp-co-section__body.is-loading in checkout.scss:
            // WC's eigen blockUI dimt alleen #payment/#order_review, niet deze
            // door mkco_reorganize() verplaatste sectie.
            if (window.jQuery) {
                jQuery(document.body).on('update_checkout', function () {
                    var body = document.querySelector('.mkcp-co-section--delivery .mkcp-co-section__body');
                    if (body) body.classList.add('is-loading');
                });
                jQuery(document.body).on('updated_checkout', function () {
                    var body = document.querySelector('.mkcp-co-section--delivery .mkcp-co-section__body');
                    if (body) body.classList.remove('is-loading');
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
            function mkco_syncOrderBar() {
                var amountEl = document.getElementById('mkcp-mobile-orderbar-amount');
                var totalTd  = document.querySelector('.order-total td');
                if (!amountEl || !totalTd) return;
                var clone = totalTd.cloneNode(true);
                var small = clone.querySelector('small');
                if (small) small.remove();

                var newHtml = clone.innerHTML;
                if (newHtml === amountEl.innerHTML) return;

                var isFirstSync = amountEl.innerHTML === '';
                amountEl.innerHTML = newHtml;

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

            mkco_syncOrderBar();
            setTimeout(mkco_syncOrderBar, 200);

            if (window.jQuery) {
                jQuery(document).on('updated_checkout', function () {
                    setTimeout(mkco_syncOrderBar, 80);
                });

                // Laadstatus op de "Bestellen"-knop: WooCommerce blokkeert bij
                // een mislukte validatie/AJAX-call het formulier weer en
                // vuurt 'checkout_error' — dat is het signaal om de knop weer
                // vrij te geven. Vangnet-timer voor het geval dat event om
                // wat voor reden dan ook niet komt.
                var orderBarTimer = null;
                jQuery(document.body).on('checkout_error', function () {
                    clearTimeout(orderBarTimer);
                    var bar = document.getElementById('mkcp-mobile-orderbar');
                    if (bar) bar.classList.remove('is-placing');
                });
            }

            document.addEventListener('click', function (e) {
                var totalEl = e.target.closest && e.target.closest('.mkcp-mobile-orderbar__total');
                if (totalEl) {
                    var toggledBtn = mkco_toggleReview();
                    if (toggledBtn && toggledBtn.getAttribute('aria-expanded') === 'true') {
                        toggledBtn.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
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

    if ( ! empty( $cfg['payment_icons_enabled'] ) ) {
        // Priority 15: fires after #order_review closes (priority 10) but before
        // #payment renders (priority 20), keeping icons between the two.
        add_action( 'woocommerce_checkout_order_review', 'mkcp_checkout_render_payment_icons_strip', 15 );
    }

    return MKCP_PATH . 'templates/checkout-page.php';
} );


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


// ── Betaalmethode iconen onder orderoverzicht (popup / legacy) ────────────────

function mkcp_checkout_render_payment_icons() {
    $icons = array_filter( mkcp_config()['payment_icons'] ?? [], fn( $p ) => ! empty( $p['url'] ) );
    if ( empty( $icons ) ) return;
    echo '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;padding-top:10px;border-top:1px solid rgba(0,0,0,.07)">';
    foreach ( $icons as $pi ) {
        echo '<img src="' . esc_url( $pi['url'] ) . '" alt="' . esc_attr( $pi['label'] ?? '' ) . '" style="height:24px;width:auto;border-radius:3px" loading="lazy">';
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
                    var bodyRow = table.querySelector('tbody tr');
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
