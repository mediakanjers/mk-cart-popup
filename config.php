<?php
/**
 * MK Cart Popup — Configuration
 *
 * mkcp_config() reads all settings from the WordPress database (wp_options).
 * Configure the plugin via WooCommerce → Cart Popup in wp-admin.
 *
 * Advanced: apply the 'mkcp_config' filter to override individual values
 * from your theme's functions.php — without touching this file:
 *
 *   add_filter( 'mkcp_config', function( $c ) {
 *       $c['title'] = 'Mijn winkelmand';
 *       return $c;
 *   } );
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Defaults ───────────────────────────────────────────────────────────────────
//
// These values are used when no setting has been saved yet (fresh install).
// You never need to edit these — use the admin settings page instead.

function mkcp_defaults() {
    return [
        'enabled'                 => true,
        'title'                   => __( 'Jouw winkelmand',                 'mk-cart-popup' ),
        'btn_checkout'            => __( 'Afrekenen',                       'mk-cart-popup' ),
        'col_product'             => __( 'Product',                         'mk-cart-popup' ),
        'col_total'               => __( 'Totaal',                          'mk-cart-popup' ),
        'empty_heading'           => __( 'Je winkelmand is leeg.',          'mk-cart-popup' ),
        'empty_button'            => __( 'Verder winkelen',                 'mk-cart-popup' ),
        'free_shipping_bar'       => false,
        'free_shipping_threshold' => 150,
        'shipping_note'           => __( 'Nog %s tot gratis verzending!',   'mk-cart-popup' ),
        'free_shipping_note'      => __( 'Je hebt gratis verzending!',      'mk-cart-popup' ),
        'redirect_cart'           => true,
        'redirect_cart_url'       => home_url( '/' ),
        'btw_split'               => false,
        'label_excl_tax'          => __( 'excl. BTW', 'mk-cart-popup' ),
        'label_incl_tax'          => __( 'incl. BTW', 'mk-cart-popup' ),
        'usps'                    => [
            [ 'icon' => 'shield', 'text' => __( 'Veilig betalen',         'mk-cart-popup' ) ],
            [ 'icon' => 'truck',  'text' => __( 'Snelle levering',        'mk-cart-popup' ) ],
            [ 'icon' => 'phone',  'text' => __( 'Gratis advies',          'mk-cart-popup' ) ],
        ],
        'analytics_enabled'       => false,
        'analytics_wc_stats'      => false,
        'analytics_debug'         => false,
        'min_order_amount'        => 0,
        'min_order_notice'        => __( 'Minimale bestelling: %1$s. Voeg nog %2$s toe.', 'mk-cart-popup' ),
        'show_coupon'             => true,
        'payment_icons'           => [],
        'delivery_preview_enabled' => false,
        'blocks'                  => [],
        'save_for_later'          => false,
        'stock_indicator'         => false,
        'stock_threshold'         => 5,
        'cart_icon_selector'      => '',
        'cart_badge_position'     => 'top-right',
        'cart_count_badge_enabled'  => false,
        'cart_count_badge_selector' => '',
        'cart_count_badge_position' => 'top-right',
        'save_cart_url'           => false,
        'save_cart_email'         => false,
        'save_cart_email_subject' => 'Jouw bewaarde winkelmand',
        'save_cart_email_body'    => "Je hebt een winkelmand bewaard. Klik op de knop hieronder om je producten terug te zetten.\n\nDe link is {expiry_days} dagen geldig.",
        'save_cart_expiry_days'   => 7,
        'crosssell_enabled'       => false,
        'crosssell_mode'          => 'category',
        'crosssell_limit'         => 3,
        'crosssell_title'         => 'Misschien ook interessant?',

        // Styling (premium) — waarden matchen de :root-defaults in
        // src/scss/cart-popup.scss, zodat een niet-aangepaste installatie er
        // pixel-gelijk uitziet aan vóór dit tabblad bestond.
        'style_accent'            => '#2e7d32',
        'style_bg'                => '#ffffff',
        'style_text'              => '#1a1a1a',
        'style_btn_text'          => '#ffffff',
        'style_border'            => '#cccccc',
        'style_danger'            => '#d32f2f',
        'style_width'             => 500,
        'style_btn_style'         => 'filled',
        'style_position'          => 'right',
        'style_expand_enabled'    => true,
        'style_dark_mode_enabled' => true,
        'mobile_app_experience'   => true,
    ];
}


// ── Styling: CSS custom-property overrides (premium) ────────────────────────
//
// Bouwt de :root-override voor de kleuren/breedte uit Instellingen → Styling.
// Wordt via wp_add_inline_style() ná assets/cart-popup.css geladen, zodat het
// de SCSS-defaults daar overschrijft — hetzelfde mechanisme dat de docblock
// van cart-popup.scss al documenteert voor handmatige thema-overrides.
// Waarden komen hier altijd via sanitize_hex_color() de instellingen in (zie
// admin/settings.php), dus geen extra escaping nodig bij het samenstellen.

function mkcp_style_inline_css( array $config ): string {
    $accent   = $config['style_accent']   ?? '#2e7d32';
    $bg       = $config['style_bg']       ?? '#ffffff';
    $text     = $config['style_text']     ?? '#1a1a1a';
    $btn_text = $config['style_btn_text'] ?? '#ffffff';
    $border   = $config['style_border']   ?? '#cccccc';
    $danger   = $config['style_danger']   ?? '#d32f2f';
    $width    = max( 360, min( 640, (int) ( $config['style_width'] ?? 500 ) ) );

    // Outline-knopstijl: transparante achtergrond, tekst + rand in de
    // hoofdkleur. De Knoptekstkleur-instelling wordt hier bewust genegeerd —
    // die is bedoeld voor tekst óp een gevulde knop en zou bij een
    // transparante achtergrond makkelijk onleesbaar worden (bv. de default
    // wit-op-wit).
    $outline    = ( $config['style_btn_style'] ?? 'filled' ) === 'outline';
    $btn_bg     = $outline ? 'transparent' : $accent;
    $btn_text   = $outline ? $accent : $btn_text;
    $btn_border = $outline ? "2px solid {$accent}" : 'none';

    // cart-popup.scss kent naast --mkcp-text nog drie kleurtokens die NIET
    // los instelbaar zijn (--mkcp-dark voor iets nadrukkelijkere tekst zoals
    // productprijzen, --mkcp-text-light voor gedempte/secundaire tekst, en
    // --mkcp-light voor hover-achtergronden) — die bleven altijd op hun
    // light-mode-default (#333/#888/#f5f5f5) staan. Onschuldig op een lichte
    // achtergrond, maar op de "Donker"-preset (of elke andere donkere
    // achtergrond) werd tekst die op --mkcp-dark/--mkcp-text-light leunt dan
    // bijna onleesbaar. color-mix() laat de browser dit automatisch afleiden
    // van de gekozen tekst-/achtergrondkleur, voor elke combinatie.
    $dark        = $text;
    // 65/35 i.p.v. 55/45 — met de standaardkleuren (#1a1a1a op #ffffff) haalde
    // de oude verhouding maar 3,90:1 (WCAG AA vereist 4,5:1 voor tekst); 65%
    // haalt op diezelfde standaardkleuren 5,41:1, met marge.
    $text_light  = "color-mix(in srgb, {$text} 65%, {$bg} 35%)";
    $hover_bg    = "color-mix(in srgb, {$text} 8%, {$bg} 92%)";
    $progress_bg = "color-mix(in srgb, {$accent} 15%, {$bg} 85%)";

    return ":root{"
        . "--mkcp-accent:{$accent};"
        . "--mkcp-primary:{$accent};"
        . "--mkcp-bg:{$bg};"
        . "--mkcp-text:{$text};"
        . "--mkcp-dark:{$dark};"
        . "--mkcp-text-light:{$text_light};"
        . "--mkcp-light:{$hover_bg};"
        . "--mkcp-progress-bg:{$progress_bg};"
        . "--mkcp-btn-p-bg:{$btn_bg};"
        . "--mkcp-btn-p-text:{$btn_text};"
        . "--mkcp-btn-p-border:{$btn_border};"
        . "--mkcp-light1:{$border};"
        . "--mkcp-light2:{$border};"
        . "--mkcp-danger:{$danger};"
        . "--mkcp-width:{$width}px;"
        . "}";
}


// ── Styling: donkere modus voor bezoekers (premium) ──────────────────────────
//
// Volgt automatisch de systeeminstelling van de bezoeker (prefers-color-scheme:
// dark) — los van de "Donker"-preset hierboven, die juist een handmatige,
// permanente keuze is voor álle bezoekers. Behoudt de eigen hoofd-/foutkleur
// (merkherkenning), maar vervangt achtergrond/tekst/randen door de neutrale
// donkere set uit de "Donker"-preset — die combinatie is al op WCAG AA
// gecontroleerd. Wordt ná mkcp_style_inline_css() geladen, dus wint alleen als
// @media (prefers-color-scheme: dark) matcht.
function mkcp_style_inline_css_dark( array $config ): string {
    $accent   = $config['style_accent'] ?? '#2e7d32';
    $bg       = '#1a1a1f';
    $text     = '#f0f0f5';
    $border   = '#3a3a42';
    $danger   = '#f87171';

    $outline    = ( $config['style_btn_style'] ?? 'filled' ) === 'outline';
    $btn_bg     = $outline ? 'transparent' : $accent;
    $btn_text   = $outline ? $accent : '#ffffff';
    $btn_border = $outline ? "2px solid {$accent}" : 'none';

    // 65/35 i.p.v. 55/45 — met de standaardkleuren (#1a1a1a op #ffffff) haalde
    // de oude verhouding maar 3,90:1 (WCAG AA vereist 4,5:1 voor tekst); 65%
    // haalt op diezelfde standaardkleuren 5,41:1, met marge.
    $text_light  = "color-mix(in srgb, {$text} 65%, {$bg} 35%)";
    $hover_bg    = "color-mix(in srgb, {$text} 8%, {$bg} 92%)";
    $progress_bg = "color-mix(in srgb, {$accent} 15%, {$bg} 85%)";

    return "@media (prefers-color-scheme: dark){:root{"
        . "--mkcp-accent:{$accent};"
        . "--mkcp-primary:{$accent};"
        . "--mkcp-bg:{$bg};"
        . "--mkcp-text:{$text};"
        . "--mkcp-dark:{$text};"
        . "--mkcp-text-light:{$text_light};"
        . "--mkcp-light:{$hover_bg};"
        . "--mkcp-progress-bg:{$progress_bg};"
        . "--mkcp-btn-p-bg:{$btn_bg};"
        . "--mkcp-btn-p-text:{$btn_text};"
        . "--mkcp-btn-p-border:{$btn_border};"
        . "--mkcp-light1:{$border};"
        . "--mkcp-light2:{$border};"
        . "--mkcp-danger:{$danger};"
        . "}}";
}


// ── Styling: kleuren van het thema detecteren (premium, tier 1) ─────────────
//
// Alleen betrouwbaar bij moderne blok-thema's met theme.json: die leggen hun
// palet vast als benoemde kleur-slots (bv. slug "primary", "background"), dus
// categorisatie komt bijna gratis mee via wp_get_global_settings(). Klassieke
// thema's zonder theme.json hebben geen enkele bron die zegt "dit ís de
// hoofdkleur" — daarvoor is er de losse live-scan-fallback in settings.js
// (tier 2, ongecategoriseerd) die de computed styles van de site zelf leest.
// Geeft bewust een LEGE array terug zodra de hoofdkleur niet herkend kon
// worden — een halve/geraden categorisatie is onbetrouwbaarder dan niks.
function mkcp_flatten_color_palette( $data ): array {
    $out = [];
    if ( ! is_array( $data ) ) return $out;
    if ( isset( $data['slug'] ) && isset( $data['color'] ) ) {
        return [ $data ];
    }
    foreach ( $data as $item ) {
        if ( is_array( $item ) ) {
            $out = array_merge( $out, mkcp_flatten_color_palette( $item ) );
        }
    }
    return $out;
}

function mkcp_detect_theme_colors(): array {
    if ( ! function_exists( 'wp_get_global_settings' ) ) return [];

    $entries = mkcp_flatten_color_palette( wp_get_global_settings( [ 'color', 'palette' ] ) );
    if ( ! $entries ) return [];

    $by_slug = [];
    foreach ( $entries as $entry ) {
        $slug = sanitize_key( $entry['slug'] ?? '' );
        $hex  = sanitize_hex_color( $entry['color'] ?? '' );
        if ( $slug && $hex ) $by_slug[ $slug ] = $hex;
    }
    if ( ! $by_slug ) return [];

    // Herkende slot-namen volgens block-thema-conventie, van meest naar minst
    // specifiek — de eerste match per veld wint.
    $needles = [
        'accent' => [ 'primary', 'accent', 'brand' ],
        'bg'     => [ 'background', 'base', 'bg' ],
        'text'   => [ 'foreground', 'text', 'contrast', 'dark' ],
    ];
    $result = [];
    foreach ( $needles as $field => $words ) {
        foreach ( $by_slug as $slug => $hex ) {
            foreach ( $words as $word ) {
                if ( strpos( $slug, $word ) !== false ) {
                    $result[ $field ] = $hex;
                    continue 3;
                }
            }
        }
    }

    return isset( $result['accent'] ) ? $result : [];
}


// ── Styling: kant-en-klare presets (premium) ─────────────────────────────────
//
// Eén klik vult de 6 kleurvelden + knopstijl in de admin-UI (admin/assets/
// settings.js, .js-mkcp-style-preset). Puur een startpunt — na toepassen zijn
// het gewoon de normale style_*-velden, de gebruiker kan alsnog los verder
// aanpassen. Kleurcombinaties zijn gecontroleerd op WCAG AA (4,5:1) tussen
// accent en knoptekstkleur, dezelfde toets als de contrastwaarschuwing zelf.
function mkcp_style_presets(): array {
    return [
        'standaard' => [
            'label'     => 'Standaard',
            'accent'    => '#2e7d32',
            'bg'        => '#ffffff',
            'text'      => '#1a1a1a',
            'btn_text'  => '#ffffff',
            'border'    => '#cccccc',
            'danger'    => '#d32f2f',
            'btn_style' => 'filled',
        ],
        'minimal' => [
            'label'     => 'Minimal',
            'accent'    => '#18181b',
            'bg'        => '#ffffff',
            'text'      => '#27272a',
            'btn_text'  => '#ffffff',
            'border'    => '#e4e4e7',
            'danger'    => '#dc2626',
            'btn_style' => 'outline',
        ],
        'bold' => [
            'label'     => 'Bold',
            'accent'    => '#2563eb',
            'bg'        => '#ffffff',
            'text'      => '#111827',
            'btn_text'  => '#ffffff',
            'border'    => '#d1d5db',
            'danger'    => '#dc2626',
            'btn_style' => 'filled',
        ],
        'pastel' => [
            'label'     => 'Pastel',
            'accent'    => '#e8b4bc',
            'bg'        => '#fffaf9',
            'text'      => '#3d2b32',
            'btn_text'  => '#4a2c3a',
            'border'    => '#f0d8dc',
            'danger'    => '#d9736a',
            'btn_style' => 'filled',
        ],
        'donker' => [
            'label'     => 'Donker',
            'accent'    => '#4f46e5',
            'bg'        => '#1a1a1f',
            'text'      => '#f0f0f5',
            'btn_text'  => '#ffffff',
            'border'    => '#3a3a42',
            'danger'    => '#f87171',
            'btn_style' => 'filled',
        ],
    ];
}


// ── Config reader ──────────────────────────────────────────────────────────────

function mkcp_config() {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $saved    = get_option( 'mkcp_settings', [] );
    $defaults = mkcp_defaults();

    // Merge saved values over defaults so new defaults are always available.
    $config = array_merge( $defaults, $saved );

    // Cast booleans (stored as '1'/'0' strings by HTML forms).
    foreach ( [ 'enabled', 'free_shipping_bar', 'redirect_cart', 'btw_split', 'analytics_enabled', 'analytics_wc_stats', 'analytics_debug', 'show_coupon', 'save_for_later', 'stock_indicator', 'save_cart_url', 'save_cart_email', 'crosssell_enabled', 'cart_count_badge_enabled', 'delivery_preview_enabled', 'style_expand_enabled', 'style_dark_mode_enabled', 'mobile_app_experience' ] as $key ) {
        $config[ $key ] = ! empty( $config[ $key ] );
    }
    $config['crosssell_limit'] = max( 1, min( 6, (int) ( $config['crosssell_limit'] ?? 3 ) ) );
    if ( ! in_array( $config['crosssell_mode'] ?? '', [ 'crosssells', 'category' ], true ) ) {
        $config['crosssell_mode'] = 'category';
    }

    // Fall back to defaults for text fields that must not be empty.
    foreach ( [ 'shipping_note', 'free_shipping_note', 'min_order_notice' ] as $key ) {
        if ( isset( $config[ $key ] ) && trim( (string) $config[ $key ] ) === '' ) {
            $config[ $key ] = $defaults[ $key ];
        }
    }

    // Ensure usps is always an array.
    if ( ! is_array( $config['usps'] ) ) {
        $config['usps'] = $defaults['usps'];
    }

    // Ensure payment_icons is always an array.
    if ( ! is_array( $config['payment_icons'] ) ) {
        $config['payment_icons'] = [];
    }

    // Ensure blocks is always an array.
    if ( ! is_array( $config['blocks'] ) ) {
        $config['blocks'] = [];
    }

    $cache = apply_filters( 'mkcp_config', $config );

    // Gate premium-only features when the active license doesn't cover them.
    if ( function_exists( 'mkcp_license_has' ) && ! mkcp_license_has( 'premium' ) ) {
        foreach ( [ 'btw_split', 'save_for_later', 'stock_indicator', 'analytics_enabled', 'analytics_wc_stats', 'analytics_debug', 'save_cart_url', 'save_cart_email', 'mobile_app_experience' ] as $k ) {
            $cache[ $k ] = false;
        }
        $cache['blocks'] = [];
    }

    return $cache;
}


// ── Icon helper ────────────────────────────────────────────────────────────────

/**
 * Returns all available payment icons as [ key => [ 'label', 'svg' ] ].
 * SVGs are self-contained, no external fonts or files needed.
 */
