<?php
/**
 * MK Cart Popup — Account Frontend (Fase 1, stap 1: fundament)
 *
 * Zelfde patroon als includes/checkout-frontend.php: custom sjabloon zonder
 * get_header()/get_footer() op "Mijn account", met hash-routing en een AJAX-
 * fragmentdispatcher. Alleen actief voor ingelogde klanten met premium +
 * hoofdschakelaar (mkcp_is_enabled()) + account_enabled beide aan.
 *
 * Dit bestand is bewust alleen het fundament (routing-shell + dispatcher);
 * views registreren zichzelf via het mkcp_account_fragment_handlers-filter
 * (zie account-profile.php, account-orders.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Config ─────────────────────────────────────────────────────────────────

function mkcp_account_defaults() {
    return [
        // Admin-toggle (data-panel="account-general"). Standaard uit — bewust
        // aanzetten, net als checkout_enabled.
        'account_enabled' => false,

        // Per-module aan/uit (data-panel="account-modules"), standaard AAN
        // zodra Account aanstaat — uitzetten is een bewuste keuze per module.
        'account_wishlist_enabled'      => true,
        'account_returns_enabled'       => true,
        'account_notifications_enabled' => true,
        'account_rewards_enabled'       => true,
        'account_reviews_enabled'       => true,
        'account_newsletter_enabled'    => true,

        // Los van account_notifications_enabled (het meldingencentrum) —
        // schakelt specifiek de prijsdaling/weer-op-voorraad-mails
        // (includes/account-wishlist.php).
        'account_wishlist_emails_enabled' => true,

        // Retourtermijn in dagen na "voltooid".
        'account_return_window_days' => 14,

        // Vrije tekst boven Retourneren-tabblad; {dagen} wordt vervangen door
        // account_return_window_days (zie mkcp_account_return_process_text()
        // in account-returns.php).
        'account_return_process_text' => __( 'Je kunt tot {dagen} dagen na aflevering een retour aanvragen. We beoordelen je aanvraag meestal binnen enkele werkdagen — zodra we een beslissing hebben genomen, hoor je van ons.', 'mk-cart-popup' ),

        // Praktische plafonds/paginering, instelbaar per winkel.
        'account_max_addresses'    => 20,
        'account_orders_per_page'  => 10,

        // 0 = nooit opruimen. Standaard 7 i.p.v. "nooit" zodat gelezen
        // meldingen van oude bestellingen niet voor altijd blijven staan; de
        // dagelijkse cron ruimt alleen GELEZEN meldingen ouder dan dit op,
        // ongelezen nooit.
        'account_notification_retention_days' => 7,

        // Puntengrenzen voor de Beloningen-widget-tier — cosmetische
        // gamification bovenop WooCommerce Points and Rewards (kent zelf
        // geen tiers).
        'account_rewards_tier_silver_threshold' => 100,
        'account_rewards_tier_gold_threshold'   => 500,

        // Wishlist-meldingsmails — placeholders zie mkcp_account_
        // wishlist_email_placeholders() in account-wishlist.php.
        'account_wishlist_price_email_subject' => __( 'Prijsdaling op je verlanglijst', 'mk-cart-popup' ),
        'account_wishlist_price_email_body'    => __( "Hoi {voornaam},\n\n{product_naam} is nu {nieuwe_prijs} (was {oude_prijs}).\n\nBekijk je verlanglijst: {wishlist_url}\n\nGroet,\n{winkel_naam}", 'mk-cart-popup' ),
        'account_wishlist_stock_email_subject' => __( 'Weer op voorraad', 'mk-cart-popup' ),
        'account_wishlist_stock_email_body'    => __( "Hoi {voornaam},\n\n{product_naam} staat weer op voorraad.\n\nBekijk je verlanglijst: {wishlist_url}\n\nGroet,\n{winkel_naam}", 'mk-cart-popup' ),

        // Inlogscherm — achtergrondfoto, welkomsttekst en 3 voordelen, door de
        // winkelier in te vullen. Lege waarden vallen terug op vaste
        // standaardtekst (zie mkcp_account_login_render_panel()).
        'account_login_bg_image_id'   => 0,
        // Eigen logo i.p.v. generiek "Mijn Account"-icoon+label — attachment-ID,
        // 0 = terugval op dat generieke merkblok.
        'account_login_logo_image_id' => 0,
        'account_login_subtitle_text' => __( 'Log in om je bestellingen, retouren en wishlist te bekijken.', 'mk-cart-popup' ),
        'account_login_panel_title'   => __( 'Alles op één plek', 'mk-cart-popup' ),
        'account_login_usp_1'         => __( 'Bekijk je bestellingen en volg je pakket', 'mk-cart-popup' ),
        'account_login_usp_2'         => __( 'Bewaar favorieten in je wishlist', 'mk-cart-popup' ),
        'account_login_usp_3'         => __( 'Dien eenvoudig een retour in', 'mk-cart-popup' ),
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
 * Eén losse module (Wishlist/Retouren/...) aan/uit, los van account_enabled.
 * Aanroepers moeten zelf ook nog mkcp_account_is_active() checken.
 */
