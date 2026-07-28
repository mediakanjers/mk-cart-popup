<?php
/**
 * MK Cart Popup — Child theme scaffold
 *
 * Creates override files in the active child theme:
 *   mk-cart-popup/style.css         — CSS overrides; auto-enqueued by the plugin
 *   mk-cart-popup/checkout.css      — Checkout CSS overrides; auto-enqueued (premium)
 *   mk-cart-popup/script.js         — JS overrides; auto-enqueued by the plugin
 *   mk-cart-popup/checkout.js       — Checkout JS overrides; auto-enqueued (premium)
 *   mk-cart-popup/cart-hooks.php    — PHP hooks; auto-loaded by the plugin
 *   mk-cart-popup/checkout-hooks.php — Checkout PHP hooks; auto-loaded by the plugin
 *
 * The plugin never touches these files after creation, so they survive updates.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Admin: scaffold action button ──────────────────────────────────────────────

add_action( 'admin_post_mkcp_create_scaffold', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Geen toegang.' );
    check_admin_referer( 'mkcp_create_scaffold' );

    $overwrite = ! empty( $_POST['mkcp_scaffold_overwrite'] );
    $result    = mkcp_scaffold_create( $overwrite );

    $status = implode( ',', $result['created'] );
    $errors = implode( ',', $result['errors'] );

    wp_safe_redirect( admin_url(
        'admin.php?page=mkcp-settings&scaffold_created=' . urlencode( $status )
        . ( $errors ? '&scaffold_errors=' . urlencode( $errors ) : '' )
        . '#mkcp-scaffold'
    ) );
    exit;
} );


// ── Scaffold creation logic ────────────────────────────────────────────────────

function mkcp_scaffold_dir() {
    return get_stylesheet_directory() . '/mk-cart-popup';
}

function mkcp_scaffold_url() {
    return get_stylesheet_directory_uri() . '/mk-cart-popup';
}

/**
 * Creates the scaffold files. Returns [ 'created' => [], 'errors' => [] ].
 *
 * @param bool $overwrite  When true, overwrites existing files.
 */
function mkcp_scaffold_create( $overwrite = false ) {
    $dir     = mkcp_scaffold_dir();
    $created = [];
    $errors  = [];

    if ( ! file_exists( $dir ) ) {
        if ( ! wp_mkdir_p( $dir ) ) {
            $errors[] = 'map';
            return compact( 'created', 'errors' );
        }
    }

    $files = [
        'style.css'          => mkcp_scaffold_css_content(),
        'checkout.css'       => mkcp_scaffold_checkout_css_content(),
        'script.js'          => mkcp_scaffold_js_content(),
        'checkout.js'        => mkcp_scaffold_checkout_js_content(),
        'cart-hooks.php'     => mkcp_scaffold_hooks_content(),
        'checkout-hooks.php' => mkcp_scaffold_checkout_hooks_content(),
    ];

    foreach ( $files as $filename => $content ) {
        $path = $dir . '/' . $filename;
        if ( ! $overwrite && file_exists( $path ) ) continue;
        if ( file_put_contents( $path, $content ) !== false ) {
            $created[] = $filename;
        } else {
            $errors[] = $filename;
        }
    }

    return compact( 'created', 'errors' );
}


// ── Scaffold file contents ─────────────────────────────────────────────────────


function mkcp_scaffold_css_content() {
    return <<<CSS
/*
 * MK Cart Popup — Gecompileerde theme overrides
 *
 * Dit bestand wordt automatisch geladen door de plugin (na de plugin-CSS).
 * Schrijf hier direct plain CSS om de popup te stijlen.
 *
 * De plugin checkt het bestaan van dit bestand op elke pagina;
 * de versie wordt bepaald door filemtime() zodat de browser altijd de
 * nieuwste versie laadt na het opslaan.
 */

CSS;
}


function mkcp_scaffold_checkout_css_content() {
    return <<<CSS
/*
 * MK Cart Popup — Checkout pagina overrides (premium)
 *
 * Dit bestand wordt automatisch geladen door de plugin na assets/checkout.css.
 * Schrijf hier je eigen CSS om de checkout pagina aan te passen.
 *
 * Alle selectors zijn al gescooped op body.mkcp-distraction-free-checkout
 * in de plugin-CSS. Je kunt hier plain CSS schrijven zonder die prefix
 * te herhalen, of hem toevoegen voor extra specificiteit.
 *
 * Voorbeeld:
 *
 *   body.mkcp-distraction-free-checkout .woocommerce-checkout {
 *       font-family : 'Jouw font', sans-serif;
 *   }
 *
 *   body.mkcp-distraction-free-checkout .wc-block-components-button {
 *       border-radius : 2px;
 *   }
 */

CSS;
}

