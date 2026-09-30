<?php
/**
 * MK Cart Popup — Rate limiting
 *
 * Lichte, IP- (of user-)gebaseerde doorloop-limiter voor publieke endpoints
 * die gevoelig zijn voor misbruik door scripts/bots: add-to-cart-flood,
 * kortingscode-brute-force, en endpoints die e-mail naar een door de
 * aanvrager opgegeven adres versturen (mail-relay-misbruik).
 *
 * Bewust géén perfecte/atomaire teller — transients volstaan als drempel
 * tegen scripts, niet als garantie tegen een geavanceerde, verspreide
 * aanval. Voor gerichte/zware aanvallen blijft bescherming op hosting-/
 * CDN-niveau (bv. Cloudflare) nodig.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Haalt een zo betrouwbaar mogelijk client-adres op (via WooCommerce's eigen,
 * al proxy-bewuste methode) om als teller-sleutel te gebruiken.
 */
function mkcp_rate_limit_identifier(): string {
    if ( class_exists( 'WC_Geolocation' ) ) {
        $ip = WC_Geolocation::get_ip_address();
        if ( $ip ) return $ip;
    }
    return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Sliding-window rate limit. Retourneert true zolang de aanvrager onder de
 * limiet blijft (en telt de poging meteen mee), false zodra de limiet is
 * bereikt. De TTL wordt bij elke poging opnieuw op $window_seconds gezet,
 * dus een rustige periode van $window_seconds laat de teller vanzelf weer
 * resetten — geen aparte cleanup-cron nodig.
 *
 * @param string $bucket         Naam van de actie (bv. 'add_to_cart', 'coupon').
 * @param int    $max            Max. aantal pogingen binnen het venster.
 * @param int    $window_seconds Venstergrootte in seconden.
 * @param string $identifier     Optioneel: eigen sleutel i.p.v. IP-adres (bv. user_id bij een ingelogde-only actie).
 */
function mkcp_rate_limit_allow( string $bucket, int $max, int $window_seconds, string $identifier = '' ): bool {
    if ( ! $identifier ) $identifier = mkcp_rate_limit_identifier();
    if ( ! $identifier ) return true; // geen bruikbare sleutel te bepalen: niet blokkeren

    $key   = 'mkcp_rl_' . $bucket . '_' . md5( $identifier );
    $count = (int) get_transient( $key );

    if ( $count >= max( 1, $max ) ) return false;

    set_transient( $key, $count + 1, max( 5, $window_seconds ) );
    return true;
}

/**
 * Dunne wrapper om mkcp_rate_limit_allow() die ook de instelling
 * ('security_ratelimit_enabled') en een admin/shopmanager-vrijstelling
 * meeneemt — zodat elke aanroepplek zelf niet steeds dezelfde twee checks
 * hoeft te herhalen.
 */
function mkcp_rate_limit_guard( string $bucket, int $max, int $window_seconds, string $identifier = '' ): bool {
    $config = function_exists( 'mkcp_config' ) ? mkcp_config() : [];
    if ( empty( $config['security_ratelimit_enabled'] ) ) return true;
    if ( current_user_can( 'manage_woocommerce' ) ) return true;

    return mkcp_rate_limit_allow( $bucket, $max, $window_seconds, $identifier );
}