function mkcp_payment_icons() {
    return [

        'ideal' => [
            'label' => 'iDEAL',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="iDEAL"><rect width="750" height="471" rx="40" fill="#fff" stroke="#e0e0e0" stroke-width="8"/><rect x="60" y="100" width="84" height="271" fill="#cc0066"/><path d="M200 100h172c100 0 162 50 162 135 0 86-62 136-162 136H200V100zm60 54v163h112c64 0 102-30 102-82 0-51-38-81-102-81H260z" fill="#cc0066"/></svg>',
        ],

        'visa' => [
            'label' => 'Visa',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Visa"><rect width="750" height="471" rx="40" fill="#fff" stroke="#e0e0e0" stroke-width="8"/><rect y="0" width="750" height="471" rx="40" fill="#1A1F71"/><text x="375" y="300" font-family="Arial,sans-serif" font-size="210" font-weight="900" font-style="italic" fill="#F7B600" text-anchor="middle">VISA</text></svg>',
        ],

        'mastercard' => [
            'label' => 'Mastercard',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Mastercard"><rect width="750" height="471" rx="40" fill="#fff" stroke="#e0e0e0" stroke-width="8"/><circle cx="280" cy="235" r="170" fill="#EB001B"/><circle cx="470" cy="235" r="170" fill="#F79E1B"/><path d="M375 100c45 36 75 92 75 135s-30 99-75 135c-45-36-75-92-75-135s30-99 75-135z" fill="#FF5F00"/></svg>',
        ],

        'paypal' => [
            'label' => 'PayPal',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="PayPal"><rect width="750" height="471" rx="40" fill="#fff" stroke="#e0e0e0" stroke-width="8"/><text x="375" y="295" font-family="Arial,sans-serif" font-size="165" font-weight="700" fill="#003087" text-anchor="middle">Pay</text><text x="490" y="295" font-family="Arial,sans-serif" font-size="165" font-weight="700" fill="#009cde" text-anchor="middle">Pal</text></svg>',
        ],

        'maestro' => [
            'label' => 'Maestro',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Maestro"><rect width="750" height="471" rx="40" fill="#fff" stroke="#e0e0e0" stroke-width="8"/><circle cx="270" cy="235" r="175" fill="#0099DF"/><circle cx="480" cy="235" r="175" fill="#E30613" opacity=".9"/><path d="M375 90a175 175 0 0 1 0 291 175 175 0 0 1 0-291z" fill="#6C6BBD"/></svg>',
        ],

        'bancontact' => [
            'label' => 'Bancontact',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Bancontact"><rect width="750" height="471" rx="40" fill="#005498"/><rect x="0" y="280" width="750" height="191" rx="0" fill="#fff"/><rect x="0" y="280" width="750" height="191" rx="40" fill="#fff"/><text x="375" y="210" font-family="Arial,sans-serif" font-size="110" font-weight="700" fill="#fff" text-anchor="middle">bancontact</text><text x="375" y="410" font-family="Arial,sans-serif" font-size="100" font-weight="700" fill="#005498" text-anchor="middle">payconiq</text></svg>',
        ],

        'klarna' => [
            'label' => 'Klarna',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Klarna"><rect width="750" height="471" rx="40" fill="#FFB3C7"/><text x="375" y="310" font-family="Arial,sans-serif" font-size="195" font-weight="700" fill="#000" text-anchor="middle">klarna</text></svg>',
        ],

        'riverty' => [
            'label' => 'Riverty',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Riverty"><rect width="750" height="471" rx="40" fill="#fff" stroke="#e0e0e0" stroke-width="8"/><circle cx="175" cy="235" r="110" fill="#00BA9D"/><text x="430" y="290" font-family="Arial,sans-serif" font-size="155" font-weight="700" fill="#1D1D1B" text-anchor="middle">riverty</text></svg>',
        ],

        'amex' => [
            'label' => 'American Express',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="American Express"><rect width="750" height="471" rx="40" fill="#2E77BC"/><text x="375" y="215" font-family="Arial,sans-serif" font-size="100" font-weight="700" fill="#fff" text-anchor="middle">AMERICAN</text><text x="375" y="330" font-family="Arial,sans-serif" font-size="100" font-weight="700" fill="#fff" text-anchor="middle">EXPRESS</text></svg>',
        ],

        'applepay' => [
            'label' => 'Apple Pay',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Apple Pay"><rect width="750" height="471" rx="40" fill="#000"/><path d="M230 155c12-15 20-35 18-56-17 1-38 11-50 26-11 13-20 34-18 54 19 1 38-10 50-24z" fill="#fff"/><path d="M248 183c-28-2-51 16-64 16-14 0-34-15-56-15-28 1-55 16-69 42-30 52-8 128 21 170 14 21 31 44 53 43 21-1 29-14 55-14s33 14 55 13c23-1 38-22 52-43 16-25 23-49 23-50-1 0-44-17-44-66 0-42 34-62 36-63-20-30-51-33-62-33z" fill="#fff"/><text x="480" y="285" font-family="Arial,sans-serif" font-size="115" font-weight="600" fill="#fff" text-anchor="middle">Pay</text></svg>',
        ],

        'googlepay' => [
            'label' => 'Google Pay',
            'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 471" role="img" aria-label="Google Pay"><rect width="750" height="471" rx="40" fill="#fff" stroke="#e0e0e0" stroke-width="8"/><text x="375" y="295" font-family="Arial,sans-serif" font-size="160" font-weight="500" fill="#3C4043" text-anchor="middle"><tspan fill="#4285F4">G</tspan><tspan fill="#EA4335">o</tspan><tspan fill="#FBBC05">o</tspan><tspan fill="#4285F4">g</tspan><tspan fill="#34A853">l</tspan><tspan fill="#EA4335">e</tspan> Pay</text></svg>',
        ],

    ];
}