function mkcp_scaffold_js_content() {
    return <<<JS
/*
 * MK Cart Popup — Eigen JS voor de winkelwagen-popup
 *
 * Dit bestand wordt automatisch geladen door de plugin (na de plugin-JS,
 * met jQuery als dependency). Schrijf hier eigen JavaScript i.p.v. dit in
 * de thema-bestanden te zetten — die worden op de checkout pagina
 * verwijderd zodra "Theme JS uitschakelen" aan staat (Checkout instellingen
 * → Styling), dit bestand juist nooit.
 *
 * De plugin checkt het bestaan van dit bestand op elke pagina; de versie
 * wordt bepaald door filemtime() zodat de browser altijd de nieuwste versie
 * laadt na het opslaan.
 */

jQuery(function (\$) {

});

JS;
}


function mkcp_scaffold_checkout_js_content() {
    return <<<JS
/*
 * MK Cart Popup — Eigen JS voor de checkout pagina (premium)
 *
 * Dit bestand wordt automatisch geladen door de plugin op de checkout
 * pagina (na de plugin-JS, met jQuery als dependency) — ook als
 * "Theme JS uitschakelen" aan staat, want dit bestand zit expliciet
 * buiten die opschoning.
 *
 * WooCommerce's checkout ververst een deel van de pagina via AJAX zodra de
 * klant iets wijzigt (adres, verzendmethode, ...). Bind daarom niet alleen
 * op page-load, maar ook op 'updated_checkout' voor code die elementen
 * binnen #order_review of #payment aanspreekt — die worden na elke
 * AJAX-ronde opnieuw gerenderd.
 *
 * Voorbeeld:
 *
 *   jQuery(document).on('updated_checkout', function () {
 *       // jouw code hier
 *   });
 */

jQuery(function (\$) {

});

JS;
}


