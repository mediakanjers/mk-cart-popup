<?php
/**
 * MK Cart Popup — Account Frontend (Fase 1, stap 1: fundament)
 *
 * Zelfde patroon als includes/checkout-frontend.php: op de WooCommerce
 * "Mijn account"-pagina serveert dit een volledig custom sjabloon zonder
 * get_header()/get_footer(), met client-side hash-routing en een AJAX-
 * fragmentdispatcher. Alleen actief voor ingelogde klanten met een premium
 * licentie, met de hoofdschakelaar (mkcp_is_enabled()) en de eigen
 * account_enabled-instelling allebei aan.
 *
 * Dit bestand is bewust alleen het fundament: routing-shell + dispatcher.
 * De views zelf (Accountgegevens/Adressen, Dashboard/Bestellingen, ...)
 * leven in hun eigen bestanden en registreren zichzelf via het
 * mkcp_account_fragment_handlers-filter — zie includes/account-profile.php
 * en includes/account-orders.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Config ─────────────────────────────────────────────────────────────────

function mkcp_account_defaults() {
    return [
        // Admin-toggle: WooCommerce → Cart Popup → Account → Algemeen
        // (admin/views/settings-page.php, data-panel="account-general").
        // Standaard uit — een winkelier moet hem bewust aanzetten, net als
        // Cart Checkout's eigen checkout_enabled-toggle.
        'account_enabled' => false,

        // Per-module aan/uit (data-panel="account-modules") — allemaal
        // standaard AAN zodra Account zelf aanstaat, zodat een winkelier die
        // net de hoofdschakelaar omzet meteen de volledige ervaring ziet;
        // uitzetten is een bewuste keuze per module, niet andersom.
        'account_wishlist_enabled'      => true,
        'account_returns_enabled'       => true,
        'account_notifications_enabled' => true,
        'account_rewards_enabled'       => true,

        // Los van account_notifications_enabled hierboven (dat is het hele
        // in-app meldingencentrum-tabblad) — deze schakelt specifiek de
        // e-mail bij prijsdaling/weer-op-voorraad op wishlist-items
        // (includes/account-wishlist.php). Standaard aan, net als de rest.
        'account_wishlist_emails_enabled' => true,

        // Retourtermijn in dagen na "voltooid" — voorheen een hardcoded
        // constante in account-returns.php, nu instelbaar.
        'account_return_window_days' => 14,

        // Praktische plafonds/paginering — voorheen hardcoded constanten,
        // nu instelbaar per winkel (een winkel met veel terugkerende
        // zakelijke klanten wil bv. meer dan 20 adressen kunnen opslaan).
        'account_max_addresses'    => 20,
        'account_orders_per_page'  => 10,

        // 0 = nooit opruimen (huidig gedrag, ongewijzigd) — pas als een
        // winkelier hier bewust een aantal dagen instelt gaat de dagelijkse
        // cron (mkcp_account_notifications_cleanup) gelezen meldingen ouder
        // dan dat aantal dagen verwijderen. Ongelezen meldingen worden nooit
        // automatisch opgeruimd, ook niet als deze instelling aanstaat.
        'account_notification_retention_days' => 0,

        // Puntengrenzen voor de Beloningen-widget-tier (Account-plan, sectie
        // 5/10) — puur cosmetische gamification bovenop WooCommerce Points
        // and Rewards (die plugin kent zelf geen tiers), dus hier instelbaar
        // i.p.v. een bedrijfsregel die eigenlijk bij de winkelier hoort.
        'account_rewards_tier_silver_threshold' => 100,
        'account_rewards_tier_gold_threshold'   => 500,

        // Wishlist-meldingsmails — placeholders zoals overal elders in deze
        // plugin ({voornaam}, {product_naam}, ...), zie mkcp_account_
        // wishlist_email_placeholders() in account-wishlist.php.
        'account_wishlist_price_email_subject' => __( 'Prijsdaling op je verlanglijst', 'mk-cart-popup' ),
        'account_wishlist_price_email_body'    => __( "Hoi {voornaam},\n\n{product_naam} is nu {nieuwe_prijs} (was {oude_prijs}).\n\nBekijk je verlanglijst: {wishlist_url}\n\nGroet,\n{winkel_naam}", 'mk-cart-popup' ),
        'account_wishlist_stock_email_subject' => __( 'Weer op voorraad', 'mk-cart-popup' ),
        'account_wishlist_stock_email_body'    => __( "Hoi {voornaam},\n\n{product_naam} staat weer op voorraad.\n\nBekijk je verlanglijst: {wishlist_url}\n\nGroet,\n{winkel_naam}", 'mk-cart-popup' ),
    ];
}

function mkcp_account_config() {
    static $cfg = null;
    if ( $cfg !== null ) return $cfg;

    $saved = get_option( 'mkcp_account_settings', [] );
    $cfg   = wp_parse_args( $saved, mkcp_account_defaults() );
    return $cfg;
}

/**
 * Eén losse module (Wishlist/Retouren/Meldingen/Beloningen) aan/uit — los
 * van de hoofdschakelaar account_enabled hierboven. Aanroepers moeten zelf
 * ook nog mkcp_account_is_active() checken; dit is puur de per-module-laag
 * daar bovenop.
 */