/**
 * Allowed HTML tags/attributes for rendering payment icon SVGs via wp_kses().
 */
function mkcp_svg_kses() {
    $common = [ 'xmlns' => [], 'viewbox' => [], 'role' => [], 'aria-label' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [], 'opacity' => [] ];
    return [
        'svg'    => $common,
        'rect'   => array_merge( $common, [ 'x' => [], 'y' => [], 'width' => [], 'height' => [], 'rx' => [], 'ry' => [] ] ),
        'circle' => array_merge( $common, [ 'cx' => [], 'cy' => [], 'r' => [] ] ),
        'path'   => array_merge( $common, [ 'd' => [] ] ),
        'text'   => array_merge( $common, [ 'x' => [], 'y' => [], 'font-family' => [], 'font-size' => [], 'font-weight' => [], 'font-style' => [], 'text-anchor' => [] ] ),
        'tspan'  => array_merge( $common, [ 'fill' => [] ] ),
    ];
}


// ── Block builder helpers ──────────────────────────────────────────────────────

/**
 * Zet een zone-key om naar een CSS-veilige modifier-klasse. Veld-zones
 * (bv. "field:billing_last_name") bevatten een dubbele punt, wat in een
 * class-attribuut prima is maar in CSS ontsnapt zou moeten worden om er
 * iets op te selecteren — vervang die daarom door een streepje. Een losse
 * "mkcp-zone-render--field"-marker-klasse maakt het bovendien mogelijk om
 * alle veld-gebonden blokken in één keer te stijlen, ongeacht welk veld.
 */