function mkcp_account_module_enabled( string $module ): bool {
    $cfg = mkcp_account_config();
    return ! empty( $cfg[ 'account_' . $module . '_enabled' ] );
}

/**
 * Zelfde voorwaarden als mkcp_account_is_active(), maar zonder inlog-check —
 * nodig voor plekken die ook aan uitgelogde bezoekers iets mogen tonen.
 */
function mkcp_account_feature_enabled(): bool {
    if ( ! function_exists( 'mkcp_is_enabled' ) || ! mkcp_is_enabled() ) return false;
    if ( ! function_exists( 'mkcp_license_has' ) || ! mkcp_license_has( 'premium' ) ) return false;

    $cfg = mkcp_account_config();
    return ! empty( $cfg['account_enabled'] );
}

/**
 * True wanneer de custom Account-ervaring actief hoort te zijn. Centrale
 * gate — template_include-override én AJAX-dispatcher gebruiken deze.
 */
function mkcp_account_is_active(): bool {
    if ( ! is_user_logged_in() ) return false;
    return mkcp_account_feature_enabled();
}


// ── Setup-check: WordPress/WooCommerce-vereisten buiten deze plugin om ──────
//
// Onze Account-ervaring vervangt de INHOUD van WooCommerce's "Mijn account"-
// pagina (via template_include hierboven, gate is_account_page()) — maar die
// gate bestaat pas als WooCommerce zelf weet welke pagina dat is
// (woocommerce_myaccount_page_id) én die pagina de [woocommerce_my_account]-
// shortcode bevat. Dat is geen instelling van ons; op een verse install (of
// na een verwijderde pagina) kan dat ontbreken, en dan doet deze hele module
// stilzwijgend niets — vandaar een expliciete check + 1-klik-fix in het
// admin-dashboard i.p.v. de winkelier dat zelf te laten uitzoeken.
function mkcp_account_setup_status(): array {
    $page_id = (int) get_option( 'woocommerce_myaccount_page_id' );
    $page    = $page_id ? get_post( $page_id ) : null;
    $page_ok = $page
        && 'publish' === $page->post_status
        && has_shortcode( $page->post_content, 'woocommerce_my_account' );

    return [
        'myaccount_page_ok'  => $page_ok,
        'myaccount_page_id'  => $page_id,
        'myaccount_edit_url' => $page_id ? (string) get_edit_post_link( $page_id, 'raw' ) : '',
        'myaccount_view_url' => $page_id ? (string) get_permalink( $page_id ) : '',
        // WC-endpoints (/orders/, /edit-address/, ...) hangen af van query-
        // vars, die alleen werken met een "mooie" permalinkstructuur — met
        // "Gewoon" (plain) breekt niet alleen ons account-gedeelte, maar
        // WooCommerce's eigen "Mijn account" net zo goed.
        'pretty_permalinks'  => '' !== get_option( 'permalink_structure' ),
        // Puur informatief, geen "fout" — deze bepalen alleen of KLANTEN
        // zelf een account kunnen aanmaken (via checkout of op de account-
        // pagina), niet of onze module werkt.
        'wc_registration_myaccount' => 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ),
        'wc_registration_checkout'  => 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ),
        'wc_generate_password'      => 'yes' === get_option( 'woocommerce_registration_generate_password' ),
        'wc_generate_username'      => 'yes' === get_option( 'woocommerce_registration_generate_username' ),
    ];
}