function mkcp_account_module_enabled( string $module ): bool {
    $cfg = mkcp_account_config();
    return ! empty( $cfg[ 'account_' . $module . '_enabled' ] );
}

/**
 * True wanneer de custom Account-ervaring voor de huidige request actief
 * hoort te zijn: ingelogd, premium, hoofdschakelaar + eigen instelling aan.
 * Centrale gate — zowel de template_include-override als de AJAX-dispatcher
 * gebruiken deze, zodat er maar één plek is waar de voorwaarden staan.
 */
function mkcp_account_is_active(): bool {
    if ( ! is_user_logged_in() ) return false;
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;

    $cfg = mkcp_account_config();
    return ! empty( $cfg['account_enabled'] );
}


// ── Thema-hooks strippen op de Account-pagina ────────────────────────────────
//
// Letterlijk hetzelfde principe als mkcp_checkout_remove_theme_hooks()
// (includes/checkout-frontend.php) — zie daar voor de volledige toelichting
// bij de Reflection-aanpak. Bewust NIET herbruikt via een gedeelde functie
// met een parameter: de checkout-variant is al specifiek getest/gehard rond
// checkout-eigen randgevallen (BTW-switch, block-checkout-detectie) en moet
// onafhankelijk kunnen wijzigen zonder de Account-variant te raken.

function mkcp_account_remove_theme_hooks() {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
    if ( ! mkcp_account_is_active() ) return;

    $child_dir    = wp_normalize_path( get_stylesheet_directory() );
    $parent_dir   = wp_normalize_path( get_template_directory() );
    $theme_dirs   = array_unique( [ $child_dir, $parent_dir ] );
    $scaffold_dir = $child_dir . '/mk-cart-popup';

    // Zelfde escape hatch als bij checkout, hier voor de account-pagina.
    $exclude_names = apply_filters( 'mkcp_account_dequeue_exclude_functions', [] );

    // Zelfde beschermde "geef-de-weergavewaarde-terug"-filters als bij
    // checkout — geen enkel scenario waarin verwijdering hiervan een
    // layoutprobleem oplost, alleen scenario's waarin klantdata verdwijnt.
    $protected_hooks = [
        'woocommerce_get_item_data',
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
        // Never let this hardening feature take down the account page.
    }
}
add_action( 'wp', 'mkcp_account_remove_theme_hooks', 20 );


// ── Custom sjabloon op de Account-pagina ─────────────────────────────────────
//
// Niet ingelogd → nooit overschrijven: WooCommerce's eigen login/registratie-
// formulier op deze pagina blijft dan gewoon staan (zie Account-plan, sectie
// 12 — "coëxistentie"-uitgangspunt).

add_filter( 'template_include', function( $template ) {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return $template;
    if ( ! mkcp_account_is_active() ) return $template;

    return MKCP_PATH . 'templates/account-page.php';
} );