function mkcp_zone_render_classes( string $zone ): string {
    $classes = [ 'mkcp-zone-render', 'mkcp-zone-render--' . str_replace( ':', '-', $zone ) ];
    if ( strpos( $zone, 'field:' ) === 0 ) $classes[] = 'mkcp-zone-render--field';
    return implode( ' ', array_map( 'sanitize_html_class', $classes ) );
}

function mkcp_render_zone( $zone, $blocks ) {
    if ( empty( $blocks ) || ! is_array( $blocks ) ) return;

    // Collect enabled blocks for this zone first — skip empty renders.
    $zone_blocks = array_filter( $blocks, fn( $b ) =>
        ! empty( $b['enabled'] ) && ( $b['zone'] ?? '' ) === $zone
    );
    if ( ! $zone_blocks ) return;

    // Veld-blokken krijgen een data-attribuut met de veld-key erin, zodat de
    // front-end (assets/checkout-blocks.js) een al aanwezig blok herkent en
    // niet dubbel terugzet na een checkout-refresh.
    $field_attr = '';
    if ( strpos( $zone, 'field:' ) === 0 ) {
        $field_attr = ' data-mkcp-field="' . esc_attr( substr( $zone, strlen( 'field:' ) ) ) . '"';
    }

    echo '<div class="' . esc_attr( mkcp_zone_render_classes( $zone ) ) . '"' . $field_attr . '>';
    foreach ( $zone_blocks as $block ) {
        mkcp_render_block( $block );
    }
    echo '</div>';
}

/**
 * Zelfde als mkcp_render_zone(), maar geeft de HTML terug i.p.v. te echoën —
 * nodig om blokken te injecteren via filters (bv. woocommerce_form_field)
 * die een string terug moeten geven i.p.v. rechtstreeks te mogen echoën.
 */
function mkcp_render_zone_html( $zone, $blocks ): string {
    ob_start();
    mkcp_render_zone( $zone, $blocks );
    return (string) ob_get_clean();
}

/**
 * Zelfde als mkcp_render_zone(), maar als een losse tabelrij (<tr><td colspan>)
 * i.p.v. een <div> — nodig voor invoegpunten die binnen een <table>/<tbody>
 * van het WooCommerce besteloverzicht liggen (een <div> daar zou door de
 * browser buiten de tabel worden "gerepareerd" en dus verkeerd geplaatst raken).
 *
 * colspan="99": de standaard WooCommerce review-order-table heeft 2 kolommen
 * (product-name + product-total), maar sommige thema's (o.a. het MKTheme/
 * Mediakanjers-framework) leveren een eigen woocommerce/checkout/review-order.php
 * met 4 kolommen (thumbnail/naam/aantal/prijs). Een hardcoded colspan="2" spant
 * dan maar de helft van de rij, waardoor het blok scheef/ingeklemd oogt i.p.v.
 * de volle breedte. Een colspan die groter is dan het werkelijke aantal
 * kolommen wordt door de browser automatisch teruggeclipt tot wat er nog over
 * is in de rij (HTML-spec-gedrag) — colspan="99" spant dus altijd de volle
 * breedte, ongeacht of de tabel 2, 4 of een ander aantal kolommen heeft.
 */
function mkcp_render_zone_row( $zone, $blocks ) {
    if ( empty( $blocks ) || ! is_array( $blocks ) ) return;

    $zone_blocks = array_filter( $blocks, fn( $b ) =>
        ! empty( $b['enabled'] ) && ( $b['zone'] ?? '' ) === $zone
    );
    if ( ! $zone_blocks ) return;

    echo '<tr class="mkcp-zone-render-row mkcp-zone-render-row--' . esc_attr( str_replace( ':', '-', $zone ) ) . '"><td colspan="99">';
    foreach ( $zone_blocks as $block ) {
        mkcp_render_block( $block );
    }
    echo '</td></tr>';
}