// Expliciete allowlist voor mkcp_toggle_wc_option hieronder — dit endpoint mag
// NOOIT een willekeurige optienaam uit $_POST aannemen, alleen deze bekende,
// boolean yes/no-WooCommerce-opties die in de Installatie-check/checkout-tab
// als live toggle worden getoond.
const MKCP_WC_TOGGLEABLE_OPTIONS = [
    'woocommerce_enable_signup_and_login_from_checkout',
    'woocommerce_enable_myaccount_registration',
    'woocommerce_registration_generate_password',
    'woocommerce_registration_generate_username',
];

// Generieke brug naar WooCommerce's eigen account-opties: winkelier hoeft
// hiervoor niet meer naar WooCommerce → Instellingen → Account, kan het hier
// meteen omzetten. Schrijft rechtstreeks in WooCommerce's optie-namespace i.p.v.
// deze te dupliceren in mkcp_account_settings — anders ontstaan er twee bronnen
// van waarheid die uit elkaar kunnen lopen (bv. als de winkelier het toch via
// het WC-scherm zelf wijzigt).
add_action( 'wp_ajax_mkcp_toggle_wc_option', function() {
    check_ajax_referer( 'mkcp_account_setup', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $option = sanitize_key( wp_unslash( $_POST['option'] ?? '' ) );
    if ( ! in_array( $option, MKCP_WC_TOGGLEABLE_OPTIONS, true ) ) {
        wp_send_json_error( [ 'message' => __( 'Onbekende instelling.', 'mk-cart-popup' ) ] );
    }

    $new_value = 'yes' === get_option( $option ) ? 'no' : 'yes';
    update_option( $option, $new_value );

    wp_send_json_success( [ 'value' => $new_value ] );
} );

add_action( 'wp_ajax_mkcp_account_create_myaccount_page', function() {
    check_ajax_referer( 'mkcp_account_setup', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $status = mkcp_account_setup_status();
    if ( $status['myaccount_page_ok'] ) {
        wp_send_json_error( [ 'message' => __( 'Er staat al een geldige Mijn account-pagina ingesteld.', 'mk-cart-popup' ) ] );
    }

    $page_id = wp_insert_post( [
        'post_title'   => __( 'Mijn account', 'mk-cart-popup' ),
        'post_content' => '[woocommerce_my_account]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ], true );

    if ( is_wp_error( $page_id ) ) {
        wp_send_json_error( [ 'message' => $page_id->get_error_message() ] );
    }

    update_option( 'woocommerce_myaccount_page_id', $page_id );

    wp_send_json_success( [
        'editUrl' => get_edit_post_link( $page_id, 'raw' ),
        'viewUrl' => get_permalink( $page_id ),
    ] );
} );


// ── Thema-hooks strippen op de Account-pagina ────────────────────────────────
//
// Zelfde principe als mkcp_checkout_remove_theme_hooks() (checkout-frontend.php,
// zie daar voor de Reflection-aanpak). Bewust niet gedeeld via een parameter:
// de checkout-variant is al gehard rond checkout-eigen randgevallen en moet
// onafhankelijk kunnen wijzigen.

function mkcp_account_remove_theme_hooks() {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
    // Ook uitgelogd (login-kaart) sweepen, niet alleen ingelogd — anders
    // bleef de kaart kwetsbaar voor thema-hooks (notices, cart-fragments).
    if ( ! mkcp_account_is_active() && ! mkcp_account_login_should_style() ) return;

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
// Ingelogd → volledige app-shell. Niet ingelogd → eigen distraction-free
// sjabloon (account-login-page.php) rond WooCommerce's ONGEWIJZIGDE login-/
// registratieformulier — zie sectie hieronder voor het "coëxistentie"-uitgangspunt.

add_filter( 'template_include', function( $template ) {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return $template;
    if ( mkcp_account_is_active() ) return MKCP_PATH . 'templates/account-page.php';
    if ( mkcp_account_login_should_style() ) return MKCP_PATH . 'templates/account-login-page.php';

    return $template;
} );


// ── Gestylede login-kaart op de Account-pagina (uitgelogde staat) ────────────
//
// Bewust GEEN vervanging van WooCommerce's login-/registratieformulier — dat
// blijft functioneel ongemoeid, enkel een merk-passende kaart eromheen (via
// form-login.php's before/after-hooks) plus CSS-reskin (account-login.css).
// De template hierboven regelt alleen het weglaten van thema-chrome.

function mkcp_account_login_should_style(): bool {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return false;
    if ( is_user_logged_in() ) return false;
    return function_exists( 'mkcp_account_feature_enabled' ) && mkcp_account_feature_enabled();
}

// Het Mediakanjers-thema overschrijft WC's notice-templates met eigen markup
// (.woo_custom_message > .inner) die altijd class "woocommerce-message"
// gebruikt, óók voor fouten — onze .woocommerce-error/-message-restyling kan
// die structuur niet raken en fout/succes niet onderscheiden. Hier daarom
// terugvallen op WC's kale eigen templates, die we zelf stylen.
add_filter( 'woocommerce_locate_template', function( $template, $template_name, $template_path, $default_path = '' ) {
    if ( ! mkcp_account_login_should_style() ) return $template;
    if ( ! in_array( $template_name, [ 'notices/error.php', 'notices/success.php', 'notices/notice.php' ], true ) ) {
        return $template;
    }
    if ( ! $default_path ) {
        $default_path = WC()->plugin_path() . '/templates/';
    }
    return trailingslashit( $default_path ) . $template_name;
}, 10, 4 );


// ── Inlog-rate-limiting (brute-force-bescherming) ────────────────────────────
//
// WooCommerce/WP kennen van zichzelf geen lockout na mislukte inlogpogingen.
// Hardcoded i.p.v. instelbaar — beveiligingsdefault, geen smaakkeuze.
function mkcp_account_login_rl_config(): array {
    return [
        'max_attempts'    => 5,
        'window_seconds'  => 15 * MINUTE_IN_SECONDS,
        'lockout_seconds' => 15 * MINUTE_IN_SECONDS,
    ];
}

// Bewust ALLEEN REMOTE_ADDR (niet X-Forwarded-For/Client-IP) — die laatste
// zijn door de bezoeker zelf te vervalsen via de request-headers, wat de
// lockout net zo makkelijk zou omzeilen als hij moet tegenhouden.
function mkcp_account_login_rl_key(): string {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
    return 'mkcp_login_rl_' . md5( $ip );
}

// Vuurt vóór wp_signon() — geblokkeerde bezoeker bereikt de echte
// authenticatie niet, melding komt via WC's eigen Exception-afhandeling naar
// buiten (dus automatisch met onze restyling).
add_filter( 'woocommerce_process_login_errors', function( WP_Error $err, $username, $password ) {
    if ( ! mkcp_account_feature_enabled() ) return $err;
    if ( $err->get_error_code() ) return $err; // een eerdere validatiefout gaat voor

    $data = get_transient( mkcp_account_login_rl_key() );
    if ( is_array( $data ) && ! empty( $data['locked_until'] ) && $data['locked_until'] > time() ) {
        $minutes = (int) ceil( ( $data['locked_until'] - time() ) / MINUTE_IN_SECONDS );
        $err->add( 'mkcp_login_locked', sprintf(
            /* translators: %d = aantal minuten */
            _n(
                'Te veel mislukte inlogpogingen. Probeer het over %d minuut opnieuw.',
                'Te veel mislukte inlogpogingen. Probeer het over %d minuten opnieuw.',
                $minutes,
                'mk-cart-popup'
            ),
            $minutes
        ) );
    }

    return $err;
}, 20, 3 );

add_action( 'wp_login_failed', function( $username ) {
    if ( ! mkcp_account_feature_enabled() ) return;

    $cfg  = mkcp_account_login_rl_config();
    $key  = mkcp_account_login_rl_key();
    $data = get_transient( $key );
    $data = is_array( $data ) ? $data : [ 'count' => 0, 'locked_until' => 0 ];

    $data['count']++;
    if ( $data['count'] >= $cfg['max_attempts'] ) {
        $data['locked_until'] = time() + $cfg['lockout_seconds'];
        $data['count']        = 0; // teller herstart voor de cyclus ná de lockout
    }

    set_transient( $key, $data, $cfg['window_seconds'] );
} );

add_action( 'wp_login', function() {
    delete_transient( mkcp_account_login_rl_key() );
} );

// Vuurt vlak vóór wp_login_failed — WP heeft de fout dan al gezet. Wij tellen
// hier NIET mee (dat blijft de taak van wp_login_failed), enkel de huidige
// stand aflezen om te tonen hoeveel pogingen er nog over zijn.
add_filter( 'authenticate', function( $user ) {
    if ( ! mkcp_account_feature_enabled() ) return $user;
    if ( ! is_wp_error( $user ) || ! $user->get_error_code() ) return $user;
    if ( $user->get_error_code() === 'mkcp_login_locked' ) return $user; // onze eigen lockout-melding, niet aanvullen

    $cfg   = mkcp_account_login_rl_config();
    $data  = get_transient( mkcp_account_login_rl_key() );
    $count = ( is_array( $data ) ? (int) ( $data['count'] ?? 0 ) : 0 ) + 1; // + deze mislukking, die wp_login_failed zo dadelijk pas telt
    $remaining = max( 0, $cfg['max_attempts'] - $count );

    $code = $user->get_error_code();
    $msg  = $user->get_error_message( $code );

    if ( $remaining > 0 ) {
        $msg .= ' ' . sprintf(
            /* translators: %d = aantal resterende pogingen */
            _n(
                'Nog %d poging over voordat je tijdelijk wordt geblokkeerd.',
                'Nog %d pogingen over voordat je tijdelijk wordt geblokkeerd.',
                $remaining,
                'mk-cart-popup'
            ),
            $remaining
        );
    } else {
        $minutes = (int) ( $cfg['lockout_seconds'] / MINUTE_IN_SECONDS );
        $msg .= ' ' . sprintf(
            /* translators: %d = aantal minuten */
            _n(
                'Dit was je laatste poging — probeer het over %d minuut opnieuw.',
                'Dit was je laatste poging — probeer het over %d minuten opnieuw.',
                $minutes,
                'mk-cart-popup'
            ),
            $minutes
        );
    }

    return new WP_Error( $code, $msg );
}, 999 );


/**
 * Eigen logo — BOVEN de kaart, buiten de witte achtergrond, gecentreerd.
 * Rendert niets als er geen logo is ingesteld.
 */
function mkcp_account_login_render_external_logo() {
    $logo_id  = (int) ( mkcp_account_config()['account_login_logo_image_id'] ?? 0 );
    $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
    if ( ! $logo_url ) return;
    echo '<img class="mkcp-account-login-external-logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '">';
}

/**
 * Merk-header bovenaan elke gestylede kaart — gedeeld tussen login en
 * "wachtwoord vergeten". Rendert niets als er al een extern logo boven de
 * kaart staat (zou dubbelop zijn).
 */
function mkcp_account_login_render_brand() {
    if ( ! empty( mkcp_account_config()['account_login_logo_image_id'] ) ) return;
    ?>
    <div class="mkcp-account-login-brand">
        <span class="mkcp-account-login-brand__mark" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
        <span class="mkcp-account-login-brand__label"><?php esc_html_e( 'Mijn Account', 'mk-cart-popup' ); ?></span>
    </div>
    <?php
}

/**
 * Voordelenpaneel (rechterkant van de split-screen kaart) — gedeeld tussen
 * login én "wachtwoord vergeten", zodat beide pagina's echt exact dezelfde
 * kaart-opmaak hebben i.p.v. twee losse varianten.
 */
function mkcp_account_login_render_panel() {
    $cfg = mkcp_account_config();

    // Iconen vast (zelfde paths als sidebar in account-page.php), alleen
    // teksten zijn instelbaar. `?:` i.p.v. alleen wp_parse_args() omdat die
    // laatste enkel ONTBREKENDE sleutels vult, geen leeggemaakte velden.
    $benefits = [
        [ 'icon' => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>', 'text' => $cfg['account_login_usp_1'] ?: __( 'Bekijk je bestellingen en volg je pakket', 'mk-cart-popup' ) ],
        [ 'icon' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>', 'text' => $cfg['account_login_usp_2'] ?: __( 'Bewaar favorieten in je wishlist', 'mk-cart-popup' ) ],
        [ 'icon' => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>', 'text' => $cfg['account_login_usp_3'] ?: __( 'Dien eenvoudig een retour in', 'mk-cart-popup' ) ],
    ];

    // Paneel blijft altijd volledig dekkend — de achtergrondfoto zit er
    // ACHTER en is alleen zichtbaar tussen kaart- en schermrand.
    ?>
    <div class="mkcp-account-login-panel" aria-hidden="true">
        <div class="mkcp-account-login-panel__inner">
            <p class="mkcp-account-login-panel__title"><?php echo esc_html( $cfg['account_login_panel_title'] ?: __( 'Alles op één plek', 'mk-cart-popup' ) ); ?></p>
            <ul class="mkcp-account-login-panel__list">
                <?php foreach ( $benefits as $benefit ) : ?>
                    <li>
                        <span class="mkcp-account-login-panel__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $benefit['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></svg></span>
                        <span><?php echo esc_html( $benefit['text'] ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

// WC print notices standaard zelf via 'woocommerce_output_all_notices' op
// dezelfde before-hooks (prio 10) — dat zou de melding IN de kaart plaatsen
// (tussen merk-blok en <h2>). Default eruit, zelf printen vóór de kaart opent.
add_action( 'wp', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    remove_action( 'woocommerce_before_customer_login_form', 'woocommerce_output_all_notices', 10 );
    remove_action( 'woocommerce_before_lost_password_form', 'woocommerce_output_all_notices', 10 );
}, 20 );

// Split-screen: formulier links (.formcol, WC's <h2>/<form> komt hier
// automatisch IN terecht), voordelenpaneel rechts (.panel). Bij ingeschakelde
// "Registreren"-kolom verbergt CSS :has(.col2-set) het paneel weer.
add_action( 'woocommerce_before_customer_login_form', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    mkcp_account_login_render_external_logo();
    wc_print_notices();
    echo '<div class="mkcp-account-login-card mkcp-account-login-card--split">';
    echo '<div class="mkcp-account-login-formcol">';
    mkcp_account_login_render_brand();
} );

add_action( 'woocommerce_after_customer_login_form', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    echo '</div>'; // /.formcol
    mkcp_account_login_render_panel();
    echo '</div>'; // /.card--split
} );

// Vuurt vlak ná de "Login"-<h2>, vóór de velden — binnenin het <form>.
/**
 * current_time() i.p.v. date() zodat dit de tijdzone van de winkel volgt,
 * niet die van de server.
 */
function mkcp_account_login_daypart(): string {
    $hour = (int) current_time( 'G' );
    if ( $hour < 6 )  return 'night';
    if ( $hour < 12 ) return 'morning';
    if ( $hour < 18 ) return 'afternoon';
    return 'evening';
}

add_action( 'woocommerce_login_form_start', function() {
    if ( ! mkcp_account_login_should_style() ) return;

    $greetings = [
        'night'     => __( 'Goedenacht!', 'mk-cart-popup' ),
        'morning'   => __( 'Goedemorgen!', 'mk-cart-popup' ),
        'afternoon' => __( 'Goedemiddag!', 'mk-cart-popup' ),
        'evening'   => __( 'Goedenavond!', 'mk-cart-popup' ),
    ];
    $greeting = $greetings[ mkcp_account_login_daypart() ];

    // Eigen <span> zodat account-login.js de begroeting client-side kan
    // vervangen door "Welkom terug, {naam}!" (via onthouden localStorage-
    // gebruikersnaam, zie rememberUsername) zonder de rest te raken.
    $subtitle_text = mkcp_account_config()['account_login_subtitle_text'] ?: __( 'Log in om je bestellingen, retouren en wishlist te bekijken.', 'mk-cart-popup' );

    echo '<p class="mkcp-account-login-subtitle"><span class="mkcp-account-login-greeting">' . esc_html( $greeting ) . '</span> '
        . esc_html( $subtitle_text ) . '</p>';
} );

// Vertrouwenssignaal vlak vóór </form>, zelfde patroon als checkout's
// .mkcp-login-trust. Vermeldt ook het maximum aantal pogingen vooraf, i.p.v.
// pas na de eerste mislukking.
add_action( 'woocommerce_login_form_end', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    $max = mkcp_account_login_rl_config()['max_attempts'];
    echo '<p class="mkcp-account-login-trust"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> '
        . esc_html__( 'Veilig inloggen', 'mk-cart-popup' ) . ' &middot; '
        . esc_html( sprintf(
            /* translators: %d = maximum aantal inlogpogingen */
            __( 'max. %d pogingen', 'mk-cart-popup' ),
            $max
        ) ) . '</p>';
} );

// "Wachtwoord vergeten" is een ANDER WC-sjabloon met eigen hookpaar, krijgt
// dezelfde split-screen kaart-opmaak als hierboven. Heeft zelf geen <h2>,
// die voegen we hier toe.
add_action( 'woocommerce_before_lost_password_form', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    mkcp_account_login_render_external_logo();
    wc_print_notices();
    echo '<div class="mkcp-account-login-card mkcp-account-login-card--split">';
    echo '<div class="mkcp-account-login-formcol">';
    mkcp_account_login_render_brand();
    echo '<h2>' . esc_html__( 'Wachtwoord vergeten', 'mk-cart-popup' ) . '</h2>';
} );

add_action( 'woocommerce_after_lost_password_form', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    // Zelfde link/klasse als het "reset-mail verstuurd"-scherm hieronder.
    echo '<p class="mkcp-account-login-back-to-login"><a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">&larr; ' . esc_html__( 'Terug naar inloggen', 'mk-cart-popup' ) . '</a></p>';
    echo '</div>'; // /.formcol
    mkcp_account_login_render_panel();
    echo '</div>'; // /.card--split
} );

// "Reset-mail verstuurd"-scherm — NÓG een ander WC-sjabloon (bereikt via
// redirect ?reset-link-sent=true) met een vroege `return;`, waardoor de
// before/after_lost_password_form-hooks hierboven NOOIT vuren. Vandaar deze
// eigen before/after-hooks, anders stond de tekst kaal buiten elke kaart.
add_action( 'woocommerce_before_lost_password_confirmation_message', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    // Kan niet vóór de succes-melding (die print WC al eerder, buiten een
    // hook om) — staat daardoor ná i.p.v. vóór zoals op de andere pagina's.
    mkcp_account_login_render_external_logo();
    echo '<div class="mkcp-account-login-card mkcp-account-login-card--split">';
    echo '<div class="mkcp-account-login-formcol">';
    mkcp_account_login_render_brand();
    echo '<h2>' . esc_html__( 'Bijna klaar', 'mk-cart-popup' ) . '</h2>';
} );

add_action( 'woocommerce_after_lost_password_confirmation_message', function() {
    if ( ! mkcp_account_login_should_style() ) return;
    echo '<p class="mkcp-account-login-back-to-login"><a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">&larr; ' . esc_html__( 'Terug naar inloggen', 'mk-cart-popup' ) . '</a></p>';
    echo '</div>'; // /.formcol
    mkcp_account_login_render_panel();
    echo '</div>'; // /.card--split
} );

add_filter( 'body_class', function( $classes ) {
    if ( mkcp_account_login_should_style() ) $classes[] = 'mkcp-account-login-page';
    return $classes;
} );

add_action( 'wp_enqueue_scripts', function() {
    if ( ! mkcp_account_login_should_style() ) return;

    wp_enqueue_style(
        'mk-cart-popup-account-login',
        MKCP_URL . 'assets/account-login.css',
        [],
        MKCP_VER
    );

    wp_enqueue_script(
        'mk-cart-popup-account-login',
        MKCP_URL . 'assets/account-login.js',
        [],
        MKCP_VER,
        true
    );
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

    // Eigen kleuren — zelfde mechanisme als checkout.css, onafhankelijk van
    // de laadvolgorde van een andere stylesheet voor de --mkcp-*-tokens.
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
// Draait via admin-ajax.php — dat vuurt 'wp' NOOIT, dus is_account_page() is
// hierbinnen altijd false. Gates gaan daarom via mkcp_account_is_active()
// (met is_user_logged_in()), nooit via is_account_page() — dezelfde klasse
// bug die eerder bij checkout/?wc-ajax= optrad.
//
// Alleen wp_ajax_ (geen _nopriv): Account is per definitie alleen-ingelogd.

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

    // Losse modules kunnen uitstaan; server moet dit sowieso zelf afdwingen
    // (verborgen navlink is UX, geen beveiliging). Als "unknown_fragment"
    // behandelen i.p.v. apart foutcode: onthult niet uit vs. bestaat niet.
    $module_routes = [ 'wishlist' => 'wishlist', 'notifications' => 'notifications', 'returns' => 'returns' ];
    if ( isset( $module_routes[ $key ] ) && ! mkcp_account_module_enabled( $module_routes[ $key ] ) ) {
        wp_send_json_error( [ 'code' => 'unknown_fragment' ], 404 );
    }

    // Elke view registreert zichzelf via dit filter vanuit zijn eigen bestand
    // — hier bewust geen enkel fragment hardcoded, om dit fundament-bestand
    // klein te houden.
    $handlers = apply_filters( 'mkcp_account_fragment_handlers', [] );

    if ( empty( $handlers[ $key ] ) || ! is_callable( $handlers[ $key ] ) ) {
        wp_send_json_error( [ 'code' => 'unknown_fragment' ], 404 );
    }

    wp_send_json_success( [
        'html' => call_user_func( $handlers[ $key ] ),
        'meta' => [ 'fragment' => $key ],
    ] );
}

