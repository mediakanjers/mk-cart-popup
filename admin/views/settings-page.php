<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php

$c              = mkcp_config();
$cfg_co         = mkcp_checkout_config();
$co_enabled     = ! empty( $cfg_co['checkout_enabled'] );
$saved          = isset( $_GET['saved'] );
$active_tab     = sanitize_key( $_GET['tab']     ?? 'dashboard' );
$active_product = sanitize_key( $_GET['product'] ?? 'popup' );

// Een tab-/product-combinatie die elkaar tegenspreekt (bv. een checkout-*
// tab met product=popup, zoals via een verouderde/handmatige URL) laat de
// verkeerde navigatiestrip zien bij het verkeerde paneel — de JS die de
// twee normaal synchroniseert (zie settings.js, activateProduct()) grijpt
// hier niet in, want die reageert alleen op een klik of de eerste product-
// bepaling bij het laden, niet op deze mismatch zelf. Hier alvast server-
// side reconciliëren voorkomt dat inconsistente scherm, ongeacht hoe de
// bezoeker op zo'n URL terechtkomt.
if ( 0 === strpos( $active_tab, 'checkout-' ) ) {
    $active_product = 'checkout';
} elseif ( 'popup' !== $active_product && 'checkout' !== $active_product ) {
    $active_product = 'popup';
}
$logo_url_co    = ! empty( $cfg_co['header_logo_id'] )
    ? ( wp_get_attachment_image_url( $cfg_co['header_logo_id'], 'medium' ) ?: '' )
    : '';
$license_data   = mkcp_license_get_data();
$license_tier   = $license_data['tier'];
$license_valid  = $license_data['valid'];
$license_key    = (string) get_option( 'mkcp_license_key', '' );
$theme_pref     = get_user_meta( get_current_user_id(), 'mkcp_admin_theme', true );
if ( ! in_array( $theme_pref, [ 'light', 'dark' ], true ) ) $theme_pref = 'auto';
$action      = admin_url( 'admin.php?page=mkcp-settings' );
$version     = MKCP_VER;
$wc_active   = class_exists( 'WooCommerce' );
$is_child    = get_template() !== get_stylesheet();
$theme_dir   = get_stylesheet_directory() . '/mk-cart-popup';
$has_style           = file_exists( $theme_dir . '/style.css' );
$has_hooks           = file_exists( $theme_dir . '/cart-hooks.php' );
$has_checkout_hooks  = file_exists( $theme_dir . '/checkout-hooks.php' );
$has_checkout_css    = file_exists( $theme_dir . '/checkout.css' );
$has_script          = file_exists( $theme_dir . '/script.js' );
$has_checkout_js     = file_exists( $theme_dir . '/checkout.js' );
$update_json = MKCP_PATH . 'mk-cart-popup-update.json';
$update_data = file_exists( $update_json ) ? json_decode( file_get_contents( $update_json ), true ) : [];
$latest_ver  = $update_data['version'] ?? $version;
$has_update  = version_compare( $latest_ver, $version, '>' );

$nav_items = [
    'dashboard' => [ 'label' => 'Dashboard',        'icon' => 'grid' ],
    'general'   => [ 'label' => 'Algemeen',          'icon' => 'sliders' ],
    'behavior'  => [ 'label' => 'Cart Gedrag',       'icon' => 'shopping-cart' ],
    'shipping'  => [ 'label' => 'Verzending',        'icon' => 'truck' ],
    'checkout'  => [ 'label' => 'Checkout',          'icon' => 'credit-card' ],
    'builder'   => [ 'label' => 'Content Builder',   'icon' => 'layout' ],
    'analytics' => [ 'label' => 'Analytics',         'icon' => 'bar-chart' ],
];

// SVG icons
$icons = [
    'grid'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
    'sliders'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
    'shopping-cart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
    'truck'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    'calendar'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'credit-card'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'bar-chart'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
    'code'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
    'refresh-cw'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
    'check'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    'zap'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
    'shield'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    'alert'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    'package'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    'external'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
    'star'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    'phone'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 14a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 3.12h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 10.7a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 18.92z"/></svg>',
    'layout'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/></svg>',
    'plus-circle'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
    'drag'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="7" r="1" fill="currentColor" stroke="none"/><circle cx="9" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="9" cy="17" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="17" r="1" fill="currentColor" stroke="none"/></svg>',
    'edit'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
    'trash'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
    'eye'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    'bookmark'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>',
    'layers'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
    'image'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
    'type'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
    'minus'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    'x'             => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    'pipette'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19.4 4.6a2 2 0 0 1 0 2.8l-1.6 1.6-2.8-2.8 1.6-1.6a2 2 0 0 1 2.8 0Z"/><path d="M17.8 9l-9 9L5 19l1-3.8 9-9"/><path d="M3 21l2.5-1"/></svg>',
    'copy'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
    'sun'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
    'moon'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
    'monitor'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
];

