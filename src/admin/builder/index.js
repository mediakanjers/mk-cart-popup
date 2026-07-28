/* global mkcpBuilder, Sortable, wp */
import { ICON, ICON_CS_PLUS, ICON_CS_PREV, ICON_CS_NEXT,
         ICON_SHARE, ICON_CHEVRON, ICON_LINK, ICON_SEND } from './icons.js';

( function ( $ ) {
    'use strict';

    // ── State ─────────────────────────────────────────────────────────────────

    var blocks          = [];
    var editingIndex    = -1;
    var previewTimer    = null;
    var DEBOUNCE_MS     = 300;
    var pendingDropZone = null;
    var history         = [];
    var historyIdx      = -1;
    var MAX_HISTORY     = 30;

    var ZONE_ORDER = [
        'above-items',
        'below-items',
        'below-totals',
        'below-payment',
        'below-checkout',
    ];

    var ZONE_LABELS = {
        'above-items'    : 'Boven producten',
        'below-items'    : 'Onder producten',
        'below-totals'   : 'Onder totalen',
        'below-payment'  : 'Onder betaalmethodes',
        'below-checkout' : 'Onder checkout-knop',
    };

    var TYPE_LABELS = {
        text    : 'Tekst',
        divider : 'Scheidingslijn',
        usp     : 'USP',
        image   : 'Afbeelding',
        banner  : 'Banner',
        button  : 'Knop',
    };

    // Mock cart values for the preview
    var MOCK = { subtotal: 35.00, items: 2 };

    // Cross-sell mock data & icons — hoisted out of buildPopupHtml to avoid per-refresh allocation
    var MOCK_CS_ALL = [
        { name: 'Aanvullend product 1', price: '&#8364;&nbsp;19,99' },
        { name: 'Gerelateerd product 2', price: '&#8364;&nbsp;34,95' },
        { name: 'Ook interessant 3', price: '&#8364;&nbsp;12,50' },
        { name: 'Aanrader product 4', price: '&#8364;&nbsp;9,95' },
        { name: 'Populair product 5', price: '&#8364;&nbsp;24,50' },
        { name: 'Extra product 6', price: '&#8364;&nbsp;17,00' },
    ];
    // Flag: pauses schedulePreview while a contenteditable field has focus
    var inlineEditingActive = false;

    // â”€â”€ Bootstrap â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function init() {
        try {
            blocks = JSON.parse( $( '#mkcp-blocks-json' ).val() ) || [];
        } catch ( e ) {
            blocks = [];
        }

        renderAllZones();
        initSortable();
        bindEvents();
        refreshPreview();
        initZoneClickPicker();
        initInlineEditing();
        pushHistory();
        snapshotSaved();

        // Refresh preview whenever the active tab changes so ghost sections
        // appear only while the builder panel is open.
        var _wrap = document.getElementById( 'mkcp-admin-wrap' );
        if ( _wrap ) {
            new MutationObserver( function () { schedulePreview(); } )
                .observe( _wrap, { attributes: true, attributeFilter: [ 'data-active-panel' ] } );
        }
    }

    // â”€â”€ Live config from form â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function readLiveConfig() {
        var $form = $( '#mkcp-form' );

        function val( name ) {
            return $form.find( '[name="' + name + '"]' ).val() || '';
        }
        function checked( name ) {
            return $form.find( '[name="' + name + '"]' ).is( ':checked' );
        }

        // Payment icons â€” read URL/label pairs per row
        var payIcons = [];
        $form.find( '.mkcp-pay-upload-row' ).each( function () {
            var url   = $( this ).find( '[name="mkcp_pay_icon_url[]"]' ).val();
            var label = $( this ).find( '[name="mkcp_pay_icon_label[]"]' ).val() || '';
            if ( url ) payIcons.push( { url: url, label: label } );
        } );

        // USP strip items â€” read icon/text pairs per row
        var usps = [];
        $form.find( '.mkcp-usp-row' ).each( function () {
            var text = $( this ).find( '[name="mkcp_usp_text[]"]' ).val();
            var icon = $( this ).find( '[name="mkcp_usp_icon[]"]' ).val() || 'check';
            if ( text ) usps.push( { icon: icon, text: text } );
        } );

        var threshold = parseFloat( val( 'mkcp_free_shipping_threshold' ) ) || 0;

        return {
            title                  : val( 'mkcp_title' )              || 'Winkelwagen',
            btn_checkout           : val( 'mkcp_btn_checkout' )       || 'Afrekenen',
            col_product            : val( 'mkcp_col_product' )        || 'Product',
            col_total              : val( 'mkcp_col_total' )          || 'Totaal',
            free_shipping_bar      : checked( 'mkcp_free_shipping_bar' ),
            free_shipping_threshold: threshold,
            shipping_note          : val( 'mkcp_shipping_note' )      || 'Nog %s voor gratis verzending',
            free_shipping_note     : val( 'mkcp_free_shipping_note' ) || 'Gratis verzending!',
            btw_split              : checked( 'mkcp_btw_split' ),
            label_excl_tax         : val( 'mkcp_label_excl_tax' )     || 'excl. BTW',
            label_incl_tax         : val( 'mkcp_label_incl_tax' )     || 'incl. BTW',
            show_coupon            : checked( 'mkcp_show_coupon' ),
            min_order_amount       : parseFloat( val( 'mkcp_min_order_amount' ) ) || 0,
            payment_icons          : payIcons,
            usps                   : usps,
            save_for_later         : checked( 'mkcp_save_for_later' ),
            stock_indicator        : checked( 'mkcp_stock_indicator' ),
            stock_threshold        : parseInt( val( 'mkcp_stock_threshold' ), 10 ) || 5,
            save_cart_url          : checked( 'mkcp_save_cart_url' ),
            save_cart_email        : checked( 'mkcp_save_cart_email' ),
            crosssell_enabled      : checked( 'mkcp_crosssell_enabled' ),
            crosssell_title        : val( 'mkcp_crosssell_title' )    || 'Misschien ook interessant?',
            crosssell_limit        : Math.min( 6, Math.max( 1, parseInt( val( 'mkcp_crosssell_limit' ), 10 ) || 3 ) ),
        };
    }

    // â”€â”€ Zone list rendering â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function renderAllZones() {
        ZONE_ORDER.forEach( renderZone );
        updateZoneCounts();
    }

    function renderZone( zone ) {
        var $list = $( '.js-mkcp-zone[data-zone="' + zone + '"]' );
        $list.empty();
        blocks.forEach( function ( block, idx ) {
            if ( block.zone === zone ) {
                $list.append( buildBlockEl( block, idx ) );
            }
        } );
    }

    function buildBlockEl( block, globalIdx ) {
        var DRAG =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14">' +
            '<circle cx="9" cy="7" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="7" r="1" fill="currentColor" stroke="none"/>' +
            '<circle cx="9" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1" fill="currentColor" stroke="none"/>' +
            '<circle cx="9" cy="17" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="17" r="1" fill="currentColor" stroke="none"/></svg>';
        var EDIT =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13">' +
            '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>' +
            '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
        var DEL =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13">' +
            '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>' +
            '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';

        var TOGGLE_ON  =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var TOGGLE_OFF =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        var DUP =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';

        var isEnabled = block.enabled !== false;

        return $( '<div class="mkcp-block-item' + ( isEnabled ? '' : ' is-disabled' ) + '" />' )
            .attr( 'data-idx', globalIdx )
            .attr( 'data-type', block.type )
            .attr( 'data-zone', block.zone )
            .append(
                $( '<span class="mkcp-block-handle">' ).html( DRAG ),
                $( '<span class="mkcp-block-badge">' ).text( TYPE_LABELS[ block.type ] || block.type ),
                $( '<span class="mkcp-block-preview">' ).text( blockPreviewText( block ) ),
                $( '<div class="mkcp-block-actions">' ).append(
                    $( '<button type="button" class="mkcp-block-action js-mkcp-toggle-block" title="' + ( isEnabled ? 'Verbergen' : 'Tonen' ) + '">' ).html( isEnabled ? TOGGLE_ON : TOGGLE_OFF ),
                    $( '<button type="button" class="mkcp-block-action js-mkcp-dup-block" title="Dupliceren">' ).html( DUP ),
                    $( '<button type="button" class="mkcp-block-action js-mkcp-edit-block" title="Bewerken">' ).html( EDIT ),
                    $( '<button type="button" class="mkcp-block-action js-mkcp-delete-block" title="Verwijderen">' ).html( DEL )
                )
            );
    }

    function blockPreviewText( block ) {
        if ( block.type === 'text' )    return stripTags( block.content || '' ).slice( 0, 50 ) || '(leeg)';
        if ( block.type === 'divider' ) return '— scheidingslijn —';
        if ( block.type === 'usp' )     return block.text || 'USP tekst';
        if ( block.type === 'image' )   return block.url ? block.url.split( '/' ).pop() : 'afbeelding';
        if ( block.type === 'banner' )  return block.text || 'Banner tekst';
        if ( block.type === 'button' )  return block.text || 'Knoptekst';
        return '';
    }

    function updateZoneCounts() {
        ZONE_ORDER.forEach( function ( zone ) {
            var count = blocks.filter( function ( b ) { return b.zone === zone; } ).length;
            $( '.mkcp-zone[data-zone="' + zone + '"] .mkcp-zone-count' ).text( count );
        } );
    }

    // â”€â”€ SortableJS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function initSortable() {
        // Left-panel zones: reorder / move between zones
        $( '.js-mkcp-zone' ).each( function () {
            Sortable.create( this, {
                group      : 'mkcp-blocks',
                handle     : '.mkcp-block-handle',
                animation  : 150,
                ghostClass : 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onEnd      : function () {
                    syncBlocksFromDom();
                    serializeToInput();
                    schedulePreview();
                },
            } );
        } );

        // Palette → preview/zone: native HTML5 drag so no SortableJS clone pollutes the preview
        $( '#mkcp-block-picker' ).on( 'dragstart', '.mkcp-block-add-btn', function ( e ) {
            e.originalEvent.dataTransfer.effectAllowed = 'copy';
            e.originalEvent.dataTransfer.setData( 'text/plain', $( this ).data( 'type' ) );
            $( this ).addClass( 'mkcp-palette-chosen' );
            $( '#mkcp-preview-frame' ).addClass( 'is-dragging' );
            $( '.js-mkcp-zone' ).addClass( 'mkcp-zone-drop-target' );
        } );
        function cleanupDrag() {
            $( '.mkcp-block-add-btn' ).removeClass( 'mkcp-palette-chosen' );
            $( '#mkcp-preview-frame' ).removeClass( 'is-dragging' );
            $( '.js-mkcp-zone' ).removeClass( 'mkcp-zone-drop-target sortable-over' );
            $( '#mkcp-preview-frame .mkcp-pzone' ).removeClass( 'sortable-over' );
        }
        $( '#mkcp-block-picker' ).on( 'dragend dragcancel', '.mkcp-block-add-btn', cleanupDrag );
        // Escape-toets annuleert drag maar veroorzaakt soms geen dragend: ook keyup afvangen
        $( document ).on( 'keyup.mkcp-drag', function ( e ) {
            if ( e.key === 'Escape' ) cleanupDrag();
        } );

        // Left-panel zones accept palette drops via HTML5
        $( '.js-mkcp-zone' ).each( function () {
            var el   = this;
            var zone = $( el ).data( 'zone' );

            el.addEventListener( 'dragover', function ( e ) {
                if ( ! e.dataTransfer.types.includes( 'text/plain' ) ) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                $( el ).addClass( 'sortable-over' );
            } );
            el.addEventListener( 'dragleave', function ( e ) {
                if ( ! el.contains( e.relatedTarget ) ) {
                    $( el ).removeClass( 'sortable-over' );
                }
            } );
            el.addEventListener( 'drop', function ( e ) {
                var type = e.dataTransfer.getData( 'text/plain' );
                if ( ! type ) return;
                e.preventDefault();
                $( el ).removeClass( 'sortable-over' );
                $( '.js-mkcp-zone' ).removeClass( 'mkcp-zone-drop-target' );
                $( '#mkcp-preview-frame' ).removeClass( 'is-dragging' );
                pendingDropZone = zone;
                openEditor( type, null );
            } );
        } );

        // Make palette buttons draggable
        $( '#mkcp-block-picker .mkcp-block-add-btn' ).attr( 'draggable', 'true' );
    }

    function initPreviewZoneDrop() {
        // Verwijder vorige listeners via een kloon-en-vervang truc om lekken te voorkomen.
        // De preview-frame wordt volledig opnieuw opgebouwd door refreshPreview(),
        // waardoor de oude DOM-nodes (en hun listeners) al verdwenen zijn.
        // Gebruik event-delegatie op de stabiele #mkcp-preview-frame in plaats van
        // per-element listeners, zodat herhaalde aanroepen geen duplicaten veroorzaken.
        var frame = document.getElementById( 'mkcp-preview-frame' );
        if ( ! frame || frame._mkcp_drop_bound ) return;
        frame._mkcp_drop_bound = true;

        frame.addEventListener( 'dragover', function ( e ) {
            var zone = e.target.closest( '.mkcp-pzone' );
            if ( ! zone ) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            $( '#mkcp-preview-frame .mkcp-pzone' ).removeClass( 'sortable-over' );
            $( zone ).addClass( 'sortable-over' );
        } );

        frame.addEventListener( 'dragleave', function ( e ) {
            var zone = e.target.closest( '.mkcp-pzone' );
            if ( zone && ! zone.contains( e.relatedTarget ) ) {
                $( zone ).removeClass( 'sortable-over' );
            }
        } );

        frame.addEventListener( 'drop', function ( e ) {
            var zone = e.target.closest( '.mkcp-pzone' );
            if ( ! zone ) return;
            e.preventDefault();
            $( zone ).removeClass( 'sortable-over' );
            $( '#mkcp-preview-frame' ).removeClass( 'is-dragging' );
            var type = e.dataTransfer.getData( 'text/plain' );
            if ( ! type ) return;
            pendingDropZone = $( zone ).data( 'zone' );
            openEditor( type, null );
        } );
    }

    function syncBlocksFromDom() {
        var newBlocks = [];
        ZONE_ORDER.forEach( function ( zone ) {
            $( '.js-mkcp-zone[data-zone="' + zone + '"] .mkcp-block-item' ).each( function () {
                var idx = parseInt( $( this ).attr( 'data-idx' ), 10 );
                if ( blocks[ idx ] ) {
                    var b  = $.extend( {}, blocks[ idx ] );
                    b.zone = zone;
                    newBlocks.push( b );
                }
            } );
        } );
        blocks = newBlocks;
        ZONE_ORDER.forEach( function ( zone ) {
            var zoneItems = blocks.filter( function ( b ) { return b.zone === zone; } );
            $( '.js-mkcp-zone[data-zone="' + zone + '"] .mkcp-block-item' ).each( function ( i ) {
                $( this ).attr( 'data-idx', blocks.indexOf( zoneItems[ i ] ) );
            } );
        } );
        updateZoneCounts();
        pushHistory();
    }

    // ── History (undo / redo) ─────────────────────────────────────────────────

    function pushHistory() {
        history = history.slice( 0, historyIdx + 1 );
        history.push( JSON.stringify( blocks ) );
        if ( history.length > MAX_HISTORY ) history.shift();
        historyIdx = history.length - 1;
        updateUndoRedoUI();
    }

    function undo() {
        if ( historyIdx <= 0 ) return;
        historyIdx--;
        blocks = JSON.parse( history[ historyIdx ] );
        renderAllZones();
        serializeToInput();
        schedulePreview();
        updateUndoRedoUI();
    }

    function redo() {
        if ( historyIdx >= history.length - 1 ) return;
        historyIdx++;
        blocks = JSON.parse( history[ historyIdx ] );
        renderAllZones();
        serializeToInput();
        schedulePreview();
        updateUndoRedoUI();
    }

    function updateUndoRedoUI() {
        $( '#mkcp-undo' ).prop( 'disabled', historyIdx <= 0 );
        $( '#mkcp-redo' ).prop( 'disabled', historyIdx >= history.length - 1 );
    }

    // ── Inline editing in preview ────────────────────────────────────────────

    var autoSaveTimer = null;
    var _savedState   = null;

    function getFormState() {
        return JSON.stringify( readLiveConfig() ) + '|' + $( '#mkcp-blocks-json' ).val();
    }

    function snapshotSaved() {
        _savedState = getFormState();
    }

    function syncDirty() {
        if ( _savedState !== null && getFormState() === _savedState ) {
            $( '#mkcp-builder-save-btn' ).removeClass( 'is-dirty' );
            $( '#mkcp-dirty-banner' ).removeClass( 'is-visible' );
            clearTimeout( autoSaveTimer );
        } else {
            markDirty();
        }
    }

    var FIELD_LABELS = {
        mkcp_title           : 'Popup titel',
        mkcp_btn_checkout    : 'Checkout knop',
        mkcp_col_product     : 'Kolom: Product',
        mkcp_col_total       : 'Kolom: Totaal',
        mkcp_crosssell_title : 'Cross-sell titel',
        mkcp_empty_heading   : 'Lege winkelwagen titel',
        mkcp_empty_button    : 'Lege winkelwagen knop',
        mkcp_shipping_note   : 'Verzending tekst',
        mkcp_free_shipping_note: 'Gratis verzending tekst',
    };

    function isBuilderOpen() {
        return $( '#mkcp-admin-wrap' ).attr( 'data-active-panel' ) === 'builder';
    }

    function sectionToggle( field, label, isOn ) {
        var sw  = '<span class="mkcp-preview-toggle-sw' + ( isOn ? ' is-on' : '' ) + '"></span>';
        var tip = isOn ? ( label + ' uitzetten' ) : ( label + ' aanzetten' );
        return '<button type="button" class="mkcp-preview-toggle-btn" data-toggle="' + field + '" title="' + tip + '"><span>' + label + '</span>' + sw + '</button>';
    }

    function markDirty() {
        $( '#mkcp-builder-save-btn' ).addClass( 'is-dirty' );
        $( '#mkcp-dirty-banner' ).addClass( 'is-visible' );
        clearTimeout( autoSaveTimer );
        autoSaveTimer = setTimeout( function () {
            if ( $( '#mkcp-builder-save-btn' ).hasClass( 'is-dirty' ) ) {
                doSave( { silent: true } );
            }
        }, 10000 );
    }

    function showToast( msg, type ) {
        $( '#mkcp-save-toast' ).remove();
        var $t = $( '<div id="mkcp-save-toast"></div>' )
            .addClass( type === 'error' ? 'is-error' : 'is-success' )
            .html( msg )
            .appendTo( 'body' );
        setTimeout( function () { $t.addClass( 'is-visible' ); }, 10 );
        setTimeout( function () { $t.removeClass( 'is-visible' ); }, 3000 );
        setTimeout( function () { $t.remove(); }, 3400 );
    }

    function doSave( opts ) {
        opts = opts || {};
        var $btn      = $( '#mkcp-builder-save-btn' );
        var $feedback = $( '#mkcp-builder-save-feedback' );

        if ( $btn.prop( 'disabled' ) ) return;

        $btn.prop( 'disabled', true ).removeClass( 'is-dirty' ).addClass( 'is-saving' );
        $feedback.attr( 'hidden', true );
        clearTimeout( autoSaveTimer );

        var $form = $( '#mkcp-form' );
        var data  = {
            action : 'mkcp_builder_save',
            nonce  : mkcpBuilder.nonce,
            blocks : $( '#mkcp-blocks-json' ).val(),
        };

        var textFields = [
            'mkcp_title', 'mkcp_btn_checkout', 'mkcp_col_product', 'mkcp_col_total',
            'mkcp_empty_heading', 'mkcp_empty_button', 'mkcp_shipping_note',
            'mkcp_free_shipping_note', 'mkcp_crosssell_title',
        ];
        textFields.forEach( function ( name ) {
            data[ name ] = $form.find( '[name="' + name + '"]' ).val() || '';
        } );

        var boolFields = [
            'mkcp_free_shipping_bar', 'mkcp_show_coupon', 'mkcp_crosssell_enabled', 'mkcp_btw_split',
            'mkcp_save_for_later', 'mkcp_stock_indicator', 'mkcp_save_cart_url', 'mkcp_save_cart_email',
        ];
        boolFields.forEach( function ( name ) {
            data[ name ] = $form.find( '[name="' + name + '"]' ).is( ':checked' ) ? '1' : '';
        } );

        var thresh = $form.find( '[name="mkcp_free_shipping_threshold"]' ).val();
        if ( thresh !== undefined ) data.mkcp_free_shipping_threshold = thresh;

        $.post( mkcpBuilder.ajaxUrl, data )
            .done( function ( res ) {
                if ( res.success ) {
                    $( '#mkcp-dirty-banner' ).removeClass( 'is-visible' );
                    snapshotSaved();
                    if ( opts.silent ) {
                        $btn.prop( 'disabled', false ).removeClass( 'is-saving' );
                        showToast( '&#10003;&nbsp; Wijzigingen automatisch opgeslagen', 'success' );
                    } else {
                        $feedback.removeAttr( 'hidden' ).removeClass( 'is-error' ).addClass( 'is-success' ).text( '✓ Opgeslagen, pagina herladen…' );
                        setTimeout( function () { window.location.reload(); }, 800 );
                    }
                } else {
                    $btn.prop( 'disabled', false ).removeClass( 'is-saving' );
                    if ( opts.silent ) {
                        showToast( '&#10007;&nbsp; Automatisch opslaan mislukt', 'error' );
                    } else {
                        $feedback.removeAttr( 'hidden' ).removeClass( 'is-success' ).addClass( 'is-error' ).text( '✗ Fout bij opslaan' );
                        setTimeout( function () { $feedback.attr( 'hidden', true ); }, 3000 );
                    }
                }
            } )
            .fail( function () {
                $btn.prop( 'disabled', false ).removeClass( 'is-saving' );
                if ( opts.silent ) {
                    showToast( '&#10007;&nbsp; Verbindingsfout bij automatisch opslaan', 'error' );
                } else {
                    $feedback.removeAttr( 'hidden' ).removeClass( 'is-success' ).addClass( 'is-error' ).text( '✗ Verbindingsfout' );
                    setTimeout( function () { $feedback.attr( 'hidden', true ); }, 3000 );
                }
            } );
    }

    function initInlineEditing() {
        var $frame = $( '#mkcp-preview-frame' );

        // Floating field label — created once, repositioned on each focus
        var $fieldLabel = $( '<div id="mkcp-field-label" aria-hidden="true"></div>' ).appendTo( 'body' );

        // "Klik om te bewerken" one-time tooltip
        if ( ! localStorage.getItem( 'mkcp_edit_tip' ) ) {
            $frame.one( 'mouseenter', '[data-mkcp-field]', function () {
                localStorage.setItem( 'mkcp_edit_tip', '1' );
                var $tip  = $( '<div id="mkcp-edit-tip">Klik om te bewerken</div>' ).appendTo( 'body' );
                var rect  = this.getBoundingClientRect();
                $tip.css( { top: ( rect.bottom + 6 ) + 'px', left: rect.left + 'px' } );
                setTimeout( function () { $tip.addClass( 'is-visible' ); }, 10 );
                setTimeout( function () { $tip.addClass( 'is-out' ); }, 2500 );
                setTimeout( function () { $tip.remove(); }, 2900 );
            } );
        }

        // Prevent Enter from inserting a newline — blur instead (saves & refreshes)
        $frame.on( 'keydown', '[data-mkcp-field]', function ( e ) {
            if ( e.key === 'Enter' || e.key === 'Escape' ) {
                e.preventDefault();
                this.blur();
            }
        } );

        // Pause auto-refresh while editing; show field label; snapshot original value
        var _inlineOriginal = '';
        $frame.on( 'focus', '[data-mkcp-field]', function () {
            inlineEditingActive = true;
            _inlineOriginal     = this.textContent;
            var field = $( this ).data( 'mkcp-field' );
            var label = FIELD_LABELS[ field ] || field.replace( /^mkcp_/, '' ).replace( /_/g, ' ' );
            var rect  = this.getBoundingClientRect();
            $fieldLabel
                .text( label )
                .css( { top: ( rect.top - 26 ) + 'px', left: rect.left + 'px' } )
                .addClass( 'is-visible' );
        } );

        // Keep form field in sync silently (no schedulePreview)
        $frame.on( 'input', '[data-mkcp-field]', function () {
            var field = $( this ).data( 'mkcp-field' );
            $( '[name="' + field + '"]' ).val( $( this ).text() );
        } );

        // On blur: commit & refresh; only mark dirty when content actually changed
        $frame.on( 'blur', '[data-mkcp-field]', function () {
            var field   = $( this ).data( 'mkcp-field' );
            var current = this.textContent;
            $( '[name="' + field + '"]' ).val( current );
            inlineEditingActive = false;
            $fieldLabel.removeClass( 'is-visible' );
            if ( current !== _inlineOriginal ) syncDirty();
            schedulePreview();
        } );

        // Section toggle clicks (only rendered when builder is open)
        $frame.on( 'click', '.mkcp-preview-toggle-btn', function ( e ) {
            e.stopPropagation();
            var field = $( this ).data( 'toggle' );
            var $cb   = $( '[name="' + field + '"]' );
            $cb.prop( 'checked', ! $cb.is( ':checked' ) ).trigger( 'change' );
            syncDirty();
        } );

        // Save button + Ctrl/Cmd+S
        $( '#mkcp-builder-save-btn' ).on( 'click', function () { doSave(); } );
        $( document ).on( 'keydown.mkcp-save', function ( e ) {
            if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
                e.preventDefault();
                doSave();
            }
        } );
    }

    // ── Zone-click popover ───────────────────────────────────────────────────

    var $zonePopover = null;

    function buildZonePopover() {
        var TYPE_ICONS = {
            text    : 'T',
            divider : '—',
            usp     : '✓',
            image   : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            banner  : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            button  : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><rect x="3" y="8" width="18" height="8" rx="4"/></svg>',
        };
        var html = '<div id="mkcp-zone-popover" class="mkcp-zone-popover" hidden>'
            + '<div class="mkcp-zone-popover__label">Voeg toe</div>'
            + '<div class="mkcp-zone-popover__grid">';
        Object.keys( TYPE_LABELS ).forEach( function ( type ) {
            html += '<button type="button" class="mkcp-zone-popover__btn" data-type="' + type + '">'
                + '<span class="mkcp-zone-popover__icon">' + ( TYPE_ICONS[ type ] || '' ) + '</span>'
                + '<span>' + TYPE_LABELS[ type ] + '</span>'
                + '</button>';
        } );
        html += '</div></div>';
        $zonePopover = $( html ).appendTo( 'body' );

        $zonePopover.on( 'click', '.mkcp-zone-popover__btn', function () {
            var type = $( this ).data( 'type' );
            hideZonePopover();
            openEditor( type, null );
        } );
    }

    function showZonePopover( zone, rect ) {
        if ( ! $zonePopover ) buildZonePopover();
        pendingDropZone = zone;
        $zonePopover.removeAttr( 'hidden' );

        var pw = $zonePopover.outerWidth();
        var ph = $zonePopover.outerHeight();
        var cx = rect.left + rect.width / 2 + window.scrollX;
        var cy = rect.top  + rect.height / 2 + window.scrollY;
        var left = Math.max( 8, Math.min( cx - pw / 2, window.innerWidth - pw - 8 ) );
        var top  = cy - ph / 2;
        if ( top + ph > window.scrollY + window.innerHeight - 8 ) top = window.scrollY + window.innerHeight - ph - 8;
        if ( top < window.scrollY + 8 ) top = window.scrollY + 8;
        $zonePopover.css( { left: left, top: top } );

        setTimeout( function () {
            $( document ).one( 'click.zonePopover', function ( e ) {
                if ( ! $zonePopover || ! $zonePopover[0].contains( e.target ) ) hideZonePopover();
            } );
        }, 0 );
    }

    function hideZonePopover() {
        if ( $zonePopover ) $zonePopover.attr( 'hidden', true );
        $( document ).off( 'click.zonePopover' );
    }

    function initZoneClickPicker() {
        var frame = document.getElementById( 'mkcp-preview-frame' );
        if ( ! frame ) return;
        $( frame ).on( 'click', '.mkcp-pzone.is-empty', function ( e ) {
            e.stopPropagation();
            var zone = $( this ).data( 'zone' );
            var rect = this.getBoundingClientRect();
            showZonePopover( zone, rect );
        } );
    }

    // ── Events ───────────────────────────────────────────────────────────────

    function bindEvents() {
        // Block picker
        $( '#mkcp-block-picker' ).on( 'click', '.mkcp-block-add-btn', function () {
            openEditor( $( this ).data( 'type' ), null );
        } );

        // Toggle enabled
        $( '#mkcp-zones' ).on( 'click', '.js-mkcp-toggle-block', function ( e ) {
            e.stopPropagation();
            var idx = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            blocks[ idx ].enabled = blocks[ idx ].enabled === false;
            renderAllZones();
            serializeToInput();
            schedulePreview();
            pushHistory();
        } );

        // Duplicate
        $( '#mkcp-zones' ).on( 'click', '.js-mkcp-dup-block', function ( e ) {
            e.stopPropagation();
            var idx  = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            var copy = $.extend( true, {}, blocks[ idx ] );
            delete copy.id;
            blocks.splice( idx + 1, 0, copy );
            renderAllZones();
            serializeToInput();
            schedulePreview();
            pushHistory();
        } );

        // Edit / delete
        $( '#mkcp-zones' ).on( 'click', '.js-mkcp-edit-block', function ( e ) {
            e.stopPropagation();
            var idx = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            openEditor( blocks[ idx ].type, idx );
        } );
        $( '#mkcp-zones' ).on( 'click', '.js-mkcp-delete-block', function ( e ) {
            e.stopPropagation();
            var idx = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            blocks.splice( idx, 1 );
            renderAllZones();
            serializeToInput();
            schedulePreview();
            pushHistory();
        } );

        // Undo / redo keyboard shortcuts
        $( document ).on( 'keydown.mkcp-builder', function ( e ) {
            var tag = document.activeElement && document.activeElement.tagName;
            if ( tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' ) return;
            if ( ( e.ctrlKey || e.metaKey ) && e.key === 'z' && ! e.shiftKey ) { e.preventDefault(); undo(); }
            if ( ( e.ctrlKey || e.metaKey ) && ( e.key === 'y' || ( e.key === 'z' && e.shiftKey ) ) ) { e.preventDefault(); redo(); }
        } );

        // Undo / redo buttons
        $( '#mkcp-undo' ).on( 'click', undo );
        $( '#mkcp-redo' ).on( 'click', redo );

        // Click-to-edit in preview
        $( '#mkcp-preview-frame' ).on( 'click', '.mkcp-preview-edit-btn', function ( e ) {
            e.preventDefault();
            e.stopPropagation();
            var idx = parseInt( $( this ).data( 'idx' ), 10 );
            if ( blocks[ idx ] ) {
                openEditor( blocks[ idx ].type, idx );
            }
        } );

        // Click-to-delete in preview
        $( '#mkcp-preview-frame' ).on( 'click', '.mkcp-preview-del-btn', function ( e ) {
            e.preventDefault();
            e.stopPropagation();
            var idx = parseInt( $( this ).data( 'idx' ), 10 );
            if ( blocks[ idx ] ) {
                pushHistory();
                blocks.splice( idx, 1 );
                renderAllZones();
                serializeToInput();
                schedulePreview();
            }
        } );

        // Editor actions
        $( '#mkcp-editor-close, #mkcp-editor-cancel' ).on( 'click', closeEditor );
        $( '#mkcp-block-editor' ).on( 'click', function ( e ) { if ( e.target === this ) closeEditor(); } );
        $( '#mkcp-editor-save' ).on( 'click', saveEditor );
        $( document ).on( 'keydown.mkcp-editor', function ( e ) { if ( e.key === 'Escape' ) closeEditor(); } );

        // Live form sync: any field change â†’ update preview
        $( '#mkcp-form' ).on( 'input change', 'input, select, textarea', function () {
            if ( $( this ).attr( 'id' ) === 'mkcp-blocks-json' ) return;
            schedulePreview();
        } );

        // Enabled toggle: slide sidebar in/out
        var $enabledToggle = $( '[name="mkcp_enabled"]' );
        function syncSidebarVisibility() {
            $( '#mkcp-popup-sidebar' ).toggleClass( 'is-plugin-disabled', ! $enabledToggle.is( ':checked' ) );
        }
        $enabledToggle.on( 'change', syncSidebarVisibility );
        syncSidebarVisibility(); // apply on load
    }

    // â”€â”€ Block editor â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function openEditor( type, idx ) {
        editingIndex = ( idx === null ) ? -1 : idx;
        var block    = ( idx !== null ) ? $.extend( {}, blocks[ idx ] ) : { type: type };

        $( '#mkcp-editor-title' ).text(
            ( idx === null ? 'Nieuw' : 'Bewerk' ) + ' ' + ( TYPE_LABELS[ type ] || type ) + ' blok'
        );
        $( '#mkcp-block-editor' ).attr( 'data-type', type );
        $( '#mkcp-editor-body' ).html( buildEditorFields( type, block ) );

        if ( type === 'image' ) {
            $( '#mkcp-editor-body' ).on( 'click', '#mkcp-editor-media-btn', function () {
                openMediaPicker( function ( url ) { $( '#mkcp-editor-image-url' ).val( url ); } );
            } );
        }

        $( '#mkcp-block-editor' ).show();
        $( '#mkcp-block-editor' ).find( 'input, textarea, select' ).first().focus();
    }

    function buildEditorFields( type, block ) {
        var zoneField =
            '<div class="mkcp-editor-field"><label>Zone</label>' + buildZoneSelect( block.zone ) + '</div>';

        if ( type === 'text' ) {
            return zoneField +
                '<div class="mkcp-editor-field"><label>Inhoud (HTML toegestaan)</label>' +
                '<textarea id="mkcp-editor-text" rows="4">' + esc( block.content || '' ) + '</textarea></div>' +
                '<div class="mkcp-editor-field mkcp-editor-row">' +
                '<div><label>Uitlijning</label>' +
                '<select id="mkcp-editor-text-align">' +
                '<option value=""'       + sel( block.align, '' )       + '>Standaard</option>' +
                '<option value="left"'   + sel( block.align, 'left' )   + '>Links</option>' +
                '<option value="center"' + sel( block.align, 'center' ) + '>Gecentreerd</option>' +
                '<option value="right"'  + sel( block.align, 'right' )  + '>Rechts</option>' +
                '</select></div>' +
                '<div><label>Kleur</label>' +
                '<input type="color" id="mkcp-editor-text-color" value="' + esc( block.color || '#000000' ) + '"></div>' +
                '</div>';
        }
        if ( type === 'divider' ) {
            return zoneField +
                '<div class="mkcp-editor-field"><label>Stijl</label>' +
                '<select id="mkcp-editor-divider-style">' +
                '<option value="solid"'  + sel( block.style, 'solid' )  + '>Doorgetrokken lijn</option>' +
                '<option value="dashed"' + sel( block.style, 'dashed' ) + '>Gestippeld</option>' +
                '<option value="dotted"' + sel( block.style, 'dotted' ) + '>Punten</option>' +
                '<option value="spacer"' + sel( block.style, 'spacer' ) + '>Witruimte</option>' +
                '</select></div>';
        }
        if ( type === 'usp' ) {
            return zoneField +
                '<div class="mkcp-editor-field"><label>Icoon</label>' +
                '<select id="mkcp-editor-usp-icon">' +
                '<option value="check"'  + sel( block.icon, 'check' )  + '>âœ“ Vinkje</option>' +
                '<option value="star"'   + sel( block.icon, 'star' )   + '>â˜… Ster</option>' +
                '<option value="shield"' + sel( block.icon, 'shield' ) + '>â¬¡ Schild</option>' +
                '<option value="truck"'  + sel( block.icon, 'truck' )  + '>â¬¡ Vrachtwagen</option>' +
                '<option value="phone"'  + sel( block.icon, 'phone' )  + '>â¬¡ Telefoon</option>' +
                '</select></div>' +
                '<div class="mkcp-editor-field"><label>Tekst</label>' +
                '<input type="text" id="mkcp-editor-usp-text" value="' + esc( block.text || '' ) + '" placeholder="Gratis verzending vanaf â‚¬50"></div>';
        }
        if ( type === 'image' ) {
            return zoneField +
                '<div class="mkcp-editor-field"><label>Afbeelding URL</label>' +
                '<div class="mkcp-media-row">' +
                '<input type="url" id="mkcp-editor-image-url" value="' + esc( block.url || '' ) + '" placeholder="https://...">' +
                '<button type="button" id="mkcp-editor-media-btn" class="mkcp-btn mkcp-btn--secondary" style="flex-shrink:0;white-space:nowrap">Kies</button>' +
                '</div></div>' +
                '<div class="mkcp-editor-field"><label>Link URL (optioneel)</label>' +
                '<input type="url" id="mkcp-editor-image-link" value="' + esc( block.link || '' ) + '" placeholder="https://..."></div>' +
                '<div class="mkcp-editor-field"><label>Alt-tekst</label>' +
                '<input type="text" id="mkcp-editor-image-alt" value="' + esc( block.alt || '' ) + '" placeholder="Beschrijving afbeelding"></div>';
        }
        if ( type === 'banner' ) {
            return zoneField +
                '<div class="mkcp-editor-field"><label>Tekst</label>' +
                '<input type="text" id="mkcp-editor-banner-text" value="' + esc( block.text || '' ) + '" placeholder="Melding tekst"></div>' +
                '<div class="mkcp-editor-field"><label>Stijl</label>' +
                '<select id="mkcp-editor-banner-variant">' +
                '<option value="info"'    + sel( block.variant, 'info' )    + '>ℹ️ Info (blauw)</option>' +
                '<option value="success"' + sel( block.variant, 'success' ) + '>✅ Succes (groen)</option>' +
                '<option value="warning"' + sel( block.variant, 'warning' ) + '>⚠️ Waarschuwing (oranje)</option>' +
                '<option value="danger"'  + sel( block.variant, 'danger' )  + '>🔴 Gevaar (rood)</option>' +
                '</select></div>';
        }
        if ( type === 'button' ) {
            return zoneField +
                '<div class="mkcp-editor-field"><label>Knoptekst</label>' +
                '<input type="text" id="mkcp-editor-button-text" value="' + esc( block.text || '' ) + '" placeholder="Klik hier"></div>' +
                '<div class="mkcp-editor-field"><label>Link URL</label>' +
                '<input type="url" id="mkcp-editor-button-url" value="' + esc( block.url || '' ) + '" placeholder="https://..."></div>' +
                '<div class="mkcp-editor-field mkcp-editor-row">' +
                '<div><label>Stijl</label>' +
                '<select id="mkcp-editor-button-variant">' +
                '<option value="primary"'   + sel( block.variant, 'primary' )   + '>Primair</option>' +
                '<option value="secondary"' + sel( block.variant, 'secondary' ) + '>Secundair</option>' +
                '<option value="ghost"'     + sel( block.variant, 'ghost' )     + '>Ghost</option>' +
                '</select></div>' +
                '<div><label>Uitlijning</label>' +
                '<select id="mkcp-editor-button-align">' +
                '<option value="center"' + sel( block.align, 'center' ) + '>Midden</option>' +
                '<option value="left"'   + sel( block.align, 'left' )   + '>Links</option>' +
                '<option value="right"'  + sel( block.align, 'right' )  + '>Rechts</option>' +
                '</select></div>' +
                '</div>';
        }
        return '';
    }

    function buildZoneSelect( currentZone ) {
        var defaultZone = currentZone || pendingDropZone || ZONE_ORDER[ 0 ];
        pendingDropZone = null;
        var html = '<select id="mkcp-editor-zone">';
        ZONE_ORDER.forEach( function ( z ) {
            html += '<option value="' + z + '"' + sel( defaultZone, z ) + '>' + ZONE_LABELS[ z ] + '</option>';
        } );
        return html + '</select>';
    }

    function saveEditor() {
        var type = $( '#mkcp-block-editor' ).attr( 'data-type' );
        var zone = $( '#mkcp-editor-zone' ).val() || ZONE_ORDER[ 0 ];
        var block;

        if ( type === 'text' )         block = { type: 'text',    zone: zone, content: $( '#mkcp-editor-text' ).val(), align: $( '#mkcp-editor-text-align' ).val(), color: $( '#mkcp-editor-text-color' ).val() };
        else if ( type === 'divider' ) block = { type: 'divider', zone: zone, style: $( '#mkcp-editor-divider-style' ).val() };
        else if ( type === 'usp' )     block = { type: 'usp',     zone: zone, icon: $( '#mkcp-editor-usp-icon' ).val(), text: $( '#mkcp-editor-usp-text' ).val() };
        else if ( type === 'image' )   block = { type: 'image',   zone: zone, url: $( '#mkcp-editor-image-url' ).val(), link: $( '#mkcp-editor-image-link' ).val(), alt: $( '#mkcp-editor-image-alt' ).val() };
        else if ( type === 'banner' )  block = { type: 'banner',  zone: zone, text: $( '#mkcp-editor-banner-text' ).val(), variant: $( '#mkcp-editor-banner-variant' ).val() };
        else if ( type === 'button' )  block = { type: 'button',  zone: zone, text: $( '#mkcp-editor-button-text' ).val(), url: $( '#mkcp-editor-button-url' ).val(), variant: $( '#mkcp-editor-button-variant' ).val(), align: $( '#mkcp-editor-button-align' ).val() };

        if ( ! block ) return;
        block.enabled = true;

        if ( editingIndex >= 0 ) blocks[ editingIndex ] = block;
        else                     blocks.push( block );

        closeEditor();
        renderAllZones();
        serializeToInput();
        schedulePreview();
    }

    function closeEditor() {
        $( '#mkcp-block-editor' ).hide();
        editingIndex = -1;
    }

    // â”€â”€ Media picker â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function openMediaPicker( callback ) {
        if ( typeof wp === 'undefined' || ! wp.media ) return;
        var frame = wp.media( { title: 'Kies een afbeelding', button: { text: 'Gebruik deze afbeelding' }, multiple: false, library: { type: 'image' } } );
        frame.on( 'select', function () { callback( frame.state().get( 'selection' ).first().toJSON().url ); } );
        frame.open();
    }

    // â”€â”€ Serialization â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function serializeToInput() {
        $( '#mkcp-blocks-json' ).val( JSON.stringify( blocks ) );
    }

    // â”€â”€ Preview scheduling â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function schedulePreview() {
        if ( inlineEditingActive ) return;
        clearTimeout( previewTimer );
        previewTimer = setTimeout( refreshPreview, DEBOUNCE_MS );
    }

    // â”€â”€ Popup HTML builder â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function refreshPreview() {
        // Vernieuw de preview HTML. initPreviewZoneDrop used event-delegatie op de
        // frame zelf (eenmalig gebonden), dus geen herinitialisatie nodig hier.
        var frame = document.getElementById( 'mkcp-preview-frame' );
        if ( frame ) {
            // Reset de flag zodat event-delegatie na een volledige DOM-vervaning
            // opnieuw gebonden wordt als het frame zelf vervangen is.
            frame._mkcp_drop_bound = false;
        }
        var $frame2 = $( '#mkcp-preview-frame' );
        $frame2.html( buildPopupHtml() );
        $frame2.find( '.mk-cart-popup.mkcp-is-preview' ).addClass( 'mkcp-preview-in' );
        initPreviewZoneDrop();
        // Voeg CSS-klasse toe aan lege zones (vervangt :has() dat niet breed ondersteund is)
        $( '#mkcp-preview-frame .mkcp-pzone' ).each( function () {
            $( this ).toggleClass( 'is-empty', $( this ).find( '.mkcp-preview-block' ).length === 0 );
        } );
    }

    // ── Preview section templates ─────────────────────────────────────────────

    // Wraps content in a ghost toggle section when builder is open;
    // otherwise shows content only when the live condition is met.
    function optionalSection( field, label, isOn, isBuilder, contentHtml, liveCondition ) {
        var show = ( liveCondition === undefined ) ? isOn : liveCondition;
        if ( isBuilder ) {
            return '<div class="mkcp-preview-section' + ( isOn ? '' : ' is-off' ) + '">' +
                   sectionToggle( field, label, isOn ) +
                   ( isOn ? contentHtml : '' ) +
                   '</div>';
        }
        return show ? contentHtml : '';
    }

    function tplEditable( field, content, isBuilder ) {
        if ( isBuilder ) {
            return '<span data-mkcp-field="' + field + '" contenteditable="true" spellcheck="false">' + content + '</span>';
        }
        return '<span>' + content + '</span>';
    }

    function tplHeader( cfg, isBuilder ) {
        return '<div class="mk-cart-popup__header">' +
               '<div class="mk-cart-popup__title">' + ICON.cart + ' ' +
               tplEditable( 'mkcp_title', esc( cfg.title ), isBuilder ) +
               '</div>' +
               '<button class="mk-cart-popup__close" disabled>' + ICON.close + '</button>' +
               '</div>';
    }

    function tplBtwSwitch( cfg ) {
        return '<div class="mk-cart-popup__btw-switch">' +
               '<span class="mk-cart-popup__btw-label">Prijzen tonen:</span>' +
               '<div class="mk-cart-popup__btw-pills">' +
               '<button type="button" class="mk-cart-popup__btw-opt is-active">' + esc( cfg.label_incl_tax || 'Incl. BTW' ) + '</button>' +
               '<button type="button" class="mk-cart-popup__btw-opt">' + esc( cfg.label_excl_tax || 'Excl. BTW' ) + '</button>' +
               '</div></div>';
    }

    function tplShippingBar( shippingData ) {
        return '<div class="mk-cart-popup__progress">' +
               '<div class="mk-cart-popup__progress-text' + ( shippingData.met ? ' mk-cart-popup__progress-text--success' : '' ) + '">' + shippingData.text + '</div>' +
               '<div class="mk-cart-popup__progress-bar">' +
               '<div class="mk-cart-popup__progress-fill" style="width:' + shippingData.pct + '%"></div>' +
               '</div></div>';
    }

    function tplCrosssell( cfg, isBuilder ) {
        var items = MOCK_CS_ALL.slice( 0, cfg.crosssell_limit );
        var title = isBuilder
            ? '<div class="mk-cart-popup__crosssell-title" data-mkcp-field="mkcp_crosssell_title" contenteditable="true" spellcheck="false">' + esc( cfg.crosssell_title ) + '</div>'
            : '<div class="mk-cart-popup__crosssell-title">' + esc( cfg.crosssell_title ) + '</div>';
        var list = items.map( function ( p ) {
            return '<div class="mk-cart-popup__crosssell-item">' +
                   '<div class="mk-cart-popup__crosssell-img" style="background:#e8e8e8;border-radius:5px;width:50px;height:50px;flex-shrink:0"></div>' +
                   '<div class="mk-cart-popup__crosssell-info">' +
                   '<span class="mk-cart-popup__crosssell-name">' + esc( p.name ) + '</span>' +
                   '<span class="mk-cart-popup__crosssell-price">' + p.price + '</span>' +
                   '</div>' +
                   '<button type="button" class="mk-cart-popup__crosssell-atc" disabled>' + ICON_CS_PLUS + '</button>' +
                   '</div>';
        } ).join( '' );
        return '<div class="mk-cart-popup__crosssell">' + title +
               '<div class="mk-cart-popup__crosssell-track can-scroll-right">' +
               '<div class="mk-cart-popup__crosssell-list">' + list + '</div>' +
               '<button class="mk-cart-popup__crosssell-nav mk-cart-popup__crosssell-nav--prev" hidden>' + ICON_CS_PREV + '</button>' +
               '<button class="mk-cart-popup__crosssell-nav mk-cart-popup__crosssell-nav--next">' + ICON_CS_NEXT + '</button>' +
               '</div></div>';
    }

    function tplCoupon() {
        return '<div class="mk-cart-popup__coupon"><div class="mk-cart-popup__coupon-row">' +
               '<input type="text" class="mk-cart-popup__coupon-input" placeholder="Kortingscode" disabled>' +
               '<button type="button" class="mk-cart-popup__coupon-btn" disabled>Toepassen</button>' +
               '</div></div>';
    }

    function tplTotals( cfg ) {
        var val = cfg.btw_split
            ? '<span class="mk-cart-popup__totals-value">' +
              '<span class="price-excl-tax" style="display:block">&#8364; 29,71 <span style="font-size:11px;opacity:.6">' + esc( cfg.label_excl_tax ) + '</span></span>' +
              '<span class="price-incl-tax" style="display:block">&#8364; 35,00 <span style="font-size:11px;opacity:.6">' + esc( cfg.label_incl_tax ) + '</span></span></span>'
            : '<span class="mk-cart-popup__totals-value">&#8364; 35,00</span>';
        var btwRow = cfg.btw_split
            ? '<div class="mk-cart-popup__btw-row"><span>Waarvan BTW (21%):</span><span>&#8364; 5,29</span></div>'
            : '';
        return '<div class="mk-cart-popup__totals"><span class="mk-cart-popup__totals-label">Subtotaal</span>' + val + '</div>' + btwRow;
    }

    function tplPaymentIcons( cfg ) {
        var icons = cfg.payment_icons.filter( function ( p ) { return p.url; } );
        if ( ! icons.length ) return '';
        return '<div class="mk-cart-popup__payment-icons">' +
               icons.map( function ( pi ) {
                   return '<img src="' + esc( pi.url ) + '" alt="' + esc( pi.label ) + '" loading="lazy" style="max-height:24px">';
               } ).join( '' ) +
               '</div>';
    }

    function tplCta( cfg, isBuilder, belowMinimum ) {
        var cls   = 'mk-cart-popup__btn mk-cart-popup__btn--primary' + ( belowMinimum ? ' mk-cart-popup__btn--disabled' : '' );
        var style = belowMinimum ? 'opacity:.45;cursor:default' : '';
        var label = tplEditable( 'mkcp_btn_checkout', esc( cfg.btn_checkout ), isBuilder );
        return '<div class="mk-cart-popup__ctas">' +
               '<span class="' + cls + '" style="' + style + '">' + label + ' ' + ICON.arrow + '</span>' +
               '</div>';
    }

    function tplSavedItems() {
        return '<div class="mk-cart-popup__saved">' +
               '<div class="mk-cart-popup__saved-header">' + ICON.heart + '<span>Bewaard voor later</span><span class="mk-cart-popup__saved-count">1</span></div>' +
               '<div class="mk-cart-popup__saved-list"><div class="mk-cart-popup__saved-item">' +
               '<div class="mk-cart-popup__saved-thumb-placeholder"></div>' +
               '<div class="mk-cart-popup__saved-item-info">' +
               '<span class="mk-cart-popup__saved-item-name">Opgeslagen product</span>' +
               '<span class="mk-cart-popup__saved-item-price">&#8364;&nbsp;24,95</span>' +
               '</div>' +
               '<div class="mk-cart-popup__saved-item-actions">' +
               '<button class="mk-cart-popup__saved-restore" type="button" disabled>' + ICON.restore + '<span>Terugzetten</span></button>' +
               '<button class="mk-cart-popup__saved-delete" type="button" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" width="12" height="12"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>' +
               '</div></div></div></div>';
    }

    function tplShare( cfg, isBuilder ) {
        var shareActive = cfg.save_cart_url || cfg.save_cart_email;

        if ( ! isBuilder && ! shareActive ) return '';

        var scopePills = ( cfg.save_for_later && shareActive )
            ? '<div class="mk-cart-popup__share-scope">' +
              '<button class="mk-cart-popup__scope-pill" type="button" disabled>' + ICON.cartSm + ' Winkelmand</button>' +
              '<button class="mk-cart-popup__scope-pill" type="button" disabled>' + ICON.heart + ' Bewaard</button>' +
              '<button class="mk-cart-popup__scope-pill is-active" type="button" disabled>' + ICON.cartSm + ICON.heart + ' Alles</button>' +
              '</div>'
            : '';

        var urlSection   = '<div class="mk-cart-popup__share-url-wrap"><button class="mk-cart-popup__share-btn" type="button" disabled>' + ICON_LINK + ' Link genereren</button></div>';
        var emailSection = '<div class="mk-cart-popup__share-email-wrap"><div class="mk-cart-popup__share-email-row">' +
                           '<input class="mk-cart-popup__share-email-input" type="email" placeholder="jouw@email.nl" disabled>' +
                           '<button class="mk-cart-popup__share-btn" type="button" disabled>' + ICON_SEND + ' Verstuur</button>' +
                           '</div></div>';

        var body;
        if ( isBuilder ) {
            body = scopePills +
                   optionalSection( 'mkcp_save_cart_url',   'Link genereren',  cfg.save_cart_url,   isBuilder, urlSection ) +
                   optionalSection( 'mkcp_save_cart_email', 'Mail naar mijzelf', cfg.save_cart_email, isBuilder, emailSection );
        } else {
            body = scopePills +
                   ( cfg.save_cart_url   ? urlSection   : '' ) +
                   ( cfg.save_cart_email ? emailSection : '' );
        }

        return '<div class="mk-cart-popup__share">' +
               '<button class="mk-cart-popup__share-heading" type="button" aria-expanded="true" style="pointer-events:none">' + ICON_SHARE + ' Deel winkelmand' + ICON_CHEVRON + '</button>' +
               '<div class="mk-cart-popup__share-body">' + body + '</div>' +
               '</div>';
    }

    function buildPopupHtml() {
        var cfg       = readLiveConfig();
        var isBuilder = isBuilderOpen();

        // Shipping bar math
        var threshold = cfg.free_shipping_threshold;
        if ( cfg.free_shipping_bar && threshold <= 0 ) threshold = 50;
        var subtotal  = MOCK.subtotal;
        var remaining = threshold > 0 ? Math.max( 0, threshold - subtotal ) : 0;
        var shipping  = {
            met  : threshold > 0 && remaining <= 0,
            pct  : threshold > 0 ? Math.min( 100, Math.round( ( subtotal / threshold ) * 100 ) ) : 0,
            text : '',
        };
        shipping.text = shipping.met
            ? esc( cfg.free_shipping_note )
            : esc( cfg.shipping_note ).replace( '%s', '&#8364;&nbsp;' + remaining.toFixed( 2 ).replace( '.', ',' ) );

        var minOrder     = cfg.min_order_amount || 0;
        var belowMinimum = minOrder > 0 && subtotal < minOrder;

        var colHeaders = '<div class="mk-cart-popup__items-header">' +
            tplEditable( 'mkcp_col_product', esc( cfg.col_product ), isBuilder ) +
            tplEditable( 'mkcp_col_total',   esc( cfg.col_total ),   isBuilder ) +
            '</div>';

        var minOrderNote = belowMinimum
            ? '<div class="mk-cart-popup__min-order"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Minimaal bestelbedrag: &#8364;&nbsp;' + minOrder.toFixed( 2 ).replace( '.', ',' ) + '</div>'
            : ( minOrder > 0 ? '<div class="mkcp-preview-note">&#128270; preview &middot; min. bestelbedrag &#8364;&nbsp;' + minOrder.toFixed( 2 ).replace( '.', ',' ) + ' bereikt</div>' : '' );

        var uspStrip = cfg.usps.length
            ? '<div class="mk-cart-popup__usps">' +
              cfg.usps.map( function ( u ) { return '<span class="mk-cart-popup__usp">' + ( ICON.usp[ u.icon ] || ICON.usp.check ) + ' ' + esc( u.text ) + '</span>'; } ).join( '' ) +
              '</div>'
            : '';

        return '<div class="mk-cart-popup mkcp-is-preview"><div class="mk-cart-popup__drawer">' +

            tplHeader( cfg, isBuilder ) +
            optionalSection( 'mkcp_btw_split', 'BTW splitsing', cfg.btw_split, isBuilder, tplBtwSwitch( cfg ) ) +

            '<div class="mk-cart-popup__body">' +
            optionalSection( 'mkcp_free_shipping_bar', 'Gratis verzending balk', cfg.free_shipping_bar, isBuilder, tplShippingBar( shipping ), cfg.free_shipping_bar && threshold > 0 ) +
            optionalSection( 'mkcp_stock_indicator', 'Voorraad indicator', cfg.stock_indicator, isBuilder, '' ) +
            renderZoneHtml( 'above-items' ) +
            colHeaders +
            '<div class="mk-cart-popup__items">' +
            mockItemHtml( 'Voorbeeldproduct 1', '&#8364; 24,95', 1, cfg, true,  isBuilder ) +
            mockItemHtml( 'Voorbeeldproduct 2', '&#8364; 10,05', 2, cfg, false, isBuilder ) +
            '</div>' +
            renderZoneHtml( 'below-items' ) +
            optionalSection( 'mkcp_crosssell_enabled', 'Cross-sell', cfg.crosssell_enabled, isBuilder, tplCrosssell( cfg, isBuilder ) ) +

            '<div class="mk-cart-popup__footer">' +
            optionalSection( 'mkcp_show_coupon', 'Kortingscode veld', cfg.show_coupon, isBuilder, tplCoupon() ) +
            tplTotals( cfg ) +
            renderZoneHtml( 'below-totals' ) +
            tplPaymentIcons( cfg ) +
            renderZoneHtml( 'below-payment' ) +
            minOrderNote +
            tplCta( cfg, isBuilder, belowMinimum ) +
            renderZoneHtml( 'below-checkout' ) +
            uspStrip +
            optionalSection( 'mkcp_save_for_later', 'Bewaar voor later', cfg.save_for_later, isBuilder, tplSavedItems() ) +
            tplShare( cfg, isBuilder ) +
            '</div>' + // footer

            '</div>' + // body
            '</div>' + // drawer
            '</div>';  // mk-cart-popup
    }

    // â”€â”€ Block zone rendering â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function renderZoneHtml( zone ) {
        var zoneBlocks = blocks.filter( function ( b ) { return b.zone === zone; } );
        var EDIT_ICO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
        var DEL_ICO  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';
        var DROP_ICO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="10" height="10"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
        var h = '<div class="mkcp-pzone" data-zone="' + zone + '" data-label="' + ( ZONE_LABELS[ zone ] || zone ) + '">';
        if ( ! zoneBlocks.length ) {
            h += '<div class="mkcp-pzone-empty">' + DROP_ICO + ' Sleep hier een blok</div>';
        }
        zoneBlocks.forEach( function ( b ) {
            var globalIdx = blocks.indexOf( b );
            h += '<div class="mkcp-preview-block" data-idx="' + globalIdx + '">';
            h += renderBlockHtml( b );
            h += '<div class="mkcp-preview-block-overlay">';
            h += '<button class="mkcp-preview-edit-btn" data-idx="' + globalIdx + '" type="button">' + EDIT_ICO + ' Bewerken</button>';
            h += '<button class="mkcp-preview-del-btn" data-idx="' + globalIdx + '" type="button">' + DEL_ICO + '</button>';
            h += '</div>';
            h += '</div>';
        } );
        return h + '</div>';
    }

    // JS-tegenhanger van mkcp_render_block() in config.php (de PHP-kant die
    // op de live site rendert). Bloktype/veld toegevoegd aan de PHP-versie?
    // Voeg 'm ook hier toe, anders wijkt deze live preview af van de site.
    function renderBlockHtml( block ) {
        if ( block.type === 'text' ) {
            var style = [];
            if ( block.align ) style.push( 'text-align:' + block.align );
            if ( block.color && block.color !== '#000000' ) style.push( 'color:' + block.color );
            return '<div class="mkcp-block mkcp-block--text"' + ( style.length ? ' style="' + style.join( ';' ) + '"' : '' ) + '>' + ( block.content || '' ) + '</div>';
        }
        if ( block.type === 'divider' ) {
            return '<div class="mkcp-block mkcp-block--divider"><hr class="mkcp-divider mkcp-divider--' + esc( block.style || 'solid' ) + '"></div>';
        }
        if ( block.type === 'usp' ) {
            var icon = ICON.usp[ block.icon ] || ICON.usp.check;
            return '<div class="mkcp-block mkcp-block--usp"><span class="mkcp-usp-icon">' + icon + '</span><span class="mkcp-usp-text">' + esc( block.text || '' ) + '</span></div>';
        }
        if ( block.type === 'image' && block.url ) {
            var img = '<img class="mkcp-block-img" src="' + esc( block.url ) + '" alt="' + esc( block.alt || '' ) + '">';
            if ( block.link ) img = '<a class="mkcp-block-img-link" href="' + esc( block.link ) + '">' + img + '</a>';
            return '<div class="mkcp-block mkcp-block--image">' + img + '</div>';
        }
        if ( block.type === 'banner' ) {
            var variant = block.variant || 'info';
            return '<div class="mkcp-block mkcp-block--banner mkcp-banner--' + esc( variant ) + '">' + esc( block.text || '' ) + '</div>';
        }
        if ( block.type === 'button' ) {
            var bVariant = block.variant || 'primary';
            var bAlign   = block.align   || 'center';
            var bLabel   = esc( block.text || 'Knop' );
            var bHref    = block.url ? ' href="' + esc( block.url ) + '"' : '';
            return '<div class="mkcp-block mkcp-block--button" style="text-align:' + bAlign + '">' +
                '<a class="mkcp-btn-block mkcp-btn-block--' + esc( bVariant ) + '"' + bHref + '>' + bLabel + '</a></div>';
        }
        return '';
    }

    function mockItemHtml( name, price, qty, cfg, showStock, isBuilder ) {
        cfg = cfg || {};
        var stockOn    = cfg.stock_indicator && showStock;
        var stockBadge = ( stockOn || ( isBuilder && showStock ) )
            ? '<div class="mk-cart-popup__stock-badge' + ( ! stockOn ? ' mkcp-ghost-feature' : '' ) + '">' + ICON.stock + ' Nog maar 3 op voorraad</div>'
            : '';
        var saveOn  = cfg.save_for_later;
        var saveBtn = ( saveOn || isBuilder )
            ? '<button class="mk-cart-popup__save-later' + ( ! saveOn ? ' mkcp-ghost-feature' : '' ) + '" type="button" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="11" height="11"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg><span>Bewaar</span></button>'
            : '';
        var removeBtn = '<button class="mk-cart-popup__item-remove" type="button" disabled>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>' +
            '</button>';

        return '<div class="mk-cart-popup__item">' +
            '<div class="mkcp-preview-img-placeholder"></div>' +
            '<div class="mk-cart-popup__item-info">' +
            '<div class="mk-cart-popup__item-name-wrap"><span class="mk-cart-popup__item-name" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + name + '</span></div>' +
            stockBadge +
            '<div class="mk-cart-popup__item-actions">' +
            '<div class="mk-cart-popup__qty">' +
            '<button class="mk-cart-popup__qty-btn mk-cart-popup__qty-btn--min" type="button" disabled>&#8722;</button>' +
            '<input class="mk-cart-popup__qty-input" type="number" value="' + qty + '" style="pointer-events:none">' +
            '<button class="mk-cart-popup__qty-btn mk-cart-popup__qty-btn--plus" type="button" disabled>+</button>' +
            '</div>' +
            saveBtn +
            removeBtn +
            '</div>' +
            '</div>' +
            '<div class="mk-cart-popup__item-col-price">' + price + '</div>' +
            '</div>';
    }
    // â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    function esc( str ) {
        return String( str ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
    }

    function sel( current, value ) { return current === value ? ' selected' : ''; }

    function stripTags( html ) {
        var d = document.createElement( 'div' );
        d.innerHTML = html;
        return d.textContent || d.innerText || '';
    }

    // â”€â”€ Init â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    $( function () {
        if ( $( '#mkcp-zones' ).length ) init();
    } );

} )( jQuery );