/**
 * Rendert een content-builder-blok op de live site.
 *
 * Heeft een JS-tegenhanger — renderBlockHtml() in
 * src/admin/builder/index.js — die dezelfde bloktypes/velden voor de
 * live-preview builder rendert (JS kan deze PHP-functie niet hergebruiken
 * zonder een AJAX-rondje per toetsaanslag). Een nieuw bloktype of veld hier
 * moet ook daar worden toegevoegd, anders wijkt de live preview in de
 * builder af van wat er echt op de site verschijnt.
 */
function mkcp_render_block( array $block ) {
    $type = $block['type'] ?? 'text';
    switch ( $type ) {
        case 'text':
            $style = [];
            if ( ! empty( $block['align'] ) ) $style[] = 'text-align:' . esc_attr( $block['align'] );
            if ( ! empty( $block['color'] ) ) $style[] = 'color:' . esc_attr( $block['color'] );
            $style_attr = $style ? ' style="' . implode( ';', $style ) . '"' : '';
            echo '<div class="mkcp-block mkcp-block--text"' . $style_attr . '>' . wp_kses_post( $block['content'] ?? '' ) . '</div>';
            break;
        case 'divider':
            $style = in_array( $block['style'] ?? 'solid', [ 'solid', 'dashed', 'dotted' ], true )
                ? $block['style'] : 'solid';
            echo '<div class="mkcp-block mkcp-block--divider"><hr class="mkcp-divider mkcp-divider--' . esc_attr( $style ) . '"></div>';
            break;
        case 'usp':
            echo '<div class="mkcp-block mkcp-block--usp"><span class="mkcp-usp-icon">';
            mkcp_icon( $block['icon'] ?? 'check' );
            echo '</span><span class="mkcp-usp-text">' . esc_html( $block['text'] ?? '' ) . '</span></div>';
            break;
        case 'image':
            if ( ! empty( $block['url'] ) ) {
                $img = '<img class="mkcp-block-img" src="' . esc_url( $block['url'] ) . '" alt="' . esc_attr( $block['alt'] ?? '' ) . '" loading="lazy">';
                if ( ! empty( $block['link'] ) ) {
                    $img = '<a href="' . esc_url( $block['link'] ) . '">' . $img . '</a>';
                }
                echo '<div class="mkcp-block mkcp-block--image">' . $img . '</div>';
            }
            break;
        case 'banner':
            $variant = in_array( $block['variant'] ?? 'info', [ 'info', 'success', 'warning', 'danger' ], true ) ? $block['variant'] : 'info';
            echo '<div class="mkcp-block mkcp-block--banner mkcp-banner--' . esc_attr( $variant ) . '">' . esc_html( $block['text'] ?? '' ) . '</div>';
            break;
        case 'button':
            if ( ! empty( $block['text'] ) ) {
                $variant = in_array( $block['variant'] ?? 'primary', [ 'primary', 'secondary', 'ghost' ], true ) ? $block['variant'] : 'primary';
                $align   = in_array( $block['align'] ?? 'center', [ 'left', 'center', 'right' ], true ) ? $block['align'] : 'center';
                $href    = ! empty( $block['url'] ) ? ' href="' . esc_url( $block['url'] ) . '"' : '';
                echo '<div class="mkcp-block mkcp-block--button" style="text-align:' . esc_attr( $align ) . '">';
                echo '<a class="mkcp-btn-block mkcp-btn-block--' . esc_attr( $variant ) . '"' . $href . '>' . esc_html( $block['text'] ) . '</a>';
                echo '</div>';
            }
            break;
    }
}

/**
 * Saneert een JSON-array van content-builder-blokken (tekst/afbeelding/
 * button/usp/banner/divider), gedeeld door de winkelwagen-popup builder
 * én de checkout-builder.
 *
 * @param string            $json        Ruwe JSON uit het formulier.
 * @param array|callable|null $valid_zones Ofwel een vaste lijst toegestane
 *        zone-waarden (winkelwagen-popup — ongeldige/lege zone valt terug op
 *        de laatste zone in de lijst), ofwel een callable( string $zone ): bool
 *        voor dynamische zones (checkout — blokken met een ongeldige zone
 *        worden overgeslagen, want er is geen zinnig "default" invoegpunt
 *        te raden tussen structurele en per-veld zones). Null = de
 *        winkelwagen-popup-zones.
 */
function mkcp_sanitize_blocks( $json, $valid_zones = null ) {
    $blocks = json_decode( $json, true );
    if ( ! is_array( $blocks ) ) return [];
    $valid_types  = [ 'text', 'divider', 'usp', 'image', 'banner', 'button' ];
    $valid_styles = [ 'solid', 'dashed', 'dotted' ];

    $is_dynamic = is_callable( $valid_zones );
    $zone_list  = ( ! $is_dynamic && is_array( $valid_zones ) && $valid_zones )
        ? array_values( $valid_zones )
        : [ 'above-items', 'below-items', 'below-totals', 'below-payment', 'below-checkout' ];

    $clean = [];
    foreach ( $blocks as $block ) {
        if ( ! is_array( $block ) ) continue;
        $type     = in_array( $block['type'] ?? '', $valid_types, true ) ? $block['type'] : 'text';
        $zone_raw = (string) ( $block['zone'] ?? '' );
        if ( $is_dynamic ) {
            if ( ! call_user_func( $valid_zones, $zone_raw ) ) continue;
            $zone = $zone_raw;
        } else {
            $zone = in_array( $zone_raw, $zone_list, true ) ? $zone_raw : $zone_list[ count( $zone_list ) - 1 ];
        }
        $item = [
            'id'      => sanitize_text_field( $block['id'] ?? wp_generate_uuid4() ),
            'type'    => $type,
            'zone'    => $zone,
            'enabled' => ! empty( $block['enabled'] ),
        ];
        switch ( $type ) {
            case 'text':
                $item['content'] = wp_kses_post( $block['content'] ?? '' );
                $item['align']   = in_array( $block['align'] ?? '', [ '', 'left', 'center', 'right' ], true ) ? $block['align'] : '';
                $item['color']   = sanitize_hex_color( $block['color'] ?? '' ) ?: '';
                break;
            case 'divider':
                $item['style'] = in_array( $block['style'] ?? 'solid', $valid_styles, true ) ? $block['style'] : 'solid';
                break;
            case 'usp':
                $item['icon'] = sanitize_text_field( $block['icon'] ?? 'check' );
                $item['text'] = sanitize_text_field( $block['text'] ?? '' );
                break;
            case 'image':
                $item['url']  = esc_url_raw( $block['url']  ?? '' );
                $item['alt']  = sanitize_text_field( $block['alt']  ?? '' );
                $item['link'] = esc_url_raw( $block['link'] ?? '' );
                break;
            case 'banner':
                $item['text']    = sanitize_text_field( $block['text'] ?? '' );
                $item['variant'] = in_array( $block['variant'] ?? 'info', [ 'info', 'success', 'warning', 'danger' ], true ) ? $block['variant'] : 'info';
                break;
            case 'button':
                $item['text'] = sanitize_text_field( $block['text'] ?? '' );
                if ( $item['text'] === '' ) continue 2; // sla lege knoppen over
                $item['url']     = esc_url_raw( $block['url'] ?? '' );
                $item['variant'] = in_array( $block['variant'] ?? 'primary', [ 'primary', 'secondary', 'ghost' ], true ) ? $block['variant'] : 'primary';
                $item['align']   = in_array( $block['align'] ?? 'center', [ 'left', 'center', 'right' ], true ) ? $block['align'] : 'center';
                break;
        }
        $clean[] = $item;
    }
    return $clean;
}