?>
<style>
/* ── Product switcher ─────────────────────────────────────────────────────── */
.mkcp-product-switcher{display:flex;flex-direction:column;gap:4px;padding:10px 10px 12px;border-bottom:1px solid var(--mkcp-ui-border);position:sticky;top:0;z-index:3;background:var(--mkcp-ui-surface)}
.mkcp-product-btn{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;border:1px solid transparent;background:transparent;cursor:pointer;text-align:left;width:100%;transition:background .15s,border-color .15s;color:var(--mkcp-ui-text2)}
.mkcp-product-btn:hover{background:var(--mkcp-ui-bg2)}
.mkcp-product-btn.is-active{background:var(--mkcp-ui-bg2);border-color:var(--mkcp-ui-accent);color:var(--mkcp-ui-text)}
.mkcp-product-icon{width:30px;height:30px;background:var(--mkcp-ui-bg);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid var(--mkcp-ui-border);transition:background .15s,border-color .15s}
.mkcp-product-btn.is-active .mkcp-product-icon{background:var(--mkcp-ui-accent);border-color:var(--mkcp-ui-accent);color:#fff}
.mkcp-product-btn.is-off:not(.is-active){opacity:.5}
.mkcp-product-icon svg{width:15px;height:15px;stroke:currentColor}
.mkcp-product-info{display:flex;flex-direction:column;gap:2px}
.mkcp-product-info strong{font-size:12px;font-weight:600;line-height:1.3;display:block}
.mkcp-product-info small{font-size:10px;color:var(--mkcp-ui-text3);line-height:1.3;display:flex;align-items:center;gap:4px}
.mkcp-status-pill{display:inline-flex;align-items:center;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:1px 5px;border-radius:10px;line-height:1.5;flex-shrink:0}
.mkcp-status-pill--on{background:#dcfce7;color:#16a34a}
.mkcp-status-pill--off{background:var(--mkcp-ui-bg2);color:var(--mkcp-ui-text3);border:1px solid var(--mkcp-ui-border)}

/* ── Fblock styles (footer block builder) ─────────────────────────────────── */
.mkcp-fblock{background:var(--mkcp-ui-bg2);border:1px solid var(--mkcp-ui-border);border-radius:8px;overflow:hidden}
.mkcp-fblock-head{display:flex;align-items:center;gap:10px;padding:9px 13px;background:var(--mkcp-ui-bg);border-bottom:1px solid var(--mkcp-ui-border)}
.mkcp-fblock-drag{cursor:grab;color:var(--mkcp-ui-text3);font-size:15px;line-height:1;flex-shrink:0}
.mkcp-fblock-label{flex:1;font-size:12px;color:var(--mkcp-ui-text2);display:flex;align-items:center;gap:7px;cursor:pointer;user-select:none}
.mkcp-fblock-delete{background:none;border:none;color:var(--mkcp-ui-text3);cursor:pointer;font-size:13px;padding:2px 4px;line-height:1}
.mkcp-fblock-delete:hover{color:#ef4444}
.mkcp-fblock-body{padding:11px 13px;display:flex;flex-direction:column;gap:8px}
.mkcp-fblock-body input[type="text"],.mkcp-fblock-body textarea,.mkcp-fblock-body select{font-size:12px}
</style>

    <div id="mkcp-admin-wrap">

        <!-- ── Sidebar ──────────────────────────────────────────────────────────── -->

        <aside class="mkcp-sidebar">

            <!-- Product switcher -->
            <div class="mkcp-product-switcher">
                <button type="button" class="mkcp-product-btn <?php echo $active_product === 'popup' ? 'is-active' : ''; ?> <?php echo ! $c['enabled'] ? 'is-off' : ''; ?>" data-product="popup">
                    <span class="mkcp-product-icon"><?php echo $icons['shopping-cart']; ?></span>
                    <span class="mkcp-product-info">
                        <strong>Cart Popup</strong>
                        <small>Winkelwagen popup <span class="mkcp-status-pill <?php echo $c['enabled'] ? 'mkcp-status-pill--on' : 'mkcp-status-pill--off'; ?>"><?php echo $c['enabled'] ? 'Aan' : 'Uit'; ?></span></small>
                    </span>
                </button>
                <button type="button" class="mkcp-product-btn <?php echo $active_product === 'checkout' ? 'is-active' : ''; ?> <?php echo ! $co_enabled ? 'is-off' : ''; ?>" data-product="checkout">
                    <span class="mkcp-product-icon"><?php echo $icons['credit-card']; ?></span>
                    <span class="mkcp-product-info">
                        <strong>Cart Checkout</strong>
                        <small>Checkout pagina <span class="mkcp-status-pill <?php echo $co_enabled ? 'mkcp-status-pill--on' : 'mkcp-status-pill--off'; ?>"><?php echo $co_enabled ? 'Aan' : 'Uit'; ?></span></small>
                    </span>
                </button>
            </div>

            <!-- Cart Popup nav -->
            <div class="mkcp-nav-wrap" id="mkcp-nav-wrap-popup" <?php echo $active_product === 'checkout' ? 'style="display:none"' : ''; ?>>
            <nav class="mkcp-nav mkcp-nav--popup" id="mkcp-nav-popup">
                <div class="mkcp-nav-section">Overzicht</div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'dashboard' ? 'is-active' : ''; ?>" data-tab="dashboard">
                    <?php echo $icons['grid']; ?>
                    Dashboard
                    <?php if ( $c['enabled'] ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-section">Instellingen</div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'general' ? 'is-active' : ''; ?>" data-tab="general">
                    <?php echo $icons['sliders']; ?>
                    Algemeen
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'styling' ? 'is-active' : ''; ?>" data-tab="styling">
                    <?php echo $icons['image']; ?>
                    Styling
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'behavior' ? 'is-active' : ''; ?>" data-tab="behavior">
                    <?php echo $icons['shopping-cart']; ?>
                    Cart Gedrag
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'shipping' ? 'is-active' : ''; ?>" data-tab="shipping">
                    <?php echo $icons['truck']; ?>
                    Verzending
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout' ? 'is-active' : ''; ?>" data-tab="checkout">
                    <?php echo $icons['credit-card']; ?>
                    Checkout
                </div>

                <div class="mkcp-nav-section mkcp-nav-section--builder">Content Builder</div>

                <div class="mkcp-nav-item mkcp-nav-item--builder <?php echo $active_tab === 'builder' ? 'is-active' : ''; ?>" data-tab="builder">
                    <?php echo $icons['layout']; ?>
                    <span>Content Builder</span>
                    <?php if ( ! empty( $c['blocks'] ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'crosssell' ? 'is-active' : ''; ?>" data-tab="crosssell">
                    <?php echo $icons['shopping-cart']; ?>
                    Cross-selling
                    <?php if ( ! empty( $c['crosssell_enabled'] ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-section">Integraties</div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'analytics' ? 'is-active' : ''; ?>" data-tab="analytics">
                    <?php echo $icons['bar-chart']; ?>
                    Analytics
                    <?php if ( ! empty( $c['analytics_enabled'] ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

            </nav>
            </div><!-- /.mkcp-nav-wrap -->

            <!-- Cart Checkout nav -->
            <div class="mkcp-nav-wrap" id="mkcp-nav-wrap-checkout" <?php echo $active_product === 'popup' ? 'style="display:none"' : ''; ?>>
            <nav class="mkcp-nav mkcp-nav--checkout" id="mkcp-nav-checkout">
                <div class="mkcp-nav-section">Overzicht</div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout-dashboard' ? 'is-active' : ''; ?>" data-tab="checkout-dashboard">
                    <?php echo $icons['grid']; ?>
                    Dashboard
                </div>

                <div class="mkcp-nav-section">Instellingen</div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout-general' ? 'is-active' : ''; ?>" data-tab="checkout-general">
                    <?php echo $icons['sliders']; ?>
                    Algemeen
                    <?php if ( $co_enabled ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout-settings' ? 'is-active' : ''; ?>" data-tab="checkout-settings">
                    <?php echo $icons['credit-card']; ?>
                    Header &amp; Footer
                    <?php if ( ! empty( $cfg_co['header_enabled'] ) || ! empty( $cfg_co['footer_enabled'] ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout-builder' ? 'is-active' : ''; ?>" data-tab="checkout-builder">
                    <?php echo $icons['layers']; ?>
                    Content Builder
                    <?php if ( ! empty( array_filter( $cfg_co['checkout_blocks'] ?? [], fn( $b ) => ! empty( $b['enabled'] ) ) ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout-styling' ? 'is-active' : ''; ?>" data-tab="checkout-styling">
                    <?php echo $icons['code']; ?>
                    Styling
                    <?php if ( ! empty( $cfg_co['dequeue_theme_css'] ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout-delivery' ? 'is-active' : ''; ?>" data-tab="checkout-delivery">
                    <?php echo $icons['package']; ?>
                    Bezorgen / Afhalen
                    <?php if ( ! empty( $cfg_co['delivery_date_enabled'] ) || ! empty( $cfg_co['pickup_enabled'] ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

                <div class="mkcp-nav-item <?php echo $active_tab === 'checkout-thankyou' ? 'is-active' : ''; ?>" data-tab="checkout-thankyou">
                    <?php echo $icons['zap']; ?>
                    Bedankt-pagina
                    <?php if ( ! empty( $cfg_co['thankyou_enabled'] ) ) : ?>
                        <span class="mkcp-nav-dot"></span>
                    <?php endif; ?>
                </div>

            </nav>
            </div><!-- /.mkcp-nav-wrap -->

            <div class="mkcp-sidebar-footer">
                <div class="mkcp-theme-switcher" role="group" aria-label="Kleurmodus admin">
                    <button type="button" class="mkcp-theme-btn <?php echo $theme_pref === 'auto' ? 'is-active' : ''; ?>" data-theme="auto" title="Systeeminstelling volgen">
                        <?php echo $icons['monitor']; ?>
                    </button>
                    <button type="button" class="mkcp-theme-btn <?php echo $theme_pref === 'light' ? 'is-active' : ''; ?>" data-theme="light" title="Altijd licht">
                        <?php echo $icons['sun']; ?>
                    </button>
                    <button type="button" class="mkcp-theme-btn <?php echo $theme_pref === 'dark' ? 'is-active' : ''; ?>" data-theme="dark" title="Altijd donker">
                        <?php echo $icons['moon']; ?>
                    </button>
                </div>
                <div class="mkcp-footer-links">
                    <button type="button" class="mkcp-docs-link <?php echo $active_tab === 'overrides' ? 'is-active' : ''; ?>" data-goto="overrides">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        Theme Overrides
                        <?php if ( $has_style || $has_hooks ) : ?>
                            <span class="mkcp-nav-dot"></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="mkcp-docs-link <?php echo $active_tab === 'updates' ? 'is-active' : ''; ?>" data-goto="updates">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Updates
                        <?php if ( $has_update ) : ?>
                            <span class="mkcp-nav-badge">!</span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="mkcp-docs-link <?php echo $active_tab === 'licentie' ? 'is-active' : ''; ?>" data-goto="licentie">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Licentie
                        <?php if ( ! $license_valid ) : ?>
                            <span class="mkcp-nav-badge" style="background:#e74c3c">!</span>
                        <?php elseif ( $license_tier === 'premium' ) : ?>
                            <span class="mkcp-nav-dot" style="background:#5d6bf8"></span>
                        <?php else : ?>
                            <span class="mkcp-nav-dot"></span>
                        <?php endif; ?>
                    </button>
                    <a target="_blank" href="<?php echo esc_url( admin_url( 'admin.php?page=mkcp-docs' ) ); ?>" class="mkcp-docs-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Documentatie &amp; uitleg
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:10px;height:10px;margin-left:auto;opacity:.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                    <button type="button" class="mkcp-docs-link" id="mkcp-replay-tour">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        Bekijk rondleiding opnieuw
                    </button>
                </div>
                <div class="mkcp-version-pill">
                    <span class="dot"></span>
                    v<?php echo esc_html( $version ); ?>
                </div>
            </div>

        </aside>

        <!-- ── Main ─────────────────────────────────────────────────────────────── -->

        <main class="mkcp-main">
        <form method="post" action="<?php echo esc_url( $action ); ?>" id="mkcp-form">
            <input type="hidden" name="mkcp_save_nonce" value="<?php echo esc_attr( wp_create_nonce( 'mkcp_save_settings' ) ); ?>">
            <input type="hidden" name="mkcp_active_tab" id="mkcp-active-tab" value="<?php echo esc_attr( $active_tab ); ?>">
            <input type="hidden" name="mkcp_active_product" id="mkcp-active-product" value="<?php echo esc_attr( $active_product ); ?>">

            <?php if ( $saved ) : ?>
            <div class="mkcp-save-banner" id="mkcp-save-banner">
                <div style="display:flex;align-items:center;gap:10px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="17" height="17"><polyline points="20 6 9 17 4 12"/></svg>
                    <strong>Opgeslagen</strong>
                    <span style="color:var(--mkcp-ui-text3);font-weight:400">Instellingen zijn succesvol opgeslagen.</span>
                </div>
                <button type="button" class="mkcp-save-banner-close" aria-label="Sluiten">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <?php endif; ?>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- DASHBOARD                                                        -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'dashboard' ? 'is-active' : ''; ?>" data-panel="dashboard">

                <div class="mkcp-page-header">
                    <h2>Dashboard</h2>
                    <p>Overzicht van de plugin-status en huidige configuratie.</p>
                </div>

                <!-- Status grid -->
                <div class="mkcp-dash-grid">

                    <?php
                    $lic_color  = [ 'none' => '#e74c3c', 'basic' => '#27ae60', 'premium' => '#5d6bf8' ][ $license_tier ] ?? '#888';
                    $lic_label  = [ 'none' => 'Geen licentie', 'basic' => 'Basic', 'premium' => 'Premium' ][ $license_tier ] ?? 'Onbekend';
                    $lic_sub    = $license_valid
                        ? ( ! empty( $license_data['expires'] ) ? 'Verloopt: ' . esc_html( $license_data['expires'] ) : 'Geen vervaldatum' )
                        : esc_html( $license_data['message'] ?? 'Geen geldige sleutel' );
                    ?>
                    <div class="mkcp-dash-card" style="grid-column:span 2; border-left:3px solid <?php echo esc_attr( $lic_color ); ?>">
                        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px">
                            <div style="display:flex; align-items:center; gap:14px">
                                <div class="mkcp-dash-card-icon" style="background:<?php echo esc_attr( $lic_color ); ?>1a; color:<?php echo esc_attr( $lic_color ); ?>; flex-shrink:0">
                                    <?php echo $icons['shield']; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px; color:var(--mkcp-ui-text3); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:4px">Licentie</div>
                                    <div style="font-size:20px; font-weight:700; color:<?php echo esc_attr( $lic_color ); ?>; line-height:1.1"><?php echo esc_html( $lic_label ); ?></div>
                                    <div class="mkcp-dash-card-sub" style="<?php echo ! $license_valid ? 'color:#e74c3c' : ''; ?>"><?php echo $lic_sub; ?></div>
                                </div>
                            </div>
                            <button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="licentie" style="margin-left:auto; white-space:nowrap">
                                <?php echo $icons['shield']; ?> Beheer licentie
                            </button>
                        </div>
                    </div>

                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon mkcp-dash-card-icon--accent">
                            <?php echo $icons['shopping-cart']; ?>
                        </div>
                        <h4>Titel popup</h4>
                        <div class="mkcp-dash-card-value" style="font-size:18px; letter-spacing:-.2px"><?php echo esc_html( $c['title'] ); ?></div>
                        <div class="mkcp-dash-card-sub">Knop: <?php echo esc_html( $c['btn_checkout'] ); ?></div>
                    </div>

                    <?php
                    $dash_zones     = mkcp_get_all_zone_thresholds();
                    $zones_with_thr = array_filter( $dash_zones, fn($z) => $z['threshold'] > 0 );
                    $zone_count     = count( $zones_with_thr );
                    ?>
                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon mkcp-dash-card-icon--green">
                            <?php echo $icons['truck']; ?>
                        </div>
                        <h4>Gratis verzending</h4>
                        <div class="mkcp-dash-card-value" style="font-size:18px; letter-spacing:-.2px">
                            <?php if ( $zone_count === 0 ) : ?>
                                —
                            <?php elseif ( $zone_count === 1 ) : ?>
                                <?php echo wc_price( reset( $zones_with_thr )['threshold'] ); ?>
                            <?php else : ?>
                                <?php echo $zone_count; ?> zones
                            <?php endif; ?>
                        </div>
                        <div class="mkcp-dash-card-sub">
                            <?php if ( $c['free_shipping_bar'] && $zone_count > 0 ) : ?>
                                Balk actief · <?php echo $zone_count; ?> <?php echo $zone_count === 1 ? 'zone' : 'zones'; ?>
                            <?php elseif ( $c['free_shipping_bar'] ) : ?>
                                Balk aan · geen drempel in WC
                            <?php else : ?>
                                Balk uitgeschakeld
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon mkcp-dash-card-icon--amber">
                            <?php echo $icons['credit-card']; ?>
                        </div>
                        <h4>Betaalmethoden</h4>
                        <div class="mkcp-dash-card-value"><?php echo count( (array)( $c['payment_icons'] ?? [] ) ); ?></div>
                        <div class="mkcp-dash-card-sub">Iconen geactiveerd</div>
                    </div>

                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon mkcp-dash-card-icon--accent">
                            <?php echo $icons['star']; ?>
                        </div>
                        <h4>USPs</h4>
                        <div class="mkcp-dash-card-value"><?php echo count( (array)( $c['usps'] ?? [] ) ); ?></div>
                        <div class="mkcp-dash-card-sub">Trustsignalen ingesteld</div>
                    </div>

                    <?php $blocks_count = count( (array)( $c['blocks'] ?? [] ) ); ?>
                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon mkcp-dash-card-icon--accent">
                            <?php echo $icons['layout']; ?>
                        </div>
                        <h4>Content Builder</h4>
                        <div class="mkcp-dash-card-value"><?php echo $blocks_count; ?></div>
                        <div class="mkcp-dash-card-sub"><?php echo $blocks_count === 1 ? 'Blok geconfigureerd' : 'Blokken geconfigureerd'; ?></div>
                    </div>

                    <?php if ( ! empty( $c['save_cart_url'] ) ) :
                        global $wpdb;
                        $saved_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_mkcp_saved_cart_%'" );
                    ?>
                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon mkcp-dash-card-icon--green">
                            <?php echo $icons['bookmark']; ?>
                        </div>
                        <h4>Winkelmand-links</h4>
                        <div class="mkcp-dash-card-value"><?php echo $saved_links; ?></div>
                        <div class="mkcp-dash-card-sub">Actieve herstel-links</div>
                    </div>
                    <?php endif; ?>

                    <!-- Popup gedrag -->
                    <div class="mkcp-dash-card" style="grid-column:span 2">
                        <div style="font-size:11px; color:var(--mkcp-ui-text3); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:14px">Popup gedrag</div>
                        <div style="display:flex; gap:20px; flex-wrap:wrap">
                            <?php
                            $redirect_sub = '';
                            if ( ! empty( $c['redirect_cart'] ) && ! empty( $c['redirect_cart_url'] ) ) {
                                $redirect_sub = parse_url( $c['redirect_cart_url'], PHP_URL_PATH ) ?: '/';
                            }
                            $min_sub = ( ! empty( $c['min_order_amount'] ) && $c['min_order_amount'] > 0 )
                                ? strip_tags( wc_price( $c['min_order_amount'] ) )
                                : '';
                            $behaviors = [
                                [ 'label' => 'Kortingscode',    'ok' => ! empty( $c['show_coupon'] ) ],
                                [ 'label' => 'Cart redirect',   'ok' => ! empty( $c['redirect_cart'] ),  'sub' => $redirect_sub ],
                                [ 'label' => 'Min bestelbedrag','ok' => $min_sub !== '',                 'sub' => $min_sub ],
                                [ 'label' => 'BTW-split',       'ok' => ! empty( $c['btw_split'] ) ],
                            ];
                            foreach ( $behaviors as $b ) :
                                $cls = $b['ok'] ? 'green' : 'amber';
                            ?>
                            <div style="display:flex; flex-direction:column; gap:4px; min-width:130px; flex:1">
                                <span style="font-size:12px; color:var(--mkcp-ui-text3); font-weight:500"><?php echo esc_html( $b['label'] ); ?></span>
                                <span class="mkcp-status mkcp-status--<?php echo $cls; ?>">
                                    <span class="mkcp-status-dot"></span>
                                    <?php echo $b['ok'] ? 'Aan' : 'Uit'; ?>
                                </span>
                                <?php if ( ! empty( $b['sub'] ) ) : ?>
                                <span style="font-size:11px; color:var(--mkcp-ui-text3)"><?php echo esc_html( $b['sub'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Premium features overzicht -->
                    <?php
                    $is_premium      = mkcp_license_has( 'premium' );
                    $prem_features   = [
                        [ 'label' => 'BTW-split weergave',       'active' => ! empty( $c['btw_split'] ) ],
                        [ 'label' => 'Analytics (GA4/GTM)',      'active' => ! empty( $c['analytics_enabled'] ) ],
                        [ 'label' => 'Bewaar voor later',        'active' => ! empty( $c['save_for_later'] ) ],
                        [ 'label' => 'Winkelmand-link delen',    'active' => ! empty( $c['save_cart_url'] ) ],
                        [ 'label' => 'Winkelmand per e-mail',    'active' => ! empty( $c['save_cart_email'] ) ],
                        [ 'label' => 'Voorraad indicator',       'active' => ! empty( $c['stock_indicator'] ) ],
                        [ 'label' => 'Content Builder blokken',  'active' => $blocks_count > 0 ],
                    ];
                    $icon_check = '<svg viewBox="0 0 24 24" fill="none" stroke="#27ae60" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>';
                    $icon_dash  = '<svg viewBox="0 0 24 24" fill="none" stroke="var(--mkcp-ui-text3)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                    $icon_lock  = '<svg viewBox="0 0 24 24" fill="none" stroke="#e74c3c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
                    ?>
                    <div class="mkcp-dash-card" style="grid-column:span 2">
                        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:14px">
                            <div style="font-size:11px; color:var(--mkcp-ui-text3); text-transform:uppercase; letter-spacing:.5px; font-weight:600">Premium features</div>
                            <?php if ( ! $is_premium ) : ?>
                            <button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="licentie" style="font-size:11px; padding:3px 10px">
                                <?php echo $icons['shield']; ?> Upgraden naar Premium →
                            </button>
                            <?php endif; ?>
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); gap:0">
                            <?php foreach ( $prem_features as $f ) :
                                $on = $f['active'] && $is_premium;
                            ?>
                            <div style="display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid var(--mkcp-ui-border)">
                                <?php if ( $on ) :
                                    echo $icon_check;
                                elseif ( $is_premium ) :
                                    echo $icon_dash;
                                else :
                                    echo $icon_lock;
                                endif; ?>
                                <span style="font-size:13px; color:<?php echo $on ? 'var(--mkcp-ui-text)' : 'var(--mkcp-ui-text3)'; ?>">
                                    <?php echo esc_html( $f['label'] ); ?>
                                    <?php if ( ! $is_premium ) : ?>
                                    <span style="font-size:10px; color:#e74c3c; font-weight:600; text-transform:uppercase; letter-spacing:.3px"> Premium</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <!-- Quick links -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Snelle acties</h3>
                    </div>
                    <div class="mkcp-glass-body" style="display:flex; gap:8px; flex-wrap:wrap">
                        <button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="general">
                            <?php echo $icons['sliders']; ?> Algemene instellingen
                        </button>
                        <button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="shipping">
                            <?php echo $icons['truck']; ?> Verzending instellen
                        </button>
                        <button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="overrides">
                            <?php echo $icons['code']; ?> Theme scaffold
                        </button>
                        <button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="updates">
                            <?php echo $icons['refresh-cw']; ?> Updates
                        </button>
                        <button type="button" class="mkcp-btn mkcp-btn--ghost" data-goto="licentie">
                            <?php echo $icons['shield']; ?> Licentie beheren
                        </button>
                    </div>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- ALGEMEEN                                                          -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'general' ? 'is-active' : ''; ?>" data-panel="general">

                <div class="mkcp-page-header">
                    <h2>Algemeen</h2>
                    <p>Teksten, labels en globale instellingen van de cart drawer.</p>
                </div>

                <!-- Plugin aan/uit -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Plugin status</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Plugin inschakelen</strong>
                                <small>Hoofdschakelaar — schakelt de cart drawer, de /cart-omleiding én alle checkout-features (bezorgdatum, afhalen, verzendkeuze, bedankpagina) in één keer uit</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle" id="mkcp-enabled-toggle-wrap">
                                        <input type="checkbox" id="mkcp-enabled-toggle" name="mkcp_enabled" value="1" <?php checked( $c['enabled'] ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Cart Popup actief</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Teksten -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['sliders']; ?></div>
                        <h3>Teksten & labels</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php
                        $text_fields = [
                            [ 'id' => 'mkcp_title',        'label' => 'Titel popup',            'hint' => 'Bijv. "Jouw winkelmand"',                   'val' => $c['title'] ],
                            [ 'id' => 'mkcp_btn_checkout',  'label' => 'Knop afrekenen',         'hint' => 'Tekst op de checkout-knop',                 'val' => $c['btn_checkout'] ],
                            [ 'id' => 'mkcp_col_product',   'label' => 'Kolomlabel Product',     'hint' => 'Koptekst boven de productnamen',             'val' => $c['col_product'] ],
                            [ 'id' => 'mkcp_col_total',     'label' => 'Kolomlabel Totaal',      'hint' => 'Koptekst boven de totaalprijzen',            'val' => $c['col_total'] ],
                            [ 'id' => 'mkcp_empty_heading', 'label' => 'Lege winkelmand titel',  'hint' => 'Wordt getoond als de winkelmand leeg is',    'val' => $c['empty_heading'] ],
                            [ 'id' => 'mkcp_empty_button',  'label' => 'Lege winkelmand knop',   'hint' => 'Tekst op de knop bij lege winkelmand',       'val' => $c['empty_button'] ],
                        ];
                        foreach ( $text_fields as $f ) :
                        ?>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong><?php echo esc_html( $f['label'] ); ?></strong>
                                <small><?php echo esc_html( $f['hint'] ); ?></small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" id="<?php echo esc_attr( $f['id'] ); ?>"
                                       name="<?php echo esc_attr( $f['id'] ); ?>"
                                       value="<?php echo esc_attr( $f['val'] ); ?>">
                            </div>
                        </div>
                        <?php endforeach; ?>

                    </div>
                </div>

                <!-- USPs -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['star']; ?></div>
                        <h3>USP truststrip</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-usp-list" id="mkcp-usp-rows">
                            <?php foreach ( $c['usps'] as $usp ) : ?>
                            <div class="mkcp-usp-row">
                                <select name="mkcp_usp_icon[]">
                                    <?php foreach ( [ 'shield', 'truck', 'phone', 'star', 'check' ] as $ico ) : ?>
                                    <option value="<?php echo esc_attr( $ico ); ?>" <?php selected( $usp['icon'] ?? '', $ico ); ?>>
                                        <?php echo esc_html( ucfirst( $ico ) ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="mkcp_usp_text[]" value="<?php echo esc_attr( $usp['text'] ?? '' ); ?>" placeholder="Voordeel omschrijving…">
                                <button type="button" class="mkcp-usp-remove" title="Verwijderen">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:12px">
                            <button type="button" id="mkcp-add-usp" class="mkcp-btn mkcp-btn--ghost">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                USP toevoegen
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- STYLING (PREMIUM)                                                -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'styling' ? 'is-active' : ''; ?>" data-panel="styling">

                <div class="mkcp-page-header">
                    <h2>Styling <span class="mkcp-premium-badge">Premium</span></h2>
                    <p>Pas de hoofdkleuren en breedte van de cart drawer aan je huisstijl aan — de preview rechts werkt live mee.</p>
                </div>

                <?php if ( ! $is_premium ) : ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
                        <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                            <strong>Premium vereist</strong> — Eigen kleuren en breedte instellen is beschikbaar met een premium licentie.
                            <button type="button" data-goto="licentie" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;text-decoration:underline">Licentie activeren →</button>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $detected_colors = $is_premium ? mkcp_detect_theme_colors() : [];
                $detected_labels = [ 'accent' => 'Hoofdkleur', 'bg' => 'Achtergrond', 'text' => 'Tekst' ];
                ?>
                <div class="mkcp-glass mkcp-style-glow-card" data-mkcp-tier="premium" id="mkcp-detected-colors-card"
                     data-mkcp-scan="<?php echo empty( $detected_colors ) ? '1' : '0'; ?>"
                     <?php echo empty( $detected_colors ) ? 'style="display:none"' : ''; ?>>
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['eye']; ?></div>
                        <h3>Kleuren van je website</h3>
                        <button type="button" id="mkcp-detected-rescan" class="mkcp-btn mkcp-btn--ghost" style="margin-left:auto;display:none">
                            <?php echo $icons['refresh-cw']; ?> Opnieuw scannen
                        </button>
                    </div>
                    <div class="mkcp-glass-body">
                        <p class="mkcp-input-hint" style="margin:0 0 12px">Automatisch gevonden in het thema van je site.</p>
                        <div class="mkcp-detected-swatches" id="mkcp-detected-swatches-categorized">
                            <?php foreach ( $detected_colors as $field => $hex ) : ?>
                            <button type="button" class="mkcp-detected-swatch js-mkcp-detected-apply"
                                    data-field="mkcp_style_<?php echo esc_attr( $field ); ?>"
                                    data-color="<?php echo esc_attr( $hex ); ?>">
                                <span class="mkcp-detected-swatch-color" style="background:<?php echo esc_attr( $hex ); ?>"></span>
                                <span class="mkcp-detected-swatch-info">
                                    <strong><?php echo esc_html( $detected_labels[ $field ] ?? ucfirst( $field ) ); ?></strong>
                                    <small><?php echo esc_html( strtoupper( $hex ) ); ?></small>
                                </span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="mkcp-detected-swatches" id="mkcp-detected-swatches-flat" style="display:none"></div>
                    </div>
                </div>

                <div class="mkcp-glass mkcp-style-glow-card" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layers']; ?></div>
                        <h3>Kant-en-klare stijlen</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <p class="mkcp-input-hint" style="margin:0 0 12px">Een startpunt — na toepassen kun je de kleuren hieronder nog los verder aanpassen.</p>
                        <div class="mkcp-style-preset-list">
                            <?php foreach ( mkcp_style_presets() as $preset_key => $p ) :
                                $is_outline = $p['btn_style'] === 'outline';
                                $btn_css    = $is_outline
                                    ? 'background:transparent;border-color:' . esc_attr( $p['accent'] )
                                    : 'background:' . esc_attr( $p['accent'] ) . ';border-color:' . esc_attr( $p['accent'] );
                                $btn_inner  = $is_outline ? $p['accent'] : $p['btn_text'];
                            ?>
                            <button type="button" class="mkcp-style-preset js-mkcp-style-preset"
                                    data-preset="<?php echo esc_attr( $preset_key ); ?>"
                                    data-accent="<?php echo esc_attr( $p['accent'] ); ?>"
                                    data-bg="<?php echo esc_attr( $p['bg'] ); ?>"
                                    data-text="<?php echo esc_attr( $p['text'] ); ?>"
                                    data-btn-text="<?php echo esc_attr( $p['btn_text'] ); ?>"
                                    data-border="<?php echo esc_attr( $p['border'] ); ?>"
                                    data-danger="<?php echo esc_attr( $p['danger'] ); ?>"
                                    data-btn-style="<?php echo esc_attr( $p['btn_style'] ); ?>">
                                <span class="mkcp-style-preset-thumb" style="background:<?php echo esc_attr( $p['bg'] ); ?>;border-color:<?php echo esc_attr( $p['border'] ); ?>">
                                    <span class="mkcp-style-preset-thumb-bar" style="border-color:<?php echo esc_attr( $p['border'] ); ?>">
                                        <span style="background:<?php echo esc_attr( $p['text'] ); ?>"></span>
                                    </span>
                                    <span class="mkcp-style-preset-thumb-lines">
                                        <span style="background:<?php echo esc_attr( $p['text'] ); ?>"></span>
                                        <span style="background:<?php echo esc_attr( $p['text'] ); ?>"></span>
                                    </span>
                                    <span class="mkcp-style-preset-thumb-btn" style="<?php echo $btn_css; ?>">
                                        <span style="background:<?php echo esc_attr( $btn_inner ); ?>"></span>
                                    </span>
                                </span>
                                <span class="mkcp-style-preset-label"><?php echo esc_html( $p['label'] ); ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass mkcp-style-glow-card" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['image']; ?></div>
                        <h3>Kleuren</h3>
                        <button type="button" id="mkcp-style-reset" class="mkcp-btn mkcp-btn--ghost" style="margin-left:auto">
                            <?php echo $icons['refresh-cw']; ?> Standaardwaarden herstellen
                        </button>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php
                        $style_fields = [
                            [ 'id' => 'style_accent',   'label' => 'Hoofdkleur',      'hint' => 'Checkout-knop, badges, focusring en voortgangsbalk',                  'default' => '#2e7d32' ],
                            [ 'id' => 'style_bg',       'label' => 'Achtergrondkleur', 'hint' => 'Achtergrond van de hele drawer',                                      'default' => '#ffffff' ],
                            [ 'id' => 'style_text',     'label' => 'Tekstkleur',      'hint' => 'Hoofdtekst in de drawer',                                             'default' => '#1a1a1a' ],
                            [ 'id' => 'style_btn_text', 'label' => 'Knoptekstkleur',  'hint' => 'Tekst op de hoofdkleur-knop — houd dit leesbaar t.o.v. de hoofdkleur', 'default' => '#ffffff' ],
                            [ 'id' => 'style_border',   'label' => 'Randkleur',       'hint' => 'Dividers en randen door de hele drawer',                              'default' => '#cccccc' ],
                            [ 'id' => 'style_danger',   'label' => 'Foutkleur',       'hint' => '"Verwijderen"-acties en niet-op-voorraad-badges',                     'default' => '#d32f2f' ],
                        ];
                        foreach ( $style_fields as $f ) :
                        ?>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong><?php echo esc_html( $f['label'] ); ?></strong>
                                <small><?php echo esc_html( $f['hint'] ); ?></small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-color-field">
                                    <input type="color" class="mkcp-color-swatch js-mkcp-style-color"
                                           id="mkcp_<?php echo esc_attr( $f['id'] ); ?>"
                                           name="mkcp_<?php echo esc_attr( $f['id'] ); ?>"
                                           data-style-var="<?php echo esc_attr( $f['id'] ); ?>"
                                           data-default="<?php echo esc_attr( $f['default'] ); ?>"
                                           value="<?php echo esc_attr( $c[ $f['id'] ] ?? $f['default'] ); ?>">
                                    <input type="text" class="mkcp-color-hex js-mkcp-style-hex"
                                           data-for="mkcp_<?php echo esc_attr( $f['id'] ); ?>"
                                           value="<?php echo esc_attr( $c[ $f['id'] ] ?? $f['default'] ); ?>"
                                           maxlength="7" spellcheck="false" autocomplete="off"
                                           aria-label="<?php echo esc_attr( $f['label'] ); ?> (hexcode)">
                                    <button type="button" class="mkcp-color-tool js-mkcp-style-copy" data-for="mkcp_<?php echo esc_attr( $f['id'] ); ?>" title="Hexcode kopiëren">
                                        <?php echo $icons['copy']; ?>
                                    </button>
                                    <button type="button" class="mkcp-color-tool js-mkcp-style-eyedrop" data-for="mkcp_<?php echo esc_attr( $f['id'] ); ?>" title="Kleur overnemen van je scherm">
                                        <?php echo $icons['pipette']; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div id="mkcp-style-contrast-warning" class="mkcp-contrast-meter" style="margin-top:12px"></div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Knopstijl</strong>
                                <small>Hoe de afrekenknop de hoofdkleur toepast. In outline-modus wordt de knoptekstkleur hierboven genegeerd — tekst en rand gebruiken dan de hoofdkleur.</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <select class="mkcp-input js-mkcp-style-btn-style" id="mkcp_style_btn_style" name="mkcp_style_btn_style" data-default="filled">
                                    <option value="filled" <?php selected( $c['style_btn_style'] ?? 'filled', 'filled' ); ?>>Gevuld</option>
                                    <option value="outline" <?php selected( $c['style_btn_style'] ?? 'filled', 'outline' ); ?>>Outline (rand, transparant)</option>
                                </select>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Donkere modus voor bezoekers</strong>
                                <small>Schakelt automatisch over naar een donkere kleurstelling bij bezoekers met donkere modus aan in hun browser/systeem (prefers-color-scheme). Behoudt je hoofdkleur, alleen achtergrond/tekst/randen wisselen. Los van de "Donker"-kant-en-klare-stijl hierboven, die voor iedereen geldt.</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_style_dark_mode_enabled" value="1" <?php checked( $c['style_dark_mode_enabled'] ?? true ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Ingeschakeld</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass mkcp-style-glow-card" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layout']; ?></div>
                        <h3>Afmeting &amp; positie</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Breedte drawer</strong>
                                <small>Breedte van de winkelwagen-lade in pixels</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm js-mkcp-style-width"
                                       id="mkcp_style_width" name="mkcp_style_width" data-default="500"
                                       value="<?php echo esc_attr( $c['style_width'] ?? 500 ); ?>"
                                       min="360" max="640" step="10">
                                <p class="mkcp-input-hint">Tussen 360 en 640 pixels. Standaard: 500px.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Positie</strong>
                                <small>Vanaf welke kant de winkelwagen opent. "Midden" opent als los paneel i.p.v. een volledige-hoogte lade.</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <select class="mkcp-input" id="mkcp_style_position" name="mkcp_style_position" data-default="right">
                                    <option value="right" <?php selected( $c['style_position'] ?? 'right', 'right' ); ?>>Rechts (standaard)</option>
                                    <option value="left" <?php selected( $c['style_position'] ?? 'right', 'left' ); ?>>Links</option>
                                    <option value="center" <?php selected( $c['style_position'] ?? 'right', 'center' ); ?>>Midden</option>
                                </select>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Volledig-scherm modus</strong>
                                <small>Toont een knop in de header waarmee klanten de winkelwagen zelf naar 100% breedte kunnen uitklappen (producten + cross-sell + samenvatting naast elkaar)</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_style_expand_enabled" value="1" <?php checked( $c['style_expand_enabled'] ?? true ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Uitklap-knop ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Zet uit om de uitklap-knop te verbergen, ook als de licentie premium is.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Mobiele app-ervaring</strong>
                                <small>Op telefoons opent de winkelwagen als bottom-sheet met sleep-handgreep en swipe-omlaag om te sluiten, producten verwijder je met een veeg naar links, en acties geven trilfeedback (Android)</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_mobile_app_experience" value="1" <?php checked( $c['mobile_app_experience'] ?? true ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">App-ervaring ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Alleen op schermen smaller dan 720px. Op desktop verandert er niets.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- CART GEDRAG                                                       -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'behavior' ? 'is-active' : ''; ?>" data-panel="behavior">

                <div class="mkcp-page-header">
                    <h2>Cart Gedrag</h2>
                    <p>Hoe de popup reageert op add-to-cart acties en winkelwagen navigatie.</p>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['shopping-cart']; ?></div>
                        <h3>Redirect & navigatie</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Cart pagina omleiden</strong>
                                <small>Bezoekers van /winkelwagen doorsturen</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_redirect_cart" value="1" <?php checked( $c['redirect_cart'] ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Omleiden ingeschakeld</span>
                                </div>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Omleiden naar</strong>
                                <small>URL bij redirect van /cart pagina</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="url" class="mkcp-input" name="mkcp_redirect_cart_url"
                                       value="<?php echo esc_attr( $c['redirect_cart_url'] ); ?>"
                                       placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
                                <p class="mkcp-input-hint">Laat leeg voor de homepage. Of gebruik de afrekenpagina-URL.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>BTW weergave <span class="mkcp-premium-badge">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>BTW-split tonen</strong>
                                <small>Aparte excl./incl. BTW prijzen</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_btw_split" value="1" <?php checked( $c['btw_split'] ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">BTW-split ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Voegt een incl./excl. BTW-schakelaar toe in de popup. Voorkeur wordt onthouden via <code>localStorage['mkcp_btw_pref']</code>.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Label excl. BTW</strong>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input mkcp-input--sm" name="mkcp_label_excl_tax" value="<?php echo esc_attr( $c['label_excl_tax'] ); ?>">
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Label incl. BTW</strong>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input mkcp-input--sm" name="mkcp_label_incl_tax" value="<?php echo esc_attr( $c['label_incl_tax'] ); ?>">
                            </div>
                        </div>

                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- VERZENDING                                                        -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'shipping' ? 'is-active' : ''; ?>" data-panel="shipping">

                <div class="mkcp-page-header">
                    <h2>Verzending</h2>
                    <p>Gratis verzending progressbalk. De drempel wordt automatisch bepaald op basis van de WooCommerce verzendzone van de bezoeker.</p>
                </div>

                <!-- WooCommerce verzendzone overzicht -->
                <?php $zone_thresholds = mkcp_get_all_zone_thresholds(); ?>
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['truck']; ?></div>
                        <h3>WooCommerce verzendzones</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <p style="font-size:13px;color:var(--mkcp-ui-text2);margin:0 0 14px">
                            De progressbalk laat automatisch de drempel zien die bij de <strong>verzendzone van de bezoeker</strong> hoort.
                            Elke bezoeker ziet dus het juiste bedrag voor zijn land, zonder dat je hier iets hoeft in te stellen.
                        </p>
                        <?php if ( empty( $zone_thresholds ) ) : ?>
                        <p style="font-size:13px;color:var(--mkcp-ui-text3)">Geen WooCommerce verzendmethoden gevonden.</p>
                        <?php else : ?>
                        <table style="width:100%;border-collapse:collapse;font-size:13px">
                            <thead>
                                <tr style="border-bottom:2px solid var(--mkcp-ui-border)">
                                    <th style="text-align:left;padding:6px 0;font-weight:600;color:var(--mkcp-ui-text2)">Zone</th>
                                    <th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--mkcp-ui-text2)">Regio's</th>
                                    <th style="text-align:right;padding:6px 0;font-weight:600;color:var(--mkcp-ui-text2)">Gratis verzending vanaf</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $zone_thresholds as $zt ) : ?>
                                <tr style="border-bottom:1px solid var(--mkcp-ui-border)">
                                    <td style="padding:7px 0">
                                        <strong><?php echo esc_html( $zt['name'] ); ?></strong>
                                        <?php if ( ! empty( $zt['is_default'] ) ) : ?>
                                            <span style="font-size:10px;color:var(--mkcp-ui-text3);margin-left:4px">(standaard)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:7px 8px;color:var(--mkcp-ui-text3);font-size:12px">
                                        <?php echo esc_html( $zt['locations'] ?: '—' ); ?>
                                    </td>
                                    <td style="padding:7px 0;text-align:right">
                                        <?php if ( $zt['threshold'] > 0 ) : ?>
                                            <strong style="color:var(--mkcp-ui-accent)"><?php echo wc_price( $zt['threshold'] ); ?></strong>
                                        <?php else : ?>
                                            <span style="color:var(--mkcp-ui-text3);font-size:12px">Geen (of coupon vereist)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p style="font-size:11px;color:var(--mkcp-ui-text3);margin:10px 0 0">
                            Verzendzones beheer je via
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>" target="_blank">
                                WooCommerce → Instellingen → Verzending
                            </a>.
                            Methoden met type <em>coupon</em> of <em>coupon + minimum</em> worden niet als drempel getoond omdat de balk dan misleidend zou zijn.
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Balk instelling + teksten -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Progressbalk instelling</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Balk tonen</strong>
                                <small>Progressbalk bovenaan de drawer</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_free_shipping_bar" value="1" <?php checked( $c['free_shipping_bar'] ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Progressbalk ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">De balk verschijnt alleen als er voor de verzendzone van de bezoeker een bedrag-drempel is ingesteld in WooCommerce (zie tabel hierboven).</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Tekst vóór drempel</strong>
                                <small>Gebruik %s voor het resterende bedrag</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_shipping_note" value="<?php echo esc_attr( $c['shipping_note'] ); ?>">
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Tekst ná drempel</strong>
                                <small>Wordt getoond als gratis verzending bereikt is</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_free_shipping_note" value="<?php echo esc_attr( $c['free_shipping_note'] ); ?>">
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Handmatige override (geavanceerd) -->
                <div class="mkcp-glass" style="border-color:rgba(251,191,36,.3)">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon mkcp-status-icon--warn"><?php echo $icons['alert']; ?></div>
                        <h3>Handmatige override</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Vaste drempelwaarde</strong>
                                <small>Overschrijft de WooCommerce verzendzone-detectie</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm" name="mkcp_free_shipping_threshold"
                                       value="<?php echo esc_attr( $c['free_shipping_threshold'] ); ?>" min="0" step="0.01">
                                <p class="mkcp-input-hint">
                                    Laat op <strong>0</strong> om automatisch de drempel uit WooCommerce te gebruiken (aanbevolen).
                                    Vul alleen een bedrag in als je de automatische detectie wil overschrijven — bijvoorbeeld voor een externe verzenddienst die WooCommerce niet gebruikt.
                                    <?php if ( (float) $c['free_shipping_threshold'] > 0 ) : ?>
                                        <br><span style="color:#e67e22">⚠ Override actief: alle bezoekers zien <?php echo wc_price( (float) $c['free_shipping_threshold'] ); ?>, ongeacht verzendzone.</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- CHECKOUT                                                          -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'checkout' ? 'is-active' : ''; ?>" data-panel="checkout">

                <div class="mkcp-page-header">
                    <h2>Checkout</h2>
                    <p>Minimum bestelbedrag en betaalmethode iconen.</p>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['alert']; ?></div>
                        <h3>Minimum bestelbedrag</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Minimumbedrag</strong>
                                <small>Excl. BTW, in winkelvaluta. 0 = uitgeschakeld</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm" name="mkcp_min_order_amount"
                                       value="<?php echo esc_attr( $c['min_order_amount'] ?? 0 ); ?>" min="0" step="0.01">
                                <p class="mkcp-input-hint">Als de winkelmand onder dit bedrag blijft, wordt de afrekenen-knop geblokkeerd en verschijnt een waarschuwing in de drawer.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Kortingscode</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Kortingscode veld tonen</strong>
                                <small>Klanten kunnen een kortingscode invoeren in de popup</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_show_coupon" value="1" <?php checked( $c['show_coupon'] ?? true ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Kortingscode ingeschakeld</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['credit-card']; ?></div>
                        <h3>Betaalmethode iconen</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <p style="font-size:13px; color:var(--mkcp-ui-text2); margin:0 0 16px">
                            Upload je eigen betaalmethode icoontjes (SVG, PNG of JPG). Ze worden getoond boven de afrekenen-knop.
                        </p>
                        <div class="mkcp-pay-upload-list" id="mkcp-pay-icons-list">
                            <?php
                            $pay_icons = $c['payment_icons'] ?? [];
                            $pay_icons_new = array_filter( (array) $pay_icons, function($i) { return is_array($i) && !empty($i['url']); } );
                            foreach ( $pay_icons_new as $pi ) :
                            ?>
                            <div class="mkcp-pay-upload-row">
                                <span class="mkcp-pay-upload-handle" title="Sleep om te herordenen">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                                </span>
                                <div class="mkcp-pay-upload-preview">
                                    <img src="<?php echo esc_url( $pi['url'] ); ?>" alt="">
                                </div>
                                <input type="hidden" name="mkcp_pay_icon_url[]" value="<?php echo esc_attr( $pi['url'] ); ?>">
                                <input type="text" class="mkcp-input" name="mkcp_pay_icon_label[]"
                                       value="<?php echo esc_attr( $pi['label'] ?? '' ); ?>"
                                       placeholder="Label (bijv. iDEAL)">
                                <button type="button" class="mkcp-pay-upload-remove" title="Verwijderen">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:12px">
                            <button type="button" id="mkcp-add-pay-icon" class="mkcp-btn mkcp-btn--ghost">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                Afbeelding uploaden
                            </button>
                        </div>
                        <p class="mkcp-input-hint" style="margin-top:8px">Sleep aan het handvat om de volgorde aan te passen. Gebruik een SVG of transparante PNG voor het beste resultaat op alle achtergronden.</p>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['calendar']; ?></div>
                        <h3>Eerstvolgende bezorgdatum <span class="mkcp-premium-badge">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Bezorgdatum-preview tonen in de winkelwagen</strong>
                                <small>Toont de eerstvolgende beschikbare bezorgdatum, ook in de volledig-scherm weergave</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_delivery_preview_enabled" value="1" <?php checked( ! empty( $c['delivery_preview_enabled'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Bezorgdatum-preview ingeschakeld</span>
                                </div>
                                <?php if ( function_exists( 'mkcp_dd_enabled' ) && mkcp_dd_enabled() ) : ?>
                                <p class="mkcp-input-hint">Gebruikt dezelfde instellingen als de bezorgdatumkiezer op de checkoutpagina (Cart Checkout → Bezorgdatum).</p>
                                <?php else : ?>
                                <p class="mkcp-input-hint">De bezorgdatumkiezer zelf staat nog uit — schakel die eerst in bij Cart Checkout → Bezorgdatum, anders blijft deze preview verborgen ook als je 'm hier aanzet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['bookmark']; ?></div>
                        <h3>Bewaar voor later <span class="mkcp-premium-badge">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Bewaar voor later inschakelen</strong>
                                <small>Klanten kunnen items parkeren buiten de winkelwagen en later terugzetten</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_save_for_later" value="1" <?php checked( ! empty( $c['save_for_later'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Bewaar voor later ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Items worden opgeslagen in de browser (localStorage) en blijven beschikbaar tot de bezoeker ze verwijdert of terugzet.</p>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>CSS selector cart-icoon</strong>
                                <small>Selector van het winkelwagen-icoon in de header waarop het hartje-badge verschijnt</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_cart_icon_selector"
                                       placeholder=".header-shop-icon a"
                                       value="<?php echo esc_attr( $c['cart_icon_selector'] ?? '' ); ?>">
                                <p class="mkcp-input-hint">Gebruik je browser-inspector (F12) om de juiste selector te vinden. Laat leeg om de standaard WooCommerce-selectors te gebruiken.</p>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Positie badge</strong>
                                <small>Hoek waar het hartje-badge verschijnt op het cart-icoon</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <?php
                                $badge_pos = $c['cart_badge_position'] ?? 'top-right';
                                $positions = [
                                    'top-left'     => [ 'label' => 'Linksboven',    'icon' => '↖' ],
                                    'top-right'    => [ 'label' => 'Rechtsboven',   'icon' => '↗' ],
                                    'bottom-left'  => [ 'label' => 'Linksonder',    'icon' => '↙' ],
                                    'bottom-right' => [ 'label' => 'Rechtsonder',   'icon' => '↘' ],
                                ];
                                ?>
                                <div class="mkcp-badge-position-grid">
                                    <?php foreach ( $positions as $val => $info ) : ?>
                                    <label class="mkcp-badge-pos-option<?php echo $badge_pos === $val ? ' is-selected' : ''; ?>">
                                        <input type="radio" name="mkcp_cart_badge_position" value="<?php echo esc_attr( $val ); ?>"
                                               <?php checked( $badge_pos, $val ); ?> style="position:absolute;opacity:0;pointer-events:none">
                                        <span class="mkcp-badge-pos-icon"><?php echo $info['icon']; ?></span>
                                        <span class="mkcp-badge-pos-label"><?php echo esc_html( $info['label'] ); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['shopping-cart']; ?></div>
                        <h3>Aantal in winkelwagen</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Aantal-badge inschakelen</strong>
                                <small>Toont het aantal producten in de winkelwagen als badge op het cart-icoon in je header</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_cart_count_badge_enabled" value="1" <?php checked( ! empty( $c['cart_count_badge_enabled'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Aantal-badge ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Werkt live mee: bij toevoegen, verwijderen of aanpassen van een item wordt het getal direct bijgewerkt, zonder dat de pagina ververst.</p>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>CSS selector cart-icoon</strong>
                                <small>Selector van het winkelwagen-icoon in de header waarop de aantal-badge verschijnt</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_cart_count_badge_selector"
                                       placeholder=".header-shop-icon a"
                                       value="<?php echo esc_attr( $c['cart_count_badge_selector'] ?? '' ); ?>">
                                <p class="mkcp-input-hint">Gebruik je browser-inspector (F12) om de juiste selector te vinden. Laat leeg om de standaard WooCommerce-selectors te gebruiken.</p>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Positie badge</strong>
                                <small>Hoek waar de aantal-badge verschijnt op het cart-icoon</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <?php $count_badge_pos = $c['cart_count_badge_position'] ?? 'top-right'; ?>
                                <div class="mkcp-badge-position-grid">
                                    <?php foreach ( $positions as $val => $info ) : ?>
                                    <label class="mkcp-badge-pos-option<?php echo $count_badge_pos === $val ? ' is-selected' : ''; ?>">
                                        <input type="radio" name="mkcp_cart_count_badge_position" value="<?php echo esc_attr( $val ); ?>"
                                               <?php checked( $count_badge_pos, $val ); ?> style="position:absolute;opacity:0;pointer-events:none">
                                        <span class="mkcp-badge-pos-icon"><?php echo $info['icon']; ?></span>
                                        <span class="mkcp-badge-pos-label"><?php echo esc_html( $info['label'] ); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['external']; ?></div>
                        <h3>Winkelmand delen <span class="mkcp-premium-badge">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Herstel-link genereren</strong>
                                <small>Klanten kunnen een unieke link kopiëren om de winkelmand later te herstellen</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_save_cart_url" value="1" <?php checked( ! empty( $c['save_cart_url'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Herstel-link ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">De link bevat een unieke code die de cart herstelt. Werkt op elk apparaat, geen account nodig. Link verloopt na het ingestelde aantal dagen.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Mail naar mijzelf</strong>
                                <small>Klanten kunnen de herstel-link naar een e-mailadres sturen</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_save_cart_email" value="1" <?php checked( ! empty( $c['save_cart_email'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">E-mail ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Mail wordt verstuurd via <code>wp_mail()</code>. Stel een SMTP-plugin in (bijv. WP Mail SMTP + SendGrid) voor betrouwbare bezorging. Zie de documentatie voor tips.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Geldigheid link</strong>
                                <small>Aantal dagen dat de herstel-link actief blijft</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm" name="mkcp_save_cart_expiry_days"
                                       value="<?php echo esc_attr( $c['save_cart_expiry_days'] ?? 7 ); ?>" min="1" max="30" step="1">
                                <p class="mkcp-input-hint">Gebruik <code>{expiry_days}</code> in de e-mailtekst om het aantal dagen automatisch in te vullen.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Onderwerp e-mail</strong>
                                <small>Onderwerpregel van de winkelmand-mail</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_save_cart_email_subject"
                                       value="<?php echo esc_attr( $c['save_cart_email_subject'] ?? '' ); ?>">
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Inhoud e-mail</strong>
                                <small>Introductietekst boven de productlijst en herstel-knop. Gebruik <code>{expiry_days}</code> voor het geldigheidsaantal.</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <textarea class="mkcp-input" name="mkcp_save_cart_email_body" rows="4"
                                          style="resize:vertical"><?php echo esc_textarea( $c['save_cart_email_body'] ?? '' ); ?></textarea>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Testmail versturen</strong>
                                <small>Stuur een voorbeeld van deze mail naar jezelf, met 2 willekeurige producten uit de winkel</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-test-email-row">
                                    <input type="email" class="mkcp-input js-mkcp-test-email-input"
                                           placeholder="jouw@email.nl"
                                           value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
                                    <button type="button" class="mkcp-btn mkcp-btn--primary js-mkcp-send-test-email"
                                            data-test-email-action="mkcp_send_test_cart_email">
                                        <?php echo $icons['zap']; ?> Stuur testmail
                                    </button>
                                </div>
                                <div class="js-mkcp-test-email-result mkcp-test-email-result"></div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <h3>Verlaten winkelwagen herinnering <span class="mkcp-premium-badge">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php
                        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
                        $guests_note   = true;
                        ?>

                        <?php if ( $cron_disabled ) : ?>
                        <div class="mkcp-notice mkcp-notice--error">
                            <strong>⚠ WP Cron is uitgeschakeld</strong> —
                            <code>DISABLE_WP_CRON</code> staat op <code>true</code> in <code>wp-config.php</code>.
                            Herinneringsmails worden <strong>niet verstuurd</strong> totdat WP Cron actief is of je een externe cron-taak instelt die <code>wp-cron.php</code> aanroept.
                        </div>
                        <?php endif; ?>

                        <div class="mkcp-notice mkcp-notice--info">
                            <strong>ℹ Ingelogde klanten én gasten</strong> —
                            Ingelogde klanten worden altijd gevolgd. Gasten (niet ingelogd) alleen zodra ze hun e-mailadres invullen bij het afrekenen — zonder adres kunnen we ze immers geen herinnering sturen.
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Herinneringen inschakelen</strong>
                                <small>Verstuur automatisch een e-mail als een klant zijn winkelwagen vergeet</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_abandoned_cart_enabled" value="1" <?php checked( ! empty( $c['abandoned_cart_enabled'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Verlaten winkelwagen herinnering ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Na activatie wordt elke 15 minuten automatisch gecontroleerd op verlaten winkelwagens. Elke klant ontvangt maximaal één herinneringsmail per winkelwagen.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Vertraging</strong>
                                <small>Hoelang na de laatste wijziging moet de herinnering worden verstuurd?</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <select class="mkcp-input mkcp-input--sm" name="mkcp_abandoned_cart_delay">
                                    <?php
                                    $delay_options = [
                                        30   => '30 minuten',
                                        60   => '1 uur',
                                        90   => '1,5 uur',
                                        120  => '2 uur',
                                        240  => '4 uur',
                                        480  => '8 uur',
                                        1440 => '24 uur',
                                        2880 => '48 uur',
                                    ];
                                    $current_delay = intval( $c['abandoned_cart_delay'] ?? 60 );
                                    foreach ( $delay_options as $val => $label ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_delay, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="mkcp-input-hint">De mail wordt verstuurd als de winkelwagen minstens deze tijd ongewijzigd is en de klant niet heeft afgerekend.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Onderwerp e-mail</strong>
                                <small>Onderwerpregel van de herinneringsmail</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_abandoned_cart_subject"
                                       value="<?php echo esc_attr( $c['abandoned_cart_subject'] ?? 'Je hebt nog iets in je winkelwagen!' ); ?>">
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Inhoud e-mail</strong>
                                <small>Gebruik <code>{voornaam}</code> voor de naam van de klant en <code>{winkelwagen_url}</code> voor de link</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <textarea class="mkcp-input" name="mkcp_abandoned_cart_body" rows="8"
                                          style="resize:vertical"><?php echo esc_textarea( $c['abandoned_cart_body'] ?? "Hé {voornaam}, je hebt nog producten in je winkelwagen. Kom je ze nog even ophalen?" ); ?></textarea>
                                <p class="mkcp-input-hint">Mail wordt verstuurd via <code>wp_mail()</code>. Stel een SMTP-plugin in (bijv. WP Mail SMTP + SendGrid) voor betrouwbare bezorging.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Testmail versturen</strong>
                                <small>Stuur een voorbeeld van deze herinneringsmail naar jezelf</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-test-email-row">
                                    <input type="email" class="mkcp-input js-mkcp-test-email-input"
                                           placeholder="jouw@email.nl"
                                           value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
                                    <button type="button" class="mkcp-btn mkcp-btn--primary js-mkcp-send-test-email"
                                            data-test-email-action="mkcp_ac_send_test_email">
                                        <?php echo $icons['zap']; ?> Stuur testmail
                                    </button>
                                </div>
                                <div class="js-mkcp-test-email-result mkcp-test-email-result"></div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layers']; ?></div>
                        <h3>Voorraad indicator <span class="mkcp-premium-badge">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Voorraadwaarschuwing tonen</strong>
                                <small>Toont "Nog maar X op voorraad" bij producten met lage voorraad</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_stock_indicator" value="1" <?php checked( ! empty( $c['stock_indicator'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Voorraad indicator ingeschakeld</span>
                                </div>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Drempelwaarde</strong>
                                <small>Toon badge als voorraad ≤ dit aantal</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm" name="mkcp_stock_threshold"
                                       value="<?php echo esc_attr( $c['stock_threshold'] ?? 5 ); ?>" min="1" max="99" step="1">
                                <p class="mkcp-input-hint">Alleen van toepassing op producten waarbij WooCommerce voorraad bijhoudt.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- ANALYTICS                                                         -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'analytics' ? 'is-active' : ''; ?>" data-panel="analytics">

                <div class="mkcp-page-header">
                    <h2>Analytics</h2>
                    <p>GA4 en Google Tag Manager integratie via <code>window.dataLayer</code>, plus eigen WooCommerce statistieken die GA4 niet kan zien.</p>
                </div>

                <!-- ── Event tracking toggles ─────────────────────────────────── -->
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['bar-chart']; ?></div>
                        <h3>Event tracking <span class="mkcp-premium-badge">Premium</span></h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>dataLayer events</strong>
                                <small>Push ecommerce events naar GTM / GA4 / Meta Pixel / Klaviyo via <code>window.dataLayer</code></small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_analytics_enabled" value="1" <?php checked( ! empty( $c['analytics_enabled'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">dataLayer events ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Stuurt <code>add_to_cart</code>, <code>remove_from_cart</code> en <code>begin_checkout</code> events naar <code>window.dataLayer</code>. Koppel in GTM aan GA4, Meta Pixel of een ander doel.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>WC-native statistieken</strong>
                                <small>Eigen opslag voor data die GA4 niet kan zien</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_analytics_wc_stats" value="1" <?php checked( ! empty( $c['analytics_wc_stats'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">WC statistieken ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Slaat meest verwijderde producten, gratis verzending gap, en popup-geassisteerde omzet op in WordPress. Zichtbaar in het statistieken-blok hieronder.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Debug modus</strong>
                                <small>Live event-overlay in de popup (alleen zichtbaar voor beheerders)</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_analytics_debug" value="1" <?php checked( ! empty( $c['analytics_debug'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Debug modus ingeschakeld</span>
                                </div>
                                <p class="mkcp-input-hint">Toont een klein venster in de popup met elk gefired event + de bijbehorende data. Alleen jij ziet dit — bezoekers nooit. Zet uit als je klaar bent met testen.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Verstuurde events ──────────────────────────────────────── -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['code']; ?></div>
                        <h3>Verstuurde dataLayer events</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <?php
                        $events = [
                            [ 'event' => 'view_cart',        'desc' => 'Popup geopend — GA4 standaard cart-funnel event' ],
                            [ 'event' => 'add_to_cart',      'desc' => 'Product toegevoegd aan winkelmand (product + archief pagina\'s, of + knop in popup)' ],
                            [ 'event' => 'remove_from_cart', 'desc' => 'Product verwijderd uit de popup (× icoon of − knop)' ],
                            [ 'event' => 'select_item',      'desc' => 'Klik op een productnaam in de popup' ],
                            [ 'event' => 'begin_checkout',   'desc' => 'Afrekenen-knop geklikt in de popup' ],
                            [ 'event' => 'apply_coupon',     'desc' => 'Kortingscode succesvol toegepast in de popup' ],
                            [ 'event' => 'remove_coupon',    'desc' => 'Kortingscode verwijderd uit de popup' ],
                        ];
                        foreach ( $events as $ev ) :
                        ?>
                        <div class="mkcp-status-item">
                            <div class="mkcp-status-icon mkcp-status-icon--ok">
                                <?php echo $icons['zap']; ?>
                            </div>
                            <div class="mkcp-status-item-label">
                                <code style="font-size:12px; font-family:monospace; color:var(--mkcp-ui-accent)"><?php echo esc_html( $ev['event'] ); ?></code>
                                <small><?php echo esc_html( $ev['desc'] ); ?></small>
                            </div>
                            <span class="mkcp-status mkcp-status--green"><span class="mkcp-status-dot"></span>GA4 ✓</span>
                        </div>
                        <?php endforeach; ?>
                        <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:14px 0 0">Elk event bevat een <code>ecommerce</code> object met item_id, item_name, price en quantity — compatibel met GA4 Enhanced Ecommerce.</p>
                    </div>
                </div>

                <!-- ── WC-native statistieken ─────────────────────────────────── -->
                <?php if ( $is_premium && ! empty( $c['analytics_wc_stats'] ) ) :
                    $stat_removed  = get_option( 'mkcp_stats_removed', [] );
                    $stat_gap      = get_option( 'mkcp_stats_gap',     [ 'total_gap' => 0.0, 'count' => 0 ] );
                    $stat_assisted = get_option( 'mkcp_stats_assisted', [ 'revenue' => 0.0, 'count' => 0 ] );
                    $avg_gap       = ( (int) $stat_gap['count'] > 0 ) ? round( (float) $stat_gap['total_gap'] / (int) $stat_gap['count'], 2 ) : 0;

                    uasort( $stat_removed, fn( $a, $b ) => $b['count'] - $a['count'] );
                    $top_removed = array_slice( $stat_removed, 0, 10, true );
                ?>
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon mkcp-dash-card-icon--green"><?php echo $icons['bar-chart']; ?></div>
                        <h3>WC-native statistieken</h3>
                        <div style="margin-left:auto">
                            <button type="button" class="mkcp-btn mkcp-btn--ghost" style="font-size:11px; padding:3px 10px; color:var(--mkcp-ui-text3)"
                                data-post-action="mkcp_clear_stats"
                                data-post-nonce="<?php echo esc_attr( wp_create_nonce( 'mkcp_clear_stats' ) ); ?>"
                                data-post-fields='{"mkcp_stat":"all"}'
                                data-confirm="Alle statistieken wissen?">
                                <?php echo $icons['trash']; ?> Reset stats
                            </button>
                        </div>
                    </div>
                    <div class="mkcp-glass-body">

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px">

                            <div style="padding:14px; background:var(--mkcp-ui-bg2); border-radius:var(--mkcp-ui-radius-sm); border:1px solid var(--mkcp-ui-border)">
                                <div style="font-size:11px; color:var(--mkcp-ui-text3); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:6px">Popup-geassisteerde omzet</div>
                                <div style="font-size:24px; font-weight:700; color:var(--mkcp-ui-accent)"><?php echo wc_price( (float) $stat_assisted['revenue'] ); ?></div>
                                <div style="font-size:12px; color:var(--mkcp-ui-text3); margin-top:4px"><?php echo (int) $stat_assisted['count']; ?> bestellingen via de popup</div>
                                <div style="font-size:11px; color:var(--mkcp-ui-text3); margin-top:6px; font-style:italic">Bestelling telt mee als de klant vanuit de popup op Afrekenen heeft geklikt</div>
                            </div>

                            <div style="padding:14px; background:var(--mkcp-ui-bg2); border-radius:var(--mkcp-ui-radius-sm); border:1px solid var(--mkcp-ui-border)">
                                <div style="font-size:11px; color:var(--mkcp-ui-text3); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:6px">Gratis verzending gap</div>
                                <div style="font-size:24px; font-weight:700; color:var(--mkcp-ui-accent)"><?php echo wc_price( $avg_gap ); ?></div>
                                <div style="font-size:12px; color:var(--mkcp-ui-text3); margin-top:4px">gem. gap over <?php echo (int) $stat_gap['count']; ?> popup-opens</div>
                                <div style="font-size:11px; color:var(--mkcp-ui-text3); margin-top:6px; font-style:italic">Gemiddeld bedrag dat bezoekers nog misten om gratis verzending te halen</div>
                            </div>

                        </div>

                        <?php if ( ! empty( $top_removed ) ) : ?>
                        <div style="font-size:11px; color:var(--mkcp-ui-text3); text-transform:uppercase; letter-spacing:.5px; font-weight:600; margin-bottom:10px">Meest verwijderde producten (top 10)</div>
                        <table style="width:100%; border-collapse:collapse; font-size:13px">
                            <thead>
                                <tr style="border-bottom:2px solid var(--mkcp-ui-border)">
                                    <th style="text-align:left; padding:5px 0; font-weight:600; color:var(--mkcp-ui-text2)">Product</th>
                                    <th style="text-align:right; padding:5px 0; font-weight:600; color:var(--mkcp-ui-text2)">Keer verwijderd</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $top_removed as $pid => $stat ) : ?>
                                <tr style="border-bottom:1px solid var(--mkcp-ui-border)">
                                    <td style="padding:6px 0">
                                        <a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" target="_blank" style="color:var(--mkcp-ui-accent); text-decoration:none">
                                            <?php echo esc_html( $stat['name'] ); ?>
                                        </a>
                                    </td>
                                    <td style="padding:6px 0; text-align:right; font-weight:600"><?php echo (int) $stat['count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else : ?>
                        <p style="font-size:13px; color:var(--mkcp-ui-text3); margin:0">Nog geen verwijder-events geregistreerd. Statistieken worden opgebouwd zodra bezoekers producten uit de popup verwijderen.</p>
                        <?php endif; ?>

                    </div>
                </div>
                <?php elseif ( $is_premium ) : ?>
                <div class="mkcp-glass" style="border-style:dashed">
                    <div class="mkcp-glass-body" style="display:flex; align-items:center; gap:12px; color:var(--mkcp-ui-text3)">
                        <?php echo $icons['bar-chart']; ?>
                        <span style="font-size:13px">Schakel <strong>WC-native statistieken</strong> in om hier meest verwijderde producten, verzending gap en geassisteerde omzet te zien.</span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ── Setup gids ─────────────────────────────────────────────── -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Setup gids — GTM / GA4 koppelen</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div style="display:flex; flex-direction:column; gap:16px">

                            <div style="display:flex; gap:14px; align-items:flex-start">
                                <div style="flex-shrink:0; width:28px; height:28px; background:var(--mkcp-ui-accent); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:13px; font-weight:700">1</div>
                                <div>
                                    <strong style="font-size:13px">Installeer GTM op je site</strong>
                                    <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:4px 0 0">Voeg de GTM container-snippet toe aan je thema (of gebruik een plugin zoals <em>Site Kit by Google</em>). De <code>window.dataLayer</code> array wordt automatisch aangemaakt door GTM.</p>
                                </div>
                            </div>

                            <div style="display:flex; gap:14px; align-items:flex-start">
                                <div style="flex-shrink:0; width:28px; height:28px; background:var(--mkcp-ui-accent); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:13px; font-weight:700">2</div>
                                <div>
                                    <strong style="font-size:13px">Maak triggers aan in GTM</strong>
                                    <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:4px 0 0">Ga in GTM naar <em>Triggers → Nieuw → Custom Event</em> en vul de eventnaam in: <code>view_cart</code>, <code>add_to_cart</code>, <code>remove_from_cart</code>, <code>select_item</code>, <code>begin_checkout</code>, <code>apply_coupon</code> of <code>remove_coupon</code>. Koppel vervolgens een GA4 Event tag aan dit trigger.</p>
                                </div>
                            </div>

                            <div style="display:flex; gap:14px; align-items:flex-start">
                                <div style="flex-shrink:0; width:28px; height:28px; background:var(--mkcp-ui-accent); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:13px; font-weight:700">3</div>
                                <div>
                                    <strong style="font-size:13px">Test met debug modus</strong>
                                    <p style="font-size:12px; color:var(--mkcp-ui-text3); margin:4px 0 0">Zet de <strong>Debug modus</strong> aan (hierboven) en bezoek de site als ingelogde beheerder. Rechtsonder verschijnt een live event-log — sleep hem via de titelbalk naar een handige plek, de positie wordt onthouden. Open ook de GTM Preview mode om te bevestigen dat de events binnenkomen.</p>
                                </div>
                            </div>

                            <div style="background:var(--mkcp-ui-bg2); border:1px solid var(--mkcp-ui-border); border-radius:var(--mkcp-ui-radius-sm); padding:14px">
                                <div style="font-size:11px; font-weight:600; color:var(--mkcp-ui-text3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px">Wanneer vuurt elk event?</div>
                                <div style="display:flex; flex-direction:column; gap:8px">
                                    <div style="display:flex; gap:10px; align-items:flex-start; font-size:12px">
                                        <code style="flex-shrink:0; color:var(--mkcp-ui-accent); background:var(--mkcp-ui-bg); padding:2px 6px; border-radius:3px; font-size:11px">view_cart</code>
                                        <span style="color:var(--mkcp-ui-text2)">Elke keer dat de popup opent — GA4 standaard funnel event</span>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:flex-start; font-size:12px">
                                        <code style="flex-shrink:0; color:var(--mkcp-ui-accent); background:var(--mkcp-ui-bg); padding:2px 6px; border-radius:3px; font-size:11px">add_to_cart</code>
                                        <span style="color:var(--mkcp-ui-text2)">Klik op <em>In winkelwagen</em> op een product- of archiefpagina, of op de + knop in de popup</span>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:flex-start; font-size:12px">
                                        <code style="flex-shrink:0; color:var(--mkcp-ui-accent); background:var(--mkcp-ui-bg); padding:2px 6px; border-radius:3px; font-size:11px">remove_from_cart</code>
                                        <span style="color:var(--mkcp-ui-text2)">Klik op het × icoon naast een product, of op de − knop bij de hoeveelheid</span>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:flex-start; font-size:12px">
                                        <code style="flex-shrink:0; color:var(--mkcp-ui-accent); background:var(--mkcp-ui-bg); padding:2px 6px; border-radius:3px; font-size:11px">select_item</code>
                                        <span style="color:var(--mkcp-ui-text2)">Klik op een productnaam in de popup (handig voor engagement-analyse)</span>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:flex-start; font-size:12px">
                                        <code style="flex-shrink:0; color:var(--mkcp-ui-accent); background:var(--mkcp-ui-bg); padding:2px 6px; border-radius:3px; font-size:11px">begin_checkout</code>
                                        <span style="color:var(--mkcp-ui-text2)">Klik op de <em>Afrekenen</em> knop in de popup</span>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:flex-start; font-size:12px">
                                        <code style="flex-shrink:0; color:var(--mkcp-ui-accent); background:var(--mkcp-ui-bg); padding:2px 6px; border-radius:3px; font-size:11px">apply_coupon</code>
                                        <span style="color:var(--mkcp-ui-text2)">Kortingscode succesvol toegepast — bevat <code>coupon_code</code> als parameter</span>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:flex-start; font-size:12px">
                                        <code style="flex-shrink:0; color:var(--mkcp-ui-accent); background:var(--mkcp-ui-bg); padding:2px 6px; border-radius:3px; font-size:11px">remove_coupon</code>
                                        <span style="color:var(--mkcp-ui-text2)">Kortingscode verwijderd uit de popup — bevat <code>coupon_code</code> als parameter</span>
                                    </div>
                                </div>
                                <p style="font-size:11px; color:var(--mkcp-ui-text3); margin:10px 0 0">Vereist: zowel <strong>dataLayer events</strong> als <strong>Debug modus</strong> moeten aan staan om events in de overlay te zien.</p>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- CONTENT BUILDER                                                   -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel mkcp-panel--builder <?php echo $active_tab === 'builder' ? 'is-active' : ''; ?>" data-panel="builder">

                <div class="mkcp-page-header">
                    <h2>Content Builder</h2>
                    <p>Sleep blokken naar de gewenste zone in de popup. De live preview rechts toont het resultaat direct.</p>
                </div>

                <input type="hidden" name="mkcp_blocks" id="mkcp-blocks-json" value="<?php echo esc_attr( wp_json_encode( $c['blocks'] ?? [] ) ); ?>">

                <div class="mkcp-builder-wrap" data-mkcp-tier="premium">

                    <!-- Left: zones canvas -->
                    <div class="mkcp-builder-canvas">

                        <!-- Block type picker -->
                        <div class="mkcp-builder-picker">
                            <div class="mkcp-builder-picker-header">
                                <span class="mkcp-builder-picker-title">Blokken</span>
                                <span class="mkcp-builder-picker-hint">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><line x1="5" y1="9" x2="19" y2="9"/><line x1="5" y1="15" x2="19" y2="15"/><circle cx="3" cy="9" r="1" fill="currentColor" stroke="none"/><circle cx="3" cy="15" r="1" fill="currentColor" stroke="none"/></svg>
                                    Sleep naar de popup
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="margin-left:2px"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                </span>
                            </div>
                            <div class="mkcp-builder-picker-grid" id="mkcp-block-picker">
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="text">
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">T</span>
                                    <span>Tekst</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="divider">
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">—</span>
                                    <span>Scheidingslijn</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="usp">
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">✓</span>
                                    <span>USP</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="image">
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    </span>
                                    <span>Afbeelding</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="banner">
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    </span>
                                    <span>Banner</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="button">
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="3" y="8" width="18" height="8" rx="4"/></svg>
                                    </span>
                                    <span>Knop</span>
                                </button>
                            </div>
                        </div>

                        <!-- Zone drop areas -->
                        <div class="mkcp-zones" id="mkcp-zones">
                            <?php
                            $zone_labels = [
                                'above-items'    => 'Boven producten',
                                'below-items'    => 'Onder producten',
                                'below-totals'   => 'Onder totalen',
                                'below-payment'  => 'Onder betaalmethodes',
                                'below-checkout' => 'Onder checkout-knop',
                            ];
                            foreach ( $zone_labels as $zone_key => $zone_label ) :
                                $zone_blocks = array_filter( $c['blocks'] ?? [], fn( $b ) => ( $b['zone'] ?? '' ) === $zone_key && ( $b['enabled'] ?? true ) );
                            ?>
                            <div class="mkcp-zone" data-zone="<?php echo esc_attr( $zone_key ); ?>">
                                <div class="mkcp-zone-header">
                                    <span class="mkcp-zone-label"><?php echo esc_html( $zone_label ); ?></span>
                                    <span class="mkcp-zone-count"><?php echo count( $zone_blocks ); ?></span>
                                </div>
                                <div class="mkcp-zone-list js-mkcp-zone" data-zone="<?php echo esc_attr( $zone_key ); ?>">
                                    <?php foreach ( $zone_blocks as $block ) : ?>
                                    <div class="mkcp-block-item"
                                         data-type="<?php echo esc_attr( $block['type'] ); ?>"
                                         data-zone="<?php echo esc_attr( $block['zone'] ); ?>"
                                         data-block="<?php echo esc_attr( wp_json_encode( $block ) ); ?>">
                                        <span class="mkcp-block-handle"><?php echo $icons['drag']; ?></span>
                                        <span class="mkcp-block-badge"><?php echo esc_html( $block['type'] ); ?></span>
                                        <span class="mkcp-block-preview"><?php
                                            if ( $block['type'] === 'text' )       echo esc_html( wp_trim_words( wp_strip_all_tags( $block['content'] ?? '' ), 6 ) );
                                            elseif ( $block['type'] === 'divider' ) echo '— scheidingslijn —';
                                            elseif ( $block['type'] === 'usp' )    echo esc_html( $block['text'] ?? 'USP tekst' );
                                            elseif ( $block['type'] === 'image' )  echo esc_html( basename( $block['url'] ?? 'afbeelding' ) );
                                            elseif ( $block['type'] === 'banner' ) echo esc_html( $block['text'] ?? 'Banner' );
                                            elseif ( $block['type'] === 'button' ) echo esc_html( $block['text'] ?? 'Knop' );
                                        ?></span>
                                        <div class="mkcp-block-actions">
                                            <button type="button" class="mkcp-block-action js-mkcp-edit-block" title="Bewerken"><?php echo $icons['edit']; ?></button>
                                            <button type="button" class="mkcp-block-action js-mkcp-delete-block" title="Verwijderen"><?php echo $icons['trash']; ?></button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div><!-- /mkcp-builder-canvas -->

                </div><!-- /mkcp-builder-wrap -->

                <!-- Block editor modal -->
                <div class="mkcp-block-editor" id="mkcp-block-editor" style="display:none">
                    <div class="mkcp-block-editor-inner">
                        <div class="mkcp-block-editor-header">
                            <strong id="mkcp-editor-title">Blok bewerken</strong>
                            <button type="button" class="mkcp-block-editor-close" id="mkcp-editor-close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div class="mkcp-block-editor-body" id="mkcp-editor-body">
                            <!-- Dynamically filled by builder.js -->
                        </div>
                        <div class="mkcp-block-editor-footer">
                            <button type="button" class="mkcp-btn mkcp-btn--secondary" id="mkcp-editor-cancel">Annuleren</button>
                            <button type="button" class="mkcp-btn mkcp-btn--primary" id="mkcp-editor-save">Opslaan</button>
                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- Modals (overrides / updates / licentie) worden via JS geopend -->
            <?php
            $tier_labels = [ 'none' => 'Niet actief', 'basic' => 'Basic', 'premium' => 'Premium' ];
            $tier_colors = [ 'none' => '#e74c3c',     'basic' => '#27ae60', 'premium' => '#5d6bf8' ];
            $tier_label  = $tier_labels[ $license_tier ] ?? 'Onbekend';
            $tier_color  = $tier_colors[ $license_tier ] ?? '#888';
            $scaffold_files = [
                'style.css'          => [ 'desc' => 'CSS overrides voor de popup — auto-geladen na de plugin-CSS',        'exists' => $has_style          ],
                'checkout.css'       => [ 'desc' => 'CSS overrides voor de checkout — auto-geladen na de checkout-CSS (premium)', 'exists' => $has_checkout_css   ],
                'script.js'          => [ 'desc' => 'JS overrides voor de popup — auto-geladen na de plugin-JS',        'exists' => $has_script          ],
                'checkout.js'        => [ 'desc' => 'JS overrides voor de checkout — auto-geladen na de checkout-JS (premium)', 'exists' => $has_checkout_js   ],
                'cart-hooks.php'     => [ 'desc' => 'Algemene cart/popup hooks — auto-geladen bij plugins_loaded',  'exists' => $has_hooks          ],
                'checkout-hooks.php' => [ 'desc' => 'Checkout-specifieke hooks — auto-geladen bij plugins_loaded',  'exists' => $has_checkout_hooks ],
            ];
            $created = sanitize_text_field( $_GET['scaffold_created'] ?? '' );
            ?>

            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- THEME OVERRIDES                                                   -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'overrides' ? 'is-active' : ''; ?>" data-panel="overrides">

                <div class="mkcp-page-header">
                    <h2>Theme Overrides</h2>
                    <p>Scaffold bestanden voor CSS en PHP aanpassingen in je (child) thema.</p>
                </div>

                <?php if ( ! $is_child ) : ?>
                <div class="mkcp-glass" style="border-color:rgba(251,191,36,.3)">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span class="mkcp-status-icon mkcp-status-icon--warn"><?php echo $icons['alert']; ?></span>
                        <div>
                            <strong style="font-size:13px;color:var(--mkcp-ui-text)">Geen child thema actief</strong>
                            <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Bestanden worden aangemaakt in het actieve thema en kunnen worden overschreven bij een thema-update.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ( $created ) : ?>
                <div style="padding:10px 14px;background:var(--mkcp-ui-green-soft);border:1px solid rgba(34,197,94,.2);border-radius:6px;font-size:12px;color:var(--mkcp-ui-green)">
                    Aangemaakt: <strong><?php echo esc_html( str_replace( ',', ', ', $created ) ); ?></strong>
                </div>
                <?php endif; ?>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['code']; ?></div>
                        <h3>Override bestanden</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-file-list">
                            <?php foreach ( $scaffold_files as $file => $info ) : ?>
                            <div class="mkcp-file-item">
                                <div class="mkcp-file-icon <?php echo $info['exists'] ? 'mkcp-file-icon--found' : 'mkcp-file-icon--missing'; ?>">
                                    <?php echo $info['exists'] ? $icons['check'] : $icons['code']; ?>
                                </div>
                                <div class="mkcp-file-info">
                                    <code>mk-cart-popup/<?php echo esc_html( $file ); ?></code>
                                    <small><?php echo esc_html( $info['desc'] ); ?></small>
                                </div>
                                <span class="mkcp-status <?php echo $info['exists'] ? 'mkcp-status--green' : 'mkcp-status--amber'; ?>">
                                    <span class="mkcp-status-dot"></span>
                                    <?php echo $info['exists'] ? 'Gevonden' : 'Ontbreekt'; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <button type="button" class="mkcp-btn mkcp-btn--ghost"
                                data-post-action="mkcp_create_scaffold"
                                data-post-nonce="<?php echo esc_attr( wp_create_nonce( 'mkcp_create_scaffold' ) ); ?>"
                                data-post-with-checkbox="mkcp-scaffold-overwrite">
                                <?php echo $icons['package']; ?> Bestanden aanmaken
                            </button>
                            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--mkcp-ui-text2);cursor:pointer">
                                <input type="checkbox" id="mkcp-scaffold-overwrite" name="mkcp_scaffold_overwrite" value="1" style="accent-color:var(--mkcp-ui-accent)">
                                Bestaande overschrijven
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Hooks isolatie op de checkout</h3>
                    </div>
                    <div class="mkcp-glass-body" style="gap:12px">

                        <p style="font-size:13px;color:var(--mkcp-ui-text2);margin:0">
                            Via <strong>Checkout → Styling → Theme hooks uitschakelen</strong> verwijdert de plugin bij activatie alle PHP-callbacks uit het (child) thema op de checkout pagina — én valt terug op WooCommerce's eigen standaard <code>checkout/*</code> templates zodra het (child) thema daar een eigen versie van heeft (bv. <code>woocommerce/checkout/review-order.php</code>). Zo'n bestand wordt namelijk rechtstreeks geladen via WooCommerce's eigen template-resolutie, niet via een hook, en blijft dus buiten bereik van de PHP-Reflection-sweep hieronder. De scaffold bestanden hieronder zijn hiervan uitgezonderd en blijven altijd actief.
                        </p>

                        <div style="display:flex;flex-direction:column;gap:6px">
                            <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:var(--mkcp-ui-green-soft);border:1px solid rgba(34,197,94,.2);border-radius:6px">
                                <span style="color:var(--mkcp-ui-green);font-size:15px;font-weight:700;flex-shrink:0;margin-top:1px"><?php echo $icons['check']; ?></span>
                                <div>
                                    <code style="font-size:12px">mk-cart-popup/cart-hooks.php</code><br>
                                    <small style="color:var(--mkcp-ui-text3)">Altijd actief — gebruik dit voor algemene cart/popup-aanpassingen en website-specifieke hooks.</small>
                                </div>
                            </div>
                            <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:var(--mkcp-ui-green-soft);border:1px solid rgba(34,197,94,.2);border-radius:6px">
                                <span style="color:var(--mkcp-ui-green);font-size:15px;font-weight:700;flex-shrink:0;margin-top:1px"><?php echo $icons['check']; ?></span>
                                <div>
                                    <code style="font-size:12px">mk-cart-popup/checkout-hooks.php</code><br>
                                    <small style="color:var(--mkcp-ui-text3)">Altijd actief — gebruik dit voor alle checkout-specifieke PHP-aanpassingen. Dit is je schone basis.</small>
                                </div>
                            </div>
                            <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:var(--mkcp-ui-surface2);border:1px solid var(--mkcp-ui-border);border-radius:6px">
                                <span style="color:var(--mkcp-ui-text3);font-size:15px;font-weight:700;flex-shrink:0;margin-top:1px"><?php echo $icons['x']; ?></span>
                                <div>
                                    <code style="font-size:12px">functions.php</code> <span style="font-size:11px;color:var(--mkcp-ui-text3)">en alle andere thema-bestanden</span><br>
                                    <small style="color:var(--mkcp-ui-text3)">Verwijderd op de checkout wanneer "Theme hooks uitschakelen" aan staat. Migreer hooks die je nodig hebt naar <code>checkout-hooks.php</code>.</small>
                                </div>
                            </div>
                        </div>

                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:0">
                            De hook-isolatie werkt via PHP Reflection en loopt over alle geregistreerde WordPress-hooks; callbacks worden verwijderd op de <code>wp</code> action (prioriteit 20), nadat de scaffold bestanden al geladen zijn. Thema-templates in <code>woocommerce/checkout/*</code> worden apart afgevangen via het <code>woocommerce_locate_template</code> filter (prioriteit 5), vóórdat WooCommerce zo'n bestand daadwerkelijk gaat includen.
                        </p>

                    </div>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- UPDATES                                                           -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'updates' ? 'is-active' : ''; ?>" data-panel="updates">

                <div class="mkcp-page-header">
                    <h2>Updates</h2>
                    <p>Plugin versie-informatie en update-mechanisme via GitHub releases.</p>
                </div>

                <div class="mkcp-version-hero">
                    <div class="mkcp-version-icon"><?php echo $icons['package']; ?></div>
                    <div>
                        <h3>MK Cart Popup</h3>
                        <p>
                            Geïnstalleerde versie: <strong>v<?php echo esc_html( $version ); ?></strong>
                            &nbsp;·&nbsp;
                            Laatste versie: <strong>v<?php echo esc_html( $latest_ver ); ?></strong>
                            &nbsp;·&nbsp;
                            <?php if ( $has_update ) : ?>
                                <span class="mkcp-status mkcp-status--amber"><span class="mkcp-status-dot"></span>Update beschikbaar</span>
                            <?php else : ?>
                                <span class="mkcp-status mkcp-status--green"><span class="mkcp-status-dot"></span>Up-to-date</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['refresh-cw']; ?></div>
                        <h3>Update manifest</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-status-list">
                            <div class="mkcp-status-item">
                                <div class="mkcp-status-icon mkcp-status-icon--ok"><?php echo $icons['check']; ?></div>
                                <div class="mkcp-status-item-label">
                                    Updater URL
                                    <small><?php echo esc_html( MKCP_UPDATER_URL ); ?></small>
                                </div>
                            </div>
                            <div class="mkcp-status-item">
                                <div class="mkcp-status-icon <?php echo file_exists( $update_json ) ? 'mkcp-status-icon--ok' : 'mkcp-status-icon--warn'; ?>">
                                    <?php echo file_exists( $update_json ) ? $icons['check'] : $icons['alert']; ?>
                                </div>
                                <div class="mkcp-status-item-label">
                                    mk-cart-popup-update.json
                                    <small><?php echo file_exists( $update_json ) ? 'Gevonden in plugin map' : 'Niet gevonden'; ?></small>
                                </div>
                            </div>
                        </div>

                        <?php $mkcp_cl_entries = mkcp_changelog_entries(); ?>
                        <?php if ( ! empty( $mkcp_cl_entries ) ) : ?>
                        <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--mkcp-ui-border)">
                            <h4 style="font-size:11px;font-weight:600;color:var(--mkcp-ui-text3);text-transform:uppercase;letter-spacing:.6px;margin:0 0 12px">Changelog</h4>

                            <div class="mkcp-changelog-list">
                                <?php foreach ( $mkcp_cl_entries as $mkcp_cl_i => $mkcp_cl_entry ) :
                                    $mkcp_cl_count = count( $mkcp_cl_entry['items'] );
                                ?>
                                <button type="button" class="mkcp-changelog-row" data-changelog-index="<?php echo (int) $mkcp_cl_i; ?>">
                                    <span class="mkcp-changelog-row__version">v<?php echo esc_html( $mkcp_cl_entry['version'] ); ?></span>
                                    <?php if ( ! empty( $mkcp_cl_entry['date'] ) ) : ?>
                                    <span class="mkcp-changelog-row__date"><?php echo esc_html( $mkcp_cl_entry['date'] ); ?></span>
                                    <?php endif; ?>
                                    <span class="mkcp-changelog-row__count"><?php echo (int) $mkcp_cl_count; ?> wijziging<?php echo 1 === $mkcp_cl_count ? '' : 'en'; ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
                            <a href="https://github.com/mediakanjers/mk-cart-popup" target="_blank" rel="noopener" class="mkcp-btn mkcp-btn--ghost">
                                <?php echo $icons['external']; ?> GitHub repository
                            </a>
                            <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="mkcp-btn mkcp-btn--ghost">
                                <?php echo $icons['refresh-cw']; ?> WordPress updater
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <div class="mkcp-changelog-modal" id="mkcp-changelog-modal" hidden>
                <div class="mkcp-changelog-modal__backdrop" data-changelog-close></div>
                <div class="mkcp-changelog-modal__panel" role="dialog" aria-modal="true" aria-labelledby="mkcp-changelog-modal-title">
                    <button type="button" class="mkcp-changelog-modal__close" data-changelog-close aria-label="Sluiten">&times;</button>
                    <h4 id="mkcp-changelog-modal-title"></h4>
                    <ul class="mkcp-changelog-modal__list"></ul>
                </div>
            </div>

            <style>
                .mkcp-changelog-list { display: flex; flex-direction: column; gap: 6px; }
                .mkcp-changelog-row {
                    display: flex; align-items: center; gap: 10px;
                    width: 100%; text-align: left; cursor: pointer;
                    background: var(--mkcp-ui-surface2); border: 1px solid var(--mkcp-ui-border);
                    border-radius: var(--mkcp-ui-radius-sm); padding: 10px 14px;
                    color: var(--mkcp-ui-text); font: inherit;
                    transition: border-color var(--mkcp-ui-transition), background var(--mkcp-ui-transition);
                }
                .mkcp-changelog-row:hover { border-color: var(--mkcp-ui-accent); background: var(--mkcp-ui-accent-soft); }
                .mkcp-changelog-row__version { font-weight: 600; font-size: 13px; }
                .mkcp-changelog-row__date { font-size: 12px; color: var(--mkcp-ui-text3); }
                .mkcp-changelog-row__count { margin-left: auto; font-size: 12px; color: var(--mkcp-ui-text2); }

                .mkcp-changelog-modal { position: fixed; inset: 0; z-index: 100000; }
                .mkcp-changelog-modal[hidden] { display: none; }
                .mkcp-changelog-modal__backdrop {
                    position: absolute; inset: 0;
                    background: var(--mkcp-ui-lock-overlay); backdrop-filter: blur(3px);
                }
                .mkcp-changelog-modal__panel {
                    position: relative; z-index: 1;
                    max-width: 560px; max-height: 80vh; overflow-y: auto;
                    margin: 8vh auto; padding: 24px 28px;
                    background: var(--mkcp-ui-surface); border: 1px solid var(--mkcp-ui-border2);
                    border-radius: var(--mkcp-ui-radius-lg); box-shadow: var(--mkcp-ui-shadow-lg);
                    color: var(--mkcp-ui-text);
                }
                .mkcp-changelog-modal__close {
                    position: absolute; top: 14px; right: 14px;
                    width: 28px; height: 28px; line-height: 26px; text-align: center;
                    background: var(--mkcp-ui-surface2); border: 1px solid var(--mkcp-ui-border);
                    border-radius: 999px; color: var(--mkcp-ui-text2); cursor: pointer; font-size: 16px;
                }
                .mkcp-changelog-modal__close:hover { color: var(--mkcp-ui-text); border-color: var(--mkcp-ui-accent); }
                .mkcp-changelog-modal__panel h4 { margin: 0 0 14px; padding-right: 30px; font-size: 16px; }
                .mkcp-changelog-modal__list { margin: 0; padding-left: 18px; font-size: 13px; color: var(--mkcp-ui-text2); }
                .mkcp-changelog-modal__list li { margin-bottom: 8px; }
            </style>

            <script>
            (function () {
                var mkcpChangelogData = <?php echo wp_json_encode( array_values( mkcp_changelog_entries() ) ); ?>;
                var modal = document.getElementById( 'mkcp-changelog-modal' );
                if ( ! modal ) return;
                var titleEl = modal.querySelector( '#mkcp-changelog-modal-title' );
                var listEl  = modal.querySelector( '.mkcp-changelog-modal__list' );

                function openChangelog( index ) {
                    var entry = mkcpChangelogData[ index ];
                    if ( ! entry ) return;
                    titleEl.textContent = 'Versie ' + entry.version + ( entry.date ? ' — ' + entry.date : '' );
                    listEl.innerHTML = '';
                    entry.items.forEach( function ( text ) {
                        var li = document.createElement( 'li' );
                        li.textContent = text;
                        listEl.appendChild( li );
                    } );
                    modal.hidden = false;
                    document.body.style.overflow = 'hidden';
                }

                function closeChangelog() {
                    modal.hidden = true;
                    document.body.style.overflow = '';
                }

                document.addEventListener( 'click', function ( e ) {
                    var row = e.target.closest && e.target.closest( '.mkcp-changelog-row' );
                    if ( row ) {
                        openChangelog( parseInt( row.getAttribute( 'data-changelog-index' ), 10 ) );
                        return;
                    }
                    if ( e.target.closest && e.target.closest( '[data-changelog-close]' ) ) {
                        closeChangelog();
                    }
                } );

                document.addEventListener( 'keydown', function ( e ) {
                    if ( 'Escape' === e.key && ! modal.hidden ) closeChangelog();
                } );
            })();
            </script>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- LICENTIE                                                          -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel <?php echo $active_tab === 'licentie' ? 'is-active' : ''; ?>" data-panel="licentie">

                <div class="mkcp-page-header">
                    <h2>Licentie</h2>
                    <p>Activeer de plugin met een licentiesleutel. Basic-sleutels schakelen de kernfuncties in; Premium-sleutels geven toegang tot alle uitgebreide functies.</p>
                </div>

                <div class="mkcp-glass" style="margin-bottom:20px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['shield']; ?></div>
                        <h3>Licentiestatus</h3>
                        <span class="mkcp-badge" style="background:<?php echo esc_attr( $tier_color ); ?>;color:#fff;margin-left:auto;font-size:11px;padding:2px 10px;border-radius:20px;font-weight:600">
                            <?php echo esc_html( $tier_label ); ?>
                        </span>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-status-list">
                            <div class="mkcp-status-item">
                                <div class="mkcp-status-icon <?php echo $license_valid ? 'mkcp-status-icon--ok' : 'mkcp-status-icon--warn'; ?>">
                                    <?php echo $license_valid ? $icons['check'] : $icons['alert']; ?>
                                </div>
                                <div class="mkcp-status-item-label">
                                    Status
                                    <small><?php echo esc_html( $license_data['message'] ?? '—' ); ?></small>
                                </div>
                            </div>
                            <?php if ( $license_valid ) : ?>
                            <div class="mkcp-status-item">
                                <div class="mkcp-status-icon mkcp-status-icon--ok"><?php echo $icons['check']; ?></div>
                                <div class="mkcp-status-item-label">Tier <small><?php echo esc_html( $tier_label ); ?></small></div>
                            </div>
                            <?php if ( ! empty( $license_data['expires'] ) ) : ?>
                            <div class="mkcp-status-item">
                                <div class="mkcp-status-icon mkcp-status-icon--ok"><?php echo $icons['check']; ?></div>
                                <div class="mkcp-status-item-label">Geldig tot <small><?php echo esc_html( $license_data['expires'] ); ?></small></div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div class="mkcp-status-item">
                                <div class="mkcp-status-icon mkcp-status-icon--ok"><?php echo $icons['check']; ?></div>
                                <div class="mkcp-status-item-label">Domein <small><?php echo esc_html( (string) parse_url( home_url(), PHP_URL_HOST ) ); ?></small></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Licentiesleutel invoeren</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-field-group" style="max-width:500px">
                            <label class="mkcp-label" for="mkcp_license_key">Licentiesleutel</label>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="password" id="mkcp_license_key" name="mkcp_license_key"
                                    value="<?php echo esc_attr( $license_key ); ?>"
                                    class="mkcp-input" placeholder="MK-XXXX-XXXX-XXXX-XXXX"
                                    autocomplete="off" style="flex:1;font-family:monospace;letter-spacing:.05em">
                                <button type="button" class="mkcp-btn mkcp-btn--secondary" id="mkcp-toggle-key" title="Toon/verberg sleutel">
                                    <?php echo $icons['eye']; ?>
                                </button>
                            </div>
                            <div class="mkcp-hint">Voer hier je licentiesleutel in. De sleutel wordt gevalideerd via de licentieserver.</div>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;align-items:center">
                            <button type="button" class="mkcp-btn mkcp-btn--primary" id="mkcp-verify-license">
                                <?php echo $icons['zap']; ?> Verifieer nu
                            </button>
                            <button type="submit" class="mkcp-btn mkcp-btn--ghost">
                                <?php echo $icons['check']; ?> Opslaan
                            </button>
                        </div>
                        <div id="mkcp-license-result" style="margin-top:14px;display:none"></div>
                    </div>
                </div>

                <div class="mkcp-glass" style="margin-top:20px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layers']; ?></div>
                        <h3>Functies per tier</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <table style="width:100%;border-collapse:collapse;font-size:13px">
                            <thead>
                                <tr style="border-bottom:2px solid var(--mkcp-ui-border)">
                                    <th style="text-align:left;padding:8px 0;font-weight:600">Functie</th>
                                    <th style="text-align:center;padding:8px;font-weight:600;color:#27ae60">Basic</th>
                                    <th style="text-align:center;padding:8px;font-weight:600;color:#5d6bf8">Premium</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $features = [
                                    [ 'Cart popup (product list, qty, verwijderen)', true,  true  ],
                                    [ 'Gratis verzending balk',                      true,  true  ],
                                    [ 'Checkout redirect',                           true,  true  ],
                                    [ 'Betaalpictogrammen',                          true,  true  ],
                                    [ 'USP\'s',                                      true,  true  ],
                                    [ 'Couponcode veld',                             true,  true  ],
                                    [ 'Cart icoon + badge',                          true,  true  ],
                                    [ 'Minimum bestelbedrag',                        true,  true  ],
                                    [ 'BTW-split weergave',                          false, true  ],
                                    [ 'Bewaar voor later (localStorage)',            false, true  ],
                                    [ 'Deel winkelmand (URL + e-mail)',              false, true  ],
                                    [ 'Voorraad indicator',                          false, true  ],
                                    [ 'Analytics',                                   false, true  ],
                                    [ 'Content Builder (blokken / zones)',           false, true  ],
                                ];
                                foreach ( $features as [$label, $basic, $premium] ) :
                                    $row_active = ( $license_tier === 'basic' && $basic ) || ( $license_tier === 'premium' && $premium );
                                ?>
                                <tr style="border-bottom:1px solid var(--mkcp-ui-border);<?php echo $row_active ? '' : 'opacity:.55'; ?>">
                                    <td style="padding:7px 0"><?php echo esc_html( $label ); ?></td>
                                    <td style="text-align:center;padding:7px"><?php echo $basic ? '<span style="color:#27ae60">✓</span>' : '<span style="color:#ccc">—</span>'; ?></td>
                                    <td style="text-align:center;padding:7px"><?php echo $premium ? '<span style="color:#5d6bf8">✓</span>' : '<span style="color:#ccc">—</span>'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- CART POPUP — Cross-selling                                       -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel mkcp-panel--crosssell <?php echo $active_tab === 'crosssell' ? 'is-active' : ''; ?>" data-panel="crosssell">

                <div class="mkcp-page-header">
                    <h2>Cross-selling</h2>
                    <p>Toon gerelateerde producten in de popup wanneer een klant iets aan de winkelmand toevoegt.</p>
                </div>

                <!-- Inschakelen -->
                <div class="mkcp-glass" style="margin-bottom:20px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['shopping-cart']; ?></div>
                        <h3>Cross-selling</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:20px">
                            <div>
                                <strong style="font-size:13px">Cross-selling inschakelen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Toont gerelateerde producten onderaan de popup.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_crosssell_enabled" value="1"
                                    <?php checked( ! empty( $c['crosssell_enabled'] ) ); ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <!-- Producten tonen -->
                        <div style="padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:16px">
                            <strong style="font-size:13px;display:block;margin-bottom:12px">Welke producten tonen?</strong>
                            <div style="display:flex;flex-direction:column;gap:10px">
                                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                                    <input type="radio" name="mkcp_crosssell_mode" value="crosssells"
                                        <?php checked( ( $c['crosssell_mode'] ?? 'category' ) === 'crosssells' ); ?>
                                        style="margin-top:3px;accent-color:var(--mkcp-ui-accent)">
                                    <div>
                                        <strong style="font-size:13px">Handmatig ingestelde producten</strong>
                                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:2px 0 0">Gebruik de "Cross-sells" die je per product instelt via WooCommerce → Producten → Gelinkte producten.</p>
                                    </div>
                                </label>
                                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                                    <input type="radio" name="mkcp_crosssell_mode" value="category"
                                        <?php checked( ( $c['crosssell_mode'] ?? 'category' ) === 'category' ); ?>
                                        style="margin-top:3px;accent-color:var(--mkcp-ui-accent)">
                                    <div>
                                        <strong style="font-size:13px">Zelfde categorie</strong>
                                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:2px 0 0">Toont automatisch andere producten uit dezelfde categorie als de producten in de winkelmand.</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Sectietitel -->
                        <div style="display:flex;align-items:center;gap:14px;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:16px">
                            <div style="flex:1">
                                <strong style="font-size:13px">Sectietitel</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Koptekst boven de cross-sell producten.</p>
                            </div>
                            <input type="text" name="mkcp_crosssell_title"
                                value="<?php echo esc_attr( $c['crosssell_title'] ?: 'Misschien ook interessant?' ); ?>"
                                placeholder="Misschien ook interessant?"
                                class="regular-text" style="font-size:13px;max-width:260px">
                        </div>

                        <!-- Aantal -->
                        <div style="display:flex;align-items:center;gap:14px">
                            <div style="flex:1">
                                <strong style="font-size:13px">Maximaal aantal producten</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Hoeveel producten er maximaal worden getoond (1–6).</p>
                            </div>
                            <input type="number" name="mkcp_crosssell_limit"
                                value="<?php echo esc_attr( $c['crosssell_limit'] ?? 3 ); ?>"
                                min="1" max="6" step="1"
                                style="width:64px;font-size:13px;text-align:center">
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass" style="margin-bottom:20px;background:var(--mkcp-ui-bg2)">
                    <div class="mkcp-glass-body" style="font-size:12px;color:var(--mkcp-ui-text3)">
                        <strong style="color:var(--mkcp-ui-text2)">Tip:</strong> Stel handmatige cross-sells in via
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" target="_blank" style="color:var(--mkcp-ui-accent)">WooCommerce → Producten</a>
                        → kies een product → tabblad <em>Gelinkte producten</em> → veld <em>Cross-sells</em>.
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- CART CHECKOUT — Dashboard                                        -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel mkcp-panel--checkout-dashboard <?php echo $active_tab === 'checkout-dashboard' ? 'is-active' : ''; ?>" data-panel="checkout-dashboard">

                <div class="mkcp-page-header">
                    <h2>Cart Checkout</h2>
                    <p>Overzicht van de checkout-pagina aanpassingen.</p>
                </div>

                <!-- Premium feature banner -->
                <?php
                $co_lic_color = $is_premium ? '#5d6bf8' : ( $license_tier === 'basic' ? '#27ae60' : '#e74c3c' );
                $co_lic_label = $is_premium ? 'Premium' : ( $license_tier === 'basic' ? 'Basic — upgrade vereist' : 'Geen licentie' );
                ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-left:3px solid <?php echo esc_attr( $co_lic_color ); ?>">
                    <div class="mkcp-glass-body">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                            <div style="display:flex;align-items:center;gap:14px">
                                <div class="mkcp-dash-card-icon" style="background:<?php echo esc_attr( $co_lic_color ); ?>1a;color:<?php echo esc_attr( $co_lic_color ); ?>;flex-shrink:0">
                                    <?php echo $icons['shield']; ?>
                                </div>
                                <div>
                                    <div style="font-size:11px;color:var(--mkcp-ui-text3);text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:4px">Cart Checkout is een premium functie</div>
                                    <div style="font-size:18px;font-weight:700;color:<?php echo esc_attr( $co_lic_color ); ?>;line-height:1.1"><?php echo esc_html( $co_lic_label ); ?></div>
                                    <div style="font-size:12px;color:var(--mkcp-ui-text3);margin-top:3px">
                                        <?php if ( $is_premium ) : ?>
                                            Alle checkout aanpassingen zijn actief beschikbaar.
                                        <?php elseif ( $license_tier === 'basic' ) : ?>
                                            Upgrade naar premium om de checkout pagina aan te passen.
                                        <?php else : ?>
                                            Activeer een licentie om Cart Checkout te gebruiken.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="mkcp-btn <?php echo $is_premium ? 'mkcp-btn--ghost' : 'mkcp-btn--primary'; ?>" data-goto="licentie" style="white-space:nowrap">
                                <?php echo $icons['shield']; ?> Licentie beheren
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Status kaarten -->
                <div class="mkcp-dash-grid" style="margin-bottom:20px">

                    <!-- Header status -->
                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon" style="background:<?php echo ! empty( $cfg_co['header_enabled'] ) ? '#5d6bf81a' : 'var(--mkcp-ui-bg2)'; ?>;color:<?php echo ! empty( $cfg_co['header_enabled'] ) ? '#5d6bf8' : 'var(--mkcp-ui-text3)'; ?>">
                            <?php echo $icons['image']; ?>
                        </div>
                        <div class="mkcp-dash-card-value" style="font-size:15px;font-weight:700">
                            <?php echo ! empty( $cfg_co['header_enabled'] ) ? 'Aan' : 'Uit'; ?>
                        </div>
                        <div class="mkcp-dash-card-sub">Aangepaste header</div>
                        <?php if ( ! empty( $cfg_co['header_enabled'] ) ) : ?>
                            <div style="margin-top:8px;font-size:11px;display:flex;align-items:center;gap:6px">
                                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $cfg_co['header_bg'] ?: '#fff' ); ?>;border:1px solid var(--mkcp-ui-border)"></span>
                                <?php echo esc_html( $cfg_co['header_bg'] ?: '#ffffff' ); ?>
                                <?php if ( ! empty( $cfg_co['header_logo_id'] ) ) : ?>
                                    &nbsp;· Eigen logo
                                <?php else : ?>
                                    &nbsp;· Site logo
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Footer status -->
                    <div class="mkcp-dash-card">
                        <div class="mkcp-dash-card-icon" style="background:<?php echo ! empty( $cfg_co['footer_enabled'] ) ? '#22c55e1a' : 'var(--mkcp-ui-bg2)'; ?>;color:<?php echo ! empty( $cfg_co['footer_enabled'] ) ? '#22c55e' : 'var(--mkcp-ui-text3)'; ?>">
                            <?php echo $icons['layout']; ?>
                        </div>
                        <div class="mkcp-dash-card-value" style="font-size:15px;font-weight:700">
                            <?php echo ! empty( $cfg_co['footer_enabled'] ) ? 'Aan' : 'Uit'; ?>
                        </div>
                        <div class="mkcp-dash-card-sub">Aangepaste footer</div>
                        <?php
                        $footer_block_count = count( array_filter( $cfg_co['footer_blocks'] ?? [], fn($b) => ! empty( $b['enabled'] ) ) );
                        ?>
                        <?php if ( $footer_block_count > 0 ) : ?>
                            <div style="margin-top:8px;font-size:11px;color:var(--mkcp-ui-text3)">
                                <?php echo $footer_block_count; ?> <?php echo $footer_block_count === 1 ? 'blok' : 'blokken'; ?> ingesteld
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Wat doet Cart Checkout -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['credit-card']; ?></div>
                        <h3>Wat doet Cart Checkout?</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div style="display:flex;flex-direction:column;gap:12px">
                            <div style="display:flex;gap:12px;align-items:flex-start">
                                <span style="color:#5d6bf8;flex-shrink:0;margin-top:1px"><?php echo $icons['image']; ?></span>
                                <div>
                                    <strong style="font-size:13px;display:block;margin-bottom:2px">Afleidingsvrije header</strong>
                                    <span style="font-size:12px;color:var(--mkcp-ui-text3)">Verbergt de thema-header op de checkoutpagina en toont alleen een logo — net als grote webshops (Shopify, Bol.com). Minder afleiding = hogere conversie.</span>
                                </div>
                            </div>
                            <div style="display:flex;gap:12px;align-items:flex-start">
                                <span style="color:#22c55e;flex-shrink:0;margin-top:1px"><?php echo $icons['layout']; ?></span>
                                <div>
                                    <strong style="font-size:13px;display:block;margin-bottom:2px">Eigen footer met blokken</strong>
                                    <span style="font-size:12px;color:var(--mkcp-ui-text3)">Vervang de thema-footer door een eigen footer. Voeg USP's, betaalmethode-iconen, tekst of scheidingslijnen toe via de block builder.</span>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--mkcp-ui-border)">
                            <button type="button" class="mkcp-btn mkcp-btn--primary" data-goto="checkout-settings">
                                <?php echo $icons['credit-card']; ?> Naar instellingen
                            </button>
                        </div>
                    </div>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- CART CHECKOUT — Algemeen                                          -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel mkcp-panel--checkout-general <?php echo $active_tab === 'checkout-general' ? 'is-active' : ''; ?>" data-panel="checkout-general">

                <div class="mkcp-page-header">
                    <h2>Cart Checkout</h2>
                    <p>Schakel de checkout-aanpassingen in of uit, onafhankelijk van de cart popup.</p>
                </div>

                <div class="mkcp-glass" style="<?php echo $co_enabled ? 'border-color:var(--mkcp-ui-accent)' : ''; ?>">
                    <div class="mkcp-glass-body" style="display:flex;justify-content:space-between;align-items:center">
                        <div>
                            <strong style="font-size:14px;color:var(--mkcp-ui-text)">Cart Checkout inschakelen</strong>
                            <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:4px 0 0">Schakel de checkout-aanpassingen (header, stappenindicator, footer) in of uit — onafhankelijk van de cart popup.</p>
                        </div>
                        <label class="mkcp-toggle" style="flex-shrink:0;margin-left:20px">
                            <input type="checkbox" name="mkcp_checkout_enabled" value="1"
                                <?php checked( $co_enabled ); ?>>
                            <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                        </label>
                    </div>
                </div>

                <?php
                $postcode_checker_detected = mkcp_postcode_checker_active();
                $postcode_lock_on = ! empty( $cfg_co['postcode_checker_lock_fields'] );
                $vat_checker_detected = mkcp_vat_checker_active();
                $vat_status_on = ! empty( $cfg_co['vat_checker_status_enabled'] );
                ?>
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['package']; ?></div>
                        <h3>Integraties</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <!-- WP Overnight detectie -->
                        <div style="display:flex;align-items:center;gap:14px;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:16px">
                            <div style="width:36px;height:36px;border-radius:8px;background:<?php echo $postcode_checker_detected ? '#dcfce7' : 'var(--mkcp-ui-bg2)'; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <?php if ( $postcode_checker_detected ) : ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php else : ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--mkcp-ui-text3)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong style="font-size:13px;display:block">WP Overnight Postcode Checker</strong>
                                <span style="font-size:12px;color:<?php echo $postcode_checker_detected ? '#16a34a' : 'var(--mkcp-ui-text3)'; ?>">
                                    <?php echo $postcode_checker_detected ? 'Gedetecteerd en actief' : 'Niet gevonden'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Optie: straatnaam + plaats vergrendelen -->
                        <div class="mkcp-setting-row" style="<?php echo ! $postcode_checker_detected ? 'opacity:.5;pointer-events:none' : ''; ?>padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:16px">
                            <div class="mkcp-setting-label">
                                <strong>Straatnaam &amp; Plaats vergrendelen</strong>
                                <small>Maakt de door de postcode checker ingevulde velden grijs en niet bewerkbaar</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_checkout_postcode_checker_lock_fields" value="1"
                                            <?php checked( $postcode_lock_on ); ?>
                                            <?php echo ! $postcode_checker_detected ? 'disabled' : ''; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Velden vergrendelen</span>
                                </div>
                                <p class="mkcp-input-hint">De velden <em>Straatnaam</em> en <em>Plaats</em> worden automatisch ingevuld door de postcode checker. Met deze optie zijn ze grijs weergegeven en niet handmatig aanpasbaar.</p>
                            </div>
                        </div>

                        <!-- EU/UK VAT Validation Manager detectie -->
                        <div style="display:flex;align-items:center;gap:14px;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:16px">
                            <div style="width:36px;height:36px;border-radius:8px;background:<?php echo $vat_checker_detected ? '#dcfce7' : 'var(--mkcp-ui-bg2)'; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <?php if ( $vat_checker_detected ) : ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php else : ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--mkcp-ui-text3)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong style="font-size:13px;display:block">EU/UK VAT Validation Manager</strong>
                                <span style="font-size:12px;color:<?php echo $vat_checker_detected ? '#16a34a' : 'var(--mkcp-ui-text3)'; ?>">
                                    <?php echo $vat_checker_detected ? 'Gedetecteerd en actief' : 'Niet gevonden'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Optie: master-switch voor de hele BTW-integratie -->
                        <div class="mkcp-setting-row" style="<?php echo ! $vat_checker_detected ? 'opacity:.5;pointer-events:none' : ''; ?>">
                            <div class="mkcp-setting-label">
                                <strong>BTW-integratie actief</strong>
                                <small>Statusbalk bij het BTW-nummerveld, veld pas tonen zodra er een bedrijfsnaam is ingevuld, en BTW-verlegging (prijzen vergrendeld op excl. BTW bij een geldig nummer)</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_checkout_vat_checker_status_enabled" value="1"
                                            <?php checked( $vat_status_on ); ?>
                                            <?php echo ! $vat_checker_detected ? 'disabled' : ''; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Integratie actief</span>
                                </div>
                                <p class="mkcp-input-hint">Uit = het BTW-nummerveld is nergens op de checkout te zien en er is geen BTW-verlegging. Een eventueel nog vergrendelde excl.-BTW-weergave wordt bij de eerstvolgende paginalaad automatisch opgeheven.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <?php
                $country_visible = isset( $cfg_co['country_field_visible'] ) ? (bool) $cfg_co['country_field_visible'] : true;
                $country_locked  = ! empty( $cfg_co['country_field_locked'] );
                ?>
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['sliders']; ?></div>
                        <h3>Formulier velden</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <!-- Land aan/uit -->
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Land/regio veld tonen</strong>
                                <small>Verberg het land-dropdown als je alleen Nederland verkoopt</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_checkout_country_field_visible" id="mkcp-country-visible" value="1"
                                            <?php checked( $country_visible ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Veld tonen</span>
                                </div>
                            </div>
                        </div>

                        <!-- Land vergrendelen (alleen zichtbaar als veld aan staat) -->
                        <div class="mkcp-setting-row" id="mkcp-country-lock-row" style="<?php echo ! $country_visible ? 'opacity:.4;pointer-events:none' : ''; ?>">
                            <div class="mkcp-setting-label">
                                <strong>Land/regio vergrendelen</strong>
                                <small>Grijs en niet aanpasbaar — waarde wordt wel meegestuurd</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_checkout_country_field_locked" value="1"
                                            <?php checked( $country_locked ); ?>
                                            <?php echo ! $country_visible ? 'disabled' : ''; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Veld vergrendelen</span>
                                </div>
                            </div>
                        </div>

                        <!-- Bedrijfsnaam aan/uit — thema-onafhankelijk, direct onder Voornaam/Achternaam -->
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Bedrijfsnaam veld tonen</strong>
                                <small>Voegt een optioneel bedrijfsnaam-veld toe, direct onder Voornaam/Achternaam</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_checkout_company_field_enabled" value="1"
                                            <?php checked( ! empty( $cfg_co['company_field_enabled'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Veld tonen</span>
                                </div>
                            </div>
                        </div>

                        <!-- Bestelnotities aan/uit — stuurt WooCommerce's eigen filter aan, geen duplicaat-veld -->
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Bestelnotities veld tonen</strong>
                                <small>Vrij tekstveld voor de klant, bv. bezorginstructies — verschijnt onder de adresvelden</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_checkout_order_notes_enabled" value="1"
                                            <?php checked( ! empty( $cfg_co['order_notes_enabled'] ) ); ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Veld tonen</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['sliders']; ?></div>
                        <h3>Bestelknop</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Knoptekst</strong>
                                <small>Leeg = WooCommerce's eigen standaardtekst ("Bestelling plaatsen")</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_checkout_button_text"
                                       placeholder="Bestelling plaatsen"
                                       value="<?php echo esc_attr( $cfg_co['checkout_button_text'] ?? '' ); ?>">
                            </div>
                        </div>

                    </div>
                </div>
                <script>
                (function(){
                    var cb = document.getElementById('mkcp-country-visible');
                    var row = document.getElementById('mkcp-country-lock-row');
                    if (!cb || !row) return;
                    cb.addEventListener('change', function(){
                        var on = cb.checked;
                        row.style.opacity = on ? '' : '.4';
                        row.style.pointerEvents = on ? '' : 'none';
                        row.querySelector('input[type=checkbox]').disabled = !on;
                    });
                })();
                </script>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary">
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- CART CHECKOUT — Header & Footer                                  -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel mkcp-panel--checkout-settings <?php echo $active_tab === 'checkout-settings' ? 'is-active' : ''; ?>" data-panel="checkout-settings">

                <div class="mkcp-page-header">
                    <h2>Cart Checkout</h2>
                    <p>Geef bezoekers een afleidingsvrije afrekenervaring — eigen header met logo en een aanpasbare footer op de checkoutpagina.</p>
                </div>

                <?php if ( ! $is_premium ) : ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
                        <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                            <strong>Premium vereist</strong> — Cart Checkout aanpassingen zijn beschikbaar met een premium licentie.
                            <button type="button" data-goto="licentie" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;text-decoration:underline">Licentie activeren →</button>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $co_disabled = '';
                $co_opacity  = '';
                ?>

                <!-- ── Checkout Header ──────────────────────────────────────── -->
                <div class="mkcp-glass" style="margin-bottom:20px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['image']; ?></div>
                        <h3>Checkout Header</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body" style="<?php echo $co_opacity; ?>">

                        <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border)">
                            <div>
                                <strong style="font-size:13px">Aangepaste header inschakelen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Verbergt de thema-header en toont alleen het logo op de checkoutpagina.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_header_enabled" value="1"
                                    <?php checked( ! empty( $cfg_co['header_enabled'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <div style="display:flex;align-items:center;gap:14px;padding:16px 0;border-bottom:1px solid var(--mkcp-ui-border)">
                            <div style="flex:1">
                                <strong style="font-size:13px">Achtergrondkleur</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Achtergrondkleur van de checkout header balk.</p>
                            </div>
                            <input type="color" name="mkcp_checkout_header_bg"
                                value="<?php echo esc_attr( $cfg_co['header_bg'] ?: '#ffffff' ); ?>"
                                <?php echo $co_disabled; ?>
                                style="width:44px;height:36px;border:1px solid var(--mkcp-ui-border);border-radius:6px;cursor:pointer;padding:2px">
                        </div>

                        <div style="padding-top:16px">
                            <strong style="font-size:13px;display:block;margin-bottom:4px">Logo</strong>
                            <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:0 0 14px">Upload een eigen logo. Laat leeg om het site-logo te gebruiken als fallback.</p>

                            <input type="hidden" name="mkcp_checkout_header_logo_id" id="mkcp-checkout-logo-id"
                                value="<?php echo esc_attr( $cfg_co['header_logo_id'] ?: '' ); ?>">

                            <div style="display:flex;align-items:flex-start;gap:14px">
                                <div style="width:120px;height:72px;background:var(--mkcp-ui-bg2);border:1px solid var(--mkcp-ui-border);border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                                    <?php if ( $logo_url_co ) : ?>
                                        <img id="mkcp-checkout-logo-preview" src="<?php echo esc_url( $logo_url_co ); ?>" alt="" style="max-width:110px;max-height:62px;width:auto;height:auto;display:block">
                                    <?php else : ?>
                                        <img id="mkcp-checkout-logo-preview" src="" alt="" style="max-width:110px;max-height:62px;display:none">
                                        <span style="font-size:11px;color:var(--mkcp-ui-text3);text-align:center">Geen logo</span>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:8px;justify-content:center">
                                    <button type="button" id="mkcp-checkout-logo-upload" class="mkcp-btn mkcp-btn--secondary" <?php echo $co_disabled; ?>>
                                        <?php echo $icons['image']; ?> Logo uploaden
                                    </button>
                                    <button type="button" id="mkcp-checkout-logo-remove" class="mkcp-btn mkcp-btn--ghost"
                                        style="font-size:11px;<?php echo $logo_url_co ? '' : 'display:none'; ?>"
                                        <?php echo $co_disabled; ?>>
                                        Verwijderen
                                    </button>
                                    <span style="font-size:11px;color:var(--mkcp-ui-text3)">Max. hoogte 60px op frontend</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Checkout Footer ──────────────────────────────────────── -->
                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layout']; ?></div>
                        <h3>Checkout Footer</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body" style="<?php echo $co_opacity; ?>">

                        <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:20px">
                            <div>
                                <strong style="font-size:13px">Aangepaste footer inschakelen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Verbergt de thema-footer en toont de blokken hieronder op de checkoutpagina.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_footer_enabled" value="1"
                                    <?php checked( ! empty( $cfg_co['footer_enabled'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <input type="hidden" name="mkcp_footer_blocks" id="mkcp-footer-blocks-json"
                            value="<?php echo esc_attr( wp_json_encode(
                                array_values( array_filter( $cfg_co['footer_blocks'] ?? [], fn($b) => ($b['zone'] ?? '') === 'footer' ) )
                            ) ); ?>">

                        <div style="font-size:12px;font-weight:600;color:var(--mkcp-ui-text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Footer blokken</div>

                        <div id="mkcp-footer-block-list" style="display:flex;flex-direction:column;gap:8px;min-height:32px"></div>

                        <div class="mkcp-footer-add-row" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                            <?php
                            $block_types = [
                                'text'    => [ 'icon' => 'type',     'label' => 'Tekst' ],
                                'usp'     => [ 'icon' => 'check',    'label' => 'USP' ],
                                'divider' => [ 'icon' => 'minus',    'label' => 'Scheidingslijn' ],
                                'image'   => [ 'icon' => 'image',    'label' => 'Afbeelding' ],
                            ];
                            foreach ( $block_types as $btype => $binfo ) : ?>
                            <button type="button" class="mkcp-footer-add-block" data-type="<?php echo esc_attr( $btype ); ?>"
                                style="display:inline-flex;align-items:center;gap:5px;font-size:12px;padding:5px 12px;background:var(--mkcp-ui-bg);border:1px dashed var(--mkcp-ui-border);border-radius:6px;cursor:pointer;color:var(--mkcp-ui-text2)"
                                <?php echo $co_disabled; ?>>
                                <?php echo $icons[ $binfo['icon'] ] ?? ''; ?> <?php echo esc_html( $binfo['label'] ); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <p style="font-size:11px;color:var(--mkcp-ui-text3);margin:12px 0 0">Sleep blokken om de volgorde aan te passen. De blokken worden naast elkaar weergegeven in de footer.</p>

                    </div>
                </div>

                <!-- ── Stap-indicator ──────────────────────────────────────── -->
                <div class="mkcp-glass" style="margin-top:20px;margin-bottom:20px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layers']; ?></div>
                        <h3>Stap-indicator</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body" style="<?php echo $co_opacity; ?>">

                        <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:20px">
                            <div>
                                <strong style="font-size:13px">Stap-indicator tonen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Toont een genummerde voortgangsbalk in de header: Winkelwagen → Gegevens → Bevestiging.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_steps_enabled" value="1"
                                    <?php checked( ! empty( $cfg_co['steps_enabled'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
                            <?php
                            $default_labels = [ 'Winkelwagen', 'Gegevens', 'Bevestiging' ];
                            $saved_labels   = $cfg_co['steps_labels'] ?? $default_labels;
                            foreach ( [ 0 => 'Stap 1', 1 => 'Stap 2', 2 => 'Stap 3' ] as $i => $placeholder ) : ?>
                            <div>
                                <label style="font-size:12px;color:var(--mkcp-ui-text3);display:block;margin-bottom:5px"><?php echo esc_html( $placeholder ); ?></label>
                                <input type="text" name="mkcp_checkout_steps_labels[]"
                                    value="<?php echo esc_attr( $saved_labels[ $i ] ?? $default_labels[ $i ] ); ?>"
                                    placeholder="<?php echo esc_attr( $default_labels[ $i ] ); ?>"
                                    <?php echo $co_disabled; ?>
                                    class="widefat" style="font-size:13px">
                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
                
                <!-- ── SSL badge ──────────────────────────────────────── -->
                <div class="mkcp-glass" style="margin-top:20px;margin-bottom:20px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layers']; ?></div>
                        <h3>SSL-badge</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body" style="<?php echo $co_opacity; ?>">

                        <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <strong style="font-size:13px">SSL-badge tonen in de header</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Toont een vertrouwensbadge (bv. "SSL-versleuteling") naast de stappenbalk in de checkout-header. Vereist dat de header aanstaat.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_ssl_badge_enabled" value="1"
                                    <?php checked( ! empty( $cfg_co['ssl_badge_enabled'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <div class="mkcp-field-row" style="margin-top:14px">
                            <label style="font-size:12px;color:var(--mkcp-ui-text3);display:block;margin-bottom:5px">Badge-tekst</label>
                            <input type="text" name="mkcp_checkout_ssl_badge_text"
                                value="<?php echo esc_attr( $cfg_co['ssl_badge_text'] ?? 'SSL-versleuteling' ); ?>"
                                placeholder="SSL-versleuteling"
                                <?php echo $co_disabled; ?>
                                class="widefat" style="font-size:13px">
                        </div>

                    </div>
                </div>

                <!-- ── Betaalmethode iconen ─────────────────────────────────── -->
                <div class="mkcp-glass" style="margin-bottom:20px">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['credit-card']; ?></div>
                        <h3>Betaalmethode iconen</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body" style="<?php echo $co_opacity; ?>">

                        <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <strong style="font-size:13px">Betaalmethode iconen tonen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Toont de betaaliconen (uit de popup-instellingen) onder het orderoverzicht op de checkoutpagina.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_payment_icons_enabled" value="1"
                                    <?php checked( ! empty( $cfg_co['payment_icons_enabled'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <?php
                        $pay_icons = array_filter( $c['payment_icons'] ?? [], fn( $p ) => ! empty( $p['url'] ) );
                        if ( empty( $pay_icons ) ) : ?>
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:12px 0 0">
                            Voeg eerst betaalmethode iconen toe via
                            <button type="button" data-goto="design" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;text-decoration:underline">Cart Popup → Design</button>.
                        </p>
                        <?php else : ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
                            <?php foreach ( $pay_icons as $pi ) : ?>
                            <img src="<?php echo esc_url( $pi['url'] ); ?>" alt="<?php echo esc_attr( $pi['label'] ?? '' ); ?>"
                                style="height:28px;width:auto;border-radius:4px;border:1px solid var(--mkcp-ui-border)" loading="lazy">
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary" <?php echo $co_disabled; ?>>
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- CART CHECKOUT — Content Builder                                   -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel mkcp-panel--checkout-builder <?php echo $active_tab === 'checkout-builder' ? 'is-active' : ''; ?>" data-panel="checkout-builder">

                <div class="mkcp-page-header">
                    <h2>Content Builder</h2>
                    <p>Sleep blokken naar de gewenste zone in de checkout. Zonder live preview — bekijk het resultaat op de echte checkoutpagina.</p>
                </div>

                <?php if ( ! $is_premium ) : ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
                        <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                            <strong>Premium vereist</strong> — Cart Checkout aanpassingen zijn beschikbaar met een premium licentie.
                            <button type="button" data-goto="licentie" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;text-decoration:underline">Licentie activeren →</button>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <input type="hidden" name="mkcp_checkout_blocks" id="mkcp-co-blocks-json"
                    value="<?php echo esc_attr( wp_json_encode( $cfg_co['checkout_blocks'] ?? [] ) ); ?>">

                <div class="mkcp-builder-wrap" data-mkcp-tier="premium" style="<?php echo $co_opacity; ?>">
                    <div class="mkcp-builder-canvas">

                        <!-- Block type picker -->
                        <div class="mkcp-builder-picker">
                            <div class="mkcp-builder-picker-header">
                                <span class="mkcp-builder-picker-title">Blokken</span>
                                <span class="mkcp-builder-picker-hint">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><line x1="5" y1="9" x2="19" y2="9"/><line x1="5" y1="15" x2="19" y2="15"/><circle cx="3" cy="9" r="1" fill="currentColor" stroke="none"/><circle cx="3" cy="15" r="1" fill="currentColor" stroke="none"/></svg>
                                    Sleep naar een zone
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="margin-left:2px"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                </span>
                            </div>
                            <div class="mkcp-builder-picker-grid" id="mkcp-co-block-picker">
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="text" <?php echo $co_disabled; ?>>
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">T</span>
                                    <span>Tekst</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="divider" <?php echo $co_disabled; ?>>
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">—</span>
                                    <span>Scheidingslijn</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="usp" <?php echo $co_disabled; ?>>
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">✓</span>
                                    <span>USP</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="image" <?php echo $co_disabled; ?>>
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    </span>
                                    <span>Afbeelding</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="banner" <?php echo $co_disabled; ?>>
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    </span>
                                    <span>Banner</span>
                                </button>
                                <button type="button" class="mkcp-block-add-btn" draggable="true" data-type="button" <?php echo $co_disabled; ?>>
                                    <span class="mkcp-block-add-drag">⠿</span>
                                    <span class="mkcp-block-add-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><rect x="3" y="8" width="18" height="8" rx="4"/></svg>
                                    </span>
                                    <span>Knop</span>
                                </button>
                            </div>
                        </div>

                        <!-- Zone drop areas -->
                        <div class="mkcp-zones" id="mkcp-co-zones">
                            <?php
                            $mkcp_co_zones = [
                                'above-order-review' => 'Boven besteloverzicht',
                                'below-order-review' => 'Onder besteloverzicht',
                                'above-payment'      => 'Boven betaalmethodes',
                                'below-payment'      => 'Onder betaalmethodes / boven bestelknop',
                                'field'               => 'Onder een specifiek formulierveld',
                            ];
                            $mkcp_co_all_blocks = $cfg_co['checkout_blocks'] ?? [];
                            foreach ( $mkcp_co_zones as $mkcp_zkey => $mkcp_zlabel ) :
                                $mkcp_zone_blocks = array_filter( $mkcp_co_all_blocks, fn( $b ) =>
                                    $mkcp_zkey === 'field'
                                        ? strpos( (string) ( $b['zone'] ?? '' ), 'field:' ) === 0
                                        : ( $b['zone'] ?? '' ) === $mkcp_zkey
                                );
                            ?>
                            <div class="mkcp-zone" data-zone="<?php echo esc_attr( $mkcp_zkey ); ?>">
                                <div class="mkcp-zone-header">
                                    <span class="mkcp-zone-label"><?php echo esc_html( $mkcp_zlabel ); ?></span>
                                    <span class="mkcp-zone-count"><?php echo count( $mkcp_zone_blocks ); ?></span>
                                </div>
                                <?php if ( $mkcp_zkey === 'field' ) : ?>
                                <p style="font-size:11px;color:var(--mkcp-ui-text3);margin:0 0 8px">Kies per blok naar welk factuur- of verzendveld het moet verschijnen.</p>
                                <?php endif; ?>
                                <div class="mkcp-zone-list js-mkcp-co-zone" data-zone="<?php echo esc_attr( $mkcp_zkey ); ?>"></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div><!-- /mkcp-builder-canvas -->
                </div><!-- /mkcp-builder-wrap -->

                <!-- Block editor modal -->
                <div class="mkcp-block-editor" id="mkcp-co-block-editor" style="display:none">
                    <div class="mkcp-block-editor-inner">
                        <div class="mkcp-block-editor-header">
                            <strong id="mkcp-co-editor-title">Blok bewerken</strong>
                            <button type="button" class="mkcp-block-editor-close" id="mkcp-co-editor-close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div class="mkcp-block-editor-body" id="mkcp-co-editor-body">
                            <!-- Dynamically filled by checkout.js -->
                        </div>
                        <div class="mkcp-block-editor-footer">
                            <button type="button" class="mkcp-btn mkcp-btn--secondary" id="mkcp-co-editor-cancel">Annuleren</button>
                            <button type="button" class="mkcp-btn mkcp-btn--primary" id="mkcp-co-editor-save">Opslaan</button>
                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary" <?php echo $co_disabled; ?>>
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- CART CHECKOUT — Styling                                           -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <div class="mkcp-panel mkcp-panel--checkout-styling <?php echo $active_tab === 'checkout-styling' ? 'is-active' : ''; ?>" data-panel="checkout-styling">

                <div class="mkcp-page-header">
                    <h2>Cart Checkout — Styling</h2>
                    <p>Bepaal welke CSS geladen wordt op de checkout pagina en geef je eigen stijlen voorrang.</p>
                </div>

                <?php if ( ! $is_premium ) : ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
                        <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                            <strong>Premium vereist</strong> — Checkout styling-opties zijn beschikbaar met een premium licentie.
                            <button type="button" data-goto="licentie" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;text-decoration:underline">Licentie activeren →</button>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['bar-chart']; ?></div>
                        <h3>Prijzen &amp; BTW</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php
                        $popup_btw_on    = ! empty( $c['btw_split'] );
                        $co_btw_follow   = isset( $cfg_co['btw_follow_popup'] ) ? (bool) $cfg_co['btw_follow_popup'] : true;
                        $co_btw_override = ! empty( $cfg_co['btw_switch'] );
                        $effective_btw   = $co_btw_follow ? $popup_btw_on : $co_btw_override;
                        $popup_label     = $popup_btw_on
                            ? '<span style="color:var(--mkcp-ui-accent);font-weight:600">AAN</span>'
                            : '<span style="color:var(--mkcp-ui-text3)">UIT</span>';
                        ?>

                        <div class="mkcp-setting-row">
                            <div>
                                <strong style="font-size:13px">BTW-schakelaar</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:4px 0 0">
                                    Popup-instelling: <?php echo $popup_label; ?> &nbsp;·&nbsp;
                                    Actief op checkout: <?php echo $effective_btw
                                        ? '<span style="color:var(--mkcp-ui-accent);font-weight:600">AAN</span>'
                                        : '<span style="color:var(--mkcp-ui-text3)">UIT</span>'; ?>
                                </p>
                                <p style="font-size:11px;color:var(--mkcp-ui-text3);margin:6px 0 0">
                                    De checkout volgt standaard de popup-instelling. Vink &quot;Overschrijven&quot; aan voor een eigen keuze.
                                </p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row" style="padding-top:6px">
                            <div>
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--mkcp-ui-text2);cursor:pointer;font-weight:500">
                                    <input type="hidden"   name="mkcp_checkout_btw_follow_popup" value="1">
                                    <input type="checkbox" name="mkcp_checkout_btw_follow_popup" value="0"
                                        <?php checked( ! $co_btw_follow ); ?>
                                        <?php echo $co_disabled; ?>
                                        id="mkcp-btw-override-chk"
                                        onchange="document.getElementById('mkcp-btw-override-row').style.display=this.checked?'flex':'none'">
                                    Overschrijf popup-instelling
                                </label>
                                <?php if ( $co_btw_follow && $popup_btw_on !== $co_btw_override ) : ?>
                                <p style="font-size:11px;color:#b45309;margin:4px 0 0">
                                    &#9888; Vorige eigen instelling (<?php echo $co_btw_override ? 'AAN' : 'UIT'; ?>) wordt genegeerd — checkout volgt nu de popup.
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mkcp-setting-row" id="mkcp-btw-override-row"
                             style="<?php echo $co_btw_follow ? 'display:none' : 'display:flex'; ?>;padding-top:4px">
                            <div>
                                <strong style="font-size:13px">BTW-schakelaar aan/uit</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Eigen instelling — overschrijft de popup-instelling.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_btw_switch" value="1"
                                    <?php checked( $co_btw_override ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['code']; ?></div>
                        <h3>Theme styling</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div>
                                <strong style="font-size:13px">Theme styling uitschakelen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Verwijdert alle (child) theme stylesheets op de checkout pagina zodat <code>checkout.css</code> volledig de stijl bepaalt.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_dequeue_theme_css" value="1"
                                    <?php checked( ! empty( $cfg_co['dequeue_theme_css'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <div class="mkcp-setting-row">
                            <div>
                                <strong style="font-size:13px">Theme hooks uitschakelen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Verwijdert alle PHP hooks én <code>woocommerce/checkout/*</code> template-overrides uit het (child) thema op de checkout pagina — de checkout valt dan terug op WooCommerce's eigen standaardtemplates. Hooks in <code>mk-cart-popup/cart-hooks.php</code> en <code>checkout-hooks.php</code> blijven actief.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_dequeue_theme_hooks" value="1"
                                    <?php checked( ! empty( $cfg_co['dequeue_theme_hooks'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                        <div class="mkcp-setting-row">
                            <div>
                                <strong style="font-size:13px">Theme JS uitschakelen</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Verwijdert alle (child) theme scripts op de checkout pagina. Scripts in <code>mk-cart-popup/script.js</code> en <code>checkout.js</code> blijven actief — dé plek voor eigen checkout-JS.</p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_dequeue_theme_js" value="1"
                                    <?php checked( ! empty( $cfg_co['dequeue_theme_js'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['bar-chart']; ?></div>
                        <h3>Mobiele weergave</h3>
                        <?php if ( ! $is_premium ) echo '<span class="mkcp-premium-badge">Premium</span>'; ?>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div>
                                <strong style="font-size:13px">Overzicht van je bestelling inklapbaar maken</strong>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">
                                    Op tablet/mobiel (schermbreedte onder 900px) staat het besteloverzicht bovenaan. Zet dit aan om het standaard ingeklapt te tonen — de klant kan het openklikken om te bekijken, wat ruimte bespaart. Niet elke site vindt dit wenselijk, dus staat standaard uit.
                                </p>
                            </div>
                            <label class="mkcp-toggle">
                                <input type="checkbox" name="mkcp_checkout_order_review_collapsible_mobile" value="1"
                                    <?php checked( ! empty( $cfg_co['order_review_collapsible_mobile'] ) ); ?>
                                    <?php echo $co_disabled; ?>>
                                <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                            </label>
                        </div>

                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['code']; ?></div>
                        <h3>Checkout CSS bestand</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <p style="font-size:13px;color:var(--mkcp-ui-text2);margin:0 0 10px">
                            Maak het bestand <code>mk-cart-popup/checkout.css</code> aan in je (child) thema om de checkout pagina te stijlen. Dit bestand wordt automatisch geladen na de plugin-CSS.
                        </p>
                        <?php
                        $checkout_css_path = get_stylesheet_directory() . '/mk-cart-popup/checkout.css';
                        if ( file_exists( $checkout_css_path ) ) : ?>
                        <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:500;color:var(--mkcp-ui-green);background:var(--mkcp-ui-green-soft);border:1px solid rgba(34,197,94,.2);border-radius:20px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <code style="font-size:11px;background:none;padding:0;color:inherit"><?php echo esc_html( 'mk-cart-popup/checkout.css' ); ?></code>
                        </div>
                        <?php else : ?>
                        <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:500;color:var(--mkcp-ui-text3);background:var(--mkcp-ui-surface);border:1px solid var(--mkcp-ui-border);border-radius:20px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Nog niet aangemaakt —
                            <button type="button" data-goto="overrides" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;font-weight:inherit;text-decoration:underline">maak aan via Theme Overrides</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mkcp-glass">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Checkout hooks bestand</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <p style="font-size:13px;color:var(--mkcp-ui-text2);margin:0 0 10px">
                            Maak het bestand <code>mk-cart-popup/checkout-hooks.php</code> aan in je (child) thema voor checkout-specifieke PHP-aanpassingen. Dit bestand wordt automatisch geladen en is uitgezonderd van de hooks-isolatie.
                        </p>
                        <?php
                        $checkout_hooks_path = get_stylesheet_directory() . '/mk-cart-popup/checkout-hooks.php';
                        if ( file_exists( $checkout_hooks_path ) ) : ?>
                        <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:500;color:var(--mkcp-ui-green);background:var(--mkcp-ui-green-soft);border:1px solid rgba(34,197,94,.2);border-radius:20px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <code style="font-size:11px;background:none;padding:0;color:inherit"><?php echo esc_html( 'mk-cart-popup/checkout-hooks.php' ); ?></code>
                        </div>
                        <?php else : ?>
                        <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:500;color:var(--mkcp-ui-text3);background:var(--mkcp-ui-surface);border:1px solid var(--mkcp-ui-border);border-radius:20px;padding:4px 10px">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            Nog niet aangemaakt —
                            <button type="button" data-goto="overrides" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;font-weight:inherit;text-decoration:underline">maak aan via Theme Overrides</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary" <?php echo $co_disabled; ?>>
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


            <!-- ════════════════════════════════════════════════════════════════ -->
            <!-- CART CHECKOUT — BEZORGDATUM                                      -->
            <!-- ════════════════════════════════════════════════════════════════ -->

            <?php
            $dd_cfg      = $cfg_co; // alias voor leesbaarheid
            $dd_enabled  = ! empty( $dd_cfg['delivery_date_enabled'] );
            $dd_required = ! empty( $dd_cfg['delivery_date_required'] );
            $dd_label      = $dd_cfg['delivery_date_label']       ?? 'Gewenste bezorgdatum';
            $dd_disclaimer = $dd_cfg['delivery_date_disclaimer'] ?? 'Dit is een inschatting — in uitzonderlijke gevallen (bv. drukte bij de vervoerder) kan de bezorging uitlopen.';
            $dd_cutoff   = $dd_cfg['delivery_date_cutoff_time'] ?? '12:00';
            $dd_lead     = $dd_cfg['delivery_date_lead_days']   ?? 1;
            $dd_range    = $dd_cfg['delivery_date_calendar_range'] ?? 60;
            $dd_ship_days = (array) ( $dd_cfg['delivery_date_shipping_days'] ?? [ 1, 2, 3, 4, 5, 6 ] );
            $dd_blackout  = (array) ( $dd_cfg['delivery_date_blackout_dates'] ?? [] );
            $dd_blackout_str = implode( "\n", array_map( 'mkcp_date_ymd_to_display', $dd_blackout ) );
            $pu_enabled          = ! empty( $dd_cfg['pickup_enabled'] );
            $hide_paid_delivery  = ! empty( $dd_cfg['hide_paid_delivery_if_free'] );
            $pr_enabled             = ! empty( $dd_cfg['pickup_ready_enabled'] );
            $pr_email_on            = ! empty( $dd_cfg['pickup_ready_email_enabled'] );
            $pr_email_subject       = $dd_cfg['pickup_ready_email_subject'] ?? '';
            $pr_email_body          = $dd_cfg['pickup_ready_email_body'] ?? '';
            $pr_sms_on              = ! empty( $dd_cfg['pickup_ready_sms_enabled'] );
            $pr_sms_body            = $dd_cfg['pickup_ready_sms_body'] ?? '';
            $pr_sms_provider_label  = $dd_cfg['pickup_ready_sms_provider_label'] ?? '';
            $pr_sms_endpoint        = $dd_cfg['pickup_ready_sms_endpoint_url'] ?? '';
            $pr_sms_api_key         = $dd_cfg['pickup_ready_sms_api_key'] ?? '';
            $pr_sms_auth_name       = $dd_cfg['pickup_ready_sms_auth_header_name'] ?? 'Authorization';
            $pr_sms_auth_val        = $dd_cfg['pickup_ready_sms_auth_header_value'] ?? 'Bearer {api_key}';
            $pr_sms_recipient_field = $dd_cfg['pickup_ready_sms_recipient_field'] ?? 'recipients';
            $pr_sms_message_field   = $dd_cfg['pickup_ready_sms_message_field'] ?? 'body';
            $pr_sms_from_field      = $dd_cfg['pickup_ready_sms_from_field'] ?? 'originator';
            $pr_sms_from            = $dd_cfg['pickup_ready_sms_from'] ?? '';
            $pr_sms_country_prefix  = $dd_cfg['pickup_ready_sms_default_country_prefix'] ?? '31';
            $pr_sms_test_mode       = ! empty( $dd_cfg['pickup_ready_sms_test_mode'] );
            $ty_enabled             = ! empty( $dd_cfg['thankyou_enabled'] );
            $ty_heading_template    = $dd_cfg['thankyou_heading_template'] ?? '';
            $ty_crosssell_enabled   = ! empty( $dd_cfg['thankyou_crosssell_enabled'] );
            $ty_crosssell_title     = $dd_cfg['thankyou_crosssell_title'] ?? '';
            $ty_invoice_enabled     = ! empty( $dd_cfg['thankyou_invoice_enabled'] );
            $ty_trust_return_text   = $dd_cfg['thankyou_trust_return_text'] ?? '';
            $ty_trust_return_url    = $dd_cfg['thankyou_trust_return_url'] ?? '';
            $ty_trust_contact_text  = $dd_cfg['thankyou_trust_contact_text'] ?? '';
            $dd_weekdays  = [
                0 => 'Zo', 1 => 'Ma', 2 => 'Di', 3 => 'Wo',
                4 => 'Do', 5 => 'Vr', 6 => 'Za',
            ];
            ?>
            <div class="mkcp-panel mkcp-panel--checkout-delivery <?php echo $active_tab === 'checkout-delivery' ? 'is-active' : ''; ?>" data-panel="checkout-delivery">

                <div class="mkcp-page-header">
                    <h2>Bezorgen &amp; afhalen <span class="mkcp-premium-badge">Premium</span></h2>
                    <p>Laat klanten kiezen tussen thuisbezorging en zelf ophalen — beide op basis van dezelfde bol.com-stijl datumpicker.</p>
                </div>

                <?php if ( ! $is_premium ) : ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
                        <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                            <strong>Premium vereist</strong> — De bezorgdatum kiezer is beschikbaar met een premium licentie.
                            <button type="button" data-goto="licentie" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;text-decoration:underline">Licentie activeren →</button>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <?php $pu_map_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'; ?>

                <div class="mkcp-subtabs" role="tablist" aria-label="Bezorgen of afhalen">
                    <button type="button" class="mkcp-subtab is-active" data-subtab="bezorgen">
                        <?php echo $icons['truck']; ?> Bezorgen
                    </button>
                    <button type="button" class="mkcp-subtab" data-subtab="afhalen">
                        <?php echo $pu_map_icon; ?> Afhalen
                    </button>
                    <button type="button" class="mkcp-subtab" data-subtab="afhaalmeldingen">
                        <?php echo $icons['phone']; ?> Afhaalmeldingen
                    </button>
                </div>

                <div class="mkcp-subpanel is-active" data-subpanel="bezorgen">

                <!-- Activeren -->
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Activering</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Bezorgdatum kiezer inschakelen</strong>
                                <small>Toont de datumpicker op de checkout pagina</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_dd_enabled" value="1" <?php checked( $dd_enabled ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Datumpicker actief</span>
                                </div>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Verplicht veld</strong>
                                <small>Klant moet een datum kiezen voor hij kan afrekenen</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_dd_required" value="1" <?php checked( $dd_required ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Datum verplicht</span>
                                </div>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Veld-label</strong>
                                <small>Tekst boven de datumkiezer</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_dd_label"
                                       value="<?php echo esc_attr( $dd_label ); ?>"
                                       placeholder="Gewenste bezorgdatum" <?php echo $co_disabled; ?>>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Disclaimer-tekst</strong>
                                <small>Kleine tekst onder de datumkiezer</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_dd_disclaimer"
                                       value="<?php echo esc_attr( $dd_disclaimer ); ?>"
                                       placeholder="Dit is een inschatting — in uitzonderlijke gevallen kan de bezorging uitlopen."
                                       <?php echo $co_disabled; ?>>
                                <p class="mkcp-input-hint">Laat leeg om de disclaimer helemaal te verbergen.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Verzenddagen -->
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['truck']; ?></div>
                        <h3>Verzenddagen</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Dagen waarop verzonden wordt</strong>
                                <small>Alleen deze weekdagen worden als optie getoond</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-day-chips">
                                    <?php foreach ( $dd_weekdays as $num => $naam ) : ?>
                                    <label class="mkcp-day-chip">
                                        <input type="checkbox" name="mkcp_dd_shipping_days[]"
                                               value="<?php echo $num; ?>"
                                               <?php checked( in_array( $num, $dd_ship_days, false ) ); ?>
                                               <?php echo $co_disabled; ?>>
                                        <?php echo esc_html( $naam ); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="mkcp-input-hint">Vink de weekdagen aan waarop jij pakketten verstuurt.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Cutoff & aanlooptijd -->
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Cutoff-tijd &amp; aanlooptijd</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php
                        // De cutoff-tijd wordt altijd geïnterpreteerd in de WP-sitetijdzone
                        // (wp_timezone_string()) — staat die verkeerd (bv. UTC i.p.v. de eigen
                        // stad), dan klopt de cutoff-klok op de checkout niet met de lokale tijd.
                        $mkcp_dd_tz_string = get_option( 'timezone_string' );
                        $mkcp_dd_gmt_off   = (float) get_option( 'gmt_offset', 0 );
                        $mkcp_dd_tz_label  = $mkcp_dd_tz_string !== ''
                            ? $mkcp_dd_tz_string
                            : sprintf( 'UTC%s%s', $mkcp_dd_gmt_off >= 0 ? '+' : '', $mkcp_dd_gmt_off );
                        $mkcp_dd_tz_is_utc = $mkcp_dd_tz_string === '' && $mkcp_dd_gmt_off === 0.0;
                        ?>

                        <div class="mkcp-notice <?php echo $mkcp_dd_tz_is_utc ? 'mkcp-notice--error' : 'mkcp-notice--info'; ?>">
                            <strong><?php echo $mkcp_dd_tz_is_utc ? '⚠' : 'ℹ'; ?> Huidige site-tijdzone: <code><?php echo esc_html( $mkcp_dd_tz_label ); ?></code></strong> —
                            de cutoff-tijd hieronder wordt in déze tijdzone berekend, niet in de tijdzone van jouw bezoekers of server.
                            <?php if ( $mkcp_dd_tz_is_utc ) : ?>
                                Dit staat nu op <strong>UTC</strong>, waardoor de cutoff-tijd niet overeenkomt met de lokale klok.
                            <?php endif; ?>
                            Klopt dit niet, pas het aan via
                            <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">Instellingen → Algemeen → Tijdzone</a>
                            (niet hier — dit veld stelt alleen het cutoff-tíjdstip in, niet de tijdzone).
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Cutoff-tijd</strong>
                                <small>Orders <em>voor</em> dit tijdstip kunnen nog dezelfde dag verstuurd worden</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="time" class="mkcp-input mkcp-input--sm" name="mkcp_dd_cutoff_time"
                                       value="<?php echo esc_attr( $dd_cutoff ); ?>" <?php echo $co_disabled; ?>>
                                <p class="mkcp-input-hint">
                                    Stel in op bijv. <code>12:00</code>. Orders vóór 12:00 worden dezelfde dag verstuurd (bezorging morgen);
                                    orders ná 12:00 gaan de volgende werkdag (bezorging overmorgen).
                                </p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Aanlooptijd (dagen)</strong>
                                <small>Minimale aanlooptijd in werkdagen vóór de eerste bezorgoptie</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm" name="mkcp_dd_lead_days"
                                       value="<?php echo esc_attr( $dd_lead ); ?>" min="0" max="30" step="1" <?php echo $co_disabled; ?>>
                                <p class="mkcp-input-hint">
                                    <code>0</code> = zo snel mogelijk (rekening houdend met cutoff-tijd).
                                    <code>1</code> = morgen als vroegste optie (standaard).
                                    <code>2</code> = overmorgen, enz.
                                </p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Kalender bereik (dagen)</strong>
                                <small>Hoeveel beschikbare datums de kalender maximaal toont</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm" name="mkcp_dd_calendar_range"
                                       value="<?php echo esc_attr( $dd_range ); ?>" min="7" max="365" step="1" <?php echo $co_disabled; ?>>
                                <p class="mkcp-input-hint">Standaard 60. De chips tonen altijd de eerste 6 beschikbare datums.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Uitgesloten datums -->
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['x']; ?></div>
                        <h3>Geblokkeerde datums</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Uitzonderingen</strong>
                                <small>Datums waarop niet bezorgd wordt (feestdagen, vakantie enz.)</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <textarea class="mkcp-input" name="mkcp_dd_blackout_dates"
                                          rows="6" style="font-family:monospace;font-size:12px"
                                          placeholder="25-12-2026&#10;26-12-2026&#10;01-01-2027"
                                          <?php echo $co_disabled; ?>><?php echo esc_textarea( $dd_blackout_str ); ?></textarea>
                                <p class="mkcp-input-hint">
                                    Één datum per regel in het formaat <code>DD-MM-JJJJ</code>.
                                    Ongeldige regels worden automatisch genegeerd bij opslaan.
                                </p>
                                <?php if ( ! empty( $dd_blackout ) ) : ?>
                                <p style="font-size:12px;color:var(--mkcp-ui-text3);margin-top:6px">
                                    <?php echo count( $dd_blackout ); ?> datum(s) geblokkeerd.
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Capaciteit -->
                <?php
                $dd_cap_enabled = ! empty( $dd_cfg['delivery_date_capacity_enabled'] );
                $dd_cap_max     = $dd_cfg['delivery_date_capacity_max'] ?? 20;
                ?>
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['bar-chart']; ?></div>
                        <h3>Capaciteit per dag</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Maximum aantal bestellingen per bezorgdag</strong>
                                <small>Zodra een dag vol zit, verdwijnt hij automatisch uit de kiezer</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_dd_capacity_enabled" value="1" <?php checked( $dd_cap_enabled ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Capaciteitslimiet actief</span>
                                </div>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Max. bestellingen per dag</strong>
                                <small>Geteld over alle niet-geannuleerde/mislukte orders met deze bezorgdatum</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="number" class="mkcp-input mkcp-input--sm" name="mkcp_dd_capacity_max"
                                       value="<?php echo esc_attr( $dd_cap_max ); ?>" min="1" step="1" <?php echo $co_disabled; ?>>
                                <p class="mkcp-input-hint">Voorkomt overbelaste bezorgdagen bij drukte of een beperkt magazijn.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Per verzendmethode -->
                <?php
                $dd_methods = function_exists( 'mkcp_dd_get_shipping_methods' ) ? mkcp_dd_get_shipping_methods() : [];
                $dd_rules   = (array) ( $dd_cfg['delivery_date_shipping_rules'] ?? [] );
                ?>
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['layers']; ?></div>
                        <h3>Eigen regels per verzendmethode</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php if ( empty( $dd_methods ) ) : ?>
                        <p style="font-size:13px;color:var(--mkcp-ui-text3)">
                            Er zijn nog geen WooCommerce-verzendzones/-methodes geconfigureerd
                            (WooCommerce → Instellingen → Verzending).
                        </p>
                        <?php else : ?>
                        <p class="mkcp-input-hint" style="margin:0 0 14px">
                            Schakel per verzendmethode in om de algemene cutoff-tijd, aanlooptijd en
                            verzenddagen hierboven te overschrijven — bijvoorbeeld voor een snellere
                            expresoptie met een latere cutoff-tijd. Niet ingeschakelde methodes
                            gebruiken gewoon de algemene instellingen.
                        </p>
                        <div class="mkcp-rule-list">
                        <?php foreach ( $dd_methods as $rate_id => $method_label ) :
                            $rule         = $dd_rules[ $rate_id ] ?? [];
                            $rule_on      = ! empty( $rule['enabled'] );
                            $rule_cutoff  = $rule['cutoff_time'] ?? $dd_cutoff;
                            $rule_lead    = $rule['lead_days']   ?? $dd_lead;
                            $rule_days    = (array) ( $rule['shipping_days'] ?? $dd_ship_days );
                            $rule_slots_on   = ! empty( $rule['slots_enabled'] );
                            $rule_win_start  = $rule['window_start']  ?? '09:00';
                            $rule_win_end    = $rule['window_end']    ?? '17:00';
                            $rule_slot_min   = $rule['slot_minutes']  ?? 60;
                            $rule_slot_cap   = $rule['slot_capacity'] ?? 0;
                            $rule_prep       = $rule['prep_minutes']  ?? 60;
                        ?>
                        <div class="mkcp-rule-row js-mkcp-rule-row">
                            <div class="mkcp-rule-row-head">
                                <label class="mkcp-toggle">
                                    <input type="checkbox" class="js-mkcp-rule-toggle"
                                           name="mkcp_dd_rule_enabled[<?php echo esc_attr( $rate_id ); ?>]"
                                           value="1" <?php checked( $rule_on ); ?> <?php echo $co_disabled; ?>>
                                    <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                </label>
                                <span class="mkcp-rule-row-title"><?php echo esc_html( $method_label ); ?></span>
                            </div>

                            <div class="mkcp-rule-row-details">
                                <div class="mkcp-rule-fields">
                                    <label class="mkcp-rule-field">
                                        Cutoff-tijd
                                        <input type="time" class="mkcp-input mkcp-input--sm"
                                               name="mkcp_dd_rule_cutoff[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="<?php echo esc_attr( $rule_cutoff ); ?>" <?php echo $co_disabled; ?>>
                                    </label>
                                    <label class="mkcp-rule-field">
                                        Aanlooptijd (dagen)
                                        <input type="number" class="mkcp-input mkcp-input--sm"
                                               name="mkcp_dd_rule_lead[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="<?php echo esc_attr( $rule_lead ); ?>" min="0" max="30" step="1" <?php echo $co_disabled; ?>>
                                    </label>
                                </div>

                                <div class="mkcp-day-chips">
                                    <?php foreach ( $dd_weekdays as $num => $naam ) : ?>
                                    <label class="mkcp-day-chip">
                                        <input type="checkbox"
                                               name="mkcp_dd_rule_days[<?php echo esc_attr( $rate_id ); ?>][]"
                                               value="<?php echo $num; ?>"
                                               <?php checked( in_array( $num, $rule_days, false ) ); ?>
                                               <?php echo $co_disabled; ?>>
                                        <?php echo esc_html( $naam ); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>

                                <div class="mkcp-rule-slots js-mkcp-slot-toggle-wrap">
                                    <div class="mkcp-toggle-wrap" style="margin:14px 0 0">
                                        <label class="mkcp-toggle">
                                            <input type="checkbox" class="js-mkcp-slot-toggle"
                                                   name="mkcp_dd_rule_slots_enabled[<?php echo esc_attr( $rate_id ); ?>]"
                                                   value="1" <?php checked( $rule_slots_on ); ?> <?php echo $co_disabled; ?>>
                                            <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                        </label>
                                        <span class="mkcp-toggle-label">Tijdsloten aanbieden (alleen zinvol als deze methode zelf rondbrengt — niet bij een vervoerder als PostNL)</span>
                                    </div>

                                    <div class="mkcp-rule-fields js-mkcp-slot-fields" style="margin-top:12px">
                                        <label class="mkcp-rule-field">
                                            Bezorgvenster van
                                            <input type="time" class="mkcp-input mkcp-input--sm"
                                                   name="mkcp_dd_rule_window_start[<?php echo esc_attr( $rate_id ); ?>]"
                                                   value="<?php echo esc_attr( $rule_win_start ); ?>" <?php echo $co_disabled; ?>>
                                        </label>
                                        <label class="mkcp-rule-field">
                                            tot
                                            <input type="time" class="mkcp-input mkcp-input--sm"
                                                   name="mkcp_dd_rule_window_end[<?php echo esc_attr( $rate_id ); ?>]"
                                                   value="<?php echo esc_attr( $rule_win_end ); ?>" <?php echo $co_disabled; ?>>
                                        </label>
                                        <label class="mkcp-rule-field">
                                            Slotduur (minuten)
                                            <input type="number" class="mkcp-input mkcp-input--sm"
                                                   name="mkcp_dd_rule_slot_minutes[<?php echo esc_attr( $rate_id ); ?>]"
                                                   value="<?php echo esc_attr( $rule_slot_min ); ?>" min="5" max="480" step="5" <?php echo $co_disabled; ?>>
                                        </label>
                                        <label class="mkcp-rule-field">
                                            Bereidingstijd (min.)
                                            <input type="number" class="mkcp-input mkcp-input--sm"
                                                   name="mkcp_dd_rule_prep_minutes[<?php echo esc_attr( $rate_id ); ?>]"
                                                   value="<?php echo esc_attr( $rule_prep ); ?>" min="0" max="1440" step="5" <?php echo $co_disabled; ?>>
                                        </label>
                                        <label class="mkcp-rule-field">
                                            Max. bestellingen/slot (0 = onbeperkt)
                                            <input type="number" class="mkcp-input mkcp-input--sm"
                                                   name="mkcp_dd_rule_slot_capacity[<?php echo esc_attr( $rate_id ); ?>]"
                                                   value="<?php echo esc_attr( $rule_slot_cap ); ?>" min="0" step="1" <?php echo $co_disabled; ?>>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Verzendkeuze-kaarten -->
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['truck']; ?></div>
                        <h3>Verzendkeuze-kaarten</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <?php if ( ! $pu_enabled ) : ?>
                        <div class="mkcp-notice mkcp-notice--info">
                            ℹ Deze instelling geldt voor de "Laten bezorgen / Zelf afhalen"-keuzekaarten
                            op de checkout — schakel eerst <strong>Afhalen actief</strong> in bij het
                            tabblad "Afhalen" om de keuzekaarten (en dus deze instelling) zichtbaar te maken.
                        </div>
                        <?php endif; ?>

                        <div class="mkcp-setting-row mkcp-setting-row--stack">
                            <div class="mkcp-setting-label">
                                <strong>Betaalde bezorging verbergen bij gratis verzending</strong>
                                <small>Als binnen "Laten bezorgen" zowel een gratis als een betaalde methode beschikbaar is, wordt de betaalde optie niet getoond</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_hide_paid_delivery" value="1" <?php checked( $hide_paid_delivery ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Actief</span>
                                </div>
                            </div>
                        </div>
                        <p class="mkcp-input-hint" style="margin:2px 0 0">
                            Schakel dit uit als je bewust zowel een gratis standaardoptie als een betaalde
                            snellere/expresoptie naast elkaar wilt tonen (bv. "Gratis, 5-7 dagen" naast
                            "Express, €6,95") — dan zijn het geen alternatieven van elkaar, maar een echte keuze.
                        </p>

                    </div>
                </div>

                </div><!-- /.mkcp-subpanel[data-subpanel="bezorgen"] -->

                <div class="mkcp-subpanel" data-subpanel="afhalen">

                <!-- Afhalen -->
                <?php
                $pu_locations  = (array) ( $dd_cfg['pickup_locations'] ?? [] );
                $pu_methods    = function_exists( 'mkcp_pickup_get_locations_methods' ) ? mkcp_pickup_get_locations_methods() : [];
                ?>
                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $pu_map_icon; ?></div>
                        <h3>Afhalen</h3>
                    </div>
                    <div class="mkcp-glass-body">

                        <div class="mkcp-notice mkcp-notice--info">
                            <strong>ℹ Werkt via je verzendmethodes</strong> —
                            maak eerst een "Ophalen"-verzendmethode aan per locatie
                            (WooCommerce → Instellingen → Verzending → een zone → Verzendmethode toevoegen → Ophalen),
                            en schakel die hieronder in als afhaallocatie. De klant ziet 'm dan als optie
                            tussen de verzendmethodes; kiest hij die, dan verschijnt deze afhaal-datumkiezer
                            <strong>in plaats van</strong> de bezorgdatumkiezer hierboven.
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Afhalen inschakelen</strong>
                                <small>Hoofdschakelaar voor de hele afhaal-functionaliteit</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_pu_feature_enabled" value="1" <?php checked( $pu_enabled ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Afhalen actief</span>
                                </div>
                            </div>
                        </div>

                        <?php if ( empty( $pu_methods ) ) : ?>
                        <p style="font-size:13px;color:var(--mkcp-ui-text3)">
                            Er is nog geen "Local pickup"-verzendmethode geconfigureerd. Maak er een aan via
                            WooCommerce → Instellingen → Verzending → een zone → Verzendmethode toevoegen → <strong>Ophalen</strong>
                            (per gewenste locatie één) — die verschijnt hier dan vanzelf.
                        </p>
                        <?php else : ?>
                        <p class="mkcp-input-hint" style="margin:14px 0">
                            Schakel per locatie in dat het een afhaallocatie is, en vul het adres,
                            de openingstijden en (optioneel) tijdsloten in. Alleen "Ophalen"-verzendmethodes
                            staan hier — gewone verzendmethodes (flat rate, gratis verzending) kunnen
                            geen afhaallocatie zijn.
                        </p>
                        <div class="mkcp-pu-locations">
                        <?php foreach ( $pu_methods as $rate_id => $method_label ) :
                            $pu       = $pu_locations[ $rate_id ] ?? [];
                            $pu_on    = ! empty( $pu['enabled'] );
                            $pu_display_name = $pu['display_name'] ?? '';
                            $pu_addr  = $pu['address']     ?? '';
                            $pu_cut   = $pu['cutoff_time']  ?? '16:00';
                            $pu_lead  = $pu['lead_days']    ?? 0;
                            $pu_prep  = $pu['prep_minutes'] ?? 60;
                            $pu_black = implode( "\n", array_map( 'mkcp_date_ymd_to_display', (array) ( $pu['blackout_dates'] ?? [] ) ) );
                            $pu_slots_on = ! empty( $pu['slots_enabled'] );
                            $pu_slot_min = $pu['slot_minutes']  ?? 60;
                            $pu_slot_cap = $pu['slot_capacity'] ?? 0;
                            $pu_hours    = (array) ( $pu['hours'] ?? [] );
                            $pu_addr_line = trim( (string) strtok( trim( (string) $pu_addr ), "\n" ) );
                        ?>
                        <div class="mkcp-pu-loc<?php echo $pu_on ? ' is-enabled' : ''; ?>">

                            <button type="button" class="mkcp-pu-loc-header" aria-expanded="<?php echo $pu_on ? 'true' : 'false'; ?>">
                                <span class="mkcp-pu-loc-chevron">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </span>
                                <span class="mkcp-pu-loc-title">
                                    <strong><?php echo esc_html( $method_label ); ?></strong>
                                    <small class="mkcp-pu-loc-subtitle"><?php echo $pu_addr_line !== '' ? esc_html( $pu_addr_line ) : 'Nog geen adres ingesteld'; ?></small>
                                </span>
                                <span class="mkcp-pu-loc-status"><?php echo $pu_on ? 'Actief' : 'Uit'; ?></span>
                            </button>

                            <div class="mkcp-pu-loc-body"<?php echo $pu_on ? '' : ' hidden'; ?>>

                                <div class="mkcp-toggle-wrap" style="margin-bottom:14px">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" class="mkcp-pu-loc-enable-input"
                                               name="mkcp_pu_enabled[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="1" <?php checked( $pu_on ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Dit is een afhaallocatie</span>
                                </div>

                                <label class="mkcp-pu-field">
                                    <span class="mkcp-pu-field-label">Weergavenaam voor klant</span>
                                    <input type="text" class="mkcp-input"
                                           name="mkcp_pu_display_name[<?php echo esc_attr( $rate_id ); ?>]"
                                           value="<?php echo esc_attr( $pu_display_name ); ?>"
                                           placeholder="Afhaallocatie" <?php echo $co_disabled; ?>>
                                </label>
                                <p class="mkcp-input-hint" style="margin:2px 0 16px">
                                    Deze tekst ziet de klant op de checkout i.p.v. de technische naam van de
                                    verzendmethode ("<?php echo esc_html( $method_label ); ?>"). Leeg laten
                                    toont "Afhaallocatie".
                                </p>

                                <label class="mkcp-pu-field">
                                    <span class="mkcp-pu-field-label">Adres (getoond aan de klant)</span>
                                    <textarea class="mkcp-input mkcp-pu-loc-address-input" rows="4"
                                              name="mkcp_pu_address[<?php echo esc_attr( $rate_id ); ?>]"
                                              placeholder="Voorbeeldstraat 1&#10;1234 AB Voorbeeldstad"
                                              <?php echo $co_disabled; ?>><?php echo esc_textarea( $pu_addr ); ?></textarea>
                                </label>

                                <div class="mkcp-pu-timing-grid">
                                    <label class="mkcp-pu-field">
                                        <span class="mkcp-pu-field-label">Cutoff-tijd</span>
                                        <input type="time" class="mkcp-input"
                                               name="mkcp_pu_cutoff[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="<?php echo esc_attr( $pu_cut ); ?>" <?php echo $co_disabled; ?>>
                                    </label>
                                    <label class="mkcp-pu-field">
                                        <span class="mkcp-pu-field-label">Aanlooptijd (dagen)</span>
                                        <input type="number" class="mkcp-input"
                                               name="mkcp_pu_lead[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="<?php echo esc_attr( $pu_lead ); ?>" min="0" max="30" step="1" <?php echo $co_disabled; ?>>
                                    </label>
                                    <label class="mkcp-pu-field">
                                        <span class="mkcp-pu-field-label">Bereidingstijd (min.)</span>
                                        <input type="number" class="mkcp-input"
                                               name="mkcp_pu_prep_minutes[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="<?php echo esc_attr( $pu_prep ); ?>" min="0" max="1440" step="5" <?php echo $co_disabled; ?>>
                                    </label>
                                </div>
                                <p class="mkcp-input-hint" style="margin:2px 0 16px">Bereidingstijd: tijd om de bestelling klaar te leggen — het vroegste tijdslot vandaag ligt minimaal dit lang na het bestelmoment.</p>

                                <p class="mkcp-pu-subhead">Openingstijden per weekdag</p>
                                <div class="mkcp-pu-hours-grid">
                                    <div class="mkcp-pu-hours-row mkcp-pu-hours-row--head">
                                        <span>Dag</span><span>Open</span><span>Van</span><span>Tot</span>
                                    </div>
                                    <?php foreach ( $dd_weekdays as $num => $naam ) :
                                        $h_closed = ! empty( $pu_hours[ $num ]['closed'] );
                                        $h_open   = $pu_hours[ $num ]['open']  ?? '09:00';
                                        $h_close  = $pu_hours[ $num ]['close'] ?? '17:00';
                                    ?>
                                    <div class="mkcp-pu-hours-row<?php echo $h_closed ? ' is-closed' : ''; ?>">
                                        <span class="mkcp-pu-hours-day"><?php echo esc_html( $naam ); ?></span>
                                        <label class="mkcp-toggle mkcp-toggle--sm mkcp-pu-hours-toggle">
                                            <input type="checkbox" class="mkcp-pu-hours-open-input"
                                                   name="mkcp_pu_hours_is_open[<?php echo esc_attr( $rate_id ); ?>][<?php echo $num; ?>]"
                                                   value="1" <?php checked( ! $h_closed ); ?> <?php echo $co_disabled; ?>>
                                            <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                        </label>
                                        <input type="time" class="mkcp-input mkcp-input--sm"
                                               name="mkcp_pu_hours_open[<?php echo esc_attr( $rate_id ); ?>][<?php echo $num; ?>]"
                                               value="<?php echo esc_attr( $h_open ); ?>" <?php echo $co_disabled; ?>>
                                        <input type="time" class="mkcp-input mkcp-input--sm"
                                               name="mkcp_pu_hours_close[<?php echo esc_attr( $rate_id ); ?>][<?php echo $num; ?>]"
                                               value="<?php echo esc_attr( $h_close ); ?>" <?php echo $co_disabled; ?>>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php /* Checkbox-semantiek in de opslag bleef bewust ongewijzigd (mkcp_pu_hours_closed,
                                         checked = gesloten) — de toggle hierboven is alleen visueel omgedraaid (checked
                                         = "open") via de `checked( ! $h_closed )`-inversie, zodat een groene/actieve
                                         toggle "open" betekent i.p.v. "gesloten". mkcp_sanitize_pickup_locations() in
                                         config.php leest het veld nog steeds op precies dezelfde manier. */ ?>

                                <label class="mkcp-pu-field" style="margin-top:16px">
                                    <span class="mkcp-pu-field-label">Geblokkeerde datums (één per regel, DD-MM-JJJJ)</span>
                                    <textarea class="mkcp-input" rows="3" style="font-family:monospace;font-size:12px"
                                              name="mkcp_pu_blackout[<?php echo esc_attr( $rate_id ); ?>]"
                                              placeholder="25-12-2026&#10;26-12-2026"
                                              <?php echo $co_disabled; ?>><?php echo esc_textarea( $pu_black ); ?></textarea>
                                </label>

                                <div class="mkcp-toggle-wrap" style="margin:16px 0 10px">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox"
                                               name="mkcp_pu_slots_enabled[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="1" <?php checked( $pu_slots_on ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Tijdsloten aanbieden (naast de datum ook een tijdstip kiezen)</span>
                                </div>

                                <div class="mkcp-pu-timing-grid mkcp-pu-timing-grid--2col">
                                    <label class="mkcp-pu-field">
                                        <span class="mkcp-pu-field-label">Slotduur (minuten)</span>
                                        <input type="number" class="mkcp-input"
                                               name="mkcp_pu_slot_minutes[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="<?php echo esc_attr( $pu_slot_min ); ?>" min="5" max="480" step="5" <?php echo $co_disabled; ?>>
                                    </label>
                                    <label class="mkcp-pu-field">
                                        <span class="mkcp-pu-field-label">Max. bestellingen per tijdslot (0 = onbeperkt)</span>
                                        <input type="number" class="mkcp-input"
                                               name="mkcp_pu_slot_capacity[<?php echo esc_attr( $rate_id ); ?>]"
                                               value="<?php echo esc_attr( $pu_slot_cap ); ?>" min="0" step="1" <?php echo $co_disabled; ?>>
                                    </label>
                                </div>

                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                </div><!-- /.mkcp-subpanel[data-subpanel="afhalen"] -->

                <div class="mkcp-subpanel" data-subpanel="afhaalmeldingen">

                <?php if ( ! $pu_enabled ) : ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
                        <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                            <strong>Afhalen staat uit</strong> — schakel eerst "Afhalen inschakelen" in bij het tabblad Afhalen, anders heeft een afhaalmelding niets om aan te koppelen.
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Activering</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-notice mkcp-notice--info">
                            Zet dit aan om op de bestelling-bewerkpagina een knop <strong>"Deze bestelling kan worden opgehaald."</strong> te tonen bij afhaalbestellingen. De klant krijgt dan een e-mail en/of sms dat de bestelling klaarstaat.
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Afhaalmeldingen inschakelen</strong>
                                <small>Toont de knop op de bestelpagina</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_pu_ready_enabled" value="1" <?php checked( $pr_enabled ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Afhaalmeldingen actief</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg>
                        </div>
                        <h3>E-mail</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>E-mail versturen</strong>
                                <small>Stuur een afhaalmelding per e-mail naar het factuuradres van de bestelling</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_pu_ready_email_enabled" value="1" <?php checked( $pr_email_on ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">E-mail actief</span>
                                </div>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Onderwerp</strong>
                                <small>Onderwerpregel van de afhaalmelding</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_email_subject"
                                       value="<?php echo esc_attr( $pr_email_subject ); ?>" <?php echo $co_disabled; ?>>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Inhoud e-mail</strong>
                                <small>Plaatshouders: <code>{voornaam}</code> <code>{achternaam}</code> <code>{ordernummer}</code> <code>{afhaallocatie}</code> <code>{afhaaldatum}</code> <code>{afhaaltijd}</code> <code>{winkel_naam}</code></small>
                            </div>
                            <div class="mkcp-setting-control">
                                <textarea class="mkcp-input" name="mkcp_pu_ready_email_body" rows="6"
                                          style="resize:vertical" <?php echo $co_disabled; ?>><?php echo esc_textarea( $pr_email_body ); ?></textarea>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Testmail versturen</strong>
                                <small>Stuur een voorbeeld van deze mail naar jezelf, met voorbeeldgegevens</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-test-email-row">
                                    <input type="email" class="mkcp-input js-mkcp-test-email-input"
                                           placeholder="jouw@email.nl"
                                           value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
                                    <button type="button" class="mkcp-btn mkcp-btn--primary js-mkcp-send-test-email"
                                            data-test-email-action="mkcp_pu_ready_send_test_email">
                                        <?php echo $icons['zap']; ?> Stuur testmail
                                    </button>
                                </div>
                                <div class="js-mkcp-test-email-result mkcp-test-email-result"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['phone']; ?></div>
                        <h3>SMS</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-notice mkcp-notice--info">
                            Werkt met elke REST-SMS-provider die een simpele JSON-POST + API-sleutel gebruikt (bijv. Spryng, MessageBird). De standaardwaarden hieronder kloppen al voor Spryng — voor MessageBird wijzig je alleen de auth-header-waarde naar <code>AccessKey {api_key}</code>.
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Sms versturen</strong>
                                <small>Stuur een afhaalmelding per sms naar het telefoonnummer van de bestelling</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_pu_ready_sms_enabled" value="1" <?php checked( $pr_sms_on ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Sms actief</span>
                                </div>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Inhoud sms</strong>
                                <small>Hou het kort — sms-berichten worden per 160 tekens afgerekend bij de meeste providers</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <textarea class="mkcp-input" name="mkcp_pu_ready_sms_body" rows="3"
                                          style="resize:vertical" <?php echo $co_disabled; ?>><?php echo esc_textarea( $pr_sms_body ); ?></textarea>
                            </div>
                        </div>

                        <p class="mkcp-pu-subhead">Provider</p>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Naam (notitie)</strong>
                                <small>Vrij invulveld, puur voor jezelf — bv. "Spryng"</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_provider_label"
                                       value="<?php echo esc_attr( $pr_sms_provider_label ); ?>" <?php echo $co_disabled; ?>>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>API-endpoint URL</strong>
                                <small>Het REST-adres waar de sms-aanvraag naartoe wordt gestuurd</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_endpoint_url"
                                       value="<?php echo esc_attr( $pr_sms_endpoint ); ?>"
                                       placeholder="https://rest.spryngsms.com/v1/messages" <?php echo $co_disabled; ?>>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>API-sleutel</strong>
                                <small>Van je sms-provider</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="password" class="mkcp-input" name="mkcp_pu_ready_sms_api_key"
                                       value="<?php echo esc_attr( $pr_sms_api_key ); ?>" autocomplete="off" <?php echo $co_disabled; ?>>
                            </div>
                        </div>

                        <div class="mkcp-pu-timing-grid mkcp-pu-timing-grid--2col">
                            <label class="mkcp-pu-field">
                                <span class="mkcp-pu-field-label">Auth-header naam</span>
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_auth_header_name"
                                       value="<?php echo esc_attr( $pr_sms_auth_name ); ?>" <?php echo $co_disabled; ?>>
                            </label>
                            <label class="mkcp-pu-field">
                                <span class="mkcp-pu-field-label">Auth-header waarde</span>
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_auth_header_value"
                                       value="<?php echo esc_attr( $pr_sms_auth_val ); ?>"
                                       placeholder="Bearer {api_key}" <?php echo $co_disabled; ?>>
                            </label>
                        </div>
                        <p class="mkcp-input-hint">Gebruik <code>{api_key}</code> als plaatshouder voor de API-sleutel hierboven.</p>

                        <p class="mkcp-pu-subhead">Geavanceerd (veldnamen in de JSON-body)</p>

                        <div class="mkcp-pu-timing-grid mkcp-pu-timing-grid--2col">
                            <label class="mkcp-pu-field">
                                <span class="mkcp-pu-field-label">Veldnaam ontvanger</span>
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_recipient_field"
                                       value="<?php echo esc_attr( $pr_sms_recipient_field ); ?>" <?php echo $co_disabled; ?>>
                            </label>
                            <label class="mkcp-pu-field">
                                <span class="mkcp-pu-field-label">Veldnaam berichttekst</span>
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_message_field"
                                       value="<?php echo esc_attr( $pr_sms_message_field ); ?>" <?php echo $co_disabled; ?>>
                            </label>
                            <label class="mkcp-pu-field">
                                <span class="mkcp-pu-field-label">Veldnaam afzender</span>
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_from_field"
                                       value="<?php echo esc_attr( $pr_sms_from_field ); ?>" <?php echo $co_disabled; ?>>
                            </label>
                            <label class="mkcp-pu-field">
                                <span class="mkcp-pu-field-label">Afzendernaam</span>
                                <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_from"
                                       value="<?php echo esc_attr( $pr_sms_from ); ?>" placeholder="Jouw winkel" <?php echo $co_disabled; ?>>
                            </label>
                        </div>

                        <label class="mkcp-pu-field" style="margin-top:16px;max-width:200px">
                            <span class="mkcp-pu-field-label">Standaard landcode (zonder +)</span>
                            <input type="text" class="mkcp-input" name="mkcp_pu_ready_sms_default_country_prefix"
                                   value="<?php echo esc_attr( $pr_sms_country_prefix ); ?>" placeholder="31" <?php echo $co_disabled; ?>>
                        </label>

                        <div class="mkcp-setting-row" style="margin-top:16px">
                            <div class="mkcp-setting-label">
                                <strong>Testmodus</strong>
                                <small>Logt de sms-aanvraag in plaats van 'm echt te versturen — handig om te testen zonder sms-account of kosten</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_pu_ready_sms_test_mode" value="1" <?php checked( $pr_sms_test_mode ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Testmodus actief</span>
                                </div>
                                <p class="mkcp-input-hint">Zet dit uit zodra je provider-gegevens kloppen en je echt sms'jes wilt versturen.</p>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Test-sms versturen</strong>
                                <small>Stuur een voorbeeld-sms naar een telefoonnummer (respecteert de testmodus hierboven)</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-test-email-row">
                                    <input type="tel" class="mkcp-input js-mkcp-test-email-input"
                                           placeholder="0612345678">
                                    <button type="button" class="mkcp-btn mkcp-btn--primary js-mkcp-send-test-email"
                                            data-test-email-action="mkcp_pu_ready_send_test_sms"
                                            data-test-value-key="phone">
                                        <?php echo $icons['zap']; ?> Stuur test-sms
                                    </button>
                                </div>
                                <div class="js-mkcp-test-email-result mkcp-test-email-result"></div>
                            </div>
                        </div>
                    </div>
                </div>

                </div><!-- /.mkcp-subpanel[data-subpanel="afhaalmeldingen"] -->

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary" <?php echo $co_disabled; ?>>
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>

            <div class="mkcp-panel mkcp-panel--checkout-thankyou <?php echo $active_tab === 'checkout-thankyou' ? 'is-active' : ''; ?>" data-panel="checkout-thankyou">

                <div class="mkcp-page-header">
                    <h2>Bedankt-pagina <span class="mkcp-premium-badge">Premium</span></h2>
                    <p>Persoonlijke heading, cross-sell, factuur-download en vertrouwenselementen op de order-bevestigingspagina.</p>
                </div>

                <?php if ( ! $is_premium ) : ?>
                <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
                    <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
                        <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
                        <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                            <strong>Premium vereist</strong> — De verbeterde bedankt-pagina is beschikbaar met een premium licentie.
                            <button type="button" data-goto="licentie" style="background:none;border:none;padding:0;color:var(--mkcp-ui-accent);cursor:pointer;font-size:inherit;text-decoration:underline">Licentie activeren →</button>
                        </span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['zap']; ?></div>
                        <h3>Activering</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-notice mkcp-notice--info">
                            Zet dit aan voor een persoonlijke heading, een grote bezorg-/afhaal-banner (met afhaallocatie-kaartje bij afhalen) en een "wat gebeurt er nu"-stappenstrip op de bedankt-pagina.
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Bedankt-pagina verbeteren</strong>
                                <small>Persoonlijke heading, banner en stappenstrip</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_ty_enabled" value="1" <?php checked( $ty_enabled ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Verbeterde bedankt-pagina actief</span>
                                </div>
                            </div>
                        </div>

                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Heading-tekst</strong>
                                <small>Plaatshouder: <code>{voornaam}</code></small>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_ty_heading_template"
                                       value="<?php echo esc_attr( $ty_heading_template ); ?>" <?php echo $co_disabled; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['package']; ?></div>
                        <h3>Cross-sell</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-notice mkcp-notice--info">
                            Los van de cross-sell in de winkelwagen-popup — deze toont suggesties op basis van wat er net besteld is. Gebruikt dezelfde modus/aantal-instelling als de winkelwagen-popup (tabblad Cart Gedrag).
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Cross-sell op bedankt-pagina</strong>
                                <small>Toont productsuggesties na het overzicht</small>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_ty_crosssell_enabled" value="1" <?php checked( $ty_crosssell_enabled ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Cross-sell actief</span>
                                </div>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Titel</strong>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_ty_crosssell_title"
                                       value="<?php echo esc_attr( $ty_crosssell_title ); ?>" <?php echo $co_disabled; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['credit-card']; ?></div>
                        <h3>Factuur</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-notice mkcp-notice--info">
                            Werkt via de WooCommerce PDF Invoices &amp; Packing Slips-plugin — zonder die plugin (of zonder ingeschakeld factuur-document) verschijnt de knop simpelweg niet.
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Factuur-downloadknop</strong>
                            </div>
                            <div class="mkcp-setting-control">
                                <div class="mkcp-toggle-wrap">
                                    <label class="mkcp-toggle">
                                        <input type="checkbox" name="mkcp_ty_invoice_enabled" value="1" <?php checked( $ty_invoice_enabled ); ?> <?php echo $co_disabled; ?>>
                                        <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                                    </label>
                                    <span class="mkcp-toggle-label">Knop tonen</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-glass" data-mkcp-tier="premium">
                    <div class="mkcp-glass-header">
                        <div class="mkcp-header-icon"><?php echo $icons['shield']; ?></div>
                        <h3>Vertrouwenselementen</h3>
                    </div>
                    <div class="mkcp-glass-body">
                        <div class="mkcp-notice mkcp-notice--info">
                            Geen aparte aan/uit-schakelaar: laat een veld leeg om dat onderdeel te verbergen.
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Retourbeleid-tekst</strong>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_ty_trust_return_text"
                                       placeholder="30 dagen retourneren" value="<?php echo esc_attr( $ty_trust_return_text ); ?>" <?php echo $co_disabled; ?>>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Retourbeleid-link (optioneel)</strong>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_ty_trust_return_url"
                                       placeholder="https://" value="<?php echo esc_attr( $ty_trust_return_url ); ?>" <?php echo $co_disabled; ?>>
                            </div>
                        </div>
                        <div class="mkcp-setting-row">
                            <div class="mkcp-setting-label">
                                <strong>Klantenservice-contact</strong>
                            </div>
                            <div class="mkcp-setting-control">
                                <input type="text" class="mkcp-input" name="mkcp_ty_trust_contact_text"
                                       placeholder="Vragen? Mail info@jouwwinkel.nl" value="<?php echo esc_attr( $ty_trust_contact_text ); ?>" <?php echo $co_disabled; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkcp-save-bar">
                    <button type="submit" class="mkcp-btn mkcp-btn--primary" <?php echo $co_disabled; ?>>
                        <?php echo $icons['check']; ?> Opslaan
                    </button>
                </div>

            </div>


        </form>
        </main>

        <!-- Popup preview sidebar — always visible -->
        <div class="mkcp-popup-sidebar" id="mkcp-popup-sidebar">
            <div id="mkcp-preview-toolbar">
<div class="mkcp-preview-toolbar-group" style="margin-left:auto">
                    <button type="button" id="mkcp-builder-save-btn" class="mkcp-toolbar-btn" title="Wijzigingen opslaan">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Opslaan
                    </button>
                    <span class="mkcp-toolbar-save-feedback" id="mkcp-builder-save-feedback" hidden></span>
                </div>
            </div>
            <div id="mkcp-dirty-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Niet-opgeslagen wijzigingen &mdash; klik <strong>Opslaan</strong> om toe te passen
            </div>
            <div class="mkcp-device-frame">
<div class="mkcp-popup-sidebar-inner" id="mkcp-preview-frame">
                    <!-- Filled by builder.js -->
                </div>
            </div>
        </div>

    </div>

