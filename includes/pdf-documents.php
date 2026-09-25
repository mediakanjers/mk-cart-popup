<?php
/**
 * MK Cart Popup — PDF-documenten (WP Overnight: PDF Invoices & Packing Slips)
 *
 * Voegt onze eigen ordergegevens toe aan factuur én pakbon, via de hooks van
 * de PDF-plugin (geen templatekopie). Per onderdeel aan/uit te zetten onder
 * Checkout → PDF-documenten; standaard staat alles aan.
 *
 * De bezorg- en afhaalrijen in de ordergegevens-tabel staan in
 * delivery-date.php en pickup.php (zelfde aan/uit-vinkjes). Wat de PDF-plugin
 * zelf al toont (verzendmethode, opmerking van de klant, telefoon, e-mail) laten
 * we aan de instellingen van die plugin.
 *
 * DOMPDF: alleen eenvoudige HTML/CSS (geen flexbox/grid), en buiten een
 * <table> geen <tr>/<td> (zie de toelichting bij wpo_wcpdf_after_order_data).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Is de PDF-plugin van WP Overnight actief? */
function mkcp_pdf_plugin_active(): bool {
    return function_exists( 'WPO_WCPDF' ) || class_exists( 'WPO_WCPDF' ) || defined( 'WPO_WCPDF_VERSION' );
}

/** Aan/uit-instelling voor één PDF-onderdeel (standaard aan). */
function mkcp_pdf_option( string $key ): bool {
    $cfg = function_exists( 'mkcp_checkout_config' ) ? mkcp_checkout_config() : [];
    return ! array_key_exists( $key, $cfg ) || ! empty( $cfg[ $key ] );
}

/**
 * Registreert een callback die een <tr> in de ordergegevens-tabel van het
 * PDF-document zet. Normaal via wpo_wcpdf_after_order_data (na "Betaalmethode").
 * Een aangepast (thema-)sjabloon kan die hook missen -- het tombloemen-factuur-
 * sjabloon heeft alleen wpo_wcpdf_before_order_data -- dan vallen we terug op
 * die hook, zodat de gegevens op elk sjabloon verschijnen.
 */
function mkcp_pdf_add_order_data_row( callable $callback, int $priority = 10 ) {
    add_action( 'wpo_wcpdf_after_order_data', function( $type, $order ) use ( $callback ) {
        if ( mkcp_pdf_template_has_after_hook() ) $callback( $type, $order );
    }, $priority, 2 );
    add_action( 'wpo_wcpdf_before_order_data', function( $type, $order ) use ( $callback ) {
        if ( ! mkcp_pdf_template_has_after_hook() ) $callback( $type, $order );
    }, $priority, 2 );
}

/**
 * Bevat het sjabloon van het document dat nu wordt gerenderd de
 * wpo_wcpdf_after_order_data-hook? Onthouden via de wpo_wcpdf_template_file-filter.
 */
function mkcp_pdf_template_has_after_hook( $set = null ): bool {
    static $has = true;
    if ( $set !== null ) $has = (bool) $set;
    return $has;
}

add_filter( 'wpo_wcpdf_template_file', function( $file ) {
    $src = ( is_string( $file ) && is_readable( $file ) ) ? file_get_contents( $file ) : '';
    mkcp_pdf_template_has_after_hook( $src === '' || strpos( $src, 'wpo_wcpdf_after_order_data' ) !== false );
    return $file;
}, 99 );

/**
 * Bezorg- en/of afhaalgegevens van een order als lijst regels (leesbare tekst).
 */
function mkcp_pdf_fulfilment_lines( $order ): array {
    $lines = [];

    $d_date = $order->get_meta( '_mkcp_delivery_date' );
    if ( $d_date ) {
        $slot    = $order->get_meta( '_mkcp_delivery_slot' );
        $lines[] = [
            'label' => __( 'Bezorgen', 'mk-cart-popup' ),
            'text'  => mkcp_dd_format_date( $d_date ) . ( $slot ? ', ' . $slot : '' ),
            'extra' => '',
        ];
    }

    $p_date = $order->get_meta( '_mkcp_pickup_date' );
    if ( $p_date ) {
        $slot    = $order->get_meta( '_mkcp_pickup_slot' );
        $rate_id = (string) $order->get_meta( '_mkcp_pickup_rate_id' );
        $loc     = function_exists( 'mkcp_pickup_location_for_rate' ) ? mkcp_pickup_location_for_rate( $rate_id ) : null;
        $label   = $loc['location_label'] ?? ( $order->get_meta( '_mkcp_pickup_location' ) ?: __( 'Afhaallocatie', 'mk-cart-popup' ) );
        $extra   = $label;
        if ( ! empty( $loc['address'] ) ) $extra .= "\n" . $loc['address'];
        $lines[] = [
            'label' => __( 'Afhalen', 'mk-cart-popup' ),
            'text'  => mkcp_dd_format_date( $p_date ) . ( $slot ? ', ' . $slot : '' ),
            'extra' => $extra,
        ];
    }

    return $lines;
}

// ── Opvallende kop onder de documenttitel ─────────────────────────────────────
//
// wpo_wcpdf_after_document_label vuurt buiten alle tabellen, dus een <div>
// is hier veilig. Inline-stijl: de PDF-plugin laadt onze CSS niet.
add_action( 'wpo_wcpdf_after_document_label', function( $document_type, $order ) {
    if ( ! $order || ! mkcp_pdf_option( 'pdf_headline' ) ) return;

    $lines = mkcp_pdf_fulfilment_lines( $order );
    if ( ! $lines ) return;

    echo '<div class="mkcp-pdf-headline" style="margin:0 0 14px;padding:8px 12px;border:1px solid #999;">';
    foreach ( $lines as $line ) {
        echo '<div style="font-size:12pt;font-weight:bold;"><span style="font-weight:normal;">'
            . esc_html( $line['label'] ) . ':</span> ' . esc_html( $line['text'] ) . '</div>';
        if ( $line['extra'] !== '' ) {
            echo '<div style="font-size:9pt;">' . nl2br( esc_html( $line['extra'] ) ) . '</div>';
        }
    }
    echo '</div>';
}, 10, 2 );

// ── BTW-nummer + verlegd/berekend ─────────────────────────────────────────────
//
// Alleen als de order een BTW-nummer heeft (EU/UK VAT Validation Manager).
// "BTW verlegd" moet volgens de regels op de factuur staan; is_vat_exempt is
// de vlag die die plugin op de order zet als de BTW echt is verlegd.
mkcp_pdf_add_order_data_row( function( $document_type, $order ) {
    if ( ! $order || ! mkcp_pdf_option( 'pdf_vat_info' ) ) return;

    $vat = trim( (string) ( $order->get_meta( '_billing_eu_vat_number' ) ?: $order->get_meta( 'billing_eu_vat_number' ) ) );
    if ( $vat === '' ) return;

    $exempt = $order->get_meta( 'is_vat_exempt' ) === 'yes';

    echo '<tr class="mkcp-vat-info"><th><strong>' . esc_html__( 'BTW-nummer', 'mk-cart-popup' ) . '</strong></th><td>'
        . esc_html( $vat ) . '<br>'
        . esc_html( $exempt ? __( 'BTW verlegd naar afnemer (0%)', 'mk-cart-popup' ) : __( 'BTW berekend', 'mk-cart-popup' ) )
        . '</td></tr>';
}, 12 );