// ── Checkout content-builder — zones ────────────────────────────────────────
//
// Naast de vaste structurele invoegpunten kan een blok ook direct na een
// specifiek checkout-veld worden geplaatst (zone "field:{veld_key}", bv.
// "field:billing_email"). Die veld-key wordt niet tegen een vaste lijst
// gevalideerd (formulieren kunnen custom velden hebben, zie de postcode-
// checker-integratie elders in deze plugin) maar tegen een streng patroon,
// zodat er nooit iets anders dan een onschuldige class/attribuut-achtige
// string in terecht kan komen.

function mkcp_checkout_structural_zones(): array {
    return [ 'above-order-review', 'below-order-review', 'above-payment', 'below-payment' ];
}

function mkcp_checkout_zone_is_valid( string $zone ): bool {
    if ( in_array( $zone, mkcp_checkout_structural_zones(), true ) ) return true;
    return (bool) preg_match( '/^field:(billing|shipping)_[a-z0-9_]+$/', $zone );
}

/**
 * Veldkeys voor de "onder dit veld"-dropdown in de checkout-builder —
 * live opgehaald bij WooCommerce zelf (incl. eventuele custom velden van
 * deze of andere plugins), met een vaste fallback-lijst zodat de builder
 * ook buiten een volledige checkout-request nog een bruikbare lijst toont.
 *
 * @return array<string,string> field_key => leesbaar label
 */
function mkcp_checkout_known_fields(): array {
    $fallback = [
        'first_name' => 'Voornaam', 'last_name' => 'Achternaam', 'company' => 'Bedrijfsnaam',
        'address_1' => 'Adres', 'address_2' => 'Adres 2', 'postcode' => 'Postcode',
        'house_number' => 'Huisnummer', 'house_number_suffix' => 'Toevoeging',
        'street_name' => 'Straatnaam', 'city' => 'Plaats', 'country' => 'Land', 'state' => 'Provincie',
        'phone' => 'Telefoon', 'email' => 'E-mailadres',
    ];

    $fields = [];
    foreach ( [ 'billing', 'shipping' ] as $group ) {
        foreach ( $fallback as $key => $label ) {
            if ( $group === 'shipping' && in_array( $key, [ 'phone', 'email' ], true ) ) continue;
            $fields[ $group . '_' . $key ] = ucfirst( $group === 'billing' ? 'Factuur' : 'Verzend' ) . ' — ' . $label;
        }
    }

    if ( function_exists( 'WC' ) && WC()->checkout() ) {
        $wc_fields = WC()->checkout()->get_checkout_fields();
        foreach ( [ 'billing', 'shipping' ] as $group ) {
            foreach ( (array) ( $wc_fields[ $group ] ?? [] ) as $key => $def ) {
                if ( ! preg_match( '/^(billing|shipping)_[a-z0-9_]+$/', $key ) ) continue;
                $label = sanitize_text_field( $def['label'] ?? $key );
                $fields[ $key ] = ucfirst( $group === 'billing' ? 'Factuur' : 'Verzend' ) . ' — ' . $label;
            }
        }
    }

    ksort( $fields );
    return $fields;
}


/**
 * ─── Tijdslot-hulpfuncties — gedeeld door afhalen (includes/pickup.php) en
 * bezorgen (includes/delivery-date.php) ──────────────────────────────────────
 *
 * Beide functies bestaan uit exact hetzelfde algoritme (tijdsloten genereren
 * uit een venster, controleren of een slot ver genoeg in de toekomst ligt) —
 * ooit alleen voor afhalen gebouwd, nu ook gebruikt voor bezorgtijdsloten per
 * verzendmethode. Eén bron van waarheid voorkomt dat de twee uit elkaar gaan
 * lopen als er ooit iets aan de generatie/validatie verandert.
 */

/**
 * Genereert tijdsloten (starttijden, "HH:MM") tussen een venster, in vaste
 * stappen. Een laatste onvolledig blok (dat over de eindtijd heen zou lopen)
 * wordt weggelaten.
 */
function mkcp_generate_time_slots( string $open, string $close, int $step_minutes ): array {
    $o = array_pad( explode( ':', $open ),  2, '0' );
    $c = array_pad( explode( ':', $close ), 2, '0' );
    $open_min  = ( (int) $o[0] ) * 60 + (int) $o[1];
    $close_min = ( (int) $c[0] ) * 60 + (int) $c[1];
    $step      = max( 5, $step_minutes );

    $slots = [];
    for ( $m = $open_min; $m + $step <= $close_min; $m += $step ) {
        $slots[] = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
    }
    return $slots;
}

/**
 * Controleert of een tijdslot ver genoeg in de toekomst ligt om de bestelling
 * nog te kunnen voorbereiden (bereidingstijd, in minuten). Alleen relevant als
 * het gekozen moment vandaag is — voor een latere dag is er per definitie
 * genoeg tijd. Zelfde server-side check als de client-side filtering in
 * assets/delivery-date.js (renderSlots, PREP_MINUTES) — hier nodig zodat een
 * klant die de JS-filtering omzeilt (of een sessie die al te lang openstond)
 * alsnog niet kan afrekenen met een tijdslot dat te dichtbij ligt.
 */
function mkcp_slot_is_reachable( string $ymd, string $slot, int $prep_minutes ): bool {
    $tz  = new DateTimeZone( wp_timezone_string() );
    $now = new DateTime( 'now', $tz );
    if ( $ymd !== $now->format( 'Y-m-d' ) ) return true;

    $parts   = array_pad( explode( ':', $slot ), 2, '0' );
    $slot_dt = clone $now;
    $slot_dt->setTime( (int) $parts[0], (int) $parts[1], 0 );

    $threshold = clone $now;
    $threshold->modify( '+' . max( 0, $prep_minutes ) . ' minutes' );

    return $slot_dt >= $threshold;
}

/**
 * Aantal (niet-geannuleerde/mislukte) orders voor een datum+tijdslot-combinatie,
 * voor de optionele capaciteitslimiet per tijdslot (transient, 45s — zelfde
 * opzet als mkcp_dd_orders_count_for_date()). De metasleutel-namen worden
 * meegegeven zodat afhalen en bezorgen elk hun eigen orderdata gebruiken
 * zonder elkaars capaciteit "op te eten".
 */
function mkcp_slot_count( string $ymd, string $slot, string $rate_id, string $date_meta_key, string $slot_meta_key, string $rate_meta_key ): int {
    if ( ! function_exists( 'wc_get_orders' ) ) return 0;

    $transient_key = 'mkcp_slotcnt_' . md5( $date_meta_key . $rate_id ) . '_' . $ymd . '_' . str_replace( ':', '', $slot );
    $cached = get_transient( $transient_key );
    if ( $cached !== false ) return (int) $cached;

    $excluded = [ 'wc-cancelled', 'wc-failed', 'wc-trash' ];
    $statuses = array_values( array_diff( array_keys( wc_get_order_statuses() ), $excluded ) );

    $ids = wc_get_orders( [
        'limit'      => -1,
        'return'     => 'ids',
        'status'     => $statuses,
        'meta_query' => [
            [ 'key' => $date_meta_key, 'value' => $ymd ],
            [ 'key' => $slot_meta_key, 'value' => $slot ],
            [ 'key' => $rate_meta_key, 'value' => $rate_id ],
        ],
    ] );

    $count = is_array( $ids ) ? count( $ids ) : 0;
    set_transient( $transient_key, $count, 45 );
    return $count;
}