function mkcp_scaffold_hooks_content() {
    return <<<'PHP'
<?php
/**
 * MK Cart Popup — Algemene hooks
 *
 * Aangemaakt door de MK Cart Popup plugin.
 * Dit bestand wordt automatisch geladen door de plugin — voeg hier
 * popup-gerelateerde PHP-overrides toe zonder de plugin zelf aan te passen.
 *
 * Voor checkout-specifieke hooks: zie checkout-hooks.php
 * Voor algemene cart/popup hooks: zie cart-hooks.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Configuratie overschrijven ─────────────────────────────────────────────────
//
// Het 'mkcp_config' filter heeft prioriteit boven de admin-instellingen.
// Handig voor waarden die per omgeving of per pagina moeten verschillen.

// add_filter( 'mkcp_config', function( $c ) {
//
//     // Popup-titel aanpassen:
//     // $c['title'] = 'Winkelmand';
//
//     // Gratis-verzending drempel overschrijven:
//     // $c['free_shipping_threshold'] = 75;
//
//     // Popup uitschakelen op specifieke pagina's:
//     // if ( is_page( 'speciale-aanbieding' ) ) {
//     //     $c['enabled'] = false;
//     // }
//
//     return $c;
// } );


// ── Extra popup hooks ──────────────────────────────────────────────────────────
//
// Voeg hieronder eigen WordPress actions en filters toe die de popup betreffen.

PHP;
}

function mkcp_scaffold_checkout_hooks_content() {
    return <<<'PHP'
<?php
/**
 * MK Cart Popup — Checkout hooks
 *
 * Aangemaakt door de MK Cart Popup plugin.
 * Dit bestand wordt automatisch geladen door de plugin — voeg hier
 * checkout-specifieke PHP hooks toe.
 *
 * Voor algemene cart/popup hooks: zie cart-hooks.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Checkout velden aanpassen ──────────────────────────────────────────────────

// Couponveld verbergen:
// add_filter( 'woocommerce_coupons_enabled', '__return_false' );

// Ordernotes verbergen:
// add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );

// Billing phone verplicht maken:
// add_filter( 'woocommerce_billing_fields', function( $fields ) {
//     $fields['billing_phone']['required'] = true;
//     return $fields;
// } );


// ── Extra inhoud toevoegen ─────────────────────────────────────────────────────

// add_action( 'woocommerce_before_checkout_form', function() {
//     echo '<div class="mijn-blok">Tekst boven het formulier</div>';
// } );

// add_action( 'woocommerce_review_order_before_payment', function() {
//     echo '<p class="mijn-opmerking">Jouw opmerking boven betaalmethodes</p>';
// } );

PHP;
}


// ── Admin section renderer (called from settings.php) ─────────────────────────

function mkcp_render_scaffold_section() {
    $dir     = mkcp_scaffold_dir();
    $files   = [ 'style.css', 'checkout.css', 'script.js', 'checkout.js', 'cart-hooks.php', 'checkout-hooks.php' ];
    $is_child = get_template() !== get_stylesheet();

    $created = sanitize_text_field( $_GET['scaffold_created'] ?? '' );
    $errors  = sanitize_text_field( $_GET['scaffold_errors']  ?? '' );

    if ( $created ) {
        $names = implode( ', ', array_map( 'esc_html', explode( ',', $created ) ) );
        echo '<div class="notice notice-success inline"><p>'
            . sprintf( __( 'Aangemaakt: <strong>%s</strong>', 'mk-cart-popup' ), $names )
            . '</p></div>';
    }
    if ( $errors ) {
        $names = implode( ', ', array_map( 'esc_html', explode( ',', $errors ) ) );
        echo '<div class="notice notice-error inline"><p>'
            . sprintf( __( 'Mislukt (controleer schrijfrechten): %s', 'mk-cart-popup' ), $names )
            . '</p></div>';
    }
    ?>
    <div class="mkcp-card" id="mkcp-scaffold">
        <div class="mkcp-card-header"><?php esc_html_e( 'Theme overrides scaffold', 'mk-cart-popup' ); ?></div>
        <div class="mkcp-card-body" style="gap:10px">

            <?php if ( ! $is_child ) : ?>
            <div class="notice notice-warning inline" style="margin:0">
                <p><?php esc_html_e( 'Je gebruikt geen child thema. De bestanden worden aangemaakt in het actieve thema, maar worden overschreven bij een thema-update.', 'mk-cart-popup' ); ?></p>
            </div>
            <?php endif; ?>

            <p style="margin:0;font-size:13px;color:#3c434a">
                <?php esc_html_e( 'De plugin maakt deze bestanden aan in je (child) thema. Ze worden automatisch geladen en overleven plugin-updates:', 'mk-cart-popup' ); ?>
            </p>

            <div class="mkcp-scaffold-files">
                <?php foreach ( $files as $file ) :
                    $path   = $dir . '/' . $file;
                    $exists = file_exists( $path );
                    $label  = $exists ? '✓' : '–';
                    $color  = $exists ? '#2e7d32' : '#888';
                    $desc   = [
                        'style.css'          => __( 'CSS overrides voor de popup — auto-geladen na de plugin-CSS', 'mk-cart-popup' ),
                        'checkout.css'       => __( 'CSS overrides voor de checkout — auto-geladen na de checkout-CSS (premium)', 'mk-cart-popup' ),
                        'script.js'          => __( 'JS overrides voor de popup — auto-geladen na de plugin-JS', 'mk-cart-popup' ),
                        'checkout.js'        => __( 'JS overrides voor de checkout — auto-geladen na de checkout-JS (premium)', 'mk-cart-popup' ),
                        'cart-hooks.php'     => __( 'Algemene cart/popup hooks — auto-geladen bij plugins_loaded', 'mk-cart-popup' ),
                        'checkout-hooks.php' => __( 'Checkout-specifieke hooks — auto-geladen bij plugins_loaded', 'mk-cart-popup' ),
                    ];
                ?>
                <div class="mkcp-scaffold-file">
                    <span class="mkcp-scaffold-status" style="color:<?php echo $color; ?>"><?php echo $label; ?></span>
                    <div>
                        <code><?php echo esc_html( 'mk-cart-popup/' . $file ); ?></code>
                        <span class="desc"><?php echo esc_html( $desc[ $file ] ?? '' ); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
                <?php wp_nonce_field( 'mkcp_create_scaffold' ); ?>
                <input type="hidden" name="action" value="mkcp_create_scaffold">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <?php submit_button( __( 'Bestanden aanmaken', 'mk-cart-popup' ), 'secondary', 'submit', false ); ?>
                    <label style="font-size:13px;display:flex;align-items:center;gap:5px">
                        <input type="checkbox" name="mkcp_scaffold_overwrite" value="1">
                        <?php esc_html_e( 'Bestaande bestanden overschrijven', 'mk-cart-popup' ); ?>
                    </label>
                </div>
            </form>

        </div>
    </div>

    <style>
        .mkcp-scaffold-files { display:flex; flex-direction:column; gap:6px; }
        .mkcp-scaffold-file  { display:flex; align-items:flex-start; gap:10px; }
        .mkcp-scaffold-status { font-size:16px; font-weight:700; width:18px; flex-shrink:0; }
        .mkcp-scaffold-file code { font-size:12px; background:#f0f0f0; padding:1px 6px; border-radius:3px; display:block; margin-bottom:2px; }
        .mkcp-scaffold-file .desc { font-size:12px; color:#646970; }
    </style>
    <?php
}