// ── Assets ─────────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function() {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
    if ( ! mkcp_account_is_active() ) return;

    wp_enqueue_style(
        'mk-cart-popup-account',
        MKCP_URL . 'assets/account.css',
        [],
        MKCP_VER
    );

    // Eigen kleuren (Instellingen → Styling) — zelfde mechanisme als checkout.css,
    // zodat de Account-pagina niet afhankelijk is van de laadvolgorde van een
    // andere stylesheet om de --mkcp-*-tokens te krijgen.
    if ( function_exists( 'mkcp_config' ) && function_exists( 'mkcp_style_inline_css' ) ) {
        $style_cfg = mkcp_config();
        wp_add_inline_style( 'mk-cart-popup-account', mkcp_style_inline_css( $style_cfg ) );
        if ( ! empty( $style_cfg['style_dark_mode_enabled'] ) && function_exists( 'mkcp_style_inline_css_dark' ) ) {
            wp_add_inline_style( 'mk-cart-popup-account', mkcp_style_inline_css_dark( $style_cfg ) );
        }
    }

    wp_enqueue_script(
        'mk-cart-popup-account',
        MKCP_URL . 'assets/account.js',
        [],
        MKCP_VER,
        true
    );

    wp_localize_script( 'mk-cart-popup-account', 'mkcp_account_params', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'mkcp_account_action' ),
        'default_route' => 'dashboard',
    ] );
} );


// ── AJAX-fragmentdispatcher ───────────────────────────────────────────────────
//
// Draait via admin-ajax.php — dat vuurt de 'wp'-actie NOOIT, dus
// is_account_page() is hierbinnen altijd false. Elke gate hier gaat daarom
// via mkcp_account_is_active() met een expliciete is_user_logged_in()-check
// erin, nooit via is_account_page(). Zie Account-plan, sectie 12, voor de
// volledige toelichting bij deze valkuil (dezelfde klasse bug die eerder al
// bij checkout/?wc-ajax= is opgetreden).
//
// Alleen wp_ajax_ (geen _nopriv-variant): Account is per definitie
// alleen-ingelogd.

add_action( 'wp_ajax_mkcp_account_get_fragment', 'mkcp_account_ajax_get_fragment' );

function mkcp_account_ajax_get_fragment() {
    // check_ajax_referer() default gedrag is een kaal wp_die(-1) — geen JSON.
    // De 'false' hieronder schakelt dat uit zodat we zelf een consistent
    // JSON-foutantwoord kunnen geven (Account-plan, sectie 15).
    if ( ! check_ajax_referer( 'mkcp_account_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'code' => 'session_expired' ], 403 );
    }

    if ( ! mkcp_account_is_active() ) {
        wp_send_json_error( [ 'code' => 'not_available' ], 403 );
    }

    $key = isset( $_POST['fragment'] ) ? sanitize_key( wp_unslash( $_POST['fragment'] ) ) : 'dashboard';

    // Losse modules kunnen uitstaan (data-panel="account-modules") — de
    // route is dan al niet in de nav te vinden, maar de server moet dit
    // sowieso zelf afdwingen (een verborgen navlink is UX, geen beveiliging).
    // Als "unknown_fragment" behandelen i.p.v. een apart foutcode: onthult
    // niet of een module bestaat maar uitstaat vs. helemaal niet bestaat.
    $module_routes = [ 'wishlist' => 'wishlist', 'notifications' => 'notifications' ];
    if ( isset( $module_routes[ $key ] ) && ! mkcp_account_module_enabled( $module_routes[ $key ] ) ) {
        wp_send_json_error( [ 'code' => 'unknown_fragment' ], 404 );
    }

    // Filter mkcp_account_fragment_handlers (Account-plan, sectie 10): elke
    // view registreert zichzelf via dit filter vanuit zijn eigen bestand
    // (account-profile.php, account-orders.php, ...) — hier staat bewust
    // geen enkel fragment hardcoded, dat voorkomt dat dit fundament-bestand
    // weer een groeiend allegaartje wordt.
    $handlers = apply_filters( 'mkcp_account_fragment_handlers', [] );

    if ( empty( $handlers[ $key ] ) || ! is_callable( $handlers[ $key ] ) ) {
        wp_send_json_error( [ 'code' => 'unknown_fragment' ], 404 );
    }

    wp_send_json_success( [
        'html' => call_user_func( $handlers[ $key ] ),
        'meta' => [ 'fragment' => $key ],
    ] );
}