/**
 * Zet de per-verzendmethode POST-velden (mkcp_dd_rule_*[rate_id]) om naar de
 * opgeslagen structuur:
 *   [ rate_id => [ 'enabled' => bool, 'cutoff_time' => 'HH:MM',
 *                  'lead_days' => int, 'shipping_days' => int[],
 *                  'slots_enabled' => bool, 'window_start' => 'HH:MM',
 *                  'window_end' => 'HH:MM', 'slot_minutes' => int,
 *                  'slot_capacity' => int, 'prep_minutes' => int ] ]
 * Alleen rate-ID's die daadwerkelijk bij een bestaande WooCommerce-
 * verzendmethode horen worden bewaard — voorkomt vervuiling door
 * verwijderde methodes of geknoei met het formulier.
 */
function mkcp_sanitize_dd_shipping_rules( array $post ): array {
    $known = function_exists( 'mkcp_dd_get_shipping_methods' ) ? mkcp_dd_get_shipping_methods() : [];
    if ( empty( $known ) ) return [];

    $enabled_map     = (array) ( $post['mkcp_dd_rule_enabled']       ?? [] );
    $cutoff_map      = (array) ( $post['mkcp_dd_rule_cutoff']        ?? [] );
    $lead_map        = (array) ( $post['mkcp_dd_rule_lead']          ?? [] );
    $days_map        = (array) ( $post['mkcp_dd_rule_days']          ?? [] );
    $slots_map       = (array) ( $post['mkcp_dd_rule_slots_enabled'] ?? [] );
    $window_start_map= (array) ( $post['mkcp_dd_rule_window_start']  ?? [] );
    $window_end_map  = (array) ( $post['mkcp_dd_rule_window_end']    ?? [] );
    $slot_min_map    = (array) ( $post['mkcp_dd_rule_slot_minutes']  ?? [] );
    $slot_cap_map    = (array) ( $post['mkcp_dd_rule_slot_capacity'] ?? [] );
    $prep_map        = (array) ( $post['mkcp_dd_rule_prep_minutes']  ?? [] );

    $rules = [];
    foreach ( array_keys( $known ) as $rate_id ) {
        if ( empty( $enabled_map[ $rate_id ] ) ) continue;

        $rules[ $rate_id ] = [
            'enabled'       => true,
            'cutoff_time'   => sanitize_text_field( $cutoff_map[ $rate_id ] ?? '12:00' ),
            'lead_days'     => max( 0, min( 30, (int) ( $lead_map[ $rate_id ] ?? 1 ) ) ),
            'shipping_days' => array_map( 'intval', (array) ( $days_map[ $rate_id ] ?? [] ) ),
            // Tijdsloten: alleen zinvol voor verzendmethodes die de shop zelf
            // rondbrengt (bv. lokale bezorging) — bij een vervoerder als PostNL
            // kan geen exact tijdstip beloofd worden, dus staat dit per methode
            // los aan/uit i.p.v. gekoppeld aan de algemene bezorgdatum-instelling.
            'slots_enabled' => ! empty( $slots_map[ $rate_id ] ),
            'window_start'  => sanitize_text_field( $window_start_map[ $rate_id ] ?? '09:00' ),
            'window_end'    => sanitize_text_field( $window_end_map[ $rate_id ]   ?? '17:00' ),
            'slot_minutes'  => max( 5, min( 480, (int) ( $slot_min_map[ $rate_id ] ?? 60 ) ) ),
            'slot_capacity' => max( 0, (int) ( $slot_cap_map[ $rate_id ] ?? 0 ) ), // 0 = onbeperkt
            'prep_minutes'  => max( 0, min( 1440, (int) ( $prep_map[ $rate_id ] ?? 60 ) ) ),
        ];
    }
    return $rules;
}

/**
 * Datums worden intern altijd als Y-m-d (ISO) opgeslagen/vergeleken — dat is
 * wat mkcp_dd_available_dates()/mkcp_pickup_available_dates() en de
 * blackout-checks (in_array tegen $cursor->format('Y-m-d')) verwachten. In de
 * admin-UI is dat voor een Nederlandse gebruiker echter een onnatuurlijk
 * formaat om zelf in te typen; deze twee helpers vertalen alleen bij het
 * weergeven (Y-m-d → DD-MM-JJJJ) en opslaan (DD-MM-JJJJ → Y-m-d) van de
 * "Geblokkeerde datums"-tekstvelden, zonder de interne opslag/vergelijkingen
 * elders aan te hoeven passen.
 */
function mkcp_date_display_to_ymd( string $display ): ?string {
    $display = trim( $display );
    if ( ! preg_match( '/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/', $display, $m ) ) return null;
    [ , $d, $mo, $y ] = $m;
    if ( ! checkdate( (int) $mo, (int) $d, (int) $y ) ) return null;
    return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
}

function mkcp_date_ymd_to_display( string $ymd ): string {
    if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) return $ymd;
    return sprintf( '%s-%s-%s', $m[3], $m[2], $m[1] );
}

/**
 * Parseert een tekstveld met één datum per regel (DD-MM-JJJJ, zoals getoond
 * in de admin-textarea's) naar een array gevalideerde, gesorteerde Y-m-d-
 * datums — gedeelde logica voor zowel de algemene bezorgdatum-blackout als
 * de blackout per afhaallocatie, zodat ongeldige regels op precies dezelfde
 * manier (stilzwijgend genegeerd) worden afgehandeld.
 */
function mkcp_sanitize_blackout_dates_field( string $raw ): array {
    $lines = preg_split( '/[\r\n]+/', $raw );
    $dates = array_values( array_filter( array_map( 'mkcp_date_display_to_ymd', array_map( 'sanitize_text_field', $lines ) ) ) );
    sort( $dates );
    return $dates;
}

/**
 * Sanitiseert de "Afhalen"-instellingen per verzendmethode (rate_id) —
 * adres, openingstijden per weekdag, cutoff/aanlooptijd, geblokkeerde
 * datums en optionele tijdsloten. Zelfde opzet als mkcp_sanitize_dd_shipping_rules(),
 * maar met meer velden per locatie (afhalen heeft een eigen adres/openingstijden
 * i.p.v. alleen een cutoff-override).
 */
function mkcp_sanitize_pickup_locations( array $post ): array {
    // Alleen "Local pickup"-methodes mogen als afhaallocatie opgeslagen worden
    // (zie mkcp_pickup_get_locations_methods()) — nooit een gewone flat_rate/
    // free_shipping-methode, ook al zou daar toevallig een 'enabled'-waarde
    // voor binnenkomen.
    $known = function_exists( 'mkcp_pickup_get_locations_methods' ) ? mkcp_pickup_get_locations_methods() : [];
    if ( empty( $known ) ) return [];

    $enabled_map      = (array) ( $post['mkcp_pu_enabled']       ?? [] );
    $display_name_map = (array) ( $post['mkcp_pu_display_name'] ?? [] );
    $address_map  = (array) ( $post['mkcp_pu_address']       ?? [] );
    $cutoff_map   = (array) ( $post['mkcp_pu_cutoff']        ?? [] );
    $lead_map     = (array) ( $post['mkcp_pu_lead']          ?? [] );
    $prep_map     = (array) ( $post['mkcp_pu_prep_minutes']  ?? [] );
    $blackout_map = (array) ( $post['mkcp_pu_blackout']      ?? [] );
    $slots_map    = (array) ( $post['mkcp_pu_slots_enabled'] ?? [] );
    $slot_min_map = (array) ( $post['mkcp_pu_slot_minutes']  ?? [] );
    $slot_cap_map = (array) ( $post['mkcp_pu_slot_capacity'] ?? [] );
    $hours_open_map    = (array) ( $post['mkcp_pu_hours_open']    ?? [] );
    $hours_close_map   = (array) ( $post['mkcp_pu_hours_close']   ?? [] );
    // Toggle in de admin-UI toont/leest "open" (checked = open, aansluitend bij
    // de groene toggle-stijl) — hier omgedraaid naar het intern opgeslagen
    // 'closed'-veld, dat verder overal (mkcp_pickup_slots_for_dow() e.a.) blijft
    // zoals het was.
    $hours_is_open_map = (array) ( $post['mkcp_pu_hours_is_open'] ?? [] );

    $locations = [];
    foreach ( array_keys( $known ) as $rate_id ) {
        if ( empty( $enabled_map[ $rate_id ] ) ) continue;

        $hours = [];
        for ( $dow = 0; $dow <= 6; $dow++ ) {
            $hours[ $dow ] = [
                'closed' => empty( $hours_is_open_map[ $rate_id ][ $dow ] ),
                'open'   => sanitize_text_field( $hours_open_map[ $rate_id ][ $dow ]  ?? '09:00' ),
                'close'  => sanitize_text_field( $hours_close_map[ $rate_id ][ $dow ] ?? '17:00' ),
            ];
        }

        $blackout = mkcp_sanitize_blackout_dates_field( wp_unslash( $blackout_map[ $rate_id ] ?? '' ) );

        $locations[ $rate_id ] = [
            'enabled'        => true,
            'display_name'   => sanitize_text_field( wp_unslash( $display_name_map[ $rate_id ] ?? '' ) ),
            'address'        => sanitize_textarea_field( wp_unslash( $address_map[ $rate_id ] ?? '' ) ),
            'hours'          => $hours,
            'cutoff_time'    => sanitize_text_field( $cutoff_map[ $rate_id ] ?? '16:00' ),
            'lead_days'      => max( 0, min( 30, (int) ( $lead_map[ $rate_id ] ?? 0 ) ) ),
            // Minimale tijd tussen bestelmoment en het vroegste tijdslot dat
            // dezelfde dag nog gekozen mag worden — zie mkcp_pickup_slots_for_dow()
            // en assets/delivery-date.js (PREP_MINUTES) voor waar dit wordt toegepast.
            'prep_minutes'   => max( 0, min( 1440, (int) ( $prep_map[ $rate_id ] ?? 60 ) ) ),
            'blackout_dates' => $blackout,
            'slots_enabled'  => ! empty( $slots_map[ $rate_id ] ),
            'slot_minutes'   => max( 5, min( 480, (int) ( $slot_min_map[ $rate_id ] ?? 60 ) ) ),
            'slot_capacity'  => max( 0, (int) ( $slot_cap_map[ $rate_id ] ?? 0 ) ), // 0 = onbeperkt
        ];
    }
    return $locations;
}


/**
 * Returns cross-sell products for the current cart contents.
 * Mode 'crosssells' uses WooCommerce Linked Products (set per product).
 * Mode 'category'   fetches published products from the same category.
 */
function mkcp_get_crosssell_products( $limit = 3, $mode = 'crosssells' ) {
    // In builder preview: return random published products so the section is visible.
    if ( ! empty( $GLOBALS['mkcp_builder_preview'] ) ) {
        $query = new WP_Query( [
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'orderby'             => 'rand',
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
        ] );
        $products = [];
        foreach ( $query->posts as $id ) {
            $p = wc_get_product( $id );
            if ( $p && $p->is_visible() ) $products[] = $p;
        }
        return $products;
    }

    $cart = WC()->cart;
    if ( ! $cart ) return [];

    $in_cart_ids = array_unique( array_column( $cart->get_cart(), 'product_id' ) );
    $candidate_ids = [];

    foreach ( $cart->get_cart() as $item ) {
        /** @var WC_Product $product */
        $product = $item['data'];
        if ( ! $product ) continue;

        if ( $mode === 'crosssells' ) {
            $ids = $product->get_cross_sell_ids();
        } else {
            $terms = get_the_terms( $product->get_id(), 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) continue;
            $query = new WP_Query( [
                'post_type'           => 'product',
                'posts_per_page'      => $limit * 4,
                'post__not_in'        => $in_cart_ids,
                'tax_query'           => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => wp_list_pluck( $terms, 'term_id' ) ] ],
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
            ] );
            $ids = $query->posts;
        }

        $candidate_ids = array_merge( $candidate_ids, (array) $ids );
    }

    $candidate_ids = array_unique( array_diff( $candidate_ids, $in_cart_ids ) );
    if ( empty( $candidate_ids ) ) return [];

    shuffle( $candidate_ids );
    $products = [];
    foreach ( array_slice( $candidate_ids, 0, $limit * 2 ) as $id ) {
        $p = wc_get_product( $id );
        if ( $p && $p->is_visible() && $p->is_in_stock() ) {
            $products[] = $p;
            if ( count( $products ) >= $limit ) break;
        }
    }
    return $products;
}


/**
 * Canonieke lijst USP-icoonsleutels + SVG-markup — enige bron van waarheid
 * aan de PHP-kant. admin/settings.php's $allowed_icons whitelist leest hier
 * de sleutels van af (array_keys), zodat er nog maar één plek is waar een
 * PHP-icoonsleutel toegevoegd/verwijderd moet worden.
 *
 * De live-preview builder (src/admin/builder/icons.js) houdt noodgedwongen
 * een eigen kopie van dezelfde SVG's aan — JS kan deze PHP-functie niet
 * hergebruiken zonder een AJAX-rondje per preview-update. Een nieuwe sleutel
 * hier moet dus ook daar worden toegevoegd, anders valt de live preview voor
 * die USP terug op het check-icoon terwijl het opgeslagen icoon wél correct
 * rendert op de live site.
 */
function mkcp_usp_icons(): array {
    return [
        'shield' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'truck'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'phone'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 14a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 3.12h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 10.7a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 18.92z"/></svg>',
        'star'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'check'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    ];
}

function mkcp_icon( $icon ) {
    $icons = mkcp_usp_icons();
    $svg   = $icons[ $icon ] ?? $icons['check'];

    echo wp_kses( $svg, [
        'svg'      => [ 'xmlns' => [], 'viewbox' => [], 'fill' => [], 'stroke' => [], 'stroke-width' => [], 'stroke-linecap' => [], 'stroke-linejoin' => [] ],
        'path'     => [ 'd' => [], 'fill' => [], 'stroke' => [] ],
        'polygon'  => [ 'points' => [] ],
        'polyline' => [ 'points' => [] ],
        'rect'     => [ 'x' => [], 'y' => [], 'width' => [], 'height' => [] ],
        'circle'   => [ 'cx' => [], 'cy' => [], 'r' => [] ],
        'line'     => [ 'x1' => [], 'y1' => [], 'x2' => [], 'y2' => [] ],
    ] );
}
